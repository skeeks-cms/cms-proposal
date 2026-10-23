<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\proposal\services;

use skeeks\cms\models\CmsCompany;
use skeeks\cms\models\CmsLog;
use skeeks\cms\models\CmsUser;
use skeeks\cms\proposal\models\CmsProposal;
use skeeks\cms\proposal\models\CmsProposalSend;

/**
 * Entries of a proposal in the activity feed (CmsLog of type
 * CmsProposal::LOG_TYPE, rendered by ProposalLogRenderer): in the card of
 * its client (lead, company or deal) and, when the client is a lead or a
 * deal of a company, also in the card of that company. The entry keeps the
 * proposal id and the event; the renderer reads the current state. An
 * event of a sending goes to the client of the sending: for a presentation
 * for everybody that is the client it was sent to.
 */
class ProposalFeed
{
    public const EVENT_CREATED = 'created';
    public const EVENT_VERSION = 'version';
    // Written by the sending and the client page (stage C).
    public const EVENT_SENT = 'sent';
    public const EVENT_OPENED = 'opened';
    public const EVENT_ACCEPTED = 'accepted';
    // An opening that looks like a link check of a mail service (stage E): not "opened".
    public const EVENT_CHECKED = 'checked';

    /** Titles of the events of a kind (CmsProposal::KIND_*). */
    public static function eventNames(string $kind = CmsProposal::KIND_PROPOSAL): array
    {
        if ($kind === CmsProposal::KIND_PRESENTATION) {
            return [
                self::EVENT_CREATED => 'Презентация создана',
                self::EVENT_SENT    => 'Презентация отправлена',
                self::EVENT_OPENED  => 'Презентация открыта',
                self::EVENT_CHECKED => 'Презентация: возможно, проверка ссылок',
            ];
        }

        return [
            self::EVENT_CREATED  => 'КП создано',
            self::EVENT_VERSION  => 'Новая версия КП',
            self::EVENT_SENT     => 'КП отправлено',
            self::EVENT_OPENED   => 'КП открыто',
            self::EVENT_ACCEPTED => 'КП принято',
            self::EVENT_CHECKED  => 'КП: возможно, проверка ссылок',
        ];
    }

    /** @return CmsLog[] the written entries */
    public static function write(CmsProposal $proposal, string $event, array $data = [], ?CmsProposalSend $send = null): array
    {
        $owner = $send && $send->client ? $send : $proposal;
        $records = [];
        if ($client = $owner->client) {
            $records[] = $client;
        }
        $company = $owner->getClientCompany();
        if ($company && !$owner->cms_company_id) {
            $records[] = $company;
        }

        $result = [];
        foreach ($records as $record) {
            $log = new CmsLog();
            $log->model_code = method_exists($record, 'getSkeeksModelCode') ? (string)$record->getSkeeksModelCode() : get_class($record);
            $log->model_id = (int)$record->id;
            $log->model_as_text = (string)$record->asText;
            $log->log_type = CmsProposal::LOG_TYPE;
            $log->comment = (string)$proposal->asText;
            $log->data = array_merge($data, ['id' => (int)$proposal->id, 'event' => $event]);
            if ($record instanceof CmsCompany) {
                $log->cms_company_id = (int)$record->id;
            } elseif ($record instanceof CmsUser) {
                $log->cms_user_id = (int)$record->id;
            }
            if (!$log->save(false)) {
                throw new \RuntimeException('Не удалось записать КП в ленту.');
            }
            $result[] = $log;
        }

        return $result;
    }
}
