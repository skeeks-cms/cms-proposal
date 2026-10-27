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
use skeeks\cms\models\CmsSite;
use skeeks\cms\models\CmsUser;
use skeeks\cms\proposal\models\queries\CmsProposalQuery;
use skeeks\cms\proposal\services\ProposalFeed;
use skeeks\cms\proposal\services\ProposalVersions;
use skeeks\cms\behaviors\RichTextBehavior;
use skeeks\cms\helpers\RichTextHtml;
use yii\helpers\ArrayHelper;

/**
 * A proposal of one of two kinds (one registry "Предложения"):
 * - a commercial proposal (KIND_PROPOSAL): the content (texts, items,
 *   totals, requisites) lives in versions; a new proposal gets its first
 *   version from the chosen template (ProposalVersions::createFirst()) in
 *   the same transaction;
 * - a presentation (KIND_PRESENTATION): a page of a site (target_url) and a
 *   short text of the manager; no versions, items or "Принять". The
 *   personal link counts the view and leads the client to the page.
 * Both have a client (a lead, a company, a deal or a client person), the
 * responsible employee, the status and, if wanted, a validity date.
 *
 * @property int                       $id
 * @property int|null                  $created_by
 * @property int|null                  $updated_by
 * @property int|null                  $created_at
 * @property int|null                  $updated_at
 * @property int                       $cms_site_id
 * @property string                    $kind KIND_PROPOSAL or KIND_PRESENTATION
 * @property string|null               $target_url the page of a presentation, absolute
 * @property string|null               $message the manager's text of a presentation (HTML, preset MESSAGE_PRESET)
 * @property int                       $number
 * @property string                    $name
 * @property int|null                  $cms_lead_id
 * @property int|null                  $cms_company_id
 * @property int|null                  $cms_deal_id
 * @property int|null                  $cms_user_id the client person
 * @property int|null                  $responsible_id
 * @property string                    $status
 * @property int|null                  $status_at
 * @property int|null                  $valid_until
 * @property string                    $currency_code
 * @property int|null                  $current_version_id
 * @property int|null                  $cms_proposal_template_id
 * @property int|null                  $accepted_at
 * @property int|null                  $accepted_version_id
 * @property int|null                  $accepted_send_id
 *
 * @property CmsSite                   $cmsSite
 * @property CmsLead|null              $lead
 * @property CmsCompany|null           $company
 * @property CmsDeal|null              $deal
 * @property CmsUser|null              $cmsUser the client person
 * @property CmsUser|null              $responsible
 * @property CmsUser|null              $createdBy
 * @property CmsProposalVersion|null   $currentVersion
 * @property CmsProposalVersion[]      $versions
 * @property CmsProposalSend[]         $sends
 * @property CmsProposalTemplate|null  $template
 * @property CmsProposalVersion|null   $acceptedVersion
 * @property CmsProposalSend|null      $acceptedSend
 * @property CmsProposalQuestion[]     $questions
 * @property CmsProposalVersion|null   $latestSentVersion
 * @property \yii\db\ActiveRecord|null $client
 * @property bool                      $isExpired
 * @property bool                      $isPresentation
 * @property bool                      $isShared a presentation for everybody: the client is that of each sending
 * @property string                    $kindName "КП", "Презентация"
 * @property string                    $numberText "КП №7", "Презентация №9"
 */
class CmsProposal extends \skeeks\cms\base\ActiveRecord
{
    /** Activity feed entries of leads, companies and deals (Skeeks::$logRenderers). */
    public const LOG_TYPE = 'proposal';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SENT = 'sent';
    public const STATUS_OPENED = 'opened';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_EXPIRED = 'expired';

    /** Statuses that still wait for the client: they expire with the validity date. */
    public const WAITING_STATUSES = [self::STATUS_DRAFT, self::STATUS_SENT, self::STATUS_OPENED];

    public const DEFAULT_VALID_DAYS = 14;

    public const KIND_PROPOSAL = 'proposal';
    public const KIND_PRESENTATION = 'presentation';

    public static function tableName()
    {
        return '{{%cms_proposal}}';
    }

    public static function find()
    {
        return new CmsProposalQuery(static::class);
    }

