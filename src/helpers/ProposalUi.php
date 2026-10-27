<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\proposal\helpers;

use skeeks\cms\backend\helpers\BackendUrlHelper;
use skeeks\cms\backend\widgets\BackendEntityLink;
use skeeks\cms\backend\widgets\BackendSurfaceWidget;
use skeeks\cms\assets\admin\CrmContactsAsset;
use skeeks\cms\communication\helpers\MailUi;
use skeeks\cms\proposal\controllers\AdminCmsProposalController;
use skeeks\cms\proposal\models\CmsProposal;
use skeeks\cms\proposal\send\ProposalSendableSource;
use skeeks\cms\proposal\services\ProposalItems;
use skeeks\cms\proposal\source\ProposalClientSource;
use skeeks\cms\source\RecordSources;
use skeeks\cms\widgets\admin\CrmContactBlockWidget;
use yii\helpers\Html;
use yii\helpers\Json;
use yii\helpers\Url;

/**
 * Pieces of the CRM interface of proposals shared by the card, the header
 * and the "КП" block of lead, company and deal cards.
 */
class ProposalUi
{
    public const ROUTE = '/cmsProposal/admin-cms-proposal';

    /** How many proposals the block of a CRM card lists before "Все предложения". */
    public const CARD_LIMIT = 5;

    /** "Отправить клиенту", "Скачать PDF" and "Новая версия" of the card header. */
    public static function toolbar(CmsProposal $model): string
    {
        $buttons = Html::button('Отправить клиенту', [
            'class' => 'sx-button sx-button--primary sx-button--sm',
            'onclick' => self::sendOnClick($model, $model->client),
            'title' => 'Письмом или СМС: у каждой отправки своя ссылка, видно, когда клиент её открыл',
        ]);
        // A presentation has no PDF and no versions: it is a page of a site.
        if ($model->isPresentation) {
            if ($model->target_url) {
                $buttons .= Html::a('Открыть страницу', (string)$model->target_url, [
                    'class' => 'sx-button sx-button--secondary sx-button--sm',
                    'data-pjax' => '0',
                    'target' => '_blank',
                    'rel' => 'noopener noreferrer',
                ]);
            }

            return Html::tag('div', $buttons, ['class' => 'sx-proposal-toolbar']);
        }
        if ($model->currentVersion) {
            $buttons .= Html::a('Скачать PDF', Url::to([self::ROUTE.'/pdf', 'pk' => (int)$model->id]), [
                'class' => 'sx-button sx-button--secondary sx-button--sm',
                'data-pjax' => '0',
                'target' => '_blank',
                'rel' => 'noopener',
            ]);
        }
        $buttons .= Html::button('Новая версия', [
            'class' => 'sx-button sx-button--secondary sx-button--sm',
            'onclick' => self::windowOnClick([self::ROUTE.'/new-version', 'pk' => (int)$model->id]),
            'title' => 'Копия текущей версии: старая останется в истории',
        ]);

        return Html::tag('div', $buttons, ['class' => 'sx-proposal-toolbar']);
    }

    /**
     * Opens a backend window. When it is saved, the standard Action refreshes
     * the page that opened it.
     */
    public static function windowOnClick(array $route): string
    {
        return self::openOnClick((string)BackendUrlHelper::createByParams($route)->enableEmptyLayout()->url);
    }

    /**
     * Sendings (with a message) to the client of a card, as a condition on
     * cms_proposal_send: a lead, deal or client person; a company with its
     * leads and deals.
     */
    public static function recordSendCondition($record): array
    {
        $client = ['cms_lead_id' => \skeeks\cms\models\CmsLead::class, 'cms_deal_id' => \skeeks\cms\models\CmsDeal::class,
            'cms_company_id' => \skeeks\cms\models\CmsCompany::class, 'cms_user_id' => \skeeks\cms\models\CmsUser::class];
        $condition = ['0=1'];
        foreach ($client as $column => $class) {
            if ($record instanceof $class) {
                $condition = [$column => (int)$record->id];
            }
        }
        if ($record instanceof \skeeks\cms\models\CmsCompany) {
            $condition = ['or', $condition,
                ['cms_lead_id' => \skeeks\cms\models\CmsLead::find()->select('id')->andWhere(['cms_company_id' => (int)$record->id])],
                ['cms_deal_id' => \skeeks\cms\models\CmsDeal::find()->select('id')->andWhere(['cms_company_id' => (int)$record->id])]];
        }

        return ['and', ['not', ['cms_comm_message_id' => null]], $condition];
    }

