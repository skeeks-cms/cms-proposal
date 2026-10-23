<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\proposal\models;

/**
 * A question the client asked on the page of a proposal ("Задать вопрос"):
 * the text, how to reach the client and the sending link it came from. The
 * same text is an incoming message in the CRM conversations of the client
 * (skeeks/cms-communication, channel "site").
 *
 * @property int                     $id
 * @property int                     $created_at
 * @property int                     $cms_proposal_id
 * @property int                     $cms_proposal_send_id
 * @property int|null                $cms_proposal_version_id
 * @property string|null             $name
 * @property string|null             $contact
 * @property string                  $text
 * @property int|null                $cms_comm_message_id
 * @property string|null             $ip
 * @property string|null             $user_agent
 *
 * @property CmsProposal             $proposal
 * @property CmsProposalSend         $send
 * @property CmsProposalVersion|null $version
 */
class CmsProposalQuestion extends \yii\db\ActiveRecord
{
    public const MAX_TEXT = 2000;

    public static function tableName()
    {
        return '{{%cms_proposal_question}}';
    }

    public function rules()
    {
        return [
            [['cms_proposal_id', 'cms_proposal_send_id', 'text'], 'required'],
            [['cms_proposal_id', 'cms_proposal_send_id', 'cms_proposal_version_id', 'cms_comm_message_id', 'created_at'], 'integer'],
            [['created_at'], 'default', 'value' => static fn() => time()],
            [['text'], 'string', 'max' => self::MAX_TEXT],
            [['name', 'contact'], 'string', 'max' => 255],
            [['ip'], 'string', 'max' => 45],
            [['user_agent'], 'string', 'max' => 500],
        ];
    }

    public function attributeLabels()
    {
        return [
            'created_at' => 'Когда',
            'name'       => 'Имя',
            'contact'    => 'Как связаться',
            'text'       => 'Вопрос',
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

    public function getVersion()
    {
        return $this->hasOne(CmsProposalVersion::class, ['id' => 'cms_proposal_version_id']);
    }
}