    /** The manager's text of a presentation: formatting of a letter, no headings or pictures. */
    public const MESSAGE_PRESET = 'basic';

    public function behaviors()
    {
        return ArrayHelper::merge(parent::behaviors(), [
            RichTextBehavior::class => [
                'class' => RichTextBehavior::class,
                'attributes' => ['message' => self::MESSAGE_PRESET],
            ],
        ]);
    }

    /** The manager's text as HTML: texts saved before the editor were plain lines. */
    public function getMessageHtml(): string
    {
        $message = trim((string)$this->message);
        if ($message === '') {
            return '';
        }

        if ($message !== strip_tags($message)) {
            return $message;
        }
        // Every line a paragraph, as the editor writes them.
        $lines = array_filter(array_map('trim', preg_split('/\R/u', $message)), static fn($line) => $line !== '');

        return implode('', array_map(static fn($line) => '<p>'.htmlspecialchars($line, ENT_QUOTES, 'UTF-8').'</p>', $lines));
    }

    /** The manager's text without tags: SMS and messengers. */
    public function getMessageText(): string
    {
        $html = $this->getMessageHtml();

        return $html === '' ? '' : trim(RichTextHtml::toText($html));
    }

    /** Insert and the first version are one transaction. */
    public function transactions()
    {
        return [self::SCENARIO_DEFAULT => self::OP_INSERT];
    }

    public static function kinds(): array
    {
        return [
            self::KIND_PROPOSAL     => 'КП',
            self::KIND_PRESENTATION => 'Презентация',
        ];
    }

    /** What the kind is for, in the create window. */
    public static function kindHints(): array
    {
        return [
            self::KIND_PROPOSAL     => 'Коммерческое предложение: позиции, итог и PDF, клиент может принять его.',
            self::KIND_PRESENTATION => 'Страница сайта (решение, кейсы, сфера бизнеса): по личной ссылке видно, когда клиент её открыл.',
        ];
    }

    /** Statuses of a kind: a presentation is never accepted or rejected. */
    public static function kindStatuses(string $kind): array
    {
        $statuses = self::statuses();
        if ($kind === self::KIND_PRESENTATION) {
            unset($statuses[self::STATUS_ACCEPTED], $statuses[self::STATUS_REJECTED]);
        }

        return $statuses;
    }

    public static function statuses(): array
    {
        return [
            self::STATUS_DRAFT    => 'Черновик',
            self::STATUS_SENT     => 'Отправлено',
            self::STATUS_OPENED   => 'Открыто',
            self::STATUS_ACCEPTED => 'Принято',
            self::STATUS_REJECTED => 'Отклонено',
            self::STATUS_EXPIRED  => 'Истекло',
        ];
    }

    /** Status names for the statuses an employee sets by hand (others are set by sending and the client page). */
    public static function manualStatuses(): array
    {
        return array_intersect_key(self::statuses(), array_flip([self::STATUS_DRAFT, self::STATUS_ACCEPTED, self::STATUS_REJECTED]));
    }

