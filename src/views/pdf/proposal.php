<?php
/**
 * PDF of a proposal version (ProposalPdf). mPDF understands a limited CSS:
 * tables for layout, no flex or grid. `$chunk` separates the WriteHTML() calls.
 *
 * @var \skeeks\cms\proposal\models\CmsProposalVersion $version
 * @var \skeeks\cms\proposal\models\CmsProposal $proposal
 * @var array $theme
 * @var string $chunk
 * @var string $intro prepared rich text
 * @var string $terms prepared rich text
 */

use skeeks\cms\proposal\services\ProposalItems;
use skeeks\cms\proposal\services\ProposalVersions;
use yii\helpers\Html;

$c = static fn(string $key, string $default = '') => Html::encode((string)($theme[$key] ?? $default));
$currency = (string)$version->currency_code;
// The PDF font has no ₽ sign: rubles are written as "руб.".
$money = static fn($value) => Html::encode(ProposalItems::money($value, $currency, false));
$groups = ProposalItems::groups(ProposalItems::normalize(ProposalVersions::contentOf($version)['items']));

$company = $proposal->getClientCompany();
$clientName = $company ? (string)$company->name : '';
if ($clientName === '' && $proposal->lead && $proposal->lead->client) {
    $clientName = (string)$proposal->lead->client->asText();
}
if ($clientName === '' && $proposal->cmsUser) {
    $clientName = $proposal->clientName;
}
$manager = $proposal->responsible;
// A version not sent yet is dated today: the PDF attached to the first sending is the one stored with it.
$date = (int)($version->sent_at ?: time());
?>
<style>
    body { font-family: dejavusans, sans-serif; font-size: 10pt; color: <?= $c('text_color', '#172033'); ?>; line-height: 1.45; }
    .muted { color: <?= $c('muted_color', '#667085'); ?>; }
    .accent { color: <?= $c('accent_color', '#e85f3c'); ?>; }
    h1 { font-size: 20pt; margin: 0 0 2mm; font-weight: bold; }
    h2 { font-size: 13pt; margin: 7mm 0 3mm; font-weight: bold; }
    h3, h4 { font-size: 11pt; margin: 4mm 0 2mm; font-weight: bold; }
    p { margin: 0 0 2.5mm; }
    ul, ol { margin: 0 0 2.5mm 5mm; padding: 0; }
    blockquote { margin: 0 0 3mm; padding: 1mm 0 1mm 4mm; border-left: 0.6mm solid <?= $c('accent_color', '#e85f3c'); ?>; color: <?= $c('muted_color', '#667085'); ?>; }
    a { color: <?= $c('accent_color', '#e85f3c'); ?>; }
    table.items { width: 100%; border-collapse: collapse; margin-top: 4mm; }
    table.items th { background-color: <?= $c('surface_color', '#ffffff'); ?>; color: <?= $c('muted_color', '#667085'); ?>; font-size: 8.5pt; font-weight: bold; text-align: left; padding: 2.2mm 2mm; border-bottom: 0.3mm solid <?= $c('border_color', '#d9e2ef'); ?>; }
    table.items td { padding: 2.2mm 2mm; border-bottom: 0.2mm solid <?= $c('border_color', '#d9e2ef'); ?>; vertical-align: top; }
    table.items td.num, table.items th.num { text-align: right; white-space: nowrap; }
    table.items tr.section td { background-color: <?= $c('surface_color', '#ffffff'); ?>; font-weight: bold; padding-top: 3mm; }
    .item-description { color: <?= $c('muted_color', '#667085'); ?>; font-size: 8.5pt; margin-top: 0.8mm; }
    table.totals { margin-top: 3mm; border-collapse: collapse; }
    table.totals td { padding: 1.2mm 0; }
    table.totals tr.main td { font-size: 13pt; font-weight: bold; padding-top: 2mm; border-top: 0.4mm solid <?= $c('accent_color', '#e85f3c'); ?>; }
    .card { background-color: <?= $c('surface_color', '#ffffff'); ?>; border: 0.2mm solid <?= $c('border_color', '#d9e2ef'); ?>; padding: 4mm 5mm; }
</style>

<table width="100%" cellspacing="0" cellpadding="0" style="border-collapse: collapse;">
    <tr>
        <td width="55%" style="vertical-align: middle;">
            <?php if (!empty($theme['logo_src'])) : ?>
                <img src="<?= Html::encode($theme['logo_src']); ?>" style="height: 12mm;">
            <?php else : ?>
                <span style="font-size: 14pt; font-weight: bold;"><?= $c('company_name'); ?></span>
            <?php endif; ?>
        </td>
        <td width="45%" align="right" class="muted" style="vertical-align: middle; font-size: 9pt;">
            <?= $c('company_name'); ?><br><?= $c('company_url'); ?>
        </td>
    </tr>
</table>

<div style="height: 1mm; background-color: <?= $c('accent_color', '#e85f3c'); ?>; margin: 6mm 0 8mm; width: 24mm;"></div>

