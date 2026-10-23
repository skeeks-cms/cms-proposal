<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\proposal\mcp;

use skeeks\cms\mcp\crm\CrmDate;
use skeeks\cms\mcp\crm\CrmReadContext;
use skeeks\cms\mcp\crm\CrmRecord;
use skeeks\cms\mcp\crm\PersonalData;
use skeeks\cms\mcp\services\AbstractCmsService;
use skeeks\cms\models\CmsDeal;
use skeeks\cms\proposal\models\CmsProposal;
use skeeks\cms\proposal\models\CmsProposalItem;
use skeeks\cms\proposal\models\CmsProposalQuestion;
use skeeks\cms\proposal\models\CmsProposalSend;
use skeeks\cms\proposal\models\CmsProposalTemplate;
use skeeks\cms\proposal\models\CmsProposalVersion;
use skeeks\cms\proposal\models\CmsProposalView;
use skeeks\cms\proposal\services\ProposalItems;
use yii\base\Exception;

/**
 * Read API of proposals for AI clients (cms-mcp, scope
 * cms.communication.read): lists by client and in general, the card of a КП
 * (items and totals of a version) or of a Презентация (its page, the
 * manager's text), sendings with honest openings, answers of the client,
 * and the library of shared presentations and КП templates. Visibility is
 * CmsProposalQuery::forManager(). Personal link codes of sendings are never
 * returned: opening one would look like the client opened it.
 */
class ProposalReadService extends AbstractCmsService
{
    public $textLength = 2000;

    public function proposalList(array $a): array
    {
        $context = $this->context($a);
        $t = CmsProposal::tableName();
        $q = CmsProposal::find()->forManager()->andWhere([$t.'.cms_site_id' => $this->siteId()]);
        if ($this->hasRecord($a)) {
            $q->forRecord(CrmRecord::resolve($a)->model);
        }
        if (!empty($a['shared'])) {
            $q->shared();
        }
        foreach (['kind', 'status', 'responsible_id'] as $attribute) {
            if (!empty($a[$attribute])) {
                $q->andWhere([$t.'.'.$attribute => $a[$attribute]]);
            }
        }
        if (isset($a['sent']) && $a['sent'] !== '' && $a['sent'] !== null) {
            $sent = ['exists', CmsProposalSend::find()->andWhere(CmsProposalSend::tableName().'.cms_proposal_id = '.$t.'.id')];
            $q->andWhere($a['sent'] ? $sent : ['not', $sent]);
        }
        if (!empty($a['q'])) {
            $q->search((string)$a['q']);
        }
        $this->applyDateRange($q, CmsProposal::class, $a, 'created_at');
        $limit = max(1, min(100, (int)($a['limit'] ?? 30)));
        $offset = max(0, (int)($a['offset'] ?? 0));
        $total = (int)(clone $q)->count();
        $items = [];
        foreach ($q->orderBy([$t.'.created_at' => SORT_DESC, $t.'.id' => SORT_DESC])->limit($limit)->offset($offset)->with(['currentVersion', 'sends'])->all() as $p) {
            $items[] = $this->proposalData($p, $context);
        }
        return ['items' => $items, 'total' => $total, 'limit' => $limit, 'offset' => $offset];
    }

    public function proposalGet(array $a): array
    {
        $context = $this->context($a);
        $p = CmsProposal::find()->forManager()->andWhere([CmsProposal::tableName().'.id' => (int)($a['id'] ?? 0)])->one();
        if (!$p) {
            throw new Exception('Proposal #'.(int)($a['id'] ?? 0).' not found or unavailable.');
        }
        $data = $this->proposalData($p, $context);
        $length = max(300, min(10000, (int)($a['max_length'] ?? $this->textLength)));
        if ($p->isPresentation) {
            $data['message'] = $context->text($p->getMessageText(), $length);
        } else {
            $version = !empty($a['version'])
                ? CmsProposalVersion::findOne(['cms_proposal_id' => $p->id, 'version' => (int)$a['version']])
                : $p->currentVersion;
            $data['versions'] = array_map(static function (CmsProposalVersion $v) {
                return ['version' => (int)$v->version, 'amount' => (float)$v->amount, 'currency_code' => (string)$v->currency_code, 'sent_at' => CrmDate::format($v->sent_at ? (int)$v->sent_at : null)];
            }, CmsProposalVersion::find()->andWhere(['cms_proposal_id' => $p->id])->orderBy(['version' => SORT_ASC])->all());
            $data['version'] = $version ? $this->versionData($version, $context, $length) : null;
        }
        $data['sends'] = array_map(function (CmsProposalSend $s) use ($context) { return $this->sendData($s, $context); },
            CmsProposalSend::find()->andWhere(['cms_proposal_id' => $p->id])->orderBy(['created_at' => SORT_DESC, 'id' => SORT_DESC])->limit(50)->all());
        $data['questions'] = array_map(static function (CmsProposalQuestion $q) use ($context) {
            return [
                'date' => CrmDate::format((int)$q->created_at),
                'name' => $context->text((string)$q->name, 200),
                'text' => $context->text((string)$q->text, 2000),
                'cms_proposal_send_id' => (int)$q->cms_proposal_send_id,
                'cms_comm_message_id' => $q->cms_comm_message_id ? (int)$q->cms_comm_message_id : null,
            ];
        }, CmsProposalQuestion::find()->andWhere(['cms_proposal_id' => $p->id])->orderBy(['created_at' => SORT_DESC])->limit(50)->all());
        if ($p->accepted_at) {
            $data['accepted'] = [
                'at' => CrmDate::format((int)$p->accepted_at),
                'version' => $p->acceptedVersion ? (int)$p->acceptedVersion->version : null,
                'cms_proposal_send_id' => $p->accepted_send_id ? (int)$p->accepted_send_id : null,
            ];
        }
        $data['template'] = $p->template ? ['id' => (int)$p->template->id, 'name' => (string)$p->template->name] : null;
        return $data;
    }

