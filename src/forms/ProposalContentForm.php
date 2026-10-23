<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\proposal\forms;

use skeeks\cms\helpers\RichTextHtml;
use skeeks\cms\models\CmsContractor;
use skeeks\cms\proposal\models\CmsProposal;
use skeeks\cms\proposal\models\CmsProposalVersion;
use skeeks\cms\proposal\services\ProposalItems;
use skeeks\cms\proposal\services\ProposalVersions;
use skeeks\cms\services\TemporaryUploads;
use yii\base\Model;
use yii\helpers\Json;

/**
 * The "Содержимое" window of a proposal: intro, items, discount, terms and
 * our requisites of the current version. Saved by
 * ProposalVersions::saveContent(): a sent version is not changed, the
 * changes go into a new version.
 */
class ProposalContentForm extends Model
{
    public ?CmsProposal $proposal = null;

    public $intro;
    public $terms;
    public $discount_value;
    public $our_contractor_id;
    public $itemsJson = '[]';

    public function init()
    {
        parent::init();
        if ($this->proposal && ($version = $this->proposal->currentVersion)) {
            $content = ProposalVersions::contentOf($version);
            $this->intro = $content['intro'];
            $this->terms = $content['terms'];
            $this->discount_value = $content['discount_value'];
            $this->our_contractor_id = $content['our_contractor_id'];
            $this->itemsJson = Json::encode(ProposalItems::normalize($content['items']));
        }
    }

    public function rules()
    {
        return [
            [['intro', 'terms', 'itemsJson'], 'string'],
            [['discount_value'], 'trim'],
            [['discount_value'], 'string', 'max' => 32],
            [['discount_value'], 'match', 'pattern' => '/^\s*\d[\d\s]*([.,]\d+)?\s*%?\s*$/u', 'message' => 'Укажите сумму или процент, например 5000 или 10%.'],
            [['our_contractor_id'], 'integer'],
            [['our_contractor_id'], 'in', 'range' => array_keys(self::ourContractors()), 'allowArray' => false, 'skipOnEmpty' => true],
            [['itemsJson'], 'validateItems'],
            [['intro', 'terms'], 'validateUploads'],
        ];
    }

    public function validateItems($attribute)
    {
        try {
            $rows = Json::decode((string)$this->$attribute);
        } catch (\Throwable $e) {
            $this->addError($attribute, 'Не удалось прочитать позиции: обновите окно и повторите.');

            return;
        }
        if (!is_array($rows)) {
            $this->addError($attribute, 'Не удалось прочитать позиции: обновите окно и повторите.');
        }
    }

    /** Pictures pasted long ago expire in the temporary area: say so before saving. */
    public function validateUploads($attribute)
    {
        foreach (RichTextHtml::uploadTokens((string)$this->$attribute) as $token) {
            if (!TemporaryUploads::path($token)) {
                $this->addError($attribute, 'Картинка «'.TemporaryUploads::name($token).'» устарела: вставьте её заново.');
            }
        }
    }

    public function attributeLabels()
    {
        return [
            'intro'             => 'Вступление',
            'terms'             => 'Условия',
            'discount_value'    => 'Скидка на всё предложение',
            'our_contractor_id' => 'Наши реквизиты',
            'itemsJson'         => 'Позиции',
        ];
    }

    public function attributeHints()
    {
        return [
            'intro'             => 'Обращение к клиенту и суть предложения. Можно вставлять картинки.',
            'terms'             => 'Сроки, порядок оплаты, что входит и не входит в стоимость.',
            'discount_value'    => 'Сумма или процент от суммы позиций, например 5000 или 10%.',
            'our_contractor_id' => 'Реквизиты копируются в версию: их последующее изменение отправленные КП не затронет.',
        ];
    }

    /** Content for ProposalVersions::saveContent(). */
    public function content(): array
    {
        $rows = Json::decode((string)$this->itemsJson) ?: [];

        return [
            'intro'             => (string)$this->intro,
            'terms'             => (string)$this->terms,
            'discount_value'    => (string)$this->discount_value,
            'our_contractor_id' => $this->our_contractor_id ?: null,
            'items'             => is_array($rows) ? $rows : [],
        ];
    }

    /** Contractors of our company on the current site: id => name. */
    public static function ourContractors(): array
    {
        $query = CmsContractor::find()->our()->orderBy(['id' => SORT_ASC]);
        if (\Yii::$app->skeeks->site) {
            $query->andWhere(['cms_site_id' => (int)\Yii::$app->skeeks->site->id]);
        }
        $result = [];
        foreach ($query->all() as $contractor) {
            $result[(int)$contractor->id] = (string)$contractor->asText;
        }

        return $result;
    }

    /** The version the form edits is sent: saving makes a new one. */
    public function getWillCreateVersion(): bool
    {
        $current = $this->proposal ? $this->proposal->currentVersion : null;

        return !$current || $current instanceof CmsProposalVersion && $current->isLocked;
    }
}
