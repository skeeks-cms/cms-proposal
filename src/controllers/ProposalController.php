<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\proposal\controllers;

use skeeks\cms\models\CmsDocumentTemplate;
use skeeks\cms\proposal\models\CmsProposal;
use skeeks\cms\proposal\models\CmsProposalSend;
use skeeks\cms\proposal\models\CmsProposalVersion;
use skeeks\cms\proposal\services\ProposalAnswers;
use skeeks\cms\proposal\services\ProposalPdf;
use skeeks\cms\proposal\services\ProposalViews;
use skeeks\cms\proposal\services\PresentationLinks;
use skeeks\cms\rbac\CmsManager;
use yii\filters\VerbFilter;
use yii\helpers\ArrayHelper;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The page of a proposal for the client, by the personal link of a sending
 * (`/~kp/<code>`), without signing in: like the bill page of cms-shop, the
 * secret code is the only access. The link shows the version it was sent
 * with; `?v=latest` shows the newest sent version. The page is not indexed
 * and has no counters of the site: it has its own layout.
 *
 * A view counts only by the signal of the shown page (actionSeen, see
 * ProposalViews), never by opening the link.
 *
 * A presentation has a transition page instead: its script sends the same
 * signal and only then leads the client to the page stored in the
 * proposal, with marks for Metrika (PresentationLinks). Without scripts
 * there is no transition and no view, only the link "Перейти".
 */
class ProposalController extends \yii\web\Controller
{
    public $layout = '@skeeks/cms/proposal/views/proposal/layout';