<div class="muted" style="font-size: 9pt; text-transform: uppercase; letter-spacing: 0.4mm;">Коммерческое предложение № <?= (int)$proposal->number; ?></div>
<h1><?= Html::encode($proposal->name); ?></h1>
<table width="100%" cellspacing="0" cellpadding="0" class="muted" style="font-size: 9pt; margin-bottom: 6mm;">
    <tr>
        <td>
            <?php if ($clientName !== '') : ?>Для: <b style="color: <?= $c('text_color'); ?>;"><?= Html::encode($clientName); ?></b><br><?php endif; ?>
            от <?= Html::encode(\Yii::$app->formatter->asDate($date, 'php:d.m.Y')); ?>
            <?php if ($proposal->valid_until) : ?> · действует до <?= Html::encode(\Yii::$app->formatter->asDate((int)$proposal->valid_until, 'php:d.m.Y')); ?><?php endif; ?>
            <?php if ((int)$version->version > 1) : ?> · версия <?= (int)$version->version; ?><?php endif; ?>
        </td>
    </tr>
</table>
<?= $chunk; ?>

<?php if ($intro !== '') : ?>
    <div class="intro"><?= $intro; ?></div>
    <?= $chunk; ?>
<?php endif; ?>

<?php if ($groups) : ?>
    <h2>Стоимость</h2>
    <table class="items">
        <thead>
        <tr>
            <th width="6%">№</th>
            <th>Наименование</th>
            <th class="num" width="13%">Кол-во</th>
            <th class="num" width="15%">Цена</th>
            <th class="num" width="13%">Скидка</th>
            <th class="num" width="16%">Сумма</th>
        </tr>
        </thead>
        <tbody>
        <?php $n = 0; foreach ($groups as $group) : ?>
            <?php if ($group['name'] !== '') : ?>
                <tr class="section">
                    <td colspan="5"><?= Html::encode($group['name']); ?></td>
                    <td class="num"><?= $money($group['amount']); ?></td>
                </tr>
            <?php endif; ?>
            <?php foreach ($group['rows'] as $row) : $n++; ?>
                <tr>
                    <td class="muted"><?= $n; ?></td>
                    <td>
                        <?= Html::encode($row['name']); ?>
                        <?php if ($row['description'] !== '') : ?>
                            <div class="item-description"><?= nl2br(Html::encode($row['description'])); ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="num"><?= Html::encode(ProposalItems::quantity($row['quantity']).' '.$row['measure_name']); ?></td>
                    <td class="num"><?= $money($row['price']); ?></td>
                    <td class="num"><?= $row['discount_amount'] > 0 ? $money($row['discount_amount']) : '—'; ?></td>
                    <td class="num"><b><?= $money($row['amount']); ?></b></td>
                </tr>
            <?php endforeach; ?>
        <?php endforeach; ?>
        </tbody>
    </table>

    <table width="100%" cellspacing="0" cellpadding="0" style="border-collapse: collapse;"><tr><td width="55%"></td><td width="45%">
        <table class="totals" width="100%">
            <?php if ((float)$version->discount_amount > 0) : ?>
                <tr><td class="muted">Сумма позиций</td><td align="right"><?= $money($version->subtotal_amount); ?></td></tr>
                <tr><td class="muted">Скидка<?= str_contains((string)$version->discount_value, '%') ? ' '.Html::encode($version->discount_value) : ''; ?></td><td align="right">−<?= $money($version->discount_amount); ?></td></tr>
            <?php endif; ?>
            <tr class="main"><td>Итого</td><td align="right"><?= $money($version->amount); ?></td></tr>
        </table>
    </td></tr></table>
    <?php
    $vat = array_unique(array_filter(array_map(static fn($row) => $row['vat_name'], array_merge(...array_column($groups, 'rows')))));
    if (count($vat) === 1) : ?>
        <div class="muted" style="text-align: right; font-size: 8.5pt; margin-top: 1mm;"><?= Html::encode(reset($vat)); ?></div>
    <?php endif; ?>
    <?= $chunk; ?>
<?php endif; ?>

<?php if ($terms !== '') : ?>
    <h2>Условия</h2>
    <?= $chunk; ?>
    <div class="terms"><?= $terms; ?></div>
    <?= $chunk; ?>
<?php endif; ?>

<?php $requisites = $version->getOurRequisitesLines(); ?>
<?php if ($requisites || $manager) : ?>
    <table width="100%" cellspacing="0" cellpadding="0" style="margin-top: 8mm; border-collapse: collapse;" autosize="1">
        <tr>
            <?php if ($manager) : ?>
                <td width="50%" class="card" style="vertical-align: top;">
                    <div class="muted" style="font-size: 8.5pt; margin-bottom: 1mm;">Ваш менеджер</div>
                    <b><?= Html::encode($manager->shortDisplayName); ?></b>
                    <?php if ($manager->phone) : ?><br><?= Html::encode($manager->phone); ?><?php endif; ?>
                    <?php if ($manager->email) : ?><br><?= Html::encode($manager->email); ?><?php endif; ?>
                </td>
                <td width="4%"></td>
            <?php endif; ?>
            <?php if ($requisites) : ?>
                <td class="card" style="vertical-align: top; font-size: 8.5pt;">
                    <div class="muted" style="margin-bottom: 1mm;">Реквизиты</div>
                    <?= implode('<br>', array_map([Html::class, 'encode'], $requisites)); ?>
                </td>
            <?php endif; ?>
        </tr>
    </table>
<?php endif; ?>