    /** "Открыто" or "Отправлено" of the sendings of a presentation for everybody to the client of a card. */
    public static function sentBadge(CmsProposal $proposal, $record): string
    {
        $opened = \skeeks\cms\proposal\models\CmsProposalSend::find()
            ->andWhere(['cms_proposal_id' => (int)$proposal->id])
            ->andWhere(self::recordSendCondition($record))->andWhere(['not', ['first_viewed_at' => null]])->exists();

        return $opened
            ? Html::tag('span', 'Открыто', ['class' => 'sx-status sx-status--accent'])
            : Html::tag('span', 'Отправлено', ['class' => 'sx-status sx-status--info']);
    }

    /** "Chrome · Android" from a User-Agent, for the view history. */
    public static function browserName(string $userAgent): string
    {
        if (trim($userAgent) === '') {
            return 'Браузер не указан';
        }
        $browser = '';
        foreach (['YaBrowser' => 'Яндекс Браузер', 'Edg/' => 'Edge', 'OPR/' => 'Opera', 'Firefox/' => 'Firefox', 'Chrome/' => 'Chrome', 'Safari/' => 'Safari'] as $needle => $name) {
            if (stripos($userAgent, $needle) !== false) {
                $browser = $name;
                break;
            }
        }
        $system = '';
        foreach (['Android' => 'Android', 'iPhone' => 'iPhone', 'iPad' => 'iPad', 'Windows' => 'Windows', 'Mac OS X' => 'macOS', 'Linux' => 'Linux'] as $needle => $name) {
            if (stripos($userAgent, $needle) !== false) {
                $system = $name;
                break;
            }
        }
        $result = implode(' · ', array_filter([$browser, $system]));

        return $result !== '' ? $result : mb_substr($userAgent, 0, 60);
    }

    /**
     * `clientCallback` of the ajax form of a proposal window that goes on
     * to the next step instead of closing: the standard ActiveFormAjaxBackend
     * closes a create window a second after saving. After a create the
     * window opens `afterSaveUrl` (the items of a КП, the sending of a
     * presentation); after "Сохранить и отправить клиенту" (submit-btn=send)
     * of the content, the send window. The card that opened the window is
     * still refreshed when it closes.
     *
     * @param string $type "create" or "update"
     */
    public static function nextStepFormCallback(string $type): \yii\web\JsExpression
    {
        $isCreate = Json::encode($type === 'create');

        return new \yii\web\JsExpression(<<<JS
function (form) {
    form.on('success', function (e, response) {
        var data = (response && response.data) || {};
        $('.sx-buttons-standart .sx-success-meessage', form.jForm).empty().append(response.message);
        form.jForm.removeClass('sx-form-data-changed');
        var goOn = data.afterSaveUrl && ({$isCreate} || data.submitBtn === 'send');
        sx.Window.openerWidgetTriggerEvent({$isCreate} ? 'model-create' : 'model-update', {'submitBtn': goOn ? 'apply' : (data.submitBtn || 'apply')});
        if (goOn) {
            window.location.href = data.afterSaveUrl;
        }
    });
    form.on('error', function (e, response) {
        response = response || {};
        var messages = [];
        $.each((response.data && response.data.validation) || {}, function (attribute, errors) {
            $.each(Array.isArray(errors) ? errors : [errors], function (i, error) {
                if (typeof error === 'string' && error.trim() && messages.indexOf(error.trim()) === -1) {
                    messages.push(error.trim());
                }
            });
        });
        var message = messages.length ? messages.join(' ') : response.message;
        message = typeof message === 'string' && message.trim()
            ? 'Не удалось сохранить. ' + message.trim()
            : 'Не удалось сохранить данные. Попробуйте ещё раз.';
        $('.sx-buttons-standart .sx-success-meessage', form.jForm).empty().append($('<span>', {'class': 'sx-form-submit-error', 'role': 'alert'}).text(message));
    });
}
JS
        );
    }

