<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\proposal\models;

use skeeks\cms\behaviors\RichTextBehavior;
use skeeks\cms\models\CmsContractor;
use skeeks\cms\models\CmsUser;
use skeeks\cms\models\CmsStorageFile;

/**
 * The content of a proposal: intro and terms (rich text), items with
 * sections, totals and a copy of our requisites taken when the version was
 * made, like ShopBill::fillContractorSnapshot(). Once sent (`sent_at`), a
 * version never changes: editing a sent proposal makes a new version
 * (ProposalVersions::saveContent()).
 *
 * @property int                    $id
 * @property int|null               $created_by
 * @property int|null               $updated_by
 * @property int|null               $created_at
 * @property int|null               $updated_at
 * @property int                    $cms_proposal_id
 * @property int                    $version
 * @property string|null            $intro
 * @property string|null            $terms
 * @property string                 $currency_code
 * @property string                 $subtotal_amount
 * @property string|null            $discount_value
 * @property string                 $discount_amount
 * @property string                 $amount
 * @property int|null               $our_contractor_id
 * @property string|null            $our_contractor_type
 * @property string|null            $our_contractor_name
 * @property string|null            $our_contractor_full_name
 * @property string|null            $our_contractor_inn
 * @property string|null            $our_contractor_kpp
 * @property string|null            $our_contractor_ogrn
 * @property string|null            $our_contractor_address
 * @property string|null            $our_contractor_mailing_postcode
 * @property int|null               $sent_at
 * @property int|null               $pdf_storage_file_id
 *
 * @property CmsProposal            $proposal
 * @property CmsProposalItem[]      $items
 * @property CmsContractor|null     $ourContractor
 * @property CmsStorageFile|null    $pdfStorageFile
 * @property CmsUser|null           $createdBy
 * @property bool                   $isLocked
 */
class CmsProposalVersion extends \skeeks\cms\base\ActiveRecord
{
    /** The rich-text preset of the intro and terms (toolbar and server cleaning together). */
    public const TEXT_PRESET = 'standard';

    /** Attributes a sent version may still receive: the PDF made at the first sending. */
    private const LOCKED_WRITABLE = ['pdf_storage_file_id', 'updated_at', 'updated_by'];

    public static function tableName()
    {
        return '{{%cms_proposal_version}}';
    }

    public function behaviors()
    {
        return array_merge(parent::behaviors(), [
            RichTextBehavior::class => [
                'class' => RichTextBehavior::class,
                'attributes' => ['intro' => self::TEXT_PRESET, 'terms' => self::TEXT_PRESET],
            ],
        ]);
    }

    public function rules()
    {
        return [
            [['cms_proposal_id', 'version'], 'required'],
            [['cms_proposal_id', 'version', 'our_contractor_id', 'sent_at', 'pdf_storage_file_id'], 'integer'],
            [['intro', 'terms'], 'string'],
            [['subtotal_amount', 'discount_amount', 'amount'], 'number'],
            [['subtotal_amount', 'discount_amount', 'amount'], 'default', 'value' => 0],
            [['discount_value'], 'string', 'max' => 32],
            [['currency_code'], 'string', 'max' => 3],
            [['our_contractor_type', 'our_contractor_inn', 'our_contractor_kpp', 'our_contractor_ogrn', 'our_contractor_mailing_postcode'], 'string', 'max' => 32],
            [['our_contractor_name', 'our_contractor_full_name'], 'string', 'max' => 255],
            [['our_contractor_address'], 'string', 'max' => 1000],
            [['our_contractor_id'], 'exist', 'skipOnEmpty' => true, 'targetClass' => CmsContractor::class, 'targetAttribute' => ['our_contractor_id' => 'id']],
        ];
    }

    public function attributeLabels()
    {
        return [
            'version'           => 'Версия',
            'intro'             => 'Вступление',
            'terms'             => 'Условия',
            'currency_code'     => 'Валюта',
            'subtotal_amount'   => 'Сумма позиций',
            'discount_value'    => 'Скидка на всё',
            'discount_amount'   => 'Скидка',
            'amount'            => 'Итого',
            'our_contractor_id' => 'Наши реквизиты',
            'sent_at'           => 'Отправлена',
            'created_at'        => 'Создана',
        ];
    }

