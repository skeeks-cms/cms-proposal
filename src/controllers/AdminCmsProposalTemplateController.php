<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\proposal\controllers;

use skeeks\cms\backend\controllers\BackendModelStandartController;
use skeeks\cms\backend\grid\BackendEntityLinkColumn;
use skeeks\cms\grid\BooleanColumn;
use skeeks\cms\proposal\models\CmsProposalTemplate;
use skeeks\cms\proposal\models\CmsProposalVersion;
use skeeks\cms\proposal\services\ProposalItems;
use skeeks\cms\proposal\widgets\ProposalItemsInputWidget;
use skeeks\cms\rbac\CmsManager;
use skeeks\cms\widgets\formInputs\richText\RichTextInputWidget;
use skeeks\yii2\form\fields\BoolField;
use skeeks\yii2\form\fields\FieldSet;
use skeeks\yii2\form\fields\NumberField;
use skeeks\yii2\form\fields\WidgetField;
use yii\base\Event;
use yii\helpers\ArrayHelper;
use yii\helpers\Html;
use yii\helpers\Url;

/**
 * Templates of proposals ("Сайт под ключ", "SEO", "Хостинг"): texts, items
 * and validity a new proposal starts from. Every employee with
 * administration access uses and edits the templates of the site.
 */
class AdminCmsProposalTemplateController extends BackendModelStandartController
{
    public function init()
    {
        $this->name = 'Шаблоны КП';
        $this->modelShowAttribute = 'name';
        $this->modelClassName = CmsProposalTemplate::class;
        $this->generateAccessActions = false;
        $this->permissionNames = [CmsManager::PERMISSION_ADMIN_ACCESS => 'Доступ к системе администрирования'];

        parent::init();
    }

    public function actions()
    {
        $hasModel = fn() => (bool)$this->model;

        return ArrayHelper::merge(parent::actions(), [
            'index' => [
                'presentationMode' => 'page',
                'pageHeader' => [
                    'title' => 'Шаблоны КП',
                    'description' => 'Готовые тексты и позиции, из которых создаются новые предложения.',
                    'actions' => [
                        ['backendAction' => 'create', 'label' => 'Добавить шаблон'],
                        ['label' => 'Все КП', 'url' => Url::to(['/cmsProposal/admin-cms-proposal']), 'variant' => 'secondary'],
                    ],
                ],
                'emptyState' => [
                    'title' => 'Шаблонов пока нет',
                    'description' => 'Сохраните типовое предложение — «Сайт под ключ», «SEO», «Хостинг» — и создавайте новые КП из него за минуту.',
                    'icon' => 'far fa-copy',
                    'action' => ['backendAction' => 'create', 'label' => 'Добавить шаблон'],
                ],
                'filters' => false,
                'grid' => [
                    'on init' => static function (Event $event) {
                        $event->sender->dataProvider->query->cmsSite();
                    },
                    'defaultOrder' => ['priority' => SORT_ASC, 'name' => SORT_ASC],
                    'visibleColumns' => ['checkbox', 'actions', 'name', 'items', 'valid_days', 'is_active'],
                    'columns' => [
                        'name' => [
                            'class' => BackendEntityLinkColumn::class,
                            'controllerId' => '/cmsProposal/admin-cms-proposal-template',
                        ],
                        'items' => [
                            'label' => 'Позиции',
                            'format' => 'raw',
                            'value' => static function (CmsProposalTemplate $model) {
                                $rows = $model->itemRows;
                                $totals = ProposalItems::totals($rows, $model->discount_value);
                                $count = count(array_filter($rows, static fn($row) => $row['type'] !== 'section'));

                                return Html::encode($count ? $count.' · '.ProposalItems::money($totals['total']) : '—');
                            },
                        ],
                        'is_active' => [
                            'class' => BooleanColumn::class,
                            'trueValue' => 1,
                            'falseValue' => 0,
                        ],
                    ],
                ],
            ],
            'create' => ['fields' => [$this, 'updateFields']],
            'update' => ['fields' => [$this, 'updateFields'], 'accessCallback' => $hasModel],
            'delete' => ['accessCallback' => $hasModel],
        ]);
    }

    public function updateFields($action)
    {
        /** @var CmsProposalTemplate $model */
        $model = $action->model;
        $editor = ['preset' => CmsProposalVersion::TEXT_PRESET, 'minHeight' => 140];

        return [
            'main' => [
                'class' => FieldSet::class,
                'name' => 'Шаблон',
                'fields' => [
                    'name',
                    'title',
                    'valid_days' => ['class' => NumberField::class],
                    'is_active' => ['class' => BoolField::class, 'allowNull' => false],
                    'priority' => ['class' => NumberField::class],
                ],
            ],
            'content' => [
                'class' => FieldSet::class,
                'name' => 'Содержимое',
                'fields' => [
                    'intro' => ['class' => WidgetField::class, 'widgetClass' => RichTextInputWidget::class, 'widgetConfig' => $editor],
                    'itemsJson' => [
                        'class' => WidgetField::class,
                        'widgetClass' => ProposalItemsInputWidget::class,
                        'widgetConfig' => [
                            'productSearchUrl' => AdminCmsProposalController::hasShop() ? Url::to(['/cmsProposal/admin-cms-proposal/product-search']) : null,
                            'discountInputId' => Html::getInputId($model, 'discount_value'),
                        ],
                    ],
                    'discount_value',
                    'terms' => ['class' => WidgetField::class, 'widgetClass' => RichTextInputWidget::class, 'widgetConfig' => $editor],
                ],
            ],
        ];
    }

    /** Only templates of the current site open by pk. */
    public function getModel()
    {
        if ($this->_model === null && \Yii::$app->controller === $this && ($pk = \Yii::$app->request->get($this->requestPkParamName))) {
            $this->_model = CmsProposalTemplate::find()->cmsSite()->andWhere([CmsProposalTemplate::tableName().'.id' => (int)$pk])->one();
            if (!$this->_model) {
                throw new \yii\web\NotFoundHttpException('Шаблон КП не найден.');
            }
        }

        return $this->_model;
    }
}