    public function behaviors()
    {
        return [
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => ['seen' => ['post'], 'accept' => ['post'], 'ask' => ['post']],
            ],
        ];
    }

    public function getViewPath()
    {
        return \Yii::getAlias('@skeeks/cms/proposal/views/proposal');
    }

    public function beforeAction($action)
    {
        $headers = \Yii::$app->response->headers;
        $headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        // The code is in the address: it must not leak to other sites through Referer.
        $headers->set('Referrer-Policy', 'no-referrer');

        return parent::beforeAction($action);
    }

    /**
     * The page as the response content, not data: the site's response
     * handlers that append counters to HTML data (cms-seo countersContent,
     * header_content) leave it alone.
     */
    private function page(string $html): Response
    {
        $response = \Yii::$app->response;
        $response->format = Response::FORMAT_HTML;
        $response->data = null;
        $response->content = $html;

        return $response;
    }

    public function actionView($code)
    {
        $send = $this->findSend($code);
        $proposal = $send->proposal;
        if ($proposal->isPresentation) {
            $this->view->title = trim((string)$proposal->name) !== '' ? (string)$proposal->name : 'Презентация';

            return $this->page($this->render('presentation', [
                'send'      => $send,
                'proposal'  => $proposal,
                'targetUrl' => PresentationLinks::targetUrl($send),
                'isExpired' => $proposal->isExpired,
                'design'    => self::design($proposal),
                'isStaff'   => self::isStaff(),
            ]));
        }
        $version = $this->shownVersion($send);
        $latest = $proposal->latestSentVersion;

        $this->view->title = 'Коммерческое предложение №'.(int)$proposal->number.($proposal->name ? ' — '.$proposal->name : '');

        return $this->page($this->render('view', [
            'send'      => $send,
            'proposal'  => $proposal,
            'version'   => $version,
            'hasNewer'  => $latest && (int)$latest->version > (int)$version->version,
            'isLatest'  => !$latest || (int)$latest->id === (int)$version->id,
            'acceptRefusal' => ProposalAnswers::acceptRefusal($proposal, $version),
            'design'    => self::design($proposal),
            'isStaff'   => self::isStaff(),
        ]));
    }

    /** The page was shown in a browser: a view of this sending (ProposalViews). */
    public function actionSeen($code)
    {
        \Yii::$app->response->format = Response::FORMAT_JSON;
        $send = $this->findSend($code);
        $request = \Yii::$app->request;
        // What the page script saw of the browser: automated browsers of link checks give themselves away.
        $signals = [];
        foreach (['webdriver', 'languages', 'width', 'height'] as $key) {
            if (($value = $request->post($key)) !== null && is_scalar($value)) {
                $signals[$key] = mb_substr((string)$value, 0, 16);
            }
        }
        $result = (new ProposalViews())->record($send, (string)$request->userAgent, (string)$request->userIP, self::isStaff(), $signals);

        return ['success' => true, 'result' => $result];
    }

    public function actionAccept($code)
    {
        \Yii::$app->response->format = Response::FORMAT_JSON;
        $send = $this->findSend($code, true);
        $version = $this->shownVersion($send);
        try {
            $accepted = (new ProposalAnswers())->accept($send, $version);
        } catch (\DomainException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        return ['success' => true, 'repeated' => !$accepted, 'message' => 'Спасибо! Предложение принято. Менеджер свяжется с вами, чтобы обсудить дальнейшие шаги.'];
    }

    public function actionAsk($code)
    {
        \Yii::$app->response->format = Response::FORMAT_JSON;
        $send = $this->findSend($code, true);
        $request = \Yii::$app->request;
        $done =['success' => true, 'message' => 'Спасибо! Вопрос отправлен менеджеру, он ответит вам.'];
        // Filled only by robots: the field is hidden from people. They hear the same answer.
        if (trim((string)$request->post('website')) !== '') {
            return $done;
        }
        try {
            (new ProposalAnswers())->ask($send, $this->shownVersion($send), (string)$request->post('text'), (string)$request->post('contact'),
                (string)$request->post('name'), (string)$request->userIP, (string)$request->userAgent);
        } catch (\DomainException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        return $done;
    }

    /** PDF of the shown version: the file stored at the first sending. */
    public function actionPdf($code)
    {
        $send = $this->findSend($code, true);
        $version = $this->shownVersion($send);
        $response = \Yii::$app->response;
        $name = ProposalPdf::fileName($version);
        $options = ['mimeType' => 'application/pdf', 'inline' => (bool)\Yii::$app->request->get('inline')];
        $file = $version->pdfStorageFile;
        $path = $file ? $file->getRootSrc() : null;
        if ($path && is_file($path)) {
            return $response->sendFile($path, $name, $options);
        }

        return $response->sendContentAsFile((new ProposalPdf())->render($version), $name, $options);
    }

    /** @param bool $commercial only a КП: "Принять", questions and PDF are not for presentations */
    private function findSend($code, bool $commercial = false): CmsProposalSend
    {
        $code = (string)$code;
        $send = preg_match('/^[A-Za-z0-9_-]{16,64}$/', $code)
            ? CmsProposalSend::find()->andWhere(['code' => $code])->andWhere(['not', ['cms_comm_message_id' => null]])->with(['proposal', 'version'])->one()
            : null;
        $proposal = $send ? $send->proposal : null;
        if (!$proposal || ($proposal->isPresentation ? $commercial : !$send->version)) {
            throw new NotFoundHttpException('Предложение не найдено. Проверьте ссылку или свяжитесь с менеджером.');
        }

        return $send;
    }

    /** The version of the link, or the newest sent one with ?v=latest. */
    private function shownVersion(CmsProposalSend $send): CmsProposalVersion
    {
        if (\Yii::$app->request->get('v') === 'latest' && ($latest = $send->proposal->latestSentVersion)) {
            return $latest;
        }

        return $send->version;
    }

    /** A signed-in employee checking the link: not the client, not a view. */
    public static function isStaff(): bool
    {
        $user = \Yii::$app->has('user') ? \Yii::$app->user : null;

        return $user && !$user->isGuest && $user->can(CmsManager::PERMISSION_ADMIN_ACCESS);
    }

    /**
     * Colors and logo of the page from the site's document template, as in
     * the PDF: the accent, the logo chosen like ProposalPdf::theme(). The
     * page itself is light; with a dark template the header is a band of
     * the template's background, so a logo made for dark backgrounds stays
     * readable.
     */
    public static function design(CmsProposal $proposal): array
    {
        $site = $proposal->cmsSite;
        $template = $site ? CmsDocumentTemplate::findDefault((int)$site->id, ProposalPdf::DOCUMENT_TYPE) : null;
        $theme = $template ? $template->themeConfig : CmsDocumentTemplate::preset(CmsDocumentTemplate::THEME_LIGHT);
        $color = static function (string $key, string $default) use ($theme): string {
            $value = (string)ArrayHelper::getValue($theme, $key, '');
            return preg_match('/^#[0-9a-f]{6}$/i', $value) ? $value : $default;
        };
        $accent = $color('accent_color', '#e85f3c');
        $isDark = ArrayHelper::getValue($theme, 'theme') === CmsDocumentTemplate::THEME_DARK;
        $logo = $template && $template->logoStorageFile ? $template->logoStorageFile : null;
        if (!$logo && $site) {
            $logo = $isDark ? $site->image : ($site->logoLightImage ?: $site->image);
        }
        [$r, $g, $b] = sscanf($accent, '#%02x%02x%02x');

        return [
            'accent' => $accent,
            // Readable text on the accent: dark on light accents, white on dark ones.
            'onAccent' => (0.299 * $r + 0.587 * $g + 0.114 * $b) > 160 ? '#111827' : '#ffffff',
            'headerBg' => $isDark ? $color('background_color', '#0f172a') : '',
            'headerText' => $isDark ? $color('text_color', '#f8fafc') : '',
            'logo' => $logo ? (string)$logo->src : '',
            'siteName' => $site ? (string)$site->name : '',
            'siteUrl' => $site ? (string)$site->url : '',
        ];
    }
}
