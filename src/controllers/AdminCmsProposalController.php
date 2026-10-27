<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\proposal\controllers;

use kartik\datecontrol\DateControl;
use skeeks\cms\backend\actions\BackendModelAction;
use skeeks\cms\backend\actions\BackendModelUpdateAction;
use skeeks\cms\backend\controllers\BackendModelStandartController;
use skeeks\cms\backend\grid\BackendEntityLinkColumn;
use skeeks\cms\backend\widgets\BackendEntityLink;
use skeeks\cms\models\CmsCompany;
use skeeks\cms\models\CmsDeal;
use skeeks\cms\models\CmsLead;
use skeeks\cms\models\CmsUser;
use skeeks\cms\proposal\assets\ProposalAsset;
use skeeks\cms\proposal\forms\ProposalContentForm;
use skeeks\cms\proposal\models\CmsProposal;
use skeeks\cms\proposal\models\CmsProposalTemplate;
use skeeks\cms\proposal\models\CmsProposalVersion;
use skeeks\cms\proposal\services\ProposalItems;
use skeeks\cms\proposal\services\ProposalPdf;
use skeeks\cms\proposal\services\ProposalVersions;
use skeeks\cms\proposal\widgets\ProposalItemsInputWidget;
use skeeks\cms\queryfilters\filters\modes\FilterModeEq;
use skeeks\cms\queryfilters\QueryFiltersEvent;
use skeeks\cms\rbac\CmsManager;
use skeeks\cms\source\RecordSources;
use skeeks\cms\widgets\AjaxSelectModel;
use skeeks\cms\widgets\formInputs\richText\RichTextInputWidget;
use skeeks\yii2\form\fields\FieldSet;
use skeeks\yii2\form\fields\HtmlBlock;
use skeeks\cms\communication\helpers\MailUi;
use skeeks\yii2\form\fields\SelectField;
use skeeks\yii2\form\fields\WidgetField;
use skeeks\cms\helpers\RequestResponse;
use skeeks\cms\proposal\components\ProposalSettings;
use skeeks\cms\proposal\models\CmsProposalSend;
use skeeks\cms\proposal\source\ProposalClientSource;
use skeeks\cms\proposal\helpers\ProposalUi;
use yii\base\Event;
use yii\helpers\ArrayHelper;
use yii\helpers\Html;
use yii\helpers\Url;
use yii\web\NotFoundHttpException;

/**
 * The "Предложения" section (КП and presentations): the list with kind,
 * status and manager filters, the card of a proposal, the content editor of
 * its current version, a new version and the PDF of a КП. The kind is
 * chosen first in the create window; a presentation has its own short form
 * (name, page, text, validity) and no content, versions or PDF. Open to
 * every employee with administration access; each record is limited by
 * CmsProposalQuery::forManager().
 */
class AdminCmsProposalController extends BackendModelStandartController
{
    public function init()
    {
        $this->name = 'Предложения';
        $this->modelShowAttribute = 'asText';
        $this->modelClassName = CmsProposal::class;
        $this->generateAccessActions = false;
        $this->permissionNames = [CmsManager::PERMISSION_ADMIN_ACCESS => 'Доступ к системе администрирования'];

        $this->modelHeader = function () {
            return $this->renderPartial('@skeeks/cms/proposal/views/admin-cms-proposal/_model_header', ['model' => $this->model]);
        };

        parent::init();
    }

    public function getViewPath()
    {
        return \Yii::getAlias('@skeeks/cms/proposal/views/admin-cms-proposal');
    }

