<?php
/**
 * The transition page of a presentation (ProposalController::actionView):
 * the script counts the view by the signal of the shown page and then
 * leads the client to the page of the presentation. Without scripts there
 * is no transition and no view: only the link "Перейти". The address is
 * the one stored in the proposal, never one from the link.
 *
 * @var yii\web\View $this
 * @var \skeeks\cms\proposal\models\CmsProposalSend $send
 * @var \skeeks\cms\proposal\models\CmsProposal $proposal
 * @var string $targetUrl the page with marks for Metrika
 * @var bool $isExpired
 * @var array $design
 * @var bool $isStaff an employee signed in to the CRM: the view is not counted
 */

use yii\helpers\Html;
use yii\helpers\Json;
use yii\helpers\Url;

$manager = $proposal->responsible;
$host = (string)parse_url($targetUrl, PHP_URL_HOST);

$this->params['kpStyle'] = ':root{--kp-accent:'.$design['accent'].';--kp-on-accent:'.$design['onAccent']
    .($design['headerBg'] ? ';--kp-header-bg:'.$design['headerBg'].';--kp-header-text:'.$design['headerText'] : '').'}';
if (!$isExpired) {
    $this->params['kpScript'] = 'window.sxPresentationPage && window.sxPresentationPage('.Json::encode([
        'seen'   => Url::to(['/cmsProposal/proposal/seen', 'code' => (string)$send->code]),
        'target' => $targetUrl,
        'staff'  => $isStaff,
    ]).');';
}
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
    </div>
</header>
<div class="sx-kp">
    <main class="sx-kp-main sx-kp-go">
        <?php if ($isExpired) : ?>
            <div class="sx-kp-card sx-kp-go__card">
                <h1 class="sx-kp-go__title">Ссылка больше не действует</h1>
                <p class="sx-kp-go__text">Срок действия этой ссылки истёк. Свяжитесь с менеджером, и он пришлёт новую.</p>
                <?php if ($manager) : ?>
                    <p class="sx-kp-go__text"><?= Html::encode($manager->shortDisplayName); ?>
                        <?php if ($manager->phone) : ?> · <?= Html::a(Html::encode($manager->phone), 'tel:'.preg_replace('/[^\d+]/', '', (string)$manager->phone)); ?><?php endif; ?>
                        <?php if ($manager->email) : ?> · <?= Html::a(Html::encode($manager->email), 'mailto:'.$manager->email); ?><?php endif; ?>
                    </p>
                <?php endif; ?>
            </div>
        <?php else : ?>
            <?php if ($isStaff) : ?>
                <div class="sx-kp-notice sx-kp-notice--muted" data-sx-kp-go-staff>
                    <div><strong>Вы вошли в CRM как сотрудник.</strong> Ваш переход не засчитывается и не меняет статус презентации.
                        Чтобы проверить учёт, откройте ссылку в окне инкогнито.</div>
                </div>
            <?php endif; ?>
            <div class="sx-kp-card sx-kp-go__card">
                <div class="sx-kp-go__spinner" aria-hidden="true" data-sx-kp-go-spinner hidden></div>
                <h1 class="sx-kp-go__title"><?= Html::encode(trim((string)$proposal->name) !== '' ? (string)$proposal->name : 'Открываем страницу'); ?></h1>
                <p class="sx-kp-go__text" data-sx-kp-go-status>Чтобы открыть страницу, нажмите «Перейти».</p>
                <p class="sx-kp-go__actions">
                    <?= Html::a('Перейти', $targetUrl, [
                        'class' => 'sx-kp-button sx-kp-button--primary',
                        'rel' => 'nofollow noopener noreferrer',
                        'data-sx-kp-go-link' => true,
                    ]); ?>
                </p>
                <?php if ($host !== '') : ?>
                    <p class="sx-kp-go__host"><?= Html::encode($host); ?></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </main>
</div>
