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

    public static function notify(CmsProposal $proposal, string $name, ?string $comment = null): ?CmsWebNotify
    {
        $userId = self::recipientId($proposal);
        if (!$userId) {
            return null;
        }
        $notify = new CmsWebNotify();
        $notify->cms_user_id = $userId;
        $notify->name = mb_substr($name, 0, 250);
        $notify->comment = $comment !== null ? mb_substr($comment, 0, 1000) : null;
        $notify->model_code = CmsProposal::class;
        $notify->model_id = (int)$proposal->id;

        return $notify->save() ? $notify : null;
    }
}