    public function rules()
    {
        return [
            [['kind'], 'default', 'value' => self::KIND_PROPOSAL],
            [['kind'], 'in', 'range' => array_keys(self::kinds())],
            [['kind'], 'validateKind'],
            [['name', 'target_url'], 'trim'],
            [['name'], 'required'],
            [['target_url', 'message'], 'default', 'value' => null],
            [['target_url'], 'required', 'when' => static fn(self $model) => $model->kind === self::KIND_PRESENTATION],
            [['target_url'], 'validateTargetUrl'],
            [['message'], 'string', 'max' => 20000],
            [['name'], 'string', 'max' => 255],
            [['cms_site_id'], 'default', 'value' => static fn() => \Yii::$app->skeeks->site ? (int)\Yii::$app->skeeks->site->id : null],
            [['cms_site_id'], 'required'],
            [['cms_site_id', 'number', 'cms_lead_id', 'cms_company_id', 'cms_deal_id', 'cms_user_id', 'responsible_id', 'status_at', 'valid_until', 'current_version_id', 'cms_proposal_template_id', 'accepted_at', 'accepted_version_id', 'accepted_send_id'], 'integer'],
            [['cms_lead_id', 'cms_company_id', 'cms_deal_id', 'cms_user_id', 'responsible_id', 'cms_proposal_template_id', 'valid_until'], 'default', 'value' => null],
            [['status'], 'default', 'value' => self::STATUS_DRAFT],
            [['status'], 'in', 'range' => array_keys(self::statuses())],
            [['currency_code'], 'default', 'value' => static fn() => \Yii::$app->has('money') ? (string)\Yii::$app->money->currencyCode : 'RUB'],
            [['currency_code'], 'string', 'max' => 3],
            // A presentation has no date unless the employee sets one.
            [['valid_until'], 'default', 'value' => static fn($model, $attribute) => $model->kind === self::KIND_PRESENTATION
                ? null
                : strtotime('today +'.self::DEFAULT_VALID_DAYS.' days 23:59:59')],
            [['responsible_id'], 'default', 'value' => static function () {
                return \Yii::$app->has('user') && !(\Yii::$app instanceof \yii\console\Application) && !\Yii::$app->user->isGuest ? (int)\Yii::$app->user->id : null;
            }],
            [['cms_lead_id'], 'validateClient', 'skipOnEmpty' => false],
            [['cms_lead_id'], 'exist', 'skipOnEmpty' => true, 'targetClass' => CmsLead::class, 'targetAttribute' => ['cms_lead_id' => 'id']],
            [['cms_company_id'], 'exist', 'skipOnEmpty' => true, 'targetClass' => CmsCompany::class, 'targetAttribute' => ['cms_company_id' => 'id']],
            [['cms_deal_id'], 'exist', 'skipOnEmpty' => true, 'targetClass' => CmsDeal::class, 'targetAttribute' => ['cms_deal_id' => 'id']],
            [['cms_user_id'], 'exist', 'skipOnEmpty' => true, 'targetClass' => CmsUser::class, 'targetAttribute' => ['cms_user_id' => 'id']],
            [['responsible_id'], 'exist', 'skipOnEmpty' => true, 'targetClass' => CmsUser::class, 'targetAttribute' => ['responsible_id' => 'id']],
            [['cms_proposal_template_id'], 'exist', 'skipOnEmpty' => true, 'targetClass' => CmsProposalTemplate::class,
                'targetAttribute' => ['cms_proposal_template_id' => 'id', 'cms_site_id' => 'cms_site_id']],
        ];
    }

    /** The kind is chosen once: versions and sendings belong to it. */
    public function validateKind($attribute)
    {
        if (!$this->isNewRecord && $this->isAttributeChanged($attribute)) {
            $this->addError($attribute, 'Вид предложения нельзя изменить после создания.');
        }
    }

