<?php
/**
 * The client page of a proposal (ProposalController::actionView): the
 * version of the link, notices (a newer version, expired, accepted), items
 * and totals, terms, "Принять", "Задать вопрос", "Скачать PDF", the manager
 * and our requisites.
 *
 * @var yii\web\View $this
 * @var \skeeks\cms\proposal\models\CmsProposalSend $send
 * @var \skeeks\cms\proposal\models\CmsProposal $proposal
 * @var \skeeks\cms\proposal\models\CmsProposalVersion $version
 * @var bool $hasNewer
 * @var bool $isLatest
 * @var string|null $acceptRefusal
 * @var array $design
 * @var bool $isStaff an employee signed in to the CRM: the view is not counted
 */

use skeeks\cms\proposal\models\CmsProposal;
use skeeks\cms\proposal\models\CmsProposalQuestion;
use skeeks\cms\proposal\services\ProposalItems;
use skeeks\cms\proposal\services\ProposalVersions;
use yii\helpers\Html;
use yii\helpers\Json;
use yii\helpers\Url;

$currency = (string)$version->currency_code;
$money = static fn($value) => Html::encode(ProposalItems::money($value, $currency));
$date = static fn($time) => Html::encode(\Yii::$app->formatter->asDate((int)$time, 'php:d.m.Y'));
$groups = ProposalItems::groups(ProposalItems::normalize(ProposalVersions::contentOf($version)['items']));
$code = (string)$send->code;
// The newest version opened by ?v=latest: the buttons act on it too.
$latestParam = (int)$send->cms_proposal_version_id !== (int)$version->id ? ['v' => 'latest'] : [];
$url = static fn(string $action) => Url::to(array_merge(['/cmsProposal/proposal/'.$action, 'code' => $code], $latestParam));

$company = $proposal->getClientCompany();
$clientName = $company ? (string)$company->name : '';
if ($clientName === '' && $proposal->lead && $proposal->lead->client) {
    $clientName = (string)$proposal->lead->client->asText();
}
if ($clientName === '' && $proposal->cmsUser) {
    $clientName = $proposal->clientName;
}
$manager = $proposal->responsible;
$accepted = $proposal->status === CmsProposal::STATUS_ACCEPTED;
$acceptedThis = $accepted && (int)$proposal->accepted_version_id === (int)$version->id;
$canAccept = !$accepted && $acceptRefusal === null;

// Printed by the layout: the page has no view events (see layout.php). Colors are validated #rrggbb.
$this->params['kpStyle'] = ':root{--kp-accent:'.$design['accent'].';--kp-on-accent:'.$design['onAccent']
    .($design['headerBg'] ? ';--kp-header-bg:'.$design['headerBg'].';--kp-header-text:'.$design['headerText'] : '').'}';
$this->params['kpScript'] = 'window.sxProposalPage && window.sxProposalPage('.Json::encode([
    'seen'   => $url('seen'),
    'accept' => $url('accept'),
    'ask'    => $url('ask'),
]).');';
?>
<header class="sx-kp-header<?= $design['headerBg'] ? ' sx-kp-header--band' : ''; ?>">
    <div class="sx-kp-header__inner">
        <div class="sx-kp-header__brand">
            <?php if ($design['logo']) : ?>
                <img class="sx-kp-header__logo" src="<?= Html::encode($design['logo']); ?>" alt="<?= Html::encode($design['siteName']); ?>">
            <?php else : ?>
                <span class="sx-kp-header__name"><?= Html::encode($design['siteName']); ?></span>
            <?php endif; ?>
        </div>
        <div class="sx-kp-header__doc">Коммерческое предложение №&nbsp;<?= (int)$proposal->number; ?></div>
    </div>
