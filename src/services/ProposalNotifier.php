<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\proposal\services;

use skeeks\cms\models\CmsWebNotify;
use skeeks\cms\proposal\models\CmsProposal;

/**
 * Notifications of the responsible employee about what the client did on
 * the page of a proposal (opened, accepted, asked), in the header
 * notification center (CmsWebNotify). The notification refers to the
 * proposal (model_code + model_id, `Skeeks::$modelsConfig` registered in
 * ProposalBootstrap): the center links it when it is shown.
 */
class ProposalNotifier
{
    /** The employee who hears about the client: the responsible one, otherwise the author. */
    public static function recipientId(CmsProposal $proposal): ?int
    {
        $id = (int)($proposal->responsible_id ?: $proposal->created_by);

        return $id ?: null;
    }

    /**
     * @param string $kind proposal.opened, proposal.accepted, proposal.question
     */
    public static function notify(CmsProposal $proposal, string $name, ?string $comment = null, string $kind = 'proposal.opened'): ?CmsWebNotify
    {
        $userId = self::recipientId($proposal);
        if (!$userId) {
            return null;
        }
        $rows = \Yii::$app->notifications->notify($userId, $kind, [
            'name' => $name,
            'comment' => $comment,
            'model' => $proposal,
        ]);

        return $rows[0] ?? null;
    }
}