    /**
     * The page of a presentation: http(s) with a host; a path of the site
     * ("/works/sfery/plitka") becomes an address of the proposal's site. The
     * client page takes the address only from here, never from the link, so
     * it cannot become an open redirect. Any domain is allowed: it is saved
     * by an employee.
     */
    public function validateTargetUrl($attribute)
    {
        $url = trim((string)$this->$attribute);
        if ($url === '') {
            return;
        }
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            // The site default of cms_site_id is applied by a later rule: fall back to the current site.
            $site = $this->cms_site_id ? CmsSite::findOne((int)$this->cms_site_id) : (\Yii::$app->skeeks->site ?? null);
            $base = $site ? rtrim((string)$site->url, '/') : '';
            if ($base === '') {
                $this->addError($attribute, 'Укажите полный адрес страницы, начиная с https://');
                return;
            }
            $url = $base.$url;
        }
        $parts = parse_url($url);
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        if (!$parts || !in_array($scheme, ['http', 'https'], true) || empty($parts['host']) || isset($parts['user']) || preg_match('/\s/u', $url)) {
            $this->addError($attribute, 'Укажите адрес страницы, например https://skeeks.com/works/sfery/plitka');
            return;
        }
        if (mb_strlen($url) > 1000) {
            $this->addError($attribute, 'Адрес страницы слишком длинный.');
            return;
        }
        $this->$attribute = $url;
    }

    /**
     * One client: a lead, a company, a deal or a client person. A
     * presentation may have none: one for everybody, the client is that of
     * each sending (CmsProposalSend::$client).
     */
    public function validateClient($attribute)
    {
        $filled = array_filter([$this->cms_lead_id, $this->cms_company_id, $this->cms_deal_id, $this->cms_user_id]);
        if (!$filled && $this->kind === self::KIND_PRESENTATION) {
            return;
        }
        if (!$filled) {
            $this->addError($attribute, 'Выберите клиента: сделку, компанию, услугу или клиента-человека.');
        } elseif (count($filled) > 1) {
            $this->addError($attribute, 'Выберите одного клиента: сделку, компанию, услугу или клиента-человека.');
        }
    }

    public function attributeLabels()
    {
        return [
            'id'                       => 'ID',
            'created_by'               => 'Создал',
            'created_at'               => 'Создано',
            'updated_at'               => 'Изменено',
            'cms_site_id'              => 'Сайт',
            'kind'                     => 'Вид',
            'target_url'               => 'Адрес страницы',
            'message'                  => 'Текст для клиента',
            'number'                   => 'Номер',
            'name'                     => 'Название',
            'cms_lead_id'              => 'Сделка',
            'cms_company_id'           => 'Компания',
            'cms_deal_id'              => 'Услуга',
            'cms_user_id'              => 'Клиент',
            'responsible_id'           => 'Ответственный',
            'status'                   => 'Статус',
            'status_at'                => 'Статус изменён',
            'valid_until'              => 'Действует до',
            'currency_code'            => 'Валюта',
            'current_version_id'       => 'Текущая версия',
            'cms_proposal_template_id' => 'Шаблон',
            'accepted_at'              => 'Принято клиентом',
        ];
    }

    public function attributeHints()
    {
        return [
            'valid_until'              => $this->kind === self::KIND_PRESENTATION
                ? 'Необязательно. После этой даты ссылка не откроет страницу.'
                : 'После этой даты клиент увидит, что предложение истекло.',
            'target_url'               => 'Страница вашего сайта (например /works/sfery/plitka) или полный адрес. Клиент перейдёт на неё по личной ссылке, к адресу добавятся метки для Метрики.',
            'message'                  => 'Пара строк в письме или СМС перед кнопкой «Посмотреть». Можно поправить при отправке.',
            'cms_proposal_template_id' => 'Текст, позиции и срок действия возьмутся из шаблона. Их можно будет изменить.',
        ];
    }

    /** A new proposal takes the validity and the name from its template when they are not filled. */
    public function beforeValidate()
    {
        if ($this->isNewRecord && $this->cms_proposal_template_id && ($template = CmsProposalTemplate::findOne((int)$this->cms_proposal_template_id))) {
            if (!$this->valid_until && $template->valid_days) {
                $this->valid_until = strtotime('today +'.(int)$template->valid_days.' days 23:59:59');
            }
            if (trim((string)$this->name) === '' && $template->title) {
                $this->name = (string)$template->title;
            }
        }
        if ($this->isNewRecord && trim((string)$this->name) === '' && ($client = $this->getClientName()) !== '') {
            $this->name = $client;
        }

        return parent::beforeValidate();
    }

    public function beforeSave($insert)
    {
        if (!parent::beforeSave($insert)) {
            return false;
        }
        if ($insert && !$this->number) {
            $this->number = (int)static::find()->andWhere(['cms_site_id' => (int)$this->cms_site_id])->max('number') + 1;
        }
        if ($insert || $this->isAttributeChanged('status')) {
            $this->status_at = time();
        }

        return true;
    }

    public function afterSave($insert, $changedAttributes)
    {
        parent::afterSave($insert, $changedAttributes);

        if ($insert) {
            if ($this->kind !== self::KIND_PRESENTATION) {
                (new ProposalVersions())->createFirst($this);
            }
            ProposalFeed::write($this, ProposalFeed::EVENT_CREATED);
        }
    }

    public function getCmsSite()
    {
        return $this->hasOne(CmsSite::class, ['id' => 'cms_site_id']);
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

    /** The client person (cms_user, not an employee). */
    public function getCmsUser()
    {
        return $this->hasOne(CmsUser::class, ['id' => 'cms_user_id']);
    }

    public function getResponsible()
    {
        return $this->hasOne(CmsUser::class, ['id' => 'responsible_id']);
    }

    public function getCreatedBy()
    {
        return $this->hasOne(CmsUser::class, ['id' => 'created_by']);
    }

    public function getCurrentVersion()
    {
        return $this->hasOne(CmsProposalVersion::class, ['id' => 'current_version_id']);
    }

    public function getVersions()
    {
        return $this->hasMany(CmsProposalVersion::class, ['cms_proposal_id' => 'id'])->orderBy(['version' => SORT_DESC]);
    }

    public function getSends()
    {
        return $this->hasMany(CmsProposalSend::class, ['cms_proposal_id' => 'id'])->orderBy(['created_at' => SORT_DESC, 'id' => SORT_DESC]);
    }

    public function getAcceptedVersion()
    {
        return $this->hasOne(CmsProposalVersion::class, ['id' => 'accepted_version_id']);
    }

    public function getAcceptedSend()
    {
        return $this->hasOne(CmsProposalSend::class, ['id' => 'accepted_send_id']);
    }

    public function getQuestions()
    {
        return $this->hasMany(CmsProposalQuestion::class, ['cms_proposal_id' => 'id'])->orderBy(['created_at' => SORT_DESC, 'id' => SORT_DESC]);
    }

    /** Tasks made by the rule "задача при первом открытии", newest first. */
    public function getTaskLinks()
    {
        return $this->hasMany(CmsProposalTask::class, ['cms_proposal_id' => 'id'])->orderBy(['id' => SORT_DESC]);
    }

    /** The newest version the client has been sent. */
    public function getLatestSentVersion()
    {
        return $this->hasOne(CmsProposalVersion::class, ['cms_proposal_id' => 'id'])
            ->andWhere(['not', ['sent_at' => null]])
            ->orderBy(['version' => SORT_DESC]);
    }

    public function getTemplate()
    {
        return $this->hasOne(CmsProposalTemplate::class, ['id' => 'cms_proposal_template_id']);
    }

    /** The client record: the lead, the company or the deal. */
    public function getClient()
    {
        return $this->lead ?: ($this->deal ?: ($this->company ?: $this->cmsUser));
    }

    /** The company of the client: the company itself, the company of the lead or of the deal. */
    public function getClientCompany(): ?CmsCompany
    {
        if ($this->company) {
            return $this->company;
        }
        if ($this->lead && $this->lead->cms_company_id) {
            return $this->lead->company;
        }
        if ($this->deal && $this->deal->cms_company_id) {
            return $this->deal->company;
        }

        return null;
    }

    /** "Лид", "Компания", "Сделка". */
    public function getClientTypeName(): string
    {
        if ($this->isShared) {
            return 'Клиент';
        }
        if ($this->cms_lead_id) {
            return 'Сделка';
        }
        if ($this->cms_user_id) {
            return 'Клиент';
        }

        return $this->cms_deal_id ? 'Услуга' : 'Компания';
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

    /** Waiting for the client while the validity date has passed. */
    public function getIsExpired(): bool
    {
        return $this->status === self::STATUS_EXPIRED
            || (in_array($this->status, self::WAITING_STATUSES, true) && $this->valid_until && (int)$this->valid_until < time());
    }

    /** The status an employee sees: a waiting proposal past its date is "Истекло". */
    public function getDisplayStatus(): string
    {
        return $this->isExpired ? self::STATUS_EXPIRED : (string)$this->status;
    }

    public function getDisplayStatusName(): string
    {
        return (string)ArrayHelper::getValue(self::statuses(), $this->displayStatus, $this->displayStatus);
    }

    public function getIsPresentation(): bool
    {
        return $this->kind === self::KIND_PRESENTATION;
    }

    /** A presentation for everybody: no client of its own, each sending has one. */
    public function getIsShared(): bool
    {
        return $this->kind === self::KIND_PRESENTATION
            && !$this->cms_lead_id && !$this->cms_company_id && !$this->cms_deal_id && !$this->cms_user_id;
    }

    public function getKindName(): string
    {
        return (string)(self::kinds()[(string)$this->kind] ?? self::kinds()[self::KIND_PROPOSAL]);
    }

    public function getNumberText(): string
    {
        return $this->kindName.' №'.$this->number;
    }

    public function asText()
    {
        return $this->numberText.($this->name ? ' — '.$this->name : '');
    }
}
