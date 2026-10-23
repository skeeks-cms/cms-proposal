<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\proposal\log;

use skeeks\cms\backend\widgets\BackendEntityLink;
use skeeks\cms\communication\services\LinkChecks;
use skeeks\cms\log\CmsLogRendererInterface;
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
class ProposalLogRenderer implements CmsLogRendererInterface
{
    public function render(CmsLog $log): string
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
        $recipient = (string)ArrayHelper::getValue($data, 'recipient', '');
        $note = '';
        if ($event === ProposalFeed::EVENT_CHECKED) {
            $note = Html::tag('div', Html::encode('Ссылку открыла, похоже, почтовая служба или защитная система ('
                .LinkChecks::reasonName((string)ArrayHelper::getValue($data, 'reason')).'). Это не засчитано как открытие клиентом.'), ['class' => 'sx-gray']);
        }

        if (!$proposal) {
            $what = $isPresentation ? 'Презентация' : 'КП';
            return Html::tag('div',
                Html::tag('div', Html::encode($title), ['class' => 'h5'])
                .Html::tag('div', CmsProposal::find()->andWhere(['id' => $id])->exists()
                    ? ($isPresentation ? 'Презентация, к которой у вас нет доступа.' : 'КП, к которому у вас нет доступа.')
                    : $what.($isPresentation ? ' удалена.' : ' удалено.'), ['class' => 'sx-gray'])
            );
        }

        $version = $isPresentation ? null : $proposal->currentVersion;
        $details = array_filter([
            $version ? ProposalItems::money($version->amount, (string)$version->currency_code) : '',
            $proposal->displayStatusName,
        ]);

        return Html::tag('div',
            Html::tag('div', Html::encode($title), ['class' => 'h5'])
            .Html::tag('div', BackendEntityLink::widget([
                'controllerId' => '/cmsProposal/admin-cms-proposal',
                'modelId'      => (int)$proposal->id,
                'label'        => $proposal->asText,
                'options'      => ['style' => 'font-weight:600'],
            ]).($details ? ' <span class="sx-gray">· '.Html::encode(implode(' · ', $details)).'</span>' : ''))
            .($recipient !== '' ? Html::tag('div', Html::encode(($event === ProposalFeed::EVENT_SENT ? 'Кому: ' : 'Ссылка отправлена: ').$recipient), ['class' => 'sx-gray']) : '')
            .$note,
            ['class' => 'sx-proposal-log']
        );
    }
}
