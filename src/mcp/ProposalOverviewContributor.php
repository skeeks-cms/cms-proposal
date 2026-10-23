<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\proposal\mcp;

use skeeks\cms\mcp\crm\CrmDate;
use skeeks\cms\mcp\crm\CrmReadContext;
use skeeks\cms\mcp\crm\OverviewContributorInterface;
use skeeks\cms\proposal\models\CmsProposal;
use skeeks\cms\proposal\models\CmsProposalSend;

/**
 * Section "proposals" of the client overview: what was already offered —
 * КП and Презентации of the card (CmsProposalQuery::forRecord(), with the
 * shared presentations sent to it), sent, opened, accepted, the newest few.
 */
class ProposalOverviewContributor implements OverviewContributorInterface
{
    public $lastCount = 5;

    public function contribute(array $records, CrmReadContext $context): array
    {
        $service = new ProposalReadService();
        $result = [];
        foreach ($records as $key => $record) {
            $q = CmsProposal::find()->forManager()->forRecord($record->model);
            $proposals = (clone $q)->with(['currentVersion', 'sends'])->orderBy([CmsProposal::tableName().'.id' => SORT_DESC])->limit(50)->all();
            $sent = $opened = $accepted = 0;
            $lastSent = 0;
            foreach ($proposals as $p) {
                $sends = $p->sends;
                $sent += $sends ? 1 : 0;
                $opened += array_filter($sends, static function ($s) { return (bool)$s->first_viewed_at; }) ? 1 : 0;
                $accepted += $p->accepted_at ? 1 : 0;
                foreach ($sends as $s) {
                    $lastSent = max($lastSent, (int)$s->created_at);
                }
            }
            $result[$key] = ['proposals' => [
                'count' => (int)$q->count(),
                'sent_count' => $sent,
                'opened_count' => $opened,
                'accepted_count' => $accepted,
                'last_sent_at' => CrmDate::format($lastSent ?: null),
                'last' => array_map(static function (CmsProposal $p) use ($service, $context) {
                    $d = $service->proposalData($p, $context);
                    return array_intersect_key($d, array_flip(['id', 'kind', 'number_text', 'name', 'status', 'status_text', 'amount_text', 'target_url', 'last_sent_at', 'first_viewed_at', 'accepted_at']));
                }, array_slice($proposals, 0, $this->lastCount)),
            ]];
        }
        return $result;
    }
}
