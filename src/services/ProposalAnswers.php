<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\proposal\services;

use skeeks\cms\communication\services\SiteMessages;
use skeeks\cms\proposal\models\CmsProposal;
use skeeks\cms\proposal\models\CmsProposalQuestion;
use skeeks\cms\proposal\models\CmsProposalSend;
use skeeks\cms\proposal\models\CmsProposalVersion;

/**
 * What the client does on the page of a proposal: "Принять" and "Задать
 * вопрос". One service for the page and future bots.
 *
 * - accept(): the latest sent version, while the proposal waits and is
 *   valid; "Принято", feed and notification once — a repeated press finds
 *   the proposal accepted and changes nothing. No bill is made: the
 *   manager issues it.
 * - ask(): an incoming message of the client in the CRM conversation
 *   (skeeks/cms-communication SiteMessages, channel "site", in the feeds of
 *   the client and its company), a CmsProposalQuestion row and a
 *   notification. Limited per link, per address and per proposal.
 */
class ProposalAnswers
{
    /** Questions from one link per hour. */
    public const LIMIT_PER_SEND = 5;
    /** Questions from one IP address per hour, over all proposals. */
    public const LIMIT_PER_IP = 10;
    /** Questions about one proposal per day. */
    public const LIMIT_PER_PROPOSAL = 20;

    /** Why the client cannot accept the version now, or null. */
    public static function acceptRefusal(CmsProposal $proposal, CmsProposalVersion $version): ?string
    {
        if ($proposal->status === CmsProposal::STATUS_ACCEPTED) {
            return null;
        }
        if ($proposal->status === CmsProposal::STATUS_REJECTED) {
            return 'Предложение отклонено. Свяжитесь с менеджером.';
        }
        if ($proposal->isExpired) {
            return 'Срок действия предложения истёк. Свяжитесь с менеджером.';
        }
        $latest = $proposal->latestSentVersion;
        if (!$latest || (int)$latest->id !== (int)$version->id) {
            return 'Есть обновлённое предложение: откройте его, чтобы принять.';
        }

        return null;
    }

    /**
     * @return bool true when this press accepted it, false when it was accepted before
     * @throws \DomainException with a text for the client
     */
    public function accept(CmsProposalSend $send, CmsProposalVersion $version): bool
    {
        $proposal = $send->proposal;
        if ($error = self::acceptRefusal($proposal, $version)) {
            throw new \DomainException($error);
        }
        $now = time();
        $changed = CmsProposal::updateAll([
            'status' => CmsProposal::STATUS_ACCEPTED,
            'status_at' => $now,
            'accepted_at' => $now,
            'accepted_version_id' => (int)$version->id,
            'accepted_send_id' => (int)$send->id,
        ], ['and', ['id' => (int)$proposal->id], ['!=', 'status', CmsProposal::STATUS_ACCEPTED]]);
        $proposal->refresh();
        if (!$changed) {
            return false;
        }

        ProposalFeed::write($proposal, ProposalFeed::EVENT_ACCEPTED, [
            'channel' => (string)$send->channel,
            'send' => (int)$send->id,
            'version' => (int)$version->version,
        ], $send);
        ProposalNotifier::notify($proposal, 'Клиент принял '.$proposal->asText,
            'Версия '.(int)$version->version.', '.ProposalItems::money($version->amount, (string)$version->currency_code)
            .'. Ссылка: '.mb_strtolower($send->channelName).($send->recipientText !== '' ? ', '.$send->recipientText : ''), 'proposal.accepted');
        self::campaignRequest($send, 'Принял '.$proposal->asText.', версия '.(int)$version->version.'.');

        return true;
    }