    /** The window "Отправить клиенту" of the proposal with its client, as the next step after making it. */
    public static function sendUrl(CmsProposal $model): string
    {
        return MailUi::sendUrl(ProposalSendableSource::TYPE.':'.(int)$model->id, $model->client);
    }

    /** "Отправить клиенту" of skeeks/cms-communication for the proposal, opened from the card $record. */
    public static function sendOnClick(CmsProposal $model, $record = null): string
    {
        return MailUi::sendOnClick(ProposalSendableSource::TYPE.':'.(int)$model->id, $record);
    }

    public static function openOnClick(string $url): string
    {
        return 'new sx.classes.backend.widgets.Action('.Json::encode(['isOpenNewWindow' => true, 'url' => $url]).').go(); return false;';
    }

    /** The window "Отправить КП": the КП of the client of a card waiting to be sent, and a new one. */
    public static function proposalsUrl($record): string
    {
        return (string)BackendUrlHelper::createByParams([self::ROUTE.'/proposals', RecordSources::PARAM => ProposalClientSource::value($record)])
            ->enableEmptyLayout()->url;
    }

    /** The window with the presentations for everybody to send to the client of a card. */
    public static function presentationsUrl($record): string
    {
        return (string)BackendUrlHelper::createByParams([self::ROUTE.'/presentations', RecordSources::PARAM => ProposalClientSource::value($record)])
            ->enableEmptyLayout()->url;
    }

    /**
     * The standard create window of proposals for a lead, company or deal
     * (?source=); with $kind the window opens with that kind chosen.
     */
    public static function createUrl($record, ?string $kind = null): ?string
    {
        $value = ProposalClientSource::value($record);
        if (!$value) {
            return null;
        }
        $params = [self::ROUTE.'/create', RecordSources::PARAM => $value];
        if ($kind !== null) {
            $params['kind'] = $kind;
        }

        return (string)BackendUrlHelper::createByParams($params)->enableEmptyLayout()->url;
    }

    /**
     * Actions of the next step of a CRM card (skeeks/cms
     * CrmCardBlocksWidget::EVENT_ACTIONS), after the core "Поставить задачу".
     *
     * @return array[] id => label, url, icon, priority
     */
    public static function nextStepActions($record): array
    {
        if (!ProposalClientSource::value($record)) {
            return [];
        }

        return [
            'presentation' => ['label' => 'Отправить презентацию', 'icon' => 'send', 'url' => self::presentationsUrl($record), 'priority' => 50],
            // The КП of this client waiting to be sent, or a new one: then the steps up to the letter.
            'proposal' => ['label' => 'Отправить КП', 'icon' => 'file', 'url' => self::proposalsUrl($record), 'priority' => 60],
        ];
    }

