<?php
/**
 * "Отправить КП" of a CRM card (AdminCmsProposalController::actionProposals):
 * the КП of this client that still wait for it and "Создать новое КП". A row
 * with content opens "Отправить клиенту" with this client; an empty draft
 * opens its items first. Then the steps go on in the same window.
 *
 * @var yii\web\View $this
 * @var \yii\db\ActiveRecord $record the lead, company, deal or client person
 * @var \skeeks\cms\proposal\models\CmsProposal[] $proposals
 * @var array $sent per proposal id: last (sent at), opened (first opened at)
 * @var string $createUrl a new КП of this client
 */

use skeeks\cms\backend\helpers\BackendUrlHelper;
use skeeks\cms\communication\helpers\MailUi;
use skeeks\cms\proposal\controllers\AdminCmsProposalController;
use skeeks\cms\proposal\helpers\ProposalUi;
use skeeks\cms\proposal\services\ProposalItems;
use skeeks\cms\proposal\services\ProposalSends;
use yii\helpers\Html;

$date = static fn($time) => \Yii::$app->formatter->asDate((int)$time, 'php:d.m.Y');
$clientName = $record instanceof \skeeks\cms\models\CmsUser
    ? (string)$record->shortDisplayName
    : ($record instanceof \skeeks\cms\models\CmsDeal ? (string)$record->asShortText : (string)$record->asText);
?>
<div class="sx-proposal-pick">
    <div class="sx-proposal-pick__head">
        <h1 class="sx-proposal-pick__title">Отправить КП</h1>
        <div class="sx-proposal-muted"><?= Html::encode('Кому: '.$clientName); ?></div>
    </div>

    <?php if ($proposals) : ?>
        <div class="sx-proposal-pick__list">
            <?php foreach ($proposals as $proposal) :
                $version = $proposal->currentVersion;
                $state = $sent[(int)$proposal->id] ?? null;
                // An empty draft needs its items first; everything else goes to the letter.
                $ready = ProposalSends::refusal($proposal) === null;
                $url = $ready
                    ? MailUi::sendUrl('proposal:'.(int)$proposal->id, $record)
                    : (string)BackendUrlHelper::createByParams([ProposalUi::ROUTE.'/content', 'pk' => (int)$proposal->id])->enableEmptyLayout()->url;
                $meta = array_filter([
                    $version ? ProposalItems::money($version->amount, (string)$proposal->currency_code) : '',
                    $proposal->valid_until ? 'до '.$date($proposal->valid_until) : '',
                    $ready ? '' : 'сначала заполните позиции',
                ]);
                ?>
                <?= Html::a(
                    Html::tag('span',
                        Html::tag('span', Html::encode($proposal->asText), ['class' => 'sx-proposal-pick__name'])
                        .Html::tag('span', Html::encode(implode(' · ', $meta)), ['class' => 'sx-proposal-pick__path']),
                        ['class' => 'sx-proposal-pick__body'])
                    .($state
                        ? Html::tag('span', Html::encode(!empty($state['opened']) ? 'Открывал '.$date($state['opened']) : 'Отправляли '.$date($state['last'])), [
                            'class' => 'sx-status '.(!empty($state['opened']) ? 'sx-status--accent' : 'sx-status--info'),
                            'title' => 'Это КП уже отправляли. Можно отправить ещё раз.',
                        ])
                        : AdminCmsProposalController::statusBadge($proposal)),
                    $url,
                    ['class' => 'sx-proposal-pick__item', 'data-pjax' => '0']
                ); ?>
            <?php endforeach; ?>
        </div>
    <?php else : ?>
        <p class="sx-proposal-muted">У этого клиента нет КП, которые ждут отправки.</p>
    <?php endif; ?>

    <div>
        <?= Html::a('+ Создать новое КП', $createUrl, ['class' => 'sx-button sx-button--'.($proposals ? 'secondary' : 'primary').' sx-button--sm', 'data-pjax' => '0']); ?>
    </div>
</div>
