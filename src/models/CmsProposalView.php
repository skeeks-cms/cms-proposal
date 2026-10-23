<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\proposal\models;

/**
 * A view of the client page of a proposal by a sending link: time and
 * browser, for the history and to filter out preview robots. Recorded by
 * the client page (stage C) only after the page was shown in a browser.
 *
 * @property int             $id
 * @property int             $created_at
 * @property int             $cms_proposal_send_id
 * @property string|null     $user_agent
 * @property string|null     $ip
 * @property int             $is_bot
 * @property string|null     $check_reason looks like a link check (LinkChecks::REASON_*), not counted
 *
 * @property CmsProposalSend $send
 */
class CmsProposalView extends \yii\db\ActiveRecord
{
    public static function tableName()
    {
        return '{{%cms_proposal_view}}';
    }

    public function rules()
    {
        return [
            [['cms_proposal_send_id'], 'required'],
            [['cms_proposal_send_id', 'created_at'], 'integer'],
            [['created_at'], 'default', 'value' => static fn() => time()],
            [['user_agent'], 'string', 'max' => 500],
            [['ip'], 'string', 'max' => 45],
            [['is_bot'], 'boolean'],
            [['is_bot'], 'default', 'value' => 0],
            [['check_reason'], 'default', 'value' => null],
            [['check_reason'], 'string', 'max' => 32],
        ];
    }

    public function getSend()
    {
        return $this->hasOne(CmsProposalSend::class, ['id' => 'cms_proposal_send_id']);
    }
}
