<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\proposal\models;

use skeeks\cms\proposal\services\ProposalItems;

/**
 * A line of a proposal version, like a bill line (ShopBillItem): name,
 * unit, quantity, price, discount, amount and a VAT label (VAT is not
 * calculated). A line of type "section" is a heading that groups the lines
 * below it. `shop_product_id` optionally refers to a product or service of
 * skeeks/cms-shop when the package is installed (no foreign key).
 *
 * @property int                $id
 * @property int|null           $created_at
 * @property int|null           $updated_at
 * @property int                $cms_proposal_version_id
 * @property string             $type
 * @property int|null           $shop_product_id
 * @property string             $name
 * @property string|null        $description
 * @property string|null        $measure_name
 * @property string             $quantity
 * @property string             $price
 * @property string|null        $discount_value
 * @property string             $discount_amount
 * @property string             $amount
 * @property string|null        $vat_name
 * @property int                $sort
 *
 * @property CmsProposalVersion $version
 * @property bool               $isSection
 */
class CmsProposalItem extends \skeeks\cms\base\ActiveRecord
{
    public const TYPE_ITEM = 'item';
    public const TYPE_SECTION = 'section';

    public static function tableName()
    {
        return '{{%cms_proposal_item}}';
    }

    public function rules()
    {
        return [
            [['cms_proposal_version_id', 'name'], 'required'],
            [['cms_proposal_version_id', 'shop_product_id', 'sort'], 'integer'],
            [['type'], 'default', 'value' => self::TYPE_ITEM],
            [['type'], 'in', 'range' => [self::TYPE_ITEM, self::TYPE_SECTION]],
            [['name'], 'string', 'max' => 255],
            [['description'], 'string'],
            [['measure_name'], 'string', 'max' => 50],
            [['discount_value', 'vat_name'], 'string', 'max' => 32],
            [['quantity', 'price', 'discount_amount', 'amount'], 'number'],
            [['quantity'], 'default', 'value' => 1],
            [['price', 'discount_amount', 'amount'], 'default', 'value' => 0],
        ];
    }

    public function attributeLabels()
    {
        return [
            'type'            => 'Тип',
            'shop_product_id' => 'Товар или услуга',
            'name'            => 'Наименование',
            'description'     => 'Описание',
            'measure_name'    => 'Ед. изм.',
            'quantity'        => 'Кол-во',
            'price'           => 'Цена',
            'discount_value'  => 'Скидка',
            'discount_amount' => 'Скидка, сумма',
            'amount'          => 'Сумма',
            'vat_name'        => 'НДС',
            'sort'            => 'Порядок',
        ];
    }

    public function beforeSave($insert)
    {
        if (!parent::beforeSave($insert) || $this->versionIsLocked()) {
            return false;
        }
        if ($this->type === self::TYPE_SECTION) {
            $this->quantity = 0;
            $this->price = 0;
            $this->discount_value = null;
            $this->discount_amount = 0;
            $this->amount = 0;
        } else {
            $quantity = (float)$this->quantity ?: 1;
            $base = max($quantity * (float)$this->price, 0);
            $this->quantity = $quantity;
            $this->discount_amount = ProposalItems::discountAmount((string)$this->discount_value, $base, $quantity, (float)$this->discount_amount);
            $this->amount = round($base - (float)$this->discount_amount, 4);
        }

        return true;
    }

    public function beforeDelete()
    {
        return !$this->versionIsLocked() && parent::beforeDelete();
    }

    private function versionIsLocked(): bool
    {
        return (bool)CmsProposalVersion::find()->andWhere(['id' => (int)$this->cms_proposal_version_id])->select('sent_at')->scalar();
    }

    public function getVersion()
    {
        return $this->hasOne(CmsProposalVersion::class, ['id' => 'cms_proposal_version_id']);
    }

    public function getIsSection(): bool
    {
        return $this->type === self::TYPE_SECTION;
    }

    /** The row of the items editor and of templates (ProposalItems::normalize()). */
    public function toRow(): array
    {
        return [
            'type'            => (string)$this->type,
            'shop_product_id' => $this->shop_product_id ? (int)$this->shop_product_id : null,
            'name'            => (string)$this->name,
            'description'     => (string)$this->description,
            'measure_name'    => (string)$this->measure_name,
            'quantity'        => (float)$this->quantity,
            'price'           => (float)$this->price,
            'discount_value'  => (string)$this->discount_value,
            'vat_name'        => (string)$this->vat_name,
        ];
    }
}
