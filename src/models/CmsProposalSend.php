<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\proposal\models;

use skeeks\cms\models\CmsCompany;
use skeeks\cms\models\CmsDeal;
use skeeks\cms\models\CmsLead;
use skeeks\cms\models\CmsUser;

/**
 * One sending of a proposal version (a presentation has no versions) to a
 * client by a channel (letter, SMS,
 * later messengers). Every sending has its own secret link code, so a view
 * of the client page tells which sending and channel it came from. The
 * sending itself goes through the common "Отправить клиенту" of
 * skeeks/cms-communication (stage C); this record only keeps the result.
 *
 * The sending has its own client (lead, company, deal or client person):
 * the proposal's client, or for a presentation for everybody the CRM record
 * it was sent to. The feed, the notification and the task of an opening go
 * to this client.
 *
 * @property int                $id
 * @property int|null           $cms_lead_id
 * @property int|null           $cms_company_id
 * @property int|null           $cms_deal_id
 * @property int|null           $cms_user_id the client person
 * @property int|null           $created_by
 * @property int|null           $created_at
 * @property int                $cms_proposal_id
 * @property int|null           $cms_proposal_version_id null for a presentation
 * @property string             $channel
 * @property string|null        $recipient
 * @property string|null        $recipient_name
 * @property int|null           $cms_comm_message_id
 * @property string             $code
 * @property int|null           $first_viewed_at
 * @property int|null           $last_viewed_at
 * @property int                $views_count
 *
 * @property CmsProposal        $proposal
 * @property CmsProposalVersion|null $version
 * @property CmsProposalView[]  $views
 * @property CmsProposalQuestion[] $questions
 * @property string             $url the personal page of this sending
 * @property CmsUser|null       $createdBy
 * @property \yii\db\ActiveRecord|null $client
 */
class CmsProposalSend extends \skeeks\cms\base\ActiveRecord
{
    public const CHANNEL_EMAIL = 'email';
    public const CHANNEL_SMS = 'sms';

    public static function tableName()
    {
        return '{{%cms_proposal_send}}';
    }

    public static function channels(): array
    {
        return [
            self::CHANNEL_EMAIL => 'Письмо',
            self::CHANNEL_SMS   => 'СМС',
            'telegram'          => 'Telegram',
            'max'               => 'MAX',
        ];
    }

    public function rules()
    {
        return [
            [['cms_proposal_id', 'channel'], 'required'],
            [['cms_proposal_version_id'], 'default', 'value' => null],
            [['cms_proposal_id', 'cms_proposal_version_id', 'cms_comm_message_id', 'first_viewed_at', 'last_viewed_at', 'views_count',
                'cms_lead_id', 'cms_company_id', 'cms_deal_id', 'cms_user_id'], 'integer'],
            [['cms_lead_id', 'cms_company_id', 'cms_deal_id', 'cms_user_id'], 'default', 'value' => null],
            [['channel'], 'string', 'max' => 32],
            [['recipient', 'recipient_name'], 'string', 'max' => 255],
            [['code'], 'default', 'value' => static fn() => self::generateCode()],
            [['code'], 'string', 'max' => 64],
            [['code'], 'unique'],
            [['views_count'], 'default', 'value' => 0],
        ];
    }

    /** A link code nobody can guess: 32 url-safe characters from 24 random bytes. */
    public static function generateCode(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    }

    public function attributeLabels()
    {
        return [
            'created_by'      => 'Отправил',
            'created_at'      => 'Отправлено',
            'channel'         => 'Канал',
            'recipient'       => 'Получатель',
            'first_viewed_at' => 'Первый просмотр',
            'last_viewed_at'  => 'Последний просмотр',
            'views_count'     => 'Просмотров',
        ];
    }

    public function getChannelName(): string
    {
        return self::channels()[$this->channel] ?? (string)$this->channel;
    }

    public function getProposal()
    {
        return $this->hasOne(CmsProposal::class, ['id' => 'cms_proposal_id']);
    }

