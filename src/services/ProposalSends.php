<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\proposal\services;

use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\send\SendContext;
use skeeks\cms\communication\send\SendException;
use skeeks\cms\proposal\models\CmsProposal;
use skeeks\cms\proposal\models\CmsProposalSend;
use skeeks\cms\proposal\models\CmsProposalVersion;

/**
 * Sendings of a proposal through "Отправить клиенту" of
 * skeeks/cms-communication. One sending is create() (a row with its own
 * secret link code, inside the transaction of the message) and complete()
 * (the message is created): the version becomes sent and read-only, its
 * PDF is stored with it at the first sending, a draft proposal becomes
 * "Отправлено", the feed gets "КП отправлено (канал)". A presentation has
 * no version and no PDF: only the status and the feed.
 */
class ProposalSends
{
    /**
     * A pending sending of the current version.
     *
     * @throws SendException when the proposal cannot be sent: nothing is created
     */
    public function create(CmsProposal $proposal, SendContext $context): CmsProposalSend
    {
        $version = $proposal->isPresentation ? null : $proposal->currentVersion;
        if ($error = self::refusal($proposal)) {
            throw new SendException(['sendable' => $error]);
        }

        $send = new CmsProposalSend([
            'cms_proposal_id' => (int)$proposal->id,
            'cms_proposal_version_id' => $version ? (int)$version->id : null,
            'channel' => $context->channel,
            'recipient' => mb_substr($context->recipient, 0, 255),
            'recipient_name' => mb_substr($context->recipientName, 0, 255) ?: null,
        ]);
        // The client of the sending: the proposal's, or for a presentation for
        // everybody the CRM record of the recipient (or of the card it is sent from).
        if (!$send->setClient($proposal->isShared ? $context->record : $proposal->client)) {
            throw new SendException(['sendable' => 'Общую презентацию можно отправить только клиенту из CRM: выберите контакт лида, компании или клиента '
                .'или откройте отправку из его карточки. Тогда открытие ссылки попадёт в его ленту.']);
        }
        if (!$send->save()) {
            throw new \RuntimeException('Не удалось создать ссылку на КП: '.implode(' ', $send->getErrorSummary(true)));
        }
        // The sender is the employee of the send, in a job or an API call too.
        if ((int)$send->created_by !== (int)$context->user->id) {
            $send->updateAttributes(['created_by' => (int)$context->user->id]);
        }
        $send->populateRelation('proposal', $proposal);

        return $send;
    }

    /** Why the proposal cannot be sent now, or null. */
    public static function refusal(CmsProposal $proposal): ?string
    {
        if ($proposal->isPresentation) {
            if (trim((string)$proposal->target_url) === '') {
                return 'У презентации не указан адрес страницы: укажите его в настройках.';
            }
            if ($proposal->valid_until && (int)$proposal->valid_until < time()) {
                return 'Срок действия презентации истёк '.\Yii::$app->formatter->asDate((int)$proposal->valid_until, 'php:d.m.Y')
                    .': продлите или уберите его в настройках, иначе ссылка не откроет страницу.';
            }

            return null;
        }
        $version = $proposal->currentVersion;
        if (!$version) {
            return 'У КП нет содержимого.';
        }
        if (!$version->items && trim(strip_tags((string)$version->intro, '<img>')) === '') {
            return 'КП пустое: добавьте позиции или текст на вкладке «Содержимое».';
        }
        if ($proposal->valid_until && (int)$proposal->valid_until < time()) {
            return 'Срок действия КП истёк '.\Yii::$app->formatter->asDate((int)$proposal->valid_until, 'php:d.m.Y')
                .': продлите его в настройках КП, иначе клиент увидит, что предложение истекло.';
        }

        return null;
    }

    /** The message carrying the link is created: the version is sent from now on. */
    public function complete(CmsProposalSend $send, CmsCommMessage $message, SendContext $context): void
    {
        $send->updateAttributes(['cms_comm_message_id' => (int)$message->id, 'created_at' => time()]);
        $proposal = $send->proposal;
        $version = $send->version;

        if ($version) {
            $version->markSent();
            if (!$version->pdf_storage_file_id) {
                $version->updateAttributes(['pdf_storage_file_id' => $this->storePdf($version)]);
            }
        }

        if (in_array($proposal->status, [CmsProposal::STATUS_DRAFT, CmsProposal::STATUS_EXPIRED], true)) {
            $proposal->status = CmsProposal::STATUS_SENT;
            $proposal->status_at = time();
            $proposal->updateAttributes(['status', 'status_at']);
        }

        ProposalFeed::write($proposal, ProposalFeed::EVENT_SENT, array_filter([
            'channel' => (string)$send->channel,
            'send' => (int)$send->id,
            'recipient' => $send->recipientText,
            'version' => $version ? (int)$version->version : null,
        ], static fn($value) => $value !== null), $send);
    }

    /**
     * The PDF of a version on disk, made once per version and day (the PDF
     * shows the date of sending): the letter attaches it, the first sending
     * stores the same file with the version.
     */
    public static function pdfPath(CmsProposalVersion $version): string
    {
        $dir = \Yii::getAlias('@runtime/cms-proposal/pdf');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $path = $dir.'/'.(int)$version->id.'-'.md5((int)$version->updated_at.'|'.date('Ymd')).'.pdf';
        if (!is_file($path)) {
            foreach (glob($dir.'/'.(int)$version->id.'-*.pdf') ?: [] as $old) {
                @unlink($old);
            }
            $tmp = $path.'.'.bin2hex(random_bytes(4));
            file_put_contents($tmp, (new ProposalPdf())->render($version));
            rename($tmp, $path);
        }

        return $path;
    }

    /** @return int id of the stored file (storage upload() returns the StorageFile base model) */
    private function storePdf(CmsProposalVersion $version): int
    {
        // upload() moves the file into the storage: give it a copy.
        $copy = dirname(self::pdfPath($version)).'/upload-'.bin2hex(random_bytes(8)).'.pdf';
        copy(self::pdfPath($version), $copy);
        try {
            $file = \Yii::$app->storage->upload($copy, [
                'original_name' => ProposalPdf::fileName($version),
                'cms_site_id' => (int)$version->proposal->cms_site_id,
            ]);
        } finally {
            if (is_file($copy)) {
                @unlink($copy);
            }
        }
        if (!$file || !$file->id) {
            throw new \RuntimeException('Не удалось сохранить PDF версии КП.');
        }

        return (int)$file->id;
    }
}
