<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\proposal\models;

use skeeks\cms\models\CmsTask;

/**
 * A task of the responsible employee made by the rule "задача при первом
 * открытии" (services\ProposalTasks): which proposal and which sending the
 * client opened. Core tasks know nothing about proposals; this row links
 * them, finds an open task before another one is made and shows
 * "Предложение: …" at the top of the task card (ProposalClientSource::origins()).
 *
 * @property int                  $id
 * @property int                  $created_at
 * @property int                  $cms_proposal_id
 * @property int|null             $cms_proposal_send_id
 * @property int                  $cms_task_id
 *
 * @property CmsProposal          $proposal
 * @property CmsProposalSend|null $send
 * @property CmsTask              $task
 */
class CmsProposalTask extends \yii\db\ActiveRecord
{
    public static function tableName()
    {
        return '{{%cms_proposal_task}}';
    }

    public function rules()
    {
        return [
            [['cms_proposal_id', 'cms_task_id'], 'required'],
            [['cms_proposal_id', 'cms_proposal_send_id', 'cms_task_id', 'created_at'], 'integer'],
            [['created_at'], 'default', 'value' => static fn() => time()],
        ];
    }

    public function getProposal()
    {
        return $this->hasOne(CmsProposal::class, ['id' => 'cms_proposal_id']);
    }

    public function getSend()
    {
        return $this->hasOne(CmsProposalSend::class, ['id' => 'cms_proposal_send_id']);
    }

    public function getTask()
    {
        return $this->hasOne(CmsTask::class, ['id' => 'cms_task_id']);
    }
}