    /**
     * @throws \DomainException with a text for the client
     */
    public function ask(CmsProposalSend $send, ?CmsProposalVersion $version, string $text, string $contact, string $name, string $ip, string $userAgent): CmsProposalQuestion
    {
        $text = trim(str_replace("\r", '', $text));
        $contact = trim($contact);
        $name = trim($name);
        if (mb_strlen($text) < 3) {
            throw new \DomainException('Напишите вопрос.');
        }
        if (mb_strlen($text) > CmsProposalQuestion::MAX_TEXT) {
            throw new \DomainException('Слишком длинный вопрос: не больше '.CmsProposalQuestion::MAX_TEXT.' символов.');
        }
        $this->checkLimits($send, $ip);

        $proposal = $send->proposal;
        $transaction = CmsProposalQuestion::getDb()->beginTransaction();
        try {
            $question = new CmsProposalQuestion([
                'cms_proposal_id' => (int)$proposal->id,
                'cms_proposal_send_id' => (int)$send->id,
                'cms_proposal_version_id' => $version ? (int)$version->id : null,
                'name' => mb_substr($name, 0, 255) ?: null,
                'contact' => mb_substr($contact, 0, 255) ?: null,
                'text' => $text,
                'ip' => mb_substr($ip, 0, 45) ?: null,
                'user_agent' => mb_substr($userAgent, 0, 500) ?: null,
            ]);
            if (!$question->save()) {
                throw new \RuntimeException('Не удалось сохранить вопрос: '.implode(' ', $question->getErrorSummary(true)));
            }

            $body = $text;
            if ($contact !== '') {
                $body .= "\n\nКак связаться: ".$contact;
            }
            $body .= "\n\n".'Вопрос задан на странице '.$proposal->asText.($version ? ', версия '.(int)$version->version : '')
                .' (ссылка: '.mb_strtolower($send->channelName).($send->recipientText !== '' ? ', '.$send->recipientText : '').').';
            $message = (new SiteMessages())->receive((int)$proposal->cms_site_id, 'proposal:'.(int)$proposal->id,
                'Вопрос по КП №'.(int)$proposal->number.($proposal->name ? ' «'.$proposal->name.'»' : ''),
                $body, $name !== '' ? $name : (string)$send->recipient_name, $contact !== '' ? $contact : (string)$send->recipient,
                (new \skeeks\cms\proposal\send\ProposalSendable($proposal))->getSendRecords());
            $question->updateAttributes(['cms_comm_message_id' => (int)$message->id]);

            ProposalNotifier::notify($proposal, 'Вопрос клиента по '.$proposal->asText, mb_substr($text, 0, 500), 'proposal.question');
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
        self::campaignRequest($send, 'Вопрос по '.$proposal->asText.': '.mb_substr($text, 0, 500).($contact !== '' ? "\nКак связаться: ".$contact : ''), $message);

        return $question;
    }

    /**
     * A question or an acceptance on the page of an offer sent in a mailing
     * campaign is a request from the letter (П2д): the open deal of the
     * client continues, otherwise a deal of the sender or «Неразобранное»
     * (cms-communication Campaigns::onOfferRequest()).
     */
    private static function campaignRequest(CmsProposalSend $send, string $what, $message = null): void
    {
        $campaigns = \skeeks\cms\communication\services\Campaigns::class;
        if (!$send->cms_comm_message_id || !method_exists($campaigns, 'onOfferRequest')) {
            return;
        }
        try {
            $campaigns::onOfferRequest((int)$send->cms_comm_message_id, $what, $message);
        } catch (\Throwable $e) {
            \Yii::error('Заявка из рассылки по ссылке #'.(int)$send->id.' не создана: '.$e->getMessage(), self::class);
        }
    }

    private function checkLimits(CmsProposalSend $send, string $ip): void
    {
        $hour = time() - 3600;
        $tooMany = 'Слишком много вопросов подряд. Попробуйте позже или свяжитесь с менеджером.';
        if (CmsProposalQuestion::find()->andWhere(['cms_proposal_send_id' => (int)$send->id])->andWhere(['>=', 'created_at', $hour])->count() >= self::LIMIT_PER_SEND) {
            throw new \DomainException($tooMany);
        }
        if ($ip !== '' && CmsProposalQuestion::find()->andWhere(['ip' => mb_substr($ip, 0, 45)])->andWhere(['>=', 'created_at', $hour])->count() >= self::LIMIT_PER_IP) {
            throw new \DomainException($tooMany);
        }
        if (CmsProposalQuestion::find()->andWhere(['cms_proposal_id' => (int)$send->cms_proposal_id])->andWhere(['>=', 'created_at', time() - 86400])->count() >= self::LIMIT_PER_PROPOSAL) {
            throw new \DomainException($tooMany);
        }
    }
}
