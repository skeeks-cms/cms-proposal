<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\proposal\send;

use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\send\SendableHtmlInterface;
use skeeks\cms\communication\send\SendableInterface;
use skeeks\cms\communication\send\SendableMarksInterface;
use skeeks\cms\communication\send\SendContext;
use skeeks\cms\communication\send\SendFile;
use skeeks\cms\communication\send\SendRefusalInterface;
use skeeks\cms\proposal\models\CmsProposal;
use skeeks\cms\proposal\services\PresentationLinks;
use skeeks\cms\proposal\services\ProposalItems;
use skeeks\cms\proposal\services\ProposalPdf;
use skeeks\cms\proposal\services\ProposalSends;

/**
 * A proposal sent to the client: the current version, a personal link of
 * every sending (CmsProposalSend), the PDF attached. The work is done by
 * ProposalSends, the same for the window, "Прикрепить из…", API and bots.
 * A presentation is the manager's short text and the button "Посмотреть",
 * no files.
 */
class ProposalSendable implements SendableInterface, SendableHtmlInterface, SendRefusalInterface, SendableMarksInterface
{
    public CmsProposal $proposal;

    public function __construct(CmsProposal $proposal)
    {
        $this->proposal = $proposal;
    }

    /** Why it cannot be sent now (expired, empty, no page): asked by checks before a letter is made. */
    public function getSendRefusal(): ?string
    {
        return ProposalSends::refusal($this->proposal);
    }

    public function getSendTitle(): string
    {
        $proposal = $this->proposal;
        if ($proposal->isPresentation) {
            // The subject of the letter: what the page is about.
            return trim((string)$proposal->name) !== '' ? (string)$proposal->name : 'Презентация №'.(int)$proposal->number;
        }

        return 'Коммерческое предложение №'.(int)$proposal->number.($proposal->name ? ' «'.$proposal->name.'»' : '');
    }

    public function getSendText(): string
    {
        $proposal = $this->proposal;
        if ($proposal->isPresentation) {
            $message = $proposal->getMessageText();

            return $message !== '' ? $message : implode("\n", [
                'Здравствуйте!',
                '',
                'Отправляем ссылку'.($proposal->name ? ' «'.$proposal->name.'»' : '').': посмотрите, когда будет удобно.',
            ]);
        }
        $version = $proposal->currentVersion;
        $about = 'коммерческое предложение'.($proposal->name ? ' «'.$proposal->name.'»' : '');
        if ($version && (float)$version->amount > 0) {
            $about .= ' на сумму '.ProposalItems::money($version->amount, (string)$proposal->currency_code);
        }
        $lines = ['Здравствуйте!', '', 'Подготовили для вас '.$about.'.'];
        if ($proposal->valid_until) {
            $lines[] = 'Предложение действует до '.\Yii::$app->formatter->asDate((int)$proposal->valid_until, 'php:d.m.Y').'.';
        }
        $lines[] = 'Посмотреть его, задать вопрос или принять можно по ссылке.';

        return implode("\n", $lines);
    }

    /** The manager's text with its formatting for a letter; a КП and an empty text use getSendText(). */
    public function getSendHtml(): string
    {
        return $this->proposal->isPresentation ? $this->proposal->getMessageHtml() : '';
    }

    /** Links of the letter to our sites are marked as visits of this offer: "proposal-12". */
    public function getSendUtmCampaign(): string
    {
        return PresentationLinks::campaign((int)$this->proposal->number);
    }

    public function getSendButton(): string
    {
        return $this->proposal->isPresentation ? 'Посмотреть' : 'Открыть предложение';
    }

    public function getSendFiles(): array
    {
        $version = $this->proposal->isPresentation ? null : $this->proposal->currentVersion;
        if (!$version) {
            return [];
        }
        if ($version->pdfStorageFile) {
            return [SendFile::storage($version->pdfStorageFile, ProposalPdf::fileName($version))];
        }

        return [SendFile::path(ProposalSends::pdfPath($version), ProposalPdf::fileName($version), 'application/pdf')];
    }

    public function getSendRecords(): array
    {
        $records = [];
        if ($client = $this->proposal->client) {
            $records[] = $client;
        }
        if (!$this->proposal->cms_company_id && ($company = $this->proposal->getClientCompany())) {
            $records[] = $company;
        }

        return $records;
    }

    public function createSendLink(SendContext $context): string
    {
        $send = (new ProposalSends())->create($this->proposal, $context);
        $context->ref = $send;

        return $send->url;
    }

    public function afterSend(SendContext $context, CmsCommMessage $message): void
    {
        (new ProposalSends())->complete($context->ref, $message, $context);
    }
}