    /** Ready shared presentations (for everybody) and active КП templates of the site. */
    public function library(array $a): array
    {
        $context = $this->context($a);
        $siteId = $this->siteId();
        $presentations = [];
        foreach (CmsProposal::find()->forManager()->shared()->andWhere([CmsProposal::tableName().'.cms_site_id' => $siteId])
            ->orderBy([CmsProposal::tableName().'.id' => SORT_DESC])->with('sends')->limit(100)->all() as $p) {
            $item = $this->proposalData($p, $context);
            $item['message'] = $context->text($p->getMessageText(), 1000);
            $presentations[] = $item;
        }
        $templates = [];
        $q = CmsProposalTemplate::find()->andWhere(['cms_site_id' => $siteId]);
        if (empty($a['include_inactive'])) {
            $q->andWhere(['is_active' => 1]);
        }
        foreach ($q->orderBy(['priority' => SORT_ASC, 'name' => SORT_ASC])->all() as $tpl) {
            $rows = $tpl->getItemRows();
            $total = 0.0;
            foreach ($rows as $row) {
                if (($row['type'] ?? CmsProposalItem::TYPE_ITEM) !== CmsProposalItem::TYPE_SECTION) {
                    $total += (float)($row['quantity'] ?? 0) * (float)($row['price'] ?? 0);
                }
            }
            $templates[] = [
                'id' => (int)$tpl->id,
                'name' => (string)$tpl->name,
                'title' => (string)$tpl->title,
                'intro' => $context->text(PersonalData::plain($tpl->intro), 1000),
                'terms' => $context->text(PersonalData::plain($tpl->terms), 500),
                'items' => array_map(static function (array $row) {
                    return array_filter([
                        'type' => $row['type'] ?? 'item', 'name' => $row['name'] ?? '', 'quantity' => $row['quantity'] ?? null,
                        'measure_name' => $row['measure_name'] ?? null, 'price' => $row['price'] ?? null, 'discount_value' => $row['discount_value'] ?? null,
                    ], static function ($v) { return $v !== null && $v !== ''; });
                }, $rows),
                'items_total_before_discount' => $total,
                'discount_value' => $tpl->discount_value,
                'valid_days' => $tpl->valid_days ? (int)$tpl->valid_days : null,
                'is_active' => (bool)$tpl->is_active,
            ];
        }
        return [
            'presentations' => $presentations,
            'templates' => $templates,
            'note' => 'Общая Презентация — страница сайта (target_url) для всех клиентов, у каждой отправки своя личная ссылка. Шаблон КП — основа нового КП клиента (позиции, вступление, условия).',
        ];
    }

    public function proposalData(CmsProposal $p, CrmReadContext $context): array
    {
        $version = $p->isPresentation ? null : $p->currentVersion;
        $sends = $p->sends;
        $opened = array_filter($sends, static function (CmsProposalSend $s) { return (bool)$s->first_viewed_at; });
        $lastSent = $sends ? max(array_map(static function ($s) { return (int)$s->created_at; }, $sends)) : 0;
        $firstViewed = $opened ? min(array_map(static function ($s) { return (int)$s->first_viewed_at; }, $opened)) : 0;
        $lastViewed = $opened ? max(array_map(static function ($s) { return (int)$s->last_viewed_at; }, $opened)) : 0;
        return array_filter([
            'id' => (int)$p->id,
            'kind' => (string)$p->kind,
            'number_text' => $p->numberText,
            'name' => $context->text((string)$p->name, 300),
            'status' => $p->displayStatus,
            'status_text' => $p->displayStatusName,
            'is_shared' => $p->isShared,
            'client' => $this->clientReference($p, $context),
            'responsible' => $p->responsible ? ['id' => (int)$p->responsible_id, 'name' => (string)$p->responsible->shortDisplayName] : null,
            'created_at' => CrmDate::format((int)$p->created_at),
            'valid_until' => CrmDate::format($p->valid_until ? (int)$p->valid_until : null),
            'target_url' => $p->isPresentation ? (string)$p->target_url : null,
            'amount' => $version ? (float)$version->amount : null,
            'currency_code' => $version ? (string)$version->currency_code : null,
            'amount_text' => $version ? ProposalItems::money($version->amount, (string)$version->currency_code) : null,
            'version' => $version ? (int)$version->version : null,
            'sends_count' => count($sends),
            'last_sent_at' => CrmDate::format($lastSent ?: null),
            'opened_sends_count' => count($opened),
            'views_count' => array_sum(array_map(static function ($s) { return (int)$s->views_count; }, $sends)),
            'first_viewed_at' => CrmDate::format($firstViewed ?: null),
            'last_viewed_at' => CrmDate::format($lastViewed ?: null),
            'accepted_at' => CrmDate::format($p->accepted_at ? (int)$p->accepted_at : null),
            'url' => CrmRecord::adminUrl('cmsProposal/admin-cms-proposal', (int)$p->id),
        ], static function ($v) { return $v !== null; });
    }

