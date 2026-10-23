<?php
/**
 * "Отправить презентацию" of a CRM card (AdminCmsProposalController::actionPresentations):
 * the ready presentations for everybody, most sent first; a row opens
 * "Отправить клиенту" of it with this client in the same window. A
 * presentation this client already got is marked; sending it again is allowed.
 *
 * @var yii\web\View $this
 * @var \yii\db\ActiveRecord $record the lead, company, deal or client person
 * @var \skeeks\cms\proposal\models\CmsProposal[] $presentations
 * @var array $sent per presentation id: last (sent at), opened (first opened at)
 * @var string $createUrl a new presentation for everybody
 */

use skeeks\cms\communication\helpers\MailUi;
use skeeks\cms\proposal\controllers\AdminCmsProposalController;
use skeeks\cms\proposal\models\CmsProposal;
use yii\helpers\Html;

$date = static fn($time) => \Yii::$app->formatter->asDate((int)$time, 'php:d.m.Y');
$clientName = $record instanceof \skeeks\cms\models\CmsUser
    ? (string)$record->shortDisplayName
    : ($record instanceof \skeeks\cms\models\CmsDeal ? (string)$record->asShortText : (string)$record->asText);
// A search field once the list stops fitting at a glance.
$searchFrom = 7;
?>
<div class="sx-proposal-pick">
    <div class="sx-proposal-pick__head">
        <h1 class="sx-proposal-pick__title">Отправить презентацию</h1>
        <div class="sx-proposal-muted"><?= Html::encode('Кому: '.$clientName); ?></div>
    </div>

    <?php if (!$presentations) : ?>
        <div class="sx-proposal-empty">
            <p class="sx-proposal-pick__empty-text">Готовых презентаций пока нет. Создайте общую презентацию один раз — страницу сайта и короткий текст, — и её можно будет отправлять любому клиенту в два щелчка.</p>
            <?= Html::a('Создать общую презентацию', $createUrl, ['class' => 'sx-button sx-button--primary sx-button--sm', 'data-pjax' => '0']); ?>
        </div>
    <?php else : ?>
        <?php if (count($presentations) > $searchFrom) : ?>
            <input type="search" class="form-control sx-proposal-pick__search" placeholder="Найти презентацию" autocomplete="off" data-sx-pick-search aria-label="Найти презентацию">
        <?php endif; ?>
        <div class="sx-proposal-pick__list" data-sx-pick-list>
            <?php foreach ($presentations as $presentation) :
                $state = $sent[(int)$presentation->id] ?? null;
                $path = AdminCmsProposalController::targetText($presentation);
                ?>
                <?= Html::a(
                    Html::tag('span',
                        Html::tag('span', Html::encode((string)$presentation->name), ['class' => 'sx-proposal-pick__name'])
                        .Html::tag('span', Html::encode($path), ['class' => 'sx-proposal-pick__path']),
                        ['class' => 'sx-proposal-pick__body'])
                    .($state
                        ? Html::tag('span', Html::encode(!empty($state['opened'])
                            ? 'Открывал '.$date($state['opened'])
                            : 'Отправляли '.$date($state['last'])), [
                            'class' => 'sx-status '.(!empty($state['opened']) ? 'sx-status--accent' : 'sx-status--info'),
                            'title' => 'Этому клиенту уже отправляли эту презентацию'.(!empty($state['last']) ? ' (последний раз '.$date($state['last']).')' : '').'. Можно отправить ещё раз.',
                        ])
                        : ''),
                    MailUi::sendUrl('proposal:'.(int)$presentation->id, $record),
                    [
                        'class' => 'sx-proposal-pick__item'.($state ? ' is-sent' : ''),
                        'data-pjax' => '0',
                        'data-sx-pick-text' => mb_strtolower((string)$presentation->name.' '.$path),
                    ]
                ); ?>
            <?php endforeach; ?>
            <div class="sx-proposal-muted sx-proposal-pick__none" data-sx-pick-none hidden>Ничего не нашлось.</div>
        </div>
        <div class="sx-proposal-pick__foot">
            <?= Html::a('Создать общую презентацию', $createUrl, ['data-pjax' => '0']); ?>
        </div>
    <?php endif; ?>
</div>
<?php
if (count($presentations) > $searchFrom) {
    $this->registerJs(<<<JS
(function () {
    var input = document.querySelector('[data-sx-pick-search]');
    var none = document.querySelector('[data-sx-pick-none]');
    var items = Array.prototype.slice.call(document.querySelectorAll('[data-sx-pick-text]'));
    input.focus();
    input.addEventListener('input', function () {
        var q = input.value.trim().toLowerCase();
        var shown = 0;
        items.forEach(function (item) {
            var ok = q === '' || item.getAttribute('data-sx-pick-text').indexOf(q) !== -1;
            item.hidden = !ok;
            shown += ok ? 1 : 0;
        });
        none.hidden = shown > 0;
    });
})();
JS
    );
}
