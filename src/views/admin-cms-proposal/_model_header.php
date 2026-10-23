<?php
/**
 * Header of a proposal card: number and name, status, client, sum, validity;
 * "Скачать PDF" and "Новая версия".
 *
 * @var yii\web\View $this
 * @var \skeeks\cms\proposal\models\CmsProposal $model
 */

use skeeks\cms\backend\widgets\BackendModelHeader;
use skeeks\cms\proposal\controllers\AdminCmsProposalController;
use skeeks\cms\proposal\helpers\ProposalUi;
use skeeks\cms\proposal\services\ProposalItems;
use yii\helpers\Html;

$version = $model->isPresentation ? null : $model->currentVersion;
// Every item is an element: the header spaces its children.
$meta = [
    $model->isShared
        ? Html::tag('span', 'Общая презентация: клиент у каждой отправки', ['class' => 'sx-proposal-header-client'])
        : Html::tag('span', Html::encode($model->clientTypeName.': ').AdminCmsProposalController::clientLink($model, false), ['class' => 'sx-proposal-header-client']),
];
if ($model->isPresentation && $model->target_url) {
    $meta[] = Html::a(Html::encode(AdminCmsProposalController::targetText($model)), (string)$model->target_url,
        ['target' => '_blank', 'rel' => 'noopener noreferrer', 'data-pjax' => '0', 'style' => 'overflow-wrap:anywhere']);
}
if ($version) {
    $meta[] = Html::tag('span', Html::encode('Итого '.ProposalItems::money($version->amount, (string)$model->currency_code)), ['style' => 'font-weight:600']);
    $meta[] = Html::tag('span', Html::encode('Версия '.$version->version));
}
if ($model->valid_until) {
    $meta[] = Html::tag('span', Html::encode('до '.\Yii::$app->formatter->asDate((int)$model->valid_until, 'php:d.m.Y')));
}

echo BackendModelHeader::widget([
    'model'             => $model,
    'title'             => $model->asText,
    'renderDefaultMeta' => false,
    'metaItems'         => $meta,
    'status'            => AdminCmsProposalController::statusBadge($model),
    'toolbar'           => ProposalUi::toolbar($model),
]);