    /**
     * The "Предложения" block of a lead, company or deal card: the latest
     * КП (with the sum) and presentations (with the page), their status and
     * "+ Создать предложение" (the kind is chosen in the window). Lead and deal proposals show in
     * the card of their company too. The markup and classes are those of the
     * neighbouring contact blocks (CrmContactBlockWidget: sx-contact-*), so
     * rows, hover, the empty text and the add button look the same.
     */
    public static function cardBlock($record): string
    {
        if (!\Yii::$app->has('user') || \Yii::$app->user->isGuest || !\Yii::$app->user->can(\skeeks\cms\rbac\CmsManager::PERMISSION_ADMIN_ACCESS)) {
            return '';
        }
        $view = \Yii::$app->view;
        CrmContactsAsset::register($view);
        \skeeks\cms\backend\widgets\assets\ControllerActionsWidgetAsset::register($view);

        $query = CmsProposal::find()->cmsSite()->forManager()->forRecord($record);
        $count = (int)(clone $query)->count();
        $proposals = $query->with(['currentVersion', 'lead', 'deal'])->orderBy([CmsProposal::tableName().'.id' => SORT_DESC])->limit(self::CARD_LIMIT)->all();

        $rows = '';
        foreach ($proposals as $proposal) {
            $version = $proposal->isPresentation ? null : $proposal->currentVersion;
            $note = [];
            if ($version) {
                $note[] = ProposalItems::money($version->amount, (string)$proposal->currency_code);
            } elseif ($proposal->isPresentation && $proposal->target_url) {
                $note[] = (string)parse_url((string)$proposal->target_url, PHP_URL_PATH) ?: AdminCmsProposalController::targetText($proposal);
            }
            if (!$record instanceof \skeeks\cms\models\CmsLead && $proposal->cms_lead_id) {
                $note[] = 'сделка';
            } elseif (!$record instanceof \skeeks\cms\models\CmsDeal && $proposal->cms_deal_id) {
                $note[] = 'услуга';
            }
            $body = Html::tag('span', Html::encode($proposal->numberText.' · '.\Yii::$app->formatter->asDate((int)$proposal->created_at, 'php:d.m.Y')), ['class' => 'sx-contact-label'])
                .Html::tag('span', BackendEntityLink::widget([
                    'controllerId' => self::ROUTE,
                    'modelId' => (int)$proposal->id,
                    'label' => (string)$proposal->name,
                ]), ['class' => 'sx-contact-value'])
                .($note ? Html::tag('span', Html::encode(implode(' · ', $note)), ['class' => 'sx-contact-note']) : '');
            $rows .= Html::tag('div',
                Html::tag('span', CrmContactBlockWidget::icon('file', 16), ['class' => 'sx-contact-icon'])
                .Html::tag('div', $body, ['class' => 'sx-contact-body'])
                // A presentation for everybody: the state of the sendings to this client, not of all of them.
                .Html::tag('div', ($proposal->isShared ? self::sentBadge($proposal, $record) : AdminCmsProposalController::statusBadge($proposal))
                    // The button of CrmContactBlockWidget::iconButton() with the mail icon: MailUi falls back to an icon font on an older cms-backend.
                    .Html::button(MailUi::icon('send', 16), [
                        'class' => 'sx-button sx-button--secondary sx-button--sm sx-contact-icon-button',
                        'title' => 'Отправить клиенту',
                        'aria-label' => 'Отправить клиенту',
                        'onclick' => self::sendOnClick($proposal, $record),
                    ]),
                    ['class' => 'sx-contact-actions']),
                ['class' => 'sx-contact-row']
            );
        }
        if (!$proposals) {
            $rows .= Html::tag('div', 'Предложений пока нет', ['class' => 'sx-contact-empty']);
        } elseif ($count > count($proposals)) {
            $rows .= Html::a(Html::encode('Все предложения ('.$count.')'), Url::to([self::ROUTE.'/index']), ['class' => 'sx-contact-empty', 'data-pjax' => '0']);
        }
        // The full-width "+ …" buttons of the contact blocks, without hiding the window actions.
        if ($url = self::createUrl($record)) {
            // A ready presentation for everybody in two clicks: the list, then "Отправить клиенту" of this client.
            $rows .= Html::a(MailUi::icon('send', 16).' '.Html::encode('Отправить презентацию'), '#', [
                'class' => 'sx-contact-add',
                'data-pjax' => '0',
                'onclick' => self::openOnClick(self::presentationsUrl($record)),
            ]);
            $rows .= Html::a(CrmContactBlockWidget::icon('plus', 16).' '.Html::encode('Создать предложение'), '#', [
                'class' => 'sx-contact-add',
                'data-pjax' => '0',
                'onclick' => self::openOnClick($url),
            ]);
        }

        return BackendSurfaceWidget::widget([
            'title' => 'Предложения',
            'titleTag' => 'h2',
            'raised' => true,
            'responsive' => true,
            'content' => Html::tag('div', $rows, ['class' => 'sx-contact-list']),
        ]);
    }
}