    protected function versionData(CmsProposalVersion $v, CrmReadContext $context, int $length): array
    {
        $items = [];
        foreach ($v->items as $item) {
            $items[] = $item->isSection
                ? ['type' => 'section', 'name' => $context->text((string)$item->name, 300)]
                : array_filter([
                    'type' => 'item',
                    'name' => $context->text((string)$item->name, 300),
                    'description' => $context->text(PersonalData::plain($item->description), 500),
                    'quantity' => (float)$item->quantity,
                    'measure_name' => (string)$item->measure_name,
                    'price' => (float)$item->price,
                    'discount' => $item->discount_value !== null && $item->discount_value !== '' ? (string)$item->discount_value : null,
                    'amount' => (float)$item->amount,
                    'shop_product_id' => $item->shop_product_id ? (int)$item->shop_product_id : null,
                ], static function ($x) { return $x !== null && $x !== ''; });
        }
        return [
            'version' => (int)$v->version,
            'sent_at' => CrmDate::format($v->sent_at ? (int)$v->sent_at : null),
            'intro' => $context->text(PersonalData::plain($v->intro), $length),
            'terms' => $context->text(PersonalData::plain($v->terms), $length),
            'items' => $items,
            'subtotal_amount' => (float)$v->subtotal_amount,
            'discount' => $v->discount_value !== null && $v->discount_value !== '' ? (string)$v->discount_value : null,
            'discount_amount' => (float)$v->discount_amount,
            'amount' => (float)$v->amount,
            'currency_code' => (string)$v->currency_code,
            'amount_text' => ProposalItems::money($v->amount, (string)$v->currency_code),
            'our_contractor' => (string)$v->our_contractor_name,
        ];
    }

    protected function sendData(CmsProposalSend $s, CrmReadContext $context): array
    {
        $checks = (int)CmsProposalView::find()->andWhere(['cms_proposal_send_id' => $s->id])->andWhere(['not', ['check_reason' => null]])->count();
        $phone = $s->channel === CmsProposalSend::CHANNEL_SMS;
        return array_filter([
            'id' => (int)$s->id,
            'date' => CrmDate::format((int)$s->created_at),
            'channel' => (string)$s->channel,
            'recipient' => $phone ? $context->phone((string)$s->recipient) : $context->email((string)$s->recipient),
            'recipient_name' => $context->text((string)$s->recipient_name, 200),
            'client' => $s->client ? (($r = CrmRecord::of($s->client)) ? $r->reference($context) : ['type' => 'deal', 'id' => (int)$s->cms_deal_id]) : null,
            'version' => $s->version ? (int)$s->version->version : null,
            'sent_by' => $s->createdBy ? ['id' => (int)$s->created_by, 'name' => (string)$s->createdBy->shortDisplayName] : null,
            'cms_comm_message_id' => $s->cms_comm_message_id ? (int)$s->cms_comm_message_id : null,
            'is_opened' => (bool)$s->first_viewed_at,
            'first_viewed_at' => CrmDate::format($s->first_viewed_at ? (int)$s->first_viewed_at : null),
            'last_viewed_at' => CrmDate::format($s->last_viewed_at ? (int)$s->last_viewed_at : null),
            'views_count' => (int)$s->views_count,
            'link_checks_count' => $checks ?: null,
        ], static function ($v) { return $v !== null && $v !== ''; });
    }

    protected function clientReference(CmsProposal $p, CrmReadContext $context): ?array
    {
        $client = $p->client;
        if (!$client) {
            return null;
        }
        if ($client instanceof CmsDeal) {
            return ['type' => 'deal', 'id' => (int)$client->id, 'name' => $context->text((string)$client->name, 200), 'url' => CrmRecord::adminUrl('cms/admin-cms-deal', (int)$client->id)];
        }
        $record = CrmRecord::of($client);
        return $record ? $record->reference($context) : null;
    }

    protected function hasRecord(array $a): bool
    {
        return !empty($a['record_type']) || !empty($a['cms_lead_id']) || !empty($a['cms_company_id']) || !empty($a['cms_user_id']);
    }

    protected function context(array $a): CrmReadContext
    {
        return new CrmReadContext(['includeContacts' => !empty($a['include_contacts'])]);
    }

    protected function siteId(): int
    {
        if (!\Yii::$app->skeeks->site) {
            throw new Exception('Site is not resolved.');
        }
        return (int)\Yii::$app->skeeks->site->id;
    }
}
