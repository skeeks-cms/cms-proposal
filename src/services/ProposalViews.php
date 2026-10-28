<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\proposal\services;

use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\services\LinkChecks;
use skeeks\cms\proposal\models\CmsProposal;
use skeeks\cms\proposal\models\CmsProposalSend;
use skeeks\cms\proposal\models\CmsProposalView;

/**
 * Honest "открыто": mail link checkers, Telegram, MAX and messenger
 * previews, antiviruses open a link before the client does. A view is
 * recorded only by a signal of the page itself after it was shown in a
 * browser (ProposalController::actionSeen, a request from JS while the page
 * is visible), never by opening the link. Known preview robots are kept in
 * the history as robots and not counted; a repeated signal of the same
 * browser within REPEAT_WINDOW is not a new view; employees are not counted.
 *
 * Link checks that run scripts in a real browser (seconds after sending, a
 * network of a mail service or a security system, an automated browser:
 * skeeks/cms-communication LinkChecks) are kept with their reason, written
 * to the feed once as "возможно, проверка ссылок" and give nothing else.
 *
 * The first counted view of a sending: the feed entry "КП открыто (канал)"
 * or "Презентация открыта (канал)", a sent proposal becomes "Открыто", the
 * responsible employee gets a notification and, by the rule "задача при
 * первом открытии", a call task (ProposalTasks). The same for both kinds.
 */
class ProposalViews
{
    public const RESULT_COUNTED = 'counted';
    public const RESULT_FIRST = 'first';
    public const RESULT_REPEAT = 'repeat';
    public const RESULT_BOT = 'bot';
    public const RESULT_STAFF = 'staff';
    public const RESULT_CHECK = 'check';

    /** Seconds in which the same browser does not count again (reloads, a second tab). */
    public const REPEAT_WINDOW = 1800;

    /**
     * Robots that open links for previews and checks: the shared list of
     * skeeks/cms-communication, also used by visits by links of letters.
     */
    public const BOT_PATTERNS = LinkChecks::ROBOT_PATTERNS;

    public static function isBot(?string $userAgent): bool
    {
        return LinkChecks::isRobot($userAgent);
    }

    /**
     * When the link reached the client: the message left (the send job sets
     * message_at of a sent message), otherwise when it was queued.
     */
    public static function sentAt(CmsProposalSend $send): int
    {
        $sentAt = (int)$send->created_at;
        if ($send->cms_comm_message_id) {
            $message = CmsCommMessage::find()->select(['status', 'message_at'])->andWhere(['id' => (int)$send->cms_comm_message_id])->asArray()->one();
            if ($message && $message['status'] === CmsCommMessage::STATUS_SENT && (int)$message['message_at'] > $sentAt) {
                $sentAt = (int)$message['message_at'];
            }
        }

        return $sentAt;
    }

