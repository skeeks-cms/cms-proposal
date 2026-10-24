<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\proposal\send;

use skeeks\cms\communication\send\CampaignSourceInterface;
use skeeks\cms\communication\send\SendableInterface;
use skeeks\cms\communication\send\SendableSourceInterface;
use skeeks\cms\communication\send\SendException;
use skeeks\cms\communication\send\SendHistoryInterface;
use skeeks\cms\models\CmsCompany;
use skeeks\cms\models\CmsDeal;
use skeeks\cms\models\CmsLead;
use skeeks\cms\models\CmsUser;
use skeeks\cms\models\User;
use skeeks\cms\proposal\models\CmsProposal;
use skeeks\cms\proposal\models\CmsProposalSend;
use skeeks\cms\proposal\models\CmsProposalTemplate;
use skeeks\cms\proposal\services\ProposalSends;
use skeeks\cms\proposal\services\ProposalItems;
use yii\db\ActiveRecord;

/**
 * Proposals (КП and presentations) in "Отправить клиенту" and "Прикрепить
 * из… → Предложение" of
 * skeeks/cms-communication (`communication.sendables['proposal']`). An
 * employee may send every proposal they see (CmsProposalQuery::forManager()).
 *
 * Mailing campaigns of skeeks/cms-communication (CampaignSourceInterface):
 * a presentation for everybody as the offer of all recipients, or every
 * recipient's own КП made from a template when the letter leaves; the
 * report reads the views and the acceptance of the sends of its letters.
 *
 * AI actions of skeeks/cms-communication (SendHistoryInterface) ask when a
 * proposal was last sent to a client, so it is not offered again too soon.
 */
class ProposalSendableSource implements SendableSourceInterface, CampaignSourceInterface, SendHistoryInterface
{
    public const TYPE = 'proposal';

    public function title(): string
    {
        return 'Предложение';
    }

    /** The latest send of the proposal to the record, its leads or its company (by any channel). */
    public function lastSentAt(int $id, ActiveRecord $record): ?int
    {
        $query = CmsProposalSend::find()->andWhere(['cms_proposal_id' => $id])->andWhere(['not', ['cms_comm_message_id' => null]]);
        if ($record instanceof CmsLead) {
            $or = ['or', ['cms_lead_id' => (int)$record->id]];
            if ($record->cms_company_id) {
                $or[] = ['cms_company_id' => (int)$record->cms_company_id];
            }
            $query->andWhere($or);
        } elseif ($record instanceof CmsCompany) {
            $query->andWhere(['or',
                ['cms_company_id' => (int)$record->id],
                ['cms_lead_id' => CmsLead::find()->select('id')->andWhere(['cms_company_id' => (int)$record->id])],
            ]);
        } elseif ($record instanceof CmsUser) {
            $query->andWhere(['cms_user_id' => (int)$record->id]);
        } else {
            return null;
        }
        $at = $query->max('created_at');

        return $at ? (int)$at : null;
    }

    public function find(int $id, User $user): ?SendableInterface
    {
        $proposal = CmsProposal::find()->cmsSite()->forManager($user)
            ->andWhere([CmsProposal::tableName().'.id' => $id])
            ->with(['currentVersion', 'lead', 'company', 'deal'])
            ->one();

        return $proposal ? new ProposalSendable($proposal) : null;
    }

    public function suggest(User $user, string $q, ?ActiveRecord $record = null, int $limit = 20): array
    {
        $t = CmsProposal::tableName();
        $query = CmsProposal::find()->cmsSite()->forManager($user)->search($q)
            ->with(['currentVersion', 'lead', 'company', 'deal'])
            ->orderBy([$t.'.id' => SORT_DESC])
            ->limit($limit);
        $proposals = $query->all();
        if ($record) {
            // The proposals of the card the letter is written from go first.
            $own = CmsProposal::find()->cmsSite()->forManager($user)->forRecord($record)->search($q)
                ->select($t.'.id')->column();
            $own = array_flip(array_map('intval', $own));
            usort($proposals, static fn(CmsProposal $a, CmsProposal $b) => (isset($own[(int)$b->id]) <=> isset($own[(int)$a->id])) ?: ((int)$b->id <=> (int)$a->id));
        }

        return array_map(static function (CmsProposal $proposal) {
            $hint = [$proposal->clientTypeName.' «'.$proposal->clientName.'»'];
            if ($proposal->isPresentation) {
                $hint[] = (string)parse_url((string)$proposal->target_url, PHP_URL_PATH) ?: (string)$proposal->target_url;
            } elseif ($proposal->currentVersion) {
                $hint[] = ProposalItems::money($proposal->currentVersion->amount, (string)$proposal->currency_code);
            }
            $hint[] = $proposal->displayStatusName;

            return ['id' => (int)$proposal->id, 'title' => $proposal->asText, 'hint' => implode(' · ', $hint)];
        }, $proposals);
    }

