<?php
/**
 * The card of a proposal: the current version (intro, items by sections,
 * totals, terms, our requisites) and, aside, the client, the versions with
 * their PDF and the sendings.
 *
 * @var yii\web\View $this
 * @var \skeeks\cms\proposal\models\CmsProposal $model
 */

use skeeks\cms\backend\widgets\BackendSurfaceWidget;
use skeeks\cms\communication\helpers\MailUi;
use skeeks\cms\communication\services\LinkChecks;
use skeeks\cms\helpers\RichTextHtml;
use skeeks\cms\proposal\models\CmsProposal;
use skeeks\cms\proposal\models\CmsProposalView;
use skeeks\cms\proposal\assets\ProposalAsset;
use skeeks\cms\proposal\controllers\AdminCmsProposalController;
use skeeks\cms\proposal\helpers\ProposalUi;
use skeeks\cms\proposal\services\ProposalItems;
use skeeks\cms\proposal\services\ProposalVersions;
use yii\helpers\Html;
use yii\helpers\Url;

ProposalAsset::register($this);

$version = $model->currentVersion;
$currency = (string)$model->currency_code;
$money = static fn($value) => Html::encode(ProposalItems::money($value, $currency));
$groups = $version ? ProposalItems::groups(ProposalItems::normalize(ProposalVersions::contentOf($version)['items'])) : [];
$contentUrl = Url::to(['content', 'pk' => (int)$model->id]);
$dt = static fn($time) => $time ? \Yii::$app->formatter->asDatetime((int)$time, 'php:d.m.Y H:i') : '';
?>
<div class="sx-detail-layout sx-proposal-card">
    <aside class="sx-detail-layout__aside sx-surface-stack">
        <?php BackendSurfaceWidget::begin(['title' => 'Предложение', 'titleTag' => 'h2', 'raised' => true, 'responsive' => true, 'options' => ['class' => 'sx-surface--compact']]); ?>
            <dl class="sx-proposal-facts">
                <dt><?= Html::encode($model->clientTypeName); ?></dt>
                <dd><?= AdminCmsProposalController::clientLink($model, false) ?: '—'; ?></dd>
                <?php if (!$model->cms_company_id && ($company = $model->getClientCompany())) : ?>
                    <dt>Компания</dt>
                    <dd><?= \skeeks\cms\backend\widgets\BackendEntityLink::widget(['controllerId' => '/cms/admin-cms-company', 'modelId' => (int)$company->id, 'label' => $company->asText]); ?></dd>
                <?php endif; ?>
                <dt>Статус</dt>
                <dd><?= AdminCmsProposalController::statusBadge($model); ?></dd>
                <?php if ($model->accepted_at) : ?>
                    <dt>Принято клиентом</dt>
                    <dd><?= Html::encode(\Yii::$app->formatter->asDatetime((int)$model->accepted_at, 'php:d.m.Y H:i')
                        .($model->acceptedVersion ? ', версия '.$model->acceptedVersion->version : '')
                        .($model->acceptedSend ? ' ('.mb_strtolower($model->acceptedSend->channelName).')' : '')); ?></dd>
                <?php endif; ?>
                <dt>Действует до</dt>
                <dd><?= $model->valid_until ? Html::encode(\Yii::$app->formatter->asDate((int)$model->valid_until, 'php:d.m.Y')) : '—'; ?></dd>
                <dt>Ответственный</dt>
                <dd><?= $model->responsible ? Html::encode($model->responsible->shortDisplayName) : '—'; ?></dd>
                <?php if (!$model->isPresentation) : ?>
                    <dt>Валюта</dt>
                    <dd><?= Html::encode($currency); ?></dd>
                <?php endif; ?>
                <?php if ($model->template) : ?>
                    <dt>Шаблон</dt>
                    <dd><?= Html::encode($model->template->name); ?></dd>
                <?php endif; ?>
            </dl>
        <?php BackendSurfaceWidget::end(); ?>

        <?php if (!$model->isPresentation) : ?>
        <?php BackendSurfaceWidget::begin(['title' => 'Версии', 'titleTag' => 'h2', 'raised' => true, 'responsive' => true, 'options' => ['class' => 'sx-surface--compact']]); ?>
            <div class="sx-proposal-versions">
                <?php foreach ($model->versions as $item) : ?>
                    <div class="sx-proposal-version<?= (int)$item->id === (int)$model->current_version_id ? ' is-current' : ''; ?>">
                        <div class="sx-proposal-version__body">
                            <div class="sx-proposal-version__title">
                                <?= Html::encode('Версия '.$item->version); ?>
                                <?php if ((int)$item->id === (int)$model->current_version_id) : ?><span class="sx-proposal-version__mark">текущая</span><?php endif; ?>
                            </div>
                            <div class="sx-proposal-version__meta">
                                <?= Html::encode(ProposalItems::money($item->amount, (string)$item->currency_code).' · '
                                    .($item->sent_at ? 'отправлена '.\Yii::$app->formatter->asDate((int)$item->sent_at, 'php:d.m.Y') : 'создана '.\Yii::$app->formatter->asDate((int)$item->created_at, 'php:d.m.Y'))); ?>
                            </div>
                        </div>
                        <?= Html::a('PDF', Url::to([ProposalUi::ROUTE.'/pdf', 'pk' => (int)$model->id, 'version' => (int)$item->id]), [
                            'class' => 'sx-button sx-button--secondary sx-button--sm',
                            'data-pjax' => '0',
                            'target' => '_blank',
                            'rel' => 'noopener',
                            'title' => 'Скачать PDF версии '.$item->version,
                        ]); ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php BackendSurfaceWidget::end(); ?>
        <?php endif; ?>

        <?php
        $sends = $model->getSends()->andWhere(['not', ['cms_comm_message_id' => null]])->with(['createdBy', 'version', 'lead', 'company', 'deal', 'cmsUser'])->all();
        BackendSurfaceWidget::begin([
            'title' => 'Отправки',
            'titleTag' => 'h2',
            'raised' => true,
            'responsive' => true,
            'actions' => Html::button('Отправить', ['class' => 'sx-button sx-button--secondary sx-button--sm', 'onclick' => ProposalUi::sendOnClick($model, $model->client)]),
            'options' => ['class' => 'sx-surface--compact'],
        ]); ?>
            <?php if ($sends) : ?>
                <div class="sx-proposal-versions">
                    <?php foreach ($sends as $send) : ?>
                        <div class="sx-proposal-version sx-proposal-send">
                            <div class="sx-proposal-version__body">
                                <div class="sx-proposal-version__title">
                                    <span class="sx-proposal-send__channel"><?= Html::encode($send->channelName); ?></span>
                                    <?= Html::encode($send->recipientText); ?>
                                    <?php if ((int)$model->accepted_send_id === (int)$send->id) : ?><span class="sx-proposal-version__mark">принято по этой ссылке</span><?php endif; ?>
                                </div>
                                <?php if ($model->isShared && ($sendClient = $send->client)) : ?>
                                    <div class="sx-proposal-version__meta"><?= AdminCmsProposalController::recordLink($sendClient, $send->clientName); ?></div>
                                <?php endif; ?>
                                <div class="sx-proposal-version__meta">
                                    <?= Html::encode('Отправлено '.$dt($send->created_at)
                                        .($send->version ? ' · версия '.$send->version->version : '')
                                        .($send->createdBy ? ' · '.$send->createdBy->shortDisplayName : '')); ?>
                                </div>
                                <div class="sx-proposal-version__meta<?= $send->first_viewed_at ? ' sx-proposal-send__opened' : ''; ?>">
                                    <?php if ($send->first_viewed_at) : ?>
                                        <?= Html::encode('Открыто '.$dt($send->first_viewed_at)
                                            .((int)$send->views_count > 1 ? ' · последний просмотр '.$dt($send->last_viewed_at) : '')
                                            .' · просмотров: '.(int)$send->views_count); ?>
                                    <?php else : ?>
                                        Ещё не открыто
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php if ($send->cms_comm_message_id) : ?>
                                <?= Html::button(MailUi::icon('mail-open', 16), [
                                    'class' => 'sx-button sx-button--ghost sx-button--sm',
                                    'onclick' => MailUi::viewOnClick((int)$send->cms_comm_message_id),
                                    'title' => 'Сообщение, с которым ушла ссылка',
                                ]); ?>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else : ?>
                <p class="sx-proposal-muted"><?= $model->isPresentation
                    ? 'Презентацию ещё не отправляли клиенту. Отправить можно письмом или СМС: у каждой отправки своя ссылка, и видно, когда клиент по ней перешёл.'
                    : 'КП ещё не отправляли клиенту. Отправить можно письмом или СМС: у каждой отправки своя ссылка, и видно, по какой клиент открыл КП.'; ?></p>
            <?php endif; ?>
        <?php BackendSurfaceWidget::end(); ?>

        <?php
        $views = $sends ? CmsProposalView::find()->andWhere(['cms_proposal_send_id' => array_map(static fn($s) => (int)$s->id, $sends)])
            ->orderBy(['created_at' => SORT_DESC, 'id' => SORT_DESC])->limit(30)->all() : [];
        $sendById = \yii\helpers\ArrayHelper::index($sends, 'id');
        ?>
        <?php if ($views) : ?>
            <?php BackendSurfaceWidget::begin(['title' => 'История просмотров', 'titleTag' => 'h2', 'raised' => true, 'responsive' => true, 'options' => ['class' => 'sx-surface--compact']]); ?>
                <div class="sx-proposal-views">
                    <?php foreach ($views as $view) : $send = $sendById[(int)$view->cms_proposal_send_id] ?? null; ?>
                        <div class="sx-proposal-view<?= $view->is_bot || $view->check_reason ? ' is-bot' : ''; ?>">
                            <div class="sx-proposal-view__time"><?= Html::encode($dt($view->created_at)); ?></div>
                            <div class="sx-proposal-view__body">
                                <?= Html::encode(($send ? $send->channelName.($send->recipient ? ': '.$send->recipient : '') : '')); ?>
                                <div class="sx-proposal-version__meta" title="<?= Html::encode((string)$view->user_agent); ?>">
                                    <?= Html::encode(($view->is_bot ? 'Робот предпросмотра, не засчитан · '
                                        : ($view->check_reason ? 'Возможно, проверка ссылок ('.LinkChecks::reasonName((string)$view->check_reason).'), не засчитано · ' : ''))
                                        .ProposalUi::browserName((string)$view->user_agent)); ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php BackendSurfaceWidget::end(); ?>
        <?php endif; ?>

        <?php if ($questions = $model->questions) : ?>
            <?php BackendSurfaceWidget::begin(['title' => 'Вопросы клиента', 'titleTag' => 'h2', 'raised' => true, 'responsive' => true, 'options' => ['class' => 'sx-surface--compact']]); ?>
                <div class="sx-proposal-versions">
                    <?php foreach ($questions as $question) : ?>
                        <div class="sx-proposal-version sx-proposal-question">
                            <div class="sx-proposal-version__body">
                                <div class="sx-proposal-question__text"><?= nl2br(Html::encode($question->text)); ?></div>
                                <div class="sx-proposal-version__meta">
                                    <?= Html::encode(implode(' · ', array_filter([$dt($question->created_at), (string)$question->name, (string)$question->contact]))); ?>
                                </div>
                            </div>
                            <?php if ($question->cms_comm_message_id) : ?>
                                <?= Html::button(MailUi::icon('mail-open', 16), [
                                    'class' => 'sx-button sx-button--ghost sx-button--sm',
                                    'onclick' => MailUi::viewOnClick((int)$question->cms_comm_message_id),
                                    'title' => 'Сообщение в переписке',
                                ]); ?>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php BackendSurfaceWidget::end(); ?>
        <?php endif; ?>
        <?php $taskLinks = $model->getTaskLinks()->with(['task.executor'])->limit(10)->all(); ?>
        <?php if ($taskLinks) : ?>
            <?php BackendSurfaceWidget::begin(['title' => 'Задачи по открытию', 'titleTag' => 'h2', 'raised' => true, 'responsive' => true, 'options' => ['class' => 'sx-surface--compact']]); ?>
                <div class="sx-proposal-versions">
                    <?php foreach ($taskLinks as $link) : if (!$task = $link->task) { continue; } ?>
                        <div class="sx-proposal-version">
                            <div class="sx-proposal-version__body">
                                <div class="sx-proposal-version__title">
                                    <?= \skeeks\cms\backend\widgets\BackendEntityLink::widget(['controllerId' => '/cms/admin-cms-task', 'modelId' => (int)$task->id, 'label' => 'Задача №'.$task->id]); ?>
                                    <span class="sx-proposal-version__mark"><?= Html::encode($task->statusAsText); ?></span>
                                </div>
                                <div class="sx-proposal-version__meta">
                                    <?= Html::encode(implode(' · ', array_filter([
                                        $task->plan_start_at ? 'на '.$dt($task->plan_start_at) : '',
                                        $task->executor ? $task->executor->shortDisplayName : '',
                                    ]))); ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php BackendSurfaceWidget::end(); ?>
        <?php endif; ?>
    </aside>

    <main class="sx-detail-layout__main sx-surface-stack">
        <?php if ($model->isPresentation) : ?>
            <?php BackendSurfaceWidget::begin(['title' => 'Презентация', 'titleTag' => 'h2', 'raised' => true, 'responsive' => true,
                'actions' => Html::a('Изменить', Url::to(['update', 'pk' => (int)$model->id]), ['class' => 'sx-button sx-button--secondary sx-button--sm'])]); ?>
                <dl class="sx-proposal-facts">
                    <dt>Страница</dt>
                    <dd>
                        <?php if ($model->target_url) : ?>
                            <?= Html::a(Html::encode((string)$model->target_url), (string)$model->target_url, ['target' => '_blank', 'rel' => 'noopener noreferrer', 'data-pjax' => '0', 'style' => 'overflow-wrap:anywhere']); ?>
                        <?php else : ?>—<?php endif; ?>
                    </dd>
                    <dt>Текст для клиента</dt>
                    <dd><?= ($messageHtml = $model->getMessageHtml()) !== ''
                        ? Html::tag('div', RichTextHtml::clean($messageHtml, CmsProposal::MESSAGE_PRESET), ['class' => 'sx-proposal-text'])
                        : '<span class="sx-proposal-muted">Не указан: в письме будет короткое приветствие.</span>'; ?></dd>
                </dl>
                <p class="sx-proposal-muted">Клиент получает личную ссылку. Когда он её открывает, переход засчитывается, и клиент сразу попадает на страницу;
                    к адресу добавляются метки для Метрики (utm_source=crm, канал отправки и номер презентации).</p>
            <?php BackendSurfaceWidget::end(); ?>
        <?php else : ?>
        <?php BackendSurfaceWidget::begin([
            'title'      => $version ? 'Версия '.$version->version.($version->isLocked ? ' · отправлена' : ' · черновик') : 'Содержимое',
            'titleTag'   => 'h2',
            'raised'     => true,
            'responsive' => true,
            'actions'    => Html::a('Изменить', $contentUrl, ['class' => 'sx-button sx-button--secondary sx-button--sm']),
        ]); ?>
            <?php if (!$version) : ?>
                <p class="sx-proposal-muted">У предложения ещё нет содержимого.</p>
            <?php else : ?>
                <?php if (trim(strip_tags((string)$version->intro, '<img>')) !== '') : ?>
                    <div class="sx-proposal-text"><?= $version->intro; ?></div>
                <?php endif; ?>

                <?php if ($groups) : ?>
                    <div class="sx-proposal-table-wrap">
                        <table class="sx-proposal-table">
                            <thead>
                            <tr>
                                <th class="sx-proposal-table__n">№</th>
                                <th>Наименование</th>
                                <th class="sx-proposal-table__num">Кол-во</th>
                                <th class="sx-proposal-table__num">Цена</th>
                                <th class="sx-proposal-table__num">Скидка</th>
                                <th class="sx-proposal-table__num">Сумма</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php $n = 0; foreach ($groups as $group) : ?>
                                <?php if ($group['name'] !== '') : ?>
                                    <tr class="sx-proposal-table__section">
                                        <td colspan="5"><?= Html::encode($group['name']); ?></td>
                                        <td class="sx-proposal-table__num"><?= $money($group['amount']); ?></td>
                                    </tr>
                                <?php endif; ?>
                                <?php foreach ($group['rows'] as $row) : $n++; ?>
                                    <tr>
                                        <td class="sx-proposal-table__n"><?= $n; ?></td>
                                        <td>
                                            <div class="sx-proposal-table__name"><?= Html::encode($row['name']); ?></div>
                                            <?php if ($row['description'] !== '') : ?>
                                                <div class="sx-proposal-table__description"><?= nl2br(Html::encode($row['description'])); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="sx-proposal-table__num"><?= Html::encode(ProposalItems::quantity($row['quantity']).' '.$row['measure_name']); ?></td>
                                        <td class="sx-proposal-table__num"><?= $money($row['price']); ?></td>
                                        <td class="sx-proposal-table__num"><?= $row['discount_amount'] > 0 ? $money($row['discount_amount']) : '—'; ?></td>
                                        <td class="sx-proposal-table__num"><strong><?= $money($row['amount']); ?></strong></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="sx-proposal-totals">
                        <?php if ((float)$version->discount_amount > 0) : ?>
                            <div><span>Сумма позиций</span><span><?= $money($version->subtotal_amount); ?></span></div>
                            <div><span>Скидка<?= $version->discount_value && str_contains((string)$version->discount_value, '%') ? ' '.Html::encode($version->discount_value) : ''; ?></span><span>−<?= $money($version->discount_amount); ?></span></div>
                        <?php endif; ?>
                        <div class="sx-proposal-totals__main"><span>Итого</span><span><?= $money($version->amount); ?></span></div>
                    </div>
                <?php else : ?>
                    <div class="sx-proposal-empty">
                        Позиций пока нет. <?= Html::a('Добавить позиции', $contentUrl); ?>
                    </div>
                <?php endif; ?>

                <?php if (trim(strip_tags((string)$version->terms, '<img>')) !== '') : ?>
                    <h3 class="sx-proposal-subtitle">Условия</h3>
                    <div class="sx-proposal-text"><?= $version->terms; ?></div>
                <?php endif; ?>

                <?php if ($lines = $version->getOurRequisitesLines()) : ?>
                    <div class="sx-proposal-requisites">
                        <?php foreach ($lines as $line) : ?><div><?= Html::encode($line); ?></div><?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        <?php BackendSurfaceWidget::end(); ?>
        <?php endif; ?>
    </main>
</div>