    /**
     * A signal of the page: records the view and returns what it was
     * (RESULT_* constants).
     *
     * @param array $signals of the page script for LinkChecks: webdriver, languages, width, height
     */
    public function record(CmsProposalSend $send, string $userAgent, string $ip, bool $isStaff = false, array $signals = []): string
    {
        if ($isStaff) {
            return self::RESULT_STAFF;
        }
        $userAgent = mb_substr($userAgent, 0, 500);
        $ip = mb_substr($ip, 0, 45);
        $bot = self::isBot($userAgent);
        $check = $bot ? null : LinkChecks::reason(self::sentAt($send), $ip, $userAgent, $signals);

        $recent = CmsProposalView::find()->andWhere([
            'cms_proposal_send_id' => (int)$send->id,
            'user_agent' => $userAgent,
            'ip' => $ip,
            'is_bot' => $bot ? 1 : 0,
        ])->andWhere($check === null ? ['check_reason' => null] : ['not', ['check_reason' => null]])
            ->andWhere(['>=', 'created_at', time() - self::REPEAT_WINDOW])->exists();
        if ($recent) {
            return $bot ? self::RESULT_BOT : ($check !== null ? self::RESULT_CHECK : self::RESULT_REPEAT);
        }

        $view = new CmsProposalView([
            'cms_proposal_send_id' => (int)$send->id,
            'user_agent' => $userAgent,
            'ip' => $ip,
            'is_bot' => $bot ? 1 : 0,
            'check_reason' => $check,
        ]);
        if (!$view->save()) {
            throw new \RuntimeException('Не удалось записать просмотр предложения.');
        }
        if ($bot) {
            return self::RESULT_BOT;
        }
        if ($check !== null) {
            // Once per sending in the feed: several checkers open one letter.
            $earlier = CmsProposalView::find()->andWhere(['cms_proposal_send_id' => (int)$send->id])
                ->andWhere(['not', ['check_reason' => null]])->andWhere(['<', 'id', (int)$view->id])->exists();
            if (!$earlier) {
                ProposalFeed::write($send->proposal, ProposalFeed::EVENT_CHECKED, [
                    'channel' => (string)$send->channel,
                    'send' => (int)$send->id,
                    'recipient' => $send->recipientText,
                    'reason' => $check,
                ], $send);
            }

            return self::RESULT_CHECK;
        }

        $now = (int)$view->created_at;
        CmsProposalSend::updateAllCounters(['views_count' => 1], ['id' => (int)$send->id]);
        CmsProposalSend::updateAll(['last_viewed_at' => $now], ['id' => (int)$send->id]);
        // Two tabs at once: only one of them is the first view.
        $first = CmsProposalSend::updateAll(['first_viewed_at' => $now], ['id' => (int)$send->id, 'first_viewed_at' => null]) > 0;
        $send->refresh();
        if (!$first) {
            return self::RESULT_COUNTED;
        }

        $proposal = $send->proposal;
        if ($proposal->status === CmsProposal::STATUS_SENT) {
            $proposal->status = CmsProposal::STATUS_OPENED;
            $proposal->status_at = $now;
            $proposal->updateAttributes(['status', 'status_at']);
        }
        $data = ['channel' => (string)$send->channel, 'send' => (int)$send->id, 'recipient' => $send->recipientText];
        ProposalFeed::write($proposal, ProposalFeed::EVENT_OPENED, $data, $send);
        if ($send->cms_comm_message_id && class_exists(\skeeks\cms\communication\services\Audience::class)) {
            // A signal of interest of a letter's recipient: the company or contact is warm (cms-communication), no deal.
            try {
                \skeeks\cms\communication\services\Audience::warmByMessage((int)$send->cms_comm_message_id, $proposal->isPresentation
                    ? \skeeks\cms\communication\models\CmsCommAudience::WARM_PRESENTATION : \skeeks\cms\communication\models\CmsCommAudience::WARM_OFFER, $now);
            } catch (\Throwable $e) {
                \Yii::error('Отметка «тёплый» по открытию предложения #'.(int)$proposal->id.' не поставлена: '.$e->getMessage(), self::class);
            }
        }
        $what = $proposal->isPresentation ? 'презентацию №'.(int)$proposal->number.($proposal->name ? ' — '.$proposal->name : '') : $proposal->asText;
        // A presentation for everybody: say who opened it.
        $who = $proposal->isShared && ($name = $send->clientName) !== '' ? $name.': ' : '';
        // "Клиент открыл" and the call task that follows are one entry of the bell and one message.
        \Yii::$app->notifications->group('proposal-send:'.(int)$send->id, static function () use ($proposal, $send, $who, $what, $now) {
            ProposalNotifier::notify($proposal, $who.($who !== '' ? 'клиент' : 'Клиент').' открыл '.$what.' ('.mb_strtolower($send->channelName).')',
                $send->recipientText !== '' ? 'Отправлено: '.$send->recipientText : null);

            // The view stays recorded whatever happens to the task: the client must not see an error.
            try {
                (new ProposalTasks())->onFirstView($proposal, $send, $now);
            } catch (\Throwable $e) {
                \Yii::error('Задача по открытию предложения #'.(int)$proposal->id.' не поставлена: '.$e->getMessage(), self::class);
            }
        });

        return self::RESULT_FIRST;
    }
}
