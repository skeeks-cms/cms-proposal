<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\proposal\log;

use skeeks\cms\backend\widgets\BackendEntityLink;
use skeeks\cms\communication\services\AiMark;
use skeeks\cms\communication\services\LinkChecks;
use skeeks\cms\log\CmsLogEntry;
use skeeks\cms\log\CmsLogEntryRenderer;
use skeeks\cms\models\CmsLog;
use skeeks\cms\proposal\models\CmsProposal;
use skeeks\cms\proposal\models\CmsProposalSend;
use skeeks\cms\proposal\services\ProposalFeed;
use skeeks\cms\proposal\services\ProposalItems;
use yii\helpers\ArrayHelper;
use yii\helpers\Html;

/**
 * A proposal in the activity feed: "КП создано", "Новая версия КП",
 * "КП отправлено (письмо)", "КП открыто", "КП принято", the same for a
 * presentation ("Презентация открыта (письмо)") and "возможно, проверка
 * ссылок" for an opening that looks like a mail service. The proposal is
 * named and linked only for employees who may see it.
 */
class ProposalLogRenderer extends CmsLogEntryRenderer implements \skeeks\cms\log\CmsLogFeedVisibilityInterface
{
    /** Icon and tone of each event in the shared feed entry. */
    private const LOOK = [
        ProposalFeed::EVENT_CREATED  => ['file-text', CmsLogEntry::TONE_NEUTRAL],
        ProposalFeed::EVENT_VERSION  => ['file-text', CmsLogEntry::TONE_NEUTRAL],
        ProposalFeed::EVENT_SENT     => ['send', CmsLogEntry::TONE_ACCENT],
        ProposalFeed::EVENT_OPENED   => ['eye', CmsLogEntry::TONE_SUCCESS],
        ProposalFeed::EVENT_ACCEPTED => ['check-circle', CmsLogEntry::TONE_SUCCESS],
        ProposalFeed::EVENT_CHECKED  => ['shield', CmsLogEntry::TONE_NEUTRAL],
    ];

    public function entry(CmsLog $log): CmsLogEntry
    {
        $data = (array)$log->data;
        $event = (string)ArrayHelper::getValue($data, 'event', ProposalFeed::EVENT_CREATED);
        $id = (int)ArrayHelper::getValue($data, 'id');

        $query = CmsProposal::find()->andWhere([CmsProposal::tableName().'.id' => $id]);
        if (\Yii::$app->has('user') && !(\Yii::$app instanceof \yii\console\Application) && !\Yii::$app->user->isGuest) {
            $query->forManager();
        }
        $proposal = $query->with('currentVersion')->one();
        // The kind is not a secret: the title reads right without access too.
        $kind = $proposal ? (string)$proposal->kind : (string)(CmsProposal::find()->select('kind')->andWhere(['id' => $id])->scalar() ?: CmsProposal::KIND_PROPOSAL);
        $isPresentation = $kind === CmsProposal::KIND_PRESENTATION;

        $title = ProposalFeed::eventNames($kind)[$event] ?? ($isPresentation ? 'Презентация' : 'КП');
        if ($event === ProposalFeed::EVENT_VERSION && ($number = (int)ArrayHelper::getValue($data, 'version'))) {
            $title .= ': версия '.$number;
        }
        // From which channel: the link of every sending is its own.
        if (in_array($event, [ProposalFeed::EVENT_SENT, ProposalFeed::EVENT_OPENED, ProposalFeed::EVENT_ACCEPTED, ProposalFeed::EVENT_CHECKED], true)
            && ($channel = (string)ArrayHelper::getValue($data, 'channel'))) {
            $title .= ' ('.mb_strtolower(CmsProposalSend::channels()[$channel] ?? $channel).')';
        }
        [$icon, $tone] = self::LOOK[$event] ?? ['file-text', CmsLogEntry::TONE_NEUTRAL];
        $entry = new CmsLogEntry(['icon' => $icon, 'tone' => $tone, 'title' => $title]);

        // "Подготовлено ИИ": the letter of the send was written by AI; the AI client and the employees are in the tooltip.
        $ai = (array)ArrayHelper::getValue($data, 'ai', []);
        if ($ai && class_exists(AiMark::class)) {
            $mark = ['by' => AiMark::BY_AI, 'client' => (string)($ai['client'] ?? ''), 'user_id' => $ai['user_id'] ?? null, 'at' => 0, 'edited' => !empty($ai['edited']),
                'sent_via' => $ai['sent_via'] ?? null];
            $entry->badges[] = CmsLogEntry::badge('Подготовлено ИИ', AiMark::text($mark, isset($ai['sent_by']) ? (int)$ai['sent_by'] : null));
        }

        if (!$proposal) {
            $what = $isPresentation ? 'Презентация' : 'КП';
            $entry->details[] = CmsProposal::find()->andWhere(['id' => $id])->exists()
                ? ($isPresentation ? 'Презентация, к которой у вас нет доступа.' : 'КП, к которому у вас нет доступа.')
                : $what.($isPresentation ? ' удалена.' : ' удалено.');

            return $entry;
        }

        $version = $isPresentation ? null : $proposal->currentVersion;
        $facts = array_filter([
            $version ? ProposalItems::money($version->amount, (string)$version->currency_code) : '',
            $proposal->displayStatusName,
        ]);
        $entry->summary = BackendEntityLink::widget([
                'controllerId' => '/cmsProposal/admin-cms-proposal',
                'modelId'      => (int)$proposal->id,
                'label'        => $proposal->asText,
            ])
            .($facts ? ' '.Html::tag('span', '· '.Html::encode(implode(' · ', $facts)), ['class' => 'sx-gray']) : '');

        $recipient = (string)ArrayHelper::getValue($data, 'recipient', '');
        if ($recipient !== '') {
            $entry->details[] = Html::encode(($event === ProposalFeed::EVENT_SENT ? 'Кому: ' : 'Ссылка отправлена: ').$recipient);
        }
        if ($event === ProposalFeed::EVENT_CHECKED) {
            $entry->details[] = Html::encode('Ссылку открыла, похоже, почтовая служба или защитная система ('
                .LinkChecks::reasonName((string)ArrayHelper::getValue($data, 'reason')).'). Это не засчитано как открытие клиентом.');
        }

        return $entry;
    }

    /**
     * A limited client feed ("Свои сделки", the pool) shows a proposal entry
     * only of a proposal the employee sees (CmsProposalQuery::forManager()).
     */
    public function feedVisibleCondition($user, string $logTable): array
    {
        $id = new \yii\db\Expression("CAST(JSON_UNQUOTE(JSON_EXTRACT({$logTable}.data, '$.id')) AS UNSIGNED)");

        return ['in', $id, CmsProposal::find()->forManager($user)->select(CmsProposal::tableName().'.id')];
    }
}
