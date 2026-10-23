<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\proposal\mcp;

use skeeks\cms\mcp\crm\CrmActor;
use skeeks\cms\mcp\crm\CrmReadContext;
use skeeks\cms\mcp\crm\HistoryLogDescriberInterface;
use skeeks\cms\models\CmsLog;
use skeeks\cms\proposal\models\CmsProposal;
use skeeks\cms\proposal\models\CmsProposalSend;
use skeeks\cms\proposal\services\ProposalFeed;
use skeeks\cms\proposal\services\ProposalItems;
use yii\helpers\ArrayHelper;

/**
 * A proposal event in the client history: created, new version, sent
 * (channel), opened by the client, accepted, or a probable link check of a
 * mail service (not an opening). Access follows ProposalLogRenderer.
 */
class ProposalHistoryDescriber implements HistoryLogDescriberInterface
{
    /** @var array id => CmsProposal|false of one request */
    private $proposals = [];

    public function describe(CmsLog $log, CrmReadContext $context): ?array
    {
        $data = (array)$log->data;
        $event = (string)ArrayHelper::getValue($data, 'event', ProposalFeed::EVENT_CREATED);
        $id = (int)ArrayHelper::getValue($data, 'id');
        if (!array_key_exists($id, $this->proposals)) {
            $this->proposals[$id] = CmsProposal::find()->forManager()->andWhere([CmsProposal::tableName().'.id' => $id])->with('currentVersion')->one() ?: false;
        }
        $p = $this->proposals[$id];
        $kind = $p ? (string)$p->kind : (string)(CmsProposal::find()->select('kind')->andWhere(['id' => $id])->scalar() ?: CmsProposal::KIND_PROPOSAL);
        $title = ProposalFeed::eventNames($kind)[$event] ?? ($kind === CmsProposal::KIND_PRESENTATION ? 'Презентация' : 'КП');
        $channel = (string)ArrayHelper::getValue($data, 'channel', '');
        if ($event === ProposalFeed::EVENT_VERSION && ($number = (int)ArrayHelper::getValue($data, 'version'))) {
            $title .= ': версия '.$number;
        }
        $result = [
            'subtype' => $event,
            'title' => $title,
            'dedup_key' => 'proposal:'.$id.':'.$event.':'.(int)$log->created_at,
        ];
        if ($channel !== '') {
            $result['channel'] = $channel === CmsProposalSend::CHANNEL_SMS ? 'sms' : $channel;
        }
        if (in_array($event, [ProposalFeed::EVENT_OPENED, ProposalFeed::EVENT_ACCEPTED], true)) {
            $result['direction'] = 'in';
            $result['actor'] = ['kind' => CrmActor::CLIENT];
        } elseif ($event === ProposalFeed::EVENT_SENT) {
            $result['direction'] = 'out';
        } elseif ($event === ProposalFeed::EVENT_CHECKED) {
            $result['actor'] = ['kind' => CrmActor::SYSTEM];
        }
        if (!$p) {
            $result['summary'] = CmsProposal::find()->andWhere(['id' => $id])->exists() ? 'Предложение, к которому у сотрудника нет доступа.' : 'Предложение удалено.';
            $result['access'] = false;
            return $result;
        }
        $parts = [(string)$p->asText()];
        $version = $p->isPresentation ? null : $p->currentVersion;
        if ($version) {
            $parts[] = ProposalItems::money($version->amount, (string)$version->currency_code);
        }
        if ($p->isPresentation && $p->target_url) {
            $parts[] = (string)$p->target_url;
        }
        $parts[] = 'статус: '.$p->displayStatusName;
        if ($event === ProposalFeed::EVENT_CHECKED) {
            $parts[] = 'ссылку, похоже, открыла почтовая служба, это не открытие клиентом';
        }
        $result['summary'] = $context->text(implode(', ', $parts));
        $result['details'] = array_filter([
            'cms_proposal_id' => (int)$p->id,
            'kind' => $kind,
            'status' => $p->displayStatus,
            'cms_proposal_send_id' => ArrayHelper::getValue($data, 'send_id') ? (int)ArrayHelper::getValue($data, 'send_id') : null,
        ], static function ($v) { return $v !== null; });
        $result['ref'] = ['tool' => 'cms_proposal_get', 'id' => (int)$p->id];
        return $result;
    }
}
