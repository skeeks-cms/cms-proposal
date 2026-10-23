<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\proposal\models;

use skeeks\cms\behaviors\RichTextBehavior;
use skeeks\cms\models\CmsSite;
use skeeks\cms\proposal\services\ProposalItems;
use yii\helpers\Json;

/**
 * A ready set of texts and items for new proposals ("Сайт под ключ", "SEO",
 * "Хостинг"). Items are kept as the JSON rows of the items editor
 * (ProposalItems::normalize()); a proposal created from a template copies
 * them into its first version.
 *
 * @property int         $id
 * @property int|null    $created_by
 * @property int|null    $updated_by
 * @property int|null    $created_at
 * @property int|null    $updated_at
 * @property int         $cms_site_id
 * @property string      $name
 * @property string|null $title
 * @property string|null $intro
 * @property string|null $terms
 * @property string|null $items_data
 * @property string|null $discount_value
 * @property int|null    $valid_days
 * @property int         $is_active
 * @property int         $priority
 *
 * @property CmsSite     $cmsSite
 * @property array       $itemRows
 */
class CmsProposalTemplate extends \skeeks\cms\base\ActiveRecord
{
    public static function tableName()
    {
        return '{{%cms_proposal_template}}';
    }

    public function behaviors()
    {
        return array_merge(parent::behaviors(), [
            RichTextBehavior::class => [
                'class' => RichTextBehavior::class,
                'attributes' => ['intro' => CmsProposalVersion::TEXT_PRESET, 'terms' => CmsProposalVersion::TEXT_PRESET],
            ],
        ]);
    }

    public function rules()
    {
        return [
            [['name', 'title'], 'trim'],
            [['name'], 'required'],
            [['name', 'title'], 'string', 'max' => 255],
            [['cms_site_id'], 'default', 'value' => static fn() => \Yii::$app->skeeks->site ? (int)\Yii::$app->skeeks->site->id : null],
            [['cms_site_id', 'name'], 'required'],
            [['cms_site_id', 'priority'], 'integer'],
            [['valid_days'], 'integer', 'min' => 1, 'max' => 365],
            [['valid_days'], 'default', 'value' => CmsProposal::DEFAULT_VALID_DAYS],
            [['intro', 'terms'], 'string'],
            [['discount_value'], 'string', 'max' => 32],
            [['discount_value'], 'default', 'value' => null],
            [['is_active'], 'boolean'],
            [['is_active'], 'default', 'value' => 1],
            [['priority'], 'default', 'value' => 100],
            [['itemsJson'], 'safe'],
        ];
    }

    public function attributeLabels()
    {
        return [
            'name'           => 'Название шаблона',
            'title'          => 'Название КП',
            'intro'          => 'Вступление',
            'terms'          => 'Условия',
            'itemsJson'      => 'Позиции',
            'discount_value' => 'Скидка на всё',
            'valid_days'     => 'Срок действия, дней',
            'is_active'      => 'Активен',
            'priority'       => 'Порядок',
        ];
    }

    public function attributeHints()
    {
        return [
            'title'          => 'Подставится в название нового КП, например «Разработка сайта». Можно оставить пустым.',
            'discount_value' => 'Сумма или процент, например 5000 или 10%.',
        ];
    }

    public function getCmsSite()
    {
        return $this->hasOne(CmsSite::class, ['id' => 'cms_site_id']);
    }

    /** @return array[] normalized rows of the items editor */
    public function getItemRows(): array
    {
        $rows = $this->items_data ? Json::decode($this->items_data) : [];

        return ProposalItems::normalize(is_array($rows) ? $rows : []);
    }

    /** The items editor posts its rows as JSON. */
    public function getItemsJson(): string
    {
        return Json::encode($this->itemRows);
    }

    public function setItemsJson($value): void
    {
        $rows = is_string($value) && $value !== '' ? Json::decode($value) : (is_array($value) ? $value : []);
        $this->items_data = Json::encode(ProposalItems::normalize(is_array($rows) ? $rows : []));
    }
}