    public function actions()
    {
        $hasModel = fn() => (bool)$this->model;
        // Content, versions and PDF belong to a КП only.
        $isCommercial = fn() => $this->model && !$this->model->isPresentation;
        $headerActions = [
            ['backendAction' => 'create', 'label' => 'Создать предложение'],
            ['label' => 'Шаблоны КП', 'url' => Url::to(['/cmsProposal/admin-cms-proposal-template']), 'variant' => 'secondary'],
        ];
        // The settings window of a component is for administrators (AdminComponentSettingsController).
        if (\Yii::$app->has('user') && \Yii::$app->user->can(CmsManager::PERMISSION_ROLE_ADMIN_ACCESS)) {
            // The standard settings window of a component, as a window over the list.
            $settingsUrl = (string)(new ProposalSettings())->getEditUrl();
            $headerActions[] = ['label' => 'Настройки', 'url' => $settingsUrl, 'variant' => 'secondary', 'options' => ['onclick' => ProposalUi::openOnClick($settingsUrl)]];
        }

        return ArrayHelper::merge(parent::actions(), [
            'index' => [
                'presentationMode' => 'page',
                'pageHeader' => [
                    'title' => 'Предложения',
                    'description' => 'КП и презентации для клиентов: по личной ссылке видно, когда клиент их открыл.',
                    'actions' => $headerActions,
                ],
                'emptyState' => [
                    'title' => 'Предложений пока нет',
                    'description' => 'Создайте КП или презентацию здесь или из карточки сделки, компании или услуги.',
                    'icon' => 'far fa-file-alt',
                    'action' => ['backendAction' => 'create', 'label' => 'Создать предложение'],
                ],
                'noResultsState' => [
                    'title' => 'Предложения не найдены',
                    'description' => 'Измените запрос или выбранные фильтры.',
                    'icon' => 'fa fa-search',
                ],
                'filters' => [
                    'visibleFilters' => ['q', 'kind', 'status', 'responsible_id'],
                    'filtersModel' => [
                        'rules' => [['q', 'safe']],
                        'attributeDefines' => ['q'],
                        'attributeLabels' => ['q' => 'Поиск', 'kind' => 'Вид', 'status' => 'Статус', 'responsible_id' => 'Ответственный'],
                        'fields' => [
                            'q' => [
                                'label' => 'Поиск',
                                'elementOptions' => ['placeholder' => 'Номер или название'],
                                'on apply' => static function (QueryFiltersEvent $e) {
                                    if ($e->field->value) {
                                        $e->dataProvider->query->search((string)$e->field->value);
                                    }
                                },
                            ],
                            'kind' => [
                                'defaultMode' => FilterModeEq::ID,
                                'isAllowChangeMode' => false,
                                'field' => [
                                    'class' => SelectField::class,
                                    'items' => CmsProposal::kinds(),
                                ],
                            ],
                            'status' => [
                                'defaultMode' => FilterModeEq::ID,
                                'isAllowChangeMode' => false,
                                'field' => [
                                    'class' => SelectField::class,
                                    'items' => CmsProposal::statuses(),
                                    'multiple' => true,
                                ],
                            ],
                            'responsible_id' => [
                                'field' => [
                                    'widgetConfig' => [
                                        'searchQuery' => static function ($word = '') {
                                            $query = CmsUser::find()->isWorker();
                                            if ($word) {
                                                $query->search($word);
                                            }

                                            return $query;
                                        },
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'grid' => [
                    'on init' => static function (Event $event) {
                        $event->sender->dataProvider->query
                            ->cmsSite()
                            ->forManager()
                            ->with(['currentVersion', 'lead', 'company', 'deal', 'responsible']);
                    },
                    'defaultOrder' => ['id' => SORT_DESC],
                    'visibleColumns' => ['checkbox', 'actions', 'number', 'client', 'status', 'amount', 'valid_until', 'responsible_id', 'created_at'],
                    'columns' => [
                        'number' => [
                            'class' => BackendEntityLinkColumn::class,
                            'controllerId' => '/cmsProposal/admin-cms-proposal',
                            'label' => 'Предложение',
                            'value' => static fn(CmsProposal $model) => $model->asText,
                        ],
                        'kind' => [
                            'value' => static fn(CmsProposal $model) => $model->kindName,
                        ],
                        'client' => [
                            'label' => 'Клиент',
                            'format' => 'raw',
                            'value' => static fn(CmsProposal $model) => self::clientLink($model),
                        ],
                        'status' => [
                            'format' => 'raw',
                            'value' => static fn(CmsProposal $model) => self::statusBadge($model),
                        ],
                        'amount' => [
                            'label' => 'Сумма или страница',
                            'format' => 'raw',
                            'value' => static fn(CmsProposal $model) => $model->isPresentation
                                ? Html::tag('span', Html::encode(self::targetText($model)), ['class' => 'sx-gray', 'style' => 'font-size:13px;overflow-wrap:anywhere'])
                                : ($model->currentVersion
                                    ? Html::tag('span', Html::encode(ProposalItems::money($model->currentVersion->amount, (string)$model->currency_code)), ['style' => 'white-space:nowrap;font-weight:600'])
                                    : ''),
                        ],
                        'valid_until' => [
                            'value' => static fn(CmsProposal $model) => $model->valid_until ? \Yii::$app->formatter->asDate((int)$model->valid_until, 'php:d.m.Y') : '',
                        ],
                        'responsible_id' => [
                            'value' => static fn(CmsProposal $model) => $model->responsible ? $model->responsible->shortDisplayName : '',
                        ],
                        'created_at' => [
                            'value' => static fn(CmsProposal $model) => \Yii::$app->formatter->asDate((int)$model->created_at, 'php:d.m.Y'),
                        ],
                    ],
                ],
            ],

            'view' => [
                'class' => BackendModelAction::class,
                'name' => 'Предложение',
                'icon' => 'far fa-file-alt',
                'priority' => 10,
                'accessCallback' => $hasModel,
                'callback' => [$this, 'view'],
            ],

            'content' => [
                'class' => BackendModelUpdateAction::class,
                'name' => 'Содержимое',
                'icon' => 'fas fa-list',
                'priority' => 20,
                // A tab of the card: saving keeps the employee in the editor.
                'buttons' => ['apply'],
                'successMessage' => 'Содержимое КП сохранено',
                'accessCallback' => $isCommercial,
                // "Сохранить и отправить клиенту" goes on to the send window.
                'activeFormConfig' => ['clientCallback' => ProposalUi::nextStepFormCallback('update')],
                'fields' => [$this, 'contentFields'],
                'on initFormModels' => function (Event $event) {
                    /** @var BackendModelUpdateAction $action */
                    $action = $event->sender;
                    // Only the content form: the proposal's own fields must not block saving the items.
                    $action->formModels = ['content' => new ProposalContentForm(['proposal' => $this->model])];
                },
                'on beforeSave' => function (Event $event) {
                    /** @var BackendModelUpdateAction $action */
                    $action = $event->sender;
                    $action->isSaveFormModels = false;
                    (new ProposalVersions())->saveContent($this->model, $action->formModels['content']->content());
                    // "Сохранить и отправить клиенту": the next step of making a КП.
                    if (\Yii::$app->request->post('submit-btn') === 'send') {
                        $action->afterSaveUrl = ProposalUi::sendUrl($this->model);
                    }
                },
            ],

            'update' => [
                'name' => 'Настройки',
                'priority' => 30,
                'accessCallback' => $hasModel,
                'fields' => [$this, 'updateFields'],
            ],

            'create' => [
                'fields' => [$this, 'createFields'],
                // The standard window closes itself after a create: this one goes on to afterSaveUrl.
                'activeFormConfig' => ['clientCallback' => ProposalUi::nextStepFormCallback('create')],
                // The window goes on with the next step instead of the list: a КП to its items,
                // a presentation of a client to "Отправить клиенту", one for everybody to its card.
                'on afterSave' => function (Event $event) {
                    /** @var \skeeks\cms\backend\actions\BackendModelCreateAction $action */
                    $action = $event->sender;
                    $model = $action->model;
                    if (!$model instanceof CmsProposal || !$model->id) {
                        return;
                    }
                    if (!$model->isPresentation) {
                        $route = ['/cmsProposal/admin-cms-proposal/content', 'pk' => (int)$model->id];
                    } elseif (!$model->isShared) {
                        $action->afterSaveUrl = ProposalUi::sendUrl($model);
                        return;
                    } else {
                        $route = ['/cmsProposal/admin-cms-proposal/view', 'pk' => (int)$model->id];
                    }
                    $action->afterSaveUrl = (string)\skeeks\cms\backend\helpers\BackendUrlHelper::createByParams($route)->enableEmptyLayout()->url;
                },
            ],

            'new-version' => [
                'class' => BackendModelUpdateAction::class,
                'name' => 'Новая версия',
                'icon' => 'fas fa-copy',
                'isVisible' => false,
                'size' => BackendModelUpdateAction::SIZE_SMALL,
                'buttons' => ['save'],
                'successMessage' => 'Создана новая версия КП',
                'accessCallback' => $isCommercial,
                'fields' => function () {
                    $current = $this->model->currentVersion;

                    return [
                        'note' => [
                            'class' => HtmlBlock::class,
                            'content' => Html::tag('p', Html::encode('Будет создана версия '.((int)($current ? $current->version : 0) + 1)
                                .' — копия текущей. Текущая версия останется в истории без изменений, реквизиты возьмутся актуальные.')),
                        ],
                    ];
                },
                'on initFormModels' => static function (Event $event) {
                    $event->sender->formModels = ['confirm' => new \yii\base\DynamicModel([])];
                },
                'on beforeSave' => function (Event $event) {
                    $event->sender->isSaveFormModels = false;
                    (new ProposalVersions())->newVersion($this->model);
                },
            ],

            'pdf' => [
                'class' => BackendModelAction::class,
                'name' => 'Скачать PDF',
                'icon' => 'far fa-file-pdf',
                'isVisible' => false,
                'accessCallback' => $isCommercial,
                'callback' => [$this, 'pdf'],
            ],

            'delete' => [
                'accessCallback' => fn() => $this->model && !$this->model->getSends()->exists(),
            ],
            'delete-multi' => ['isVisible' => false, 'accessCallback' => static fn() => false],
        ]);
    }

    public function view()
    {
        return $this->render('view', ['model' => $this->model]);
    }

    /** PDF of the current version or of `?version=<id>` of this proposal. */
    public function pdf()
    {
        /** @var CmsProposal $proposal */
        $proposal = $this->model;
        $version = $proposal->currentVersion;
        if ($versionId = (int)\Yii::$app->request->get('version')) {
            $version = CmsProposalVersion::find()->andWhere(['id' => $versionId, 'cms_proposal_id' => (int)$proposal->id])->one();
        }
        if (!$version) {
            throw new NotFoundHttpException('Версия КП не найдена.');
        }

        $response = \Yii::$app->response;
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');

        return $response->sendContentAsFile((new ProposalPdf())->render($version), ProposalPdf::fileName($version), [
            'mimeType' => 'application/pdf',
            'inline' => (bool)\Yii::$app->request->get('inline'),
        ]);
    }

    /**
     * Products and services of skeeks/cms-shop for the items editor:
     * `items` with id, name, price, priceText, measure, category, image.
     */
    public function actionProductSearch($q = '')
    {
        \Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;
        if (!self::hasShop()) {
            return ['items' => []];
        }

        $q = trim((string)$q);
        $query = \skeeks\cms\shop\models\ShopProduct::find()
            ->alias('shopProduct')
            ->joinWith(['cmsContentElement as cmsContentElement'])
            ->with(['cmsContentElement.cmsTree', 'measure', 'baseProductPrice'])
            ->andWhere(['cmsContentElement.cms_site_id' => (int)\Yii::$app->skeeks->site->id])
            ->limit(12);
        if ($q !== '') {
            $query->andWhere(['or', ['like', 'cmsContentElement.name', $q], ['like', 'cmsContentElement.code', $q]]);
        }

        $items = [];
        foreach ($query->orderBy(['cmsContentElement.name' => SORT_ASC])->all() as $product) {
            $element = $product->cmsContentElement;
            $price = $product->baseProductPrice ? (float)$product->baseProductPrice->price : 0;
            $currency = $product->baseProductPrice && $product->baseProductPrice->currency_code ? (string)$product->baseProductPrice->currency_code : 'RUB';
            $image = '';
            if ($element && $element->mainProductImage) {
                $image = \Yii::$app->imaging->thumbnailUrlOnRequest($element->mainProductImage->src, new \skeeks\cms\components\imaging\filters\Thumbnail());
            }
            $items[] = [
                'id'        => (int)$product->id,
                'name'      => $element ? (string)$element->productName : '#'.$product->id,
                'price'     => $price,
                'priceText' => $price ? ProposalItems::money($price, $currency) : '',
                'measure'   => $product->measure ? (string)$product->measure->asShortText : ProposalItems::DEFAULT_MEASURE,
                'category'  => $element && $element->cmsTree ? (string)$element->cmsTree->name : '',
                'image'     => (string)$image,
            ];
        }

        return ['items' => $items];
    }

    /**
     * "Отправить презентацию" of a CRM card (`?source=proposal-client:lead.29`):
     * the presentations for everybody, most sent first, with what this client
     * already got and opened; a row opens "Отправить клиенту" of the
     * presentation with this client. Without presentations — a link to make one.
     */
    public function actionPresentations()
    {
        $parsed = RecordSources::parse(\Yii::$app->request->get(RecordSources::PARAM));
        $record = $parsed && $parsed[0] === ProposalClientSource::TYPE ? (new ProposalClientSource())->find($parsed[1]) : null;
        if (!$record) {
            throw new NotFoundHttpException('Клиент не найден или у вас нет к нему доступа.');
        }

        $t = CmsProposal::tableName();
        $s = CmsProposalSend::tableName();
        $presentations = CmsProposal::find()->cmsSite()->forManager()->shared()
            ->select([$t.'.*', 'sendsCount' => CmsProposalSend::find()->select('COUNT(*)')
                ->andWhere(['not', ['cms_comm_message_id' => null]])->andWhere($s.'.cms_proposal_id = '.$t.'.id')])
            ->andWhere(['or', [$t.'.valid_until' => null], ['>=', $t.'.valid_until', time()]])
            ->orderBy(['sendsCount' => SORT_DESC, $t.'.name' => SORT_ASC])
            ->limit(100)
            ->all();

        // What this client already got: the last sending and whether any was opened.
        $sent = [];
        if ($presentations) {
            $sends = CmsProposalSend::find()->andWhere(['cms_proposal_id' => array_map(static fn($p) => (int)$p->id, $presentations)])
                ->andWhere(ProposalUi::recordSendCondition($record))->orderBy(['id' => SORT_ASC])->all();
            foreach ($sends as $send) {
                $id = (int)$send->cms_proposal_id;
                $sent[$id]['last'] = (int)$send->created_at;
                if ($send->first_viewed_at) {
                    $sent[$id]['opened'] = max((int)($sent[$id]['opened'] ?? 0), (int)$send->first_viewed_at);
                }
            }
        }
        ProposalAsset::register($this->view);

        return $this->render('presentations', [
            'record' => $record,
            'presentations' => $presentations,
            'sent' => $sent,
            'createUrl' => (string)\skeeks\cms\backend\helpers\BackendUrlHelper::createByParams(['/cmsProposal/admin-cms-proposal/create', 'kind' => CmsProposal::KIND_PRESENTATION])
                ->enableEmptyLayout()->url,
        ]);
    }

    /**
     * "Отправить КП" of a CRM card (`?source=proposal-client:lead.29`): the КП
     * of this client that still wait for it (drafts first, with their sum,
     * status and what was sent and opened) and "Создать новое КП"; a row with
     * content opens "Отправить клиенту", an empty draft its items. Without
     * such КП the window opens the creation of a new one at once.
     */
    public function actionProposals()
    {
        $parsed = RecordSources::parse(\Yii::$app->request->get(RecordSources::PARAM));
        $record = $parsed && $parsed[0] === ProposalClientSource::TYPE ? (new ProposalClientSource())->find($parsed[1]) : null;
        if (!$record) {
            throw new NotFoundHttpException('Клиент не найден или у вас нет к нему доступа.');
        }

        $t = CmsProposal::tableName();
        $proposals = CmsProposal::find()->cmsSite()->forManager()->forRecord($record)
            ->andWhere([$t.'.kind' => CmsProposal::KIND_PROPOSAL])
            ->andWhere([$t.'.status' => CmsProposal::WAITING_STATUSES])
            ->with(['currentVersion.items'])
            ->orderBy(new \yii\db\Expression($t.".status = 'draft' DESC, ".$t.'.id DESC'))
            ->limit(50)
            ->all();
        // Nothing to choose from: straight to a new КП of this client.
        if (!$proposals) {
            return $this->redirect((string)ProposalUi::createUrl($record, CmsProposal::KIND_PROPOSAL));
        }
        $sent = [];
        if ($proposals) {
            $sends = CmsProposalSend::find()->andWhere(['cms_proposal_id' => array_map(static fn($p) => (int)$p->id, $proposals)])
                ->andWhere(['not', ['cms_comm_message_id' => null]])->orderBy(['id' => SORT_ASC])->all();
            foreach ($sends as $send) {
                $id = (int)$send->cms_proposal_id;
                $sent[$id]['last'] = (int)$send->created_at;
                if ($send->first_viewed_at) {
                    $sent[$id]['opened'] = max((int)($sent[$id]['opened'] ?? 0), (int)$send->first_viewed_at);
                }
            }
        }
        ProposalAsset::register($this->view);

        return $this->render('proposals', [
            'record' => $record,
            'proposals' => $proposals,
            'sent' => $sent,
            'createUrl' => (string)ProposalUi::createUrl($record, CmsProposal::KIND_PROPOSAL),
        ]);
    }

    public static function hasShop(): bool
    {
        return class_exists(\skeeks\cms\shop\models\ShopProduct::class) && \Yii::$app->db->getTableSchema('{{%shop_product}}') !== null;
    }

    public function contentFields($action)
    {
        /** @var ProposalContentForm $form */
        $form = $action->formModels['content'];
        ProposalAsset::register($this->view);
        $current = $this->model->currentVersion;
        $editorOptions = ['preset' => CmsProposalVersion::TEXT_PRESET, 'minHeight' => 140];

        $note = $form->willCreateVersion
            ? Html::tag('div', Html::encode('Версия '.($current ? $current->version : '').' уже отправлена клиенту и не меняется. При сохранении будет создана новая версия.'), ['class' => 'sx-proposal-note sx-proposal-note--warning'])
            : Html::tag('div', Html::encode('Вы редактируете версию '.($current ? $current->version : 1).'. Пока её не отправили, изменения сохраняются в ней.'), ['class' => 'sx-proposal-note']);

        return [
            'note' => [
                'class' => HtmlBlock::class,
                'content' => $note,
            ],
            'content.intro' => [
                'class' => WidgetField::class,
                'widgetClass' => RichTextInputWidget::class,
                'widgetConfig' => $editorOptions,
            ],
            'content.itemsJson' => [
                'class' => WidgetField::class,
                'widgetClass' => ProposalItemsInputWidget::class,
                'widgetConfig' => [
                    'productSearchUrl' => self::hasShop() ? Url::to(['/cmsProposal/admin-cms-proposal/product-search']) : null,
                    'discountInputId' => Html::getInputId($form, 'discount_value'),
                    'currencyCode' => (string)$this->model->currency_code,
                ],
            ],
            'content.discount_value',
            'content.terms' => [
                'class' => WidgetField::class,
                'widgetClass' => RichTextInputWidget::class,
                'widgetConfig' => $editorOptions,
            ],
            'content.our_contractor_id' => [
                'class' => SelectField::class,
                'items' => ProposalContentForm::ourContractors(),
            ],
            // The next step: the standard form posts submit-btn=send, the action then opens "Отправить клиенту".
            'nextStep' => [
                'class' => HtmlBlock::class,
                'content' => Html::tag('div',
                    Html::tag('div', Html::encode('Когда позиции и текст готовы, сохраните КП и отправьте его клиенту письмом или СМС.'), ['class' => 'sx-proposal-muted'])
                    .Html::button(MailUi::icon('send', 16).' '.Html::encode('Сохранить и отправить клиенту'), [
                        'class' => 'sx-button sx-button--primary',
                        'data-sx-proposal-save-send' => true,
                        'onclick' => 'var f = this.closest("form"); f.querySelector("[name=\'submit-btn\']").value = "send"; $(f).submit(); return false;',
                    ]),
                    ['class' => 'sx-proposal-next-step']),
            ],
        ];
    }

    /**
     * The kind first (the form reloads on change), then the fields of the
     * kind: a КП from a template, a presentation with its page and text.
     * `?kind=presentation` opens the window with the kind chosen.
     */
    public function createFields($action)
    {
        /** @var CmsProposal $model */
        $model = $action->model;
        // The window opens with ?kind=; after that the form posts the chosen kind.
        $kind = (string)\Yii::$app->request->get('kind');
        if (!\Yii::$app->request->isPost && isset(CmsProposal::kinds()[$kind])) {
            $model->kind = $kind;
        } elseif (!$model->kind) {
            $model->kind = CmsProposal::KIND_PROPOSAL;
        }
        $fields = $this->mainFields($action);
        if (!$model->isPresentation) {
            $templates =CmsProposalTemplate::find()->cmsSite()->andWhere(['is_active' => 1])->orderBy(['priority' => SORT_ASC, 'name' => SORT_ASC])->all();
            if ($templates) {
                $fields['main']['fields'] = array_merge([
                    'cms_proposal_template_id' => [
                        'class' => SelectField::class,
                        'items' => ArrayHelper::map($templates, 'id', 'name'),
                    ],
                ], $fields['main']['fields']);
            }
        }
        $fields['main']['fields'] = array_merge([
            'kind' => [
                'class' => SelectField::class,
                'items' => CmsProposal::kinds(),
                'allowNull' => false,
                'hint' => CmsProposal::kindHints()[$model->kind] ?? '',
                'elementOptions' => [RequestResponse::DYNAMIC_RELOAD_FIELD_ELEMENT => 'true'],
            ],
        ], $fields['main']['fields']);

        return $fields;
    }

    public function updateFields($action)
    {
        $fields = $this->mainFields($action);
        if ($this->model->isPresentation) {
            return $fields;
        }
        $fields['main']['fields']['status'] = [
            'class' => SelectField::class,
            'items' => in_array($this->model->status, array_keys(CmsProposal::manualStatuses()), true)
                ? CmsProposal::manualStatuses()
                : [$this->model->status => CmsProposal::statuses()[$this->model->status] ?? $this->model->status] + CmsProposal::manualStatuses(),
            'allowNull' => false,
        ];

        return $fields;
    }

    private function mainFields($action): array
    {
        /** @var CmsProposal $model */
        $model = $action->model;
        // A proposal made from a card keeps its client: RecordSources fills and enforces it.
        $fromSource = (bool)RecordSources::parse(\Yii::$app->request->get(RecordSources::PARAM));
        $client = $fromSource && $model->isNewRecord && $model->client
            ? []
            : [
                'cms_lead_id' => $this->clientSelect(CmsLead::class),
                'cms_company_id' => $this->clientSelect(CmsCompany::class),
                'cms_deal_id' => $this->clientSelect(CmsDeal::class),
                'cms_user_id' => $this->clientSelect(CmsUser::class),
            ];

        $responsible = [
            'class' => WidgetField::class,
            'widgetClass' => AjaxSelectModel::class,
            'widgetConfig' => [
                'modelClass' => CmsUser::class,
                'searchQuery' => static function ($word = '') {
                    $query = CmsUser::find()->isWorker();
                    if ($word) {
                        $query->search($word);
                    }

                    return $query;
                },
            ],
        ];
        $validUntil = [
            'class' => WidgetField::class,
            'widgetClass' => DateControl::class,
            'widgetConfig' => ['type' => DateControl::FORMAT_DATE],
        ];

        // A presentation: the page and the text, no currency, items or versions.
        if ($model->isPresentation) {
            // A text saved before the editor is plain lines: the editor shows them as paragraphs.
            if (!\Yii::$app->request->isPost && $model->message) {
                $model->message = $model->getMessageHtml();
            }
            return [
                'main' => [
                    'class' => FieldSet::class,
                    'name' => 'Презентация',
                    'fields' => array_merge(['name'], $client ? [
                        'clientNote' => [
                            'class' => HtmlBlock::class,
                            'content' => Html::tag('p', Html::encode('Клиента можно не выбирать: такую презентацию отправляют разным клиентам из их карточек, '
                                .'у каждого своя ссылка, а открытие попадает в ленту того, кому её отправили.'), ['class' => 'sx-gray', 'style' => 'margin:0 0 8px;font-size:13px']),
                        ],
                    ] + $client : [], [
                        'target_url' => [
                            'elementOptions' => ['placeholder' => '/works/sfery/plitka'],
                        ],
                        'message' => [
                            'class' => WidgetField::class,
                            'widgetClass' => RichTextInputWidget::class,
                            'widgetConfig' => ['preset' => CmsProposal::MESSAGE_PRESET, 'minHeight' => 120],
                        ],
                        'responsible_id' => $responsible,
                        'valid_until' => $validUntil,
                    ]),
                ],
            ];
        }

        return [
            'main' => [
                'class' => FieldSet::class,
                'name' => 'Предложение',
                'fields' => array_merge(['name'], $client, [
                    'responsible_id' => $responsible,
                    'valid_until' => $validUntil,
                    'currency_code' => [
                        'class' => SelectField::class,
                        'items' => self::currencies(),
                        'allowNull' => false,
                    ],
                ]),
            ],
        ];
    }

    /** The page of a presentation as people read it: host and path. */
    public static function targetText(CmsProposal $model): string
    {
        $url = (string)$model->target_url;
        $parts = parse_url($url);

        return $parts && !empty($parts['host']) ? $parts['host'].($parts['path'] ?? '').(isset($parts['query']) ? '?'.$parts['query'] : '') : $url;
    }

    private function clientSelect(string $class): array
    {
        return [
            'class' => WidgetField::class,
            'widgetClass' => AjaxSelectModel::class,
            'widgetConfig' => [
                'modelClass' => $class,
                'searchQuery' => static function ($word = '') use ($class) {
                    $query = $class::find()->forManager();
                    if ($class === CmsUser::class) {
                        // Client people only, not employees.
                        $query->andWhere([CmsUser::tableName().'.is_worker' => 0]);
                    }
                    if ($word && method_exists($query, 'search')) {
                        $query->search($word);
                    } elseif ($word) {
                        $query->andWhere(['like', $class::tableName().'.name', $word]);
                    }

                    return $query;
                },
            ],
        ];
    }

    public static function currencies(): array
    {
        $result = [];
        if (class_exists(\skeeks\cms\money\models\MoneyCurrency::class)) {
            foreach (\skeeks\cms\money\models\MoneyCurrency::find()->andWhere(['is_active' => 1])->orderBy(['priority' => SORT_ASC])->all() as $currency) {
                $result[(string)$currency->code] = (string)$currency->code;
            }
        }

        return $result ?: ['RUB' => 'RUB'];
    }

    /** The client as a link to its card. */
    public static function clientLink(CmsProposal $model, bool $withType = true): string
    {
        if ($model->isShared) {
            return Html::tag('span', 'Общая: клиент у каждой отправки', ['class' => 'sx-gray']);
        }
        $client = $model->client;
        if (!$client) {
            return '';
        }
        return ($withType ? Html::tag('span', Html::encode($model->clientTypeName), ['class' => 'sx-gray', 'style' => 'font-size:12px;display:block']) : '')
            .self::recordLink($client, $model->clientName);
    }

    /** A lead, deal, company or client person as a link to its card. */
    public static function recordLink($client, string $label): string
    {
        $controller = $client instanceof CmsLead ? '/cms/admin-cms-lead'
            : ($client instanceof CmsDeal ? '/cms/admin-cms-deal' : ($client instanceof CmsUser ? '/cms/admin-user' : '/cms/admin-cms-company'));

        return BackendEntityLink::widget([
            'controllerId' => $controller,
            'modelId' => (int)$client->id,
            'label' => $label,
        ]);
    }

    public static function statusBadge(CmsProposal $model): string
    {
        $class = [
            CmsProposal::STATUS_DRAFT    => '',
            CmsProposal::STATUS_SENT     => 'sx-status--info',
            CmsProposal::STATUS_OPENED   => 'sx-status--accent',
            CmsProposal::STATUS_ACCEPTED => 'sx-status--success',
            CmsProposal::STATUS_REJECTED => 'sx-status--danger',
            CmsProposal::STATUS_EXPIRED  => 'sx-status--warning',
        ][$model->displayStatus] ?? '';

        return Html::tag('span', Html::encode($model->displayStatusName), ['class' => trim('sx-status '.$class)]);
    }

    /** Only proposals the employee may see open by pk. */
    public function getModel()
    {
        if ($this->_model === null && \Yii::$app->controller === $this && ($pk = \Yii::$app->request->get($this->requestPkParamName))) {
            $this->_model = CmsProposal::find()->cmsSite()->forManager()->andWhere([CmsProposal::tableName().'.id' => (int)$pk])->one();
            if (!$this->_model) {
                throw new NotFoundHttpException('КП не найдено или у вас нет к нему доступа.');
            }
        }

        return $this->_model;
    }
}