    public function getVersion()
    {
        return $this->hasOne(CmsProposalVersion::class, ['id' => 'cms_proposal_version_id']);
    }

    public function getViews()
    {
        return $this->hasMany(CmsProposalView::class, ['cms_proposal_send_id' => 'id'])->orderBy(['created_at' => SORT_DESC]);
    }

    public function getQuestions()
    {
        return $this->hasMany(CmsProposalQuestion::class, ['cms_proposal_send_id' => 'id'])->orderBy(['created_at' => SORT_DESC]);
    }

    /** The recipient as people read it: a phone formatted, the name added. */
    public function getRecipientText(): string
    {
        $recipient = (string)$this->recipient;
        if (strlen($recipient) === 11 && ctype_digit($recipient) && $recipient[0] === '7') {
            $recipient = '+7 '.substr($recipient, 1, 3).' '.substr($recipient, 4, 3).'-'.substr($recipient, 7, 2).'-'.substr($recipient, 9, 2);
        }
        $name = trim((string)$this->recipient_name);

        // A name that already holds the address (a lead named after a letter) adds nothing.
        $repeats = $name === '' || ($this->recipient && mb_stripos($name, (string)$this->recipient) !== false) || mb_stripos($name, $recipient) !== false;

        return $repeats ? $recipient : trim($recipient.' ('.$name.')');
    }

    /**
     * The personal page of this sending as an absolute URL: the host of the
     * web request, otherwise the address of the site (API, bots, jobs).
     */
    public function getUrl(): string
    {
        $path = \Yii::$app->urlManager->createUrl(['/cmsProposal/proposal/view', 'code' => (string)$this->code]);
        if (\Yii::$app->request instanceof \yii\web\Request) {
            return \Yii::$app->request->hostInfo.$path;
        }
        $site = $this->proposal ? $this->proposal->cmsSite : null;

        return rtrim($site ? (string)$site->url : '', '/').$path;
    }

    public function getLead()
    {
        return $this->hasOne(CmsLead::class, ['id' => 'cms_lead_id']);
    }

    public function getCompany()
    {
        return $this->hasOne(CmsCompany::class, ['id' => 'cms_company_id']);
    }

    public function getDeal()
    {
        return $this->hasOne(CmsDeal::class, ['id' => 'cms_deal_id']);
    }

    public function getCmsUser()
    {
        return $this->hasOne(CmsUser::class, ['id' => 'cms_user_id']);
    }

    /** The client of this sending: the lead, the deal, the company or the client person. */
    public function getClient()
    {
        return $this->lead ?: ($this->deal ?: ($this->company ?: $this->cmsUser));
    }

    /** The company of the client: the company itself, of the lead or of the deal. */
    public function getClientCompany(): ?CmsCompany
    {
        if ($this->company) {
            return $this->company;
        }
        $client = $this->lead ?: $this->deal;

        return $client && $client->cms_company_id ? $client->company : null;
    }

    public function getClientName(): string
    {
        $client = $this->client;
        if (!$client) {
            return '';
        }
        if ($client instanceof CmsUser) {
            return (string)($client->shortDisplayName ?: $client->asText());
        }

        return $client instanceof CmsDeal ? (string)$client->asShortText : (string)$client->asText;
    }

    /** Takes the client from a CRM record: a lead, a company, a deal or a client person (not an employee). */
    public function setClient(?\yii\db\ActiveRecord $record): bool
    {
        $this->cms_lead_id = $record instanceof CmsLead ? (int)$record->id : null;
        $this->cms_company_id = $record instanceof CmsCompany ? (int)$record->id : null;
        $this->cms_deal_id = $record instanceof CmsDeal ? (int)$record->id : null;
        $this->cms_user_id = $record instanceof CmsUser && !$record->is_worker ? (int)$record->id : null;

        return (bool)($this->cms_lead_id || $this->cms_company_id || $this->cms_deal_id || $this->cms_user_id);
    }

    public function getCreatedBy()
    {
        return $this->hasOne(CmsUser::class, ['id' => 'created_by']);
    }
}