    public function beforeSave($insert)
    {
        if (!parent::beforeSave($insert)) {
            return false;
        }
        if (!$insert && $this->getOldAttribute('sent_at')) {
            $changed = array_diff(array_keys($this->getDirtyAttributes()), self::LOCKED_WRITABLE);
            if ($changed) {
                $this->addError('sent_at', 'Отправленная версия КП не меняется: изменения сохраняются в новой версии.');

                return false;
            }
        }
        if ($insert || $this->isAttributeChanged('our_contractor_id') || ($this->our_contractor_id && !$this->our_contractor_name)) {
            $this->fillContractorSnapshot($this->ourContractor);
        }

        return true;
    }

    public function beforeDelete()
    {
        if ($this->sent_at) {
            return false;
        }

        return parent::beforeDelete();
    }

    /** A copy of our requisites at the moment the version is made, as ShopBill does. */
    public function fillContractorSnapshot(?CmsContractor $contractor = null): void
    {
        $fields = ['type', 'name', 'full_name', 'inn', 'kpp', 'ogrn', 'address', 'mailing_postcode'];
        if (!$contractor) {
            foreach ($fields as $field) {
                $this->{'our_contractor_'.$field} = null;
            }

            return;
        }
        $this->our_contractor_type = $contractor->contractor_type;
        $this->our_contractor_name = $contractor->asShortText;
        $this->our_contractor_full_name = $contractor->contractor_type === CmsContractor::TYPE_LEGAL
            ? ($contractor->full_name ?: $contractor->asShortText)
            : $contractor->asShortText;
        $this->our_contractor_inn = $contractor->inn;
        $this->our_contractor_kpp = $contractor->kpp;
        $this->our_contractor_ogrn = $contractor->ogrn;
        $this->our_contractor_address = $contractor->address;
        $this->our_contractor_mailing_postcode = $contractor->mailing_postcode;
    }

    /** Sent to the client at least once: nothing can be changed. */
    public function getIsLocked(): bool
    {
        return (bool)$this->sent_at;
    }

    /**
     * Marks the version sent (the first sending, stage C). From now on it is
     * read-only; the stored PDF can still be attached.
     */
    public function markSent(?int $time = null): void
    {
        if ($this->sent_at) {
            return;
        }
        $this->sent_at = $time ?: time();
        $this->updateAttributes(['sent_at' => $this->sent_at]);
    }

    public function getProposal()
    {
        return $this->hasOne(CmsProposal::class, ['id' => 'cms_proposal_id']);
    }

    public function getItems()
    {
        return $this->hasMany(CmsProposalItem::class, ['cms_proposal_version_id' => 'id'])->orderBy(['sort' => SORT_ASC, 'id' => SORT_ASC]);
    }

    public function getOurContractor()
    {
        return $this->hasOne(CmsContractor::class, ['id' => 'our_contractor_id']);
    }

    public function getPdfStorageFile()
    {
        return $this->hasOne(CmsStorageFile::class, ['id' => 'pdf_storage_file_id']);
    }

    public function getCreatedBy()
    {
        return $this->hasOne(CmsUser::class, ['id' => 'created_by']);
    }

    /** Our requisites as lines for the PDF and the client page: name, INN/KPP, OGRN, address. */
    public function getOurRequisitesLines(): array
    {
        $lines = [];
        if ($this->our_contractor_full_name || $this->our_contractor_name) {
            $lines[] = (string)($this->our_contractor_full_name ?: $this->our_contractor_name);
        }
        $codes = array_filter([
            $this->our_contractor_inn ? 'ИНН '.$this->our_contractor_inn : '',
            $this->our_contractor_kpp ? 'КПП '.$this->our_contractor_kpp : '',
            $this->our_contractor_ogrn ? ((strlen((string)$this->our_contractor_ogrn) === 15 ? 'ОГРНИП ' : 'ОГРН ').$this->our_contractor_ogrn) : '',
        ]);
        if ($codes) {
            $lines[] = implode(', ', $codes);
        }
        if ($this->our_contractor_address) {
            $postcode = (string)$this->our_contractor_mailing_postcode;
            $address = (string)$this->our_contractor_address;
            $lines[] = $postcode !== '' && !str_contains($address, $postcode) ? $postcode.', '.$address : $address;
        }

        return $lines;
    }

    public function asText()
    {
        return 'Версия '.$this->version;
    }
}