    /** Presentations for everybody that still work (the page is set, not expired). */
    public function sharedItems(User $user, int $siteId): array
    {
        $t = CmsProposal::tableName();
        $result = [];
        foreach (CmsProposal::find()->andWhere([$t.'.cms_site_id' => $siteId])->shared()->forManager($user)
                     ->orderBy([$t.'.id' => SORT_DESC])->limit(200)->all() as $proposal) {
            if (ProposalSends::refusal($proposal) === null) {
                $result[(int)$proposal->id] = $proposal->asText.($proposal->target_url ? ' — '.$proposal->target_url : '');
            }
        }

        return $result;
    }

    public function templates(int $siteId): array
    {
        $result = [];
        foreach (CmsProposalTemplate::find()->andWhere(['cms_site_id' => $siteId, 'is_active' => 1])
                     ->orderBy(['priority' => SORT_ASC, 'name' => SORT_ASC])->all() as $template) {
            $result[(int)$template->id] = (string)$template->name;
        }

        return $result;
    }

    /** A КП of the recipient from the template: the first version is the template's content (CmsProposal::afterSave()). */
    public function createFromTemplate(int $templateId, ActiveRecord $record, User $user): int
    {
        $template = CmsProposalTemplate::findOne(['id' => $templateId, 'is_active' => 1]);
        if (!$template) {
            throw new SendException(['sendable' => 'Шаблон КП не найден или выключен.']);
        }
        $proposal = new CmsProposal([
            'kind' => CmsProposal::KIND_PROPOSAL,
            'cms_site_id' => (int)$template->cms_site_id,
            'cms_proposal_template_id' => (int)$template->id,
            'responsible_id' => (int)$user->id,
        ]);
        if ($record instanceof CmsLead) {
            $proposal->cms_lead_id = (int)$record->id;
        } elseif ($record instanceof CmsCompany) {
            $proposal->cms_company_id = (int)$record->id;
        } elseif ($record instanceof CmsDeal) {
            $proposal->cms_deal_id = (int)$record->id;
        } elseif ($record instanceof CmsUser) {
            $proposal->cms_user_id = (int)$record->id;
        } else {
            throw new SendException(['sendable' => 'КП можно сделать только для лида, компании, сделки или клиента.']);
        }
        if (!$proposal->save()) {
            throw new SendException(['sendable' => 'КП из шаблона не создано: '.implode(' ', $proposal->getErrorSummary(true))]);
        }
        // The author is the employee of the letter, in a job too.
        if ((int)$proposal->created_by !== (int)$user->id) {
            $proposal->updateAttributes(['created_by' => (int)$user->id]);
        }

        return (int)$proposal->id;
    }

    public function states(array $messageIds): array
    {
        $messageIds = array_values(array_filter(array_map('intval', $messageIds)));
        if (!$messageIds) {
            return [];
        }
        $result = [];
        $sends = CmsProposalSend::find()->andWhere(['cms_comm_message_id' => $messageIds])->with('proposal')->all();
        $checks = $sends ? \skeeks\cms\proposal\models\CmsProposalView::find()->select(['n' => 'COUNT(*)', 'cms_proposal_send_id'])
            ->andWhere(['cms_proposal_send_id' => array_map(static fn($s) => (int)$s->id, $sends)])->andWhere(['not', ['check_reason' => null]])
            ->groupBy('cms_proposal_send_id')->indexBy('cms_proposal_send_id')->column() : [];
        foreach ($sends as $send) {
            $proposal = $send->proposal;
            $result[(int)$send->cms_comm_message_id] = [
                'opened_at' => $send->first_viewed_at ? (int)$send->first_viewed_at : null,
                'accepted_at' => $proposal && (int)$proposal->accepted_send_id === (int)$send->id && $proposal->accepted_at ? (int)$proposal->accepted_at : null,
                // The send itself, for API status: its id and honest views; link checks of mail services are counted apart.
                'send_id' => (int)$send->id,
                'proposal_id' => (int)$send->cms_proposal_id,
                'last_viewed_at' => $send->last_viewed_at ? (int)$send->last_viewed_at : null,
                'views_count' => (int)$send->views_count,
                'link_checks_count' => (int)($checks[(int)$send->id] ?? 0),
            ];
        }

        return $result;
    }
}