</header>
<div class="sx-kp">

    <main class="sx-kp-main">
        <?php if ($isStaff) : ?>
            <div class="sx-kp-notice sx-kp-notice--muted">
                <div><strong>Вы вошли в CRM как сотрудник.</strong> Так клиент видит предложение; ваш просмотр не засчитывается и не меняет статус КП.
                    Чтобы проверить учёт просмотров, откройте ссылку в окне инкогнито.</div>
            </div>
        <?php endif; ?>
        <?php if ($hasNewer) : ?>
            <div class="sx-kp-notice sx-kp-notice--info">
                <div>
                    <strong>Есть обновлённое предложение.</strong>
                    Вы смотрите версию <?= (int)$version->version; ?>, менеджер отправил более новую.
                </div>
                <?= Html::a('Открыть обновлённое', Url::to(['/cmsProposal/proposal/view', 'code' => $code, 'v' => 'latest']), ['class' => 'sx-kp-button sx-kp-button--small']); ?>
            </div>
        <?php endif; ?>

        <?php if ($accepted) : ?>
            <div class="sx-kp-notice sx-kp-notice--success" data-sx-kp-accepted>
                <strong>Предложение принято<?= $proposal->accepted_at ? ' '.$date($proposal->accepted_at) : ''; ?>.</strong>
                <?php if (!$acceptedThis && $proposal->acceptedVersion) : ?>Принята версия <?= (int)$proposal->acceptedVersion->version; ?>.<?php endif; ?>
                Менеджер свяжется с вами, чтобы обсудить дальнейшие шаги.
            </div>
        <?php elseif ($proposal->status === CmsProposal::STATUS_REJECTED) : ?>
            <div class="sx-kp-notice sx-kp-notice--muted"><strong>Предложение отклонено.</strong> Если оно снова актуально, свяжитесь с менеджером.</div>
        <?php elseif ($proposal->isExpired) : ?>
            <div class="sx-kp-notice sx-kp-notice--warning">
                <strong>Предложение истекло<?= $proposal->valid_until ? ' '.$date($proposal->valid_until) : ''; ?>.</strong>
                Свяжитесь с менеджером: он подготовит актуальное предложение.
            </div>
        <?php endif; ?>
        <div class="sx-kp-notice sx-kp-notice--success" data-sx-kp-accepted-now hidden></div>

        <section class="sx-kp-title">
            <h1><?= Html::encode($proposal->name); ?></h1>
            <div class="sx-kp-title__meta">
                <?php if ($clientName !== '') : ?><span>Для: <strong><?= Html::encode($clientName); ?></strong></span><?php endif; ?>
                <span>от <?= $date($version->sent_at ?: $version->created_at); ?></span>
                <?php if ($proposal->valid_until) : ?><span>действует до <?= $date($proposal->valid_until); ?></span><?php endif; ?>
                <?php if ((int)$version->version > 1) : ?><span>версия <?= (int)$version->version; ?></span><?php endif; ?>
            </div>
        </section>

        <?php if (trim(strip_tags((string)$version->intro, '<img>')) !== '') : ?>
            <section class="sx-kp-text"><?= $version->intro; ?></section>
        <?php endif; ?>

        <?php if ($groups) : ?>
            <section class="sx-kp-card">
                <h2>Стоимость</h2>
                <table class="sx-kp-items">
                    <thead>
                    <tr>
                        <th class="sx-kp-items__n">№</th>
                        <th>Наименование</th>
                        <th class="sx-kp-items__num">Кол-во</th>
                        <th class="sx-kp-items__num">Цена</th>
                        <th class="sx-kp-items__num">Скидка</th>
                        <th class="sx-kp-items__num">Сумма</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php $n = 0; foreach ($groups as $group) : ?>
                        <?php if ($group['name'] !== '') : ?>
                            <tr class="sx-kp-items__section">
                                <td colspan="5"><?= Html::encode($group['name']); ?></td>
                                <td class="sx-kp-items__num"><?= $money($group['amount']); ?></td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($group['rows'] as $row) : $n++; ?>
                            <tr class="sx-kp-items__row">
                                <td class="sx-kp-items__n"><?= $n; ?></td>
                                <td class="sx-kp-items__name">
                                    <div><?= Html::encode($row['name']); ?></div>
                                    <?php if ($row['description'] !== '') : ?>
                                        <div class="sx-kp-items__description"><?= nl2br(Html::encode($row['description'])); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="sx-kp-items__num" data-label="Кол-во"><?= Html::encode(ProposalItems::quantity($row['quantity']).' '.$row['measure_name']); ?></td>
                                <td class="sx-kp-items__num" data-label="Цена"><?= $money($row['price']); ?></td>
                                <td class="sx-kp-items__num<?= $row['discount_amount'] > 0 ? '' : ' sx-kp-items__empty'; ?>" data-label="Скидка"><?= $row['discount_amount'] > 0 ? $money($row['discount_amount']) : '—'; ?></td>
                                <td class="sx-kp-items__num sx-kp-items__amount" data-label="Сумма"><?= $money($row['amount']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="sx-kp-totals">
                    <?php if ((float)$version->discount_amount > 0) : ?>
                        <div><span>Сумма позиций</span><span><?= $money($version->subtotal_amount); ?></span></div>
                        <div><span>Скидка<?= str_contains((string)$version->discount_value, '%') ? ' '.Html::encode($version->discount_value) : ''; ?></span><span>−<?= $money($version->discount_amount); ?></span></div>
                    <?php endif; ?>
                    <div class="sx-kp-totals__main"><span>Итого</span><span><?= $money($version->amount); ?></span></div>
                    <?php
                    $vat = array_unique(array_filter(array_map(static fn($row) => $row['vat_name'], array_merge(...array_column($groups, 'rows')))));
                    if (count($vat) === 1) : ?>
                        <div class="sx-kp-totals__vat"><?= Html::encode(reset($vat)); ?></div>
                    <?php endif; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if (trim(strip_tags((string)$version->terms, '<img>')) !== '') : ?>
            <section class="sx-kp-card">
                <h2>Условия</h2>
                <div class="sx-kp-text"><?= $version->terms; ?></div>
            </section>
        <?php endif; ?>

        <section class="sx-kp-actions" data-sx-kp-actions>
            <?php if ($canAccept) : ?>
                <button type="button" class="sx-kp-button sx-kp-button--primary" data-sx-kp-accept>Принять предложение</button>
            <?php endif; ?>
            <button type="button" class="sx-kp-button<?= $canAccept ? '' : ' sx-kp-button--primary'; ?>" data-sx-kp-ask-open>Задать вопрос</button>
            <?= Html::a('Скачать PDF', $url('pdf'), ['class' => 'sx-kp-button sx-kp-button--ghost', 'rel' => 'nofollow']); ?>
        </section>

        <section class="sx-kp-card sx-kp-ask" data-sx-kp-ask hidden>
            <h2>Вопрос менеджеру</h2>
            <form data-sx-kp-ask-form novalidate>
                <label class="sx-kp-field">
                    <span>Ваш вопрос</span>
                    <textarea name="text" rows="4" maxlength="<?= CmsProposalQuestion::MAX_TEXT; ?>" required></textarea>
                </label>
                <div class="sx-kp-fields">
                    <label class="sx-kp-field">
                        <span>Как к вам обращаться</span>
                        <input type="text" name="name" maxlength="255" autocomplete="name">
                    </label>
                    <label class="sx-kp-field">
                        <span>Как с вами связаться</span>
                        <input type="text" name="contact" maxlength="255" autocomplete="tel" placeholder="Телефон или email">
                    </label>
                </div>
                <label class="sx-kp-trap" aria-hidden="true">Сайт <input type="text" name="website" tabindex="-1" autocomplete="off"></label>
                <div class="sx-kp-form__error" data-sx-kp-ask-error hidden></div>
                <div class="sx-kp-form__actions">
                    <button type="submit" class="sx-kp-button sx-kp-button--primary">Отправить вопрос</button>
                    <button type="button" class="sx-kp-button sx-kp-button--ghost" data-sx-kp-ask-close>Отмена</button>
                </div>
            </form>
            <div class="sx-kp-notice sx-kp-notice--success" data-sx-kp-ask-done hidden></div>
        </section>

        <?php $requisites = $version->getOurRequisitesLines(); ?>
        <?php if ($manager || $requisites) : ?>
            <section class="sx-kp-contacts">
                <?php if ($manager) : ?>
                    <div class="sx-kp-card sx-kp-contact">
                        <div class="sx-kp-contact__label">Ваш менеджер</div>
                        <div class="sx-kp-contact__name"><?= Html::encode($manager->shortDisplayName); ?></div>
                        <?php if ($manager->phone) : ?><div><?= Html::a(Html::encode($manager->phone), 'tel:'.preg_replace('/[^\d+]/', '', (string)$manager->phone)); ?></div><?php endif; ?>
                        <?php if ($manager->email) : ?><div><?= Html::a(Html::encode($manager->email), 'mailto:'.$manager->email); ?></div><?php endif; ?>
                    </div>
                <?php endif; ?>
                <?php if ($requisites) : ?>
                    <div class="sx-kp-card sx-kp-contact sx-kp-contact--requisites">
                        <div class="sx-kp-contact__label">Реквизиты</div>
                        <?php foreach ($requisites as $line) : ?><div><?= Html::encode($line); ?></div><?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </main>

    <footer class="sx-kp-footer">
        <?= Html::encode(trim($design['siteName'].' · '.preg_replace('~^https?://~', '', rtrim($design['siteUrl'], '/')), ' ·')); ?>
    </footer>
</div>

<?php if ($canAccept) : ?>
    <dialog class="sx-kp-dialog" data-sx-kp-accept-dialog>
        <form method="dialog">
            <h2>Принять предложение?</h2>
            <p>«<?= Html::encode($proposal->name); ?>», итого <strong><?= $money($version->amount); ?></strong>. Менеджер получит уведомление и свяжется с вами.</p>
            <div class="sx-kp-form__error" data-sx-kp-accept-error hidden></div>
            <div class="sx-kp-form__actions">
                <button type="button" class="sx-kp-button sx-kp-button--primary" data-sx-kp-accept-confirm>Да, принимаю</button>
                <button value="cancel" class="sx-kp-button sx-kp-button--ghost">Отмена</button>
            </div>
        </form>
    </dialog>
<?php endif; ?>
