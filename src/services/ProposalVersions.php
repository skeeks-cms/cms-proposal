<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\proposal\services;

use skeeks\cms\models\CmsContractor;
use skeeks\cms\proposal\models\CmsProposal;
use skeeks\cms\proposal\models\CmsProposalItem;
use skeeks\cms\proposal\models\CmsProposalVersion;

/**
 * Versions of a proposal. One service for the CRM windows, API tools and
 * bots:
 * - createFirst(): version 1 of a new proposal, from its template;
 * - saveContent(): the employee's changes; a version already sent to the
 *   client is never changed, the changes go into a new version;
 * - newVersion(): a copy of the current version that becomes current.
 */
class ProposalVersions
{
    /** Content attributes of a version: intro, terms, discount_value, our_contractor_id, items (rows). */
    public const CONTENT = ['intro', 'terms', 'discount_value', 'our_contractor_id', 'items'];

    public function createFirst(CmsProposal $proposal): CmsProposalVersion
    {
        $template = $proposal->template;
        $content = [
            'intro'             => $template ? $template->intro : null,
            'terms'             => $template ? $template->terms : null,
            'discount_value'    => $template ? $template->discount_value : null,
            'our_contractor_id' => self::defaultOurContractorId((int)$proposal->cms_site_id),
            'items'             => $template ? $template->itemRows : [],
        ];

        return $this->write($proposal, null, $content);
    }

    /**
     * Saves the content into the current version, or into a new one when the
     * current version was sent. Returns the version that holds it.
     * @param array $content see CONTENT; `items` are editor rows
     */
    public function saveContent(CmsProposal $proposal, array $content): CmsProposalVersion
    {
        $current = $proposal->currentVersion;
        $isNew = !$current || $current->isLocked;
        $version = $this->transaction(fn() => $this->write($proposal, $isNew ? null : $current, $content));
        if ($isNew && (int)$version->version > 1) {
            ProposalFeed::write($proposal, ProposalFeed::EVENT_VERSION, ['version' => (int)$version->version]);
        }

        return $version;
    }

    /** A new current version: a copy of the current one, with our requisites as they are now. */
    public function newVersion(CmsProposal $proposal): CmsProposalVersion
    {
        $current = $proposal->currentVersion;
        $content = $current ? self::contentOf($current) : ['items' => []];
        $version = $this->transaction(fn() => $this->write($proposal, null, $content));
        ProposalFeed::write($proposal, ProposalFeed::EVENT_VERSION, ['version' => (int)$version->version]);

        return $version;
    }

    /** The content of a version in the form saveContent() takes. */
    public static function contentOf(CmsProposalVersion $version): array
    {
        return [
            'intro'             => $version->intro,
            'terms'             => $version->terms,
            'discount_value'    => $version->discount_value,
            'our_contractor_id' => $version->our_contractor_id,
            'items'             => array_map(static fn(CmsProposalItem $item) => $item->toRow(), $version->items),
        ];
    }

    /**
     * Our requisites for a new proposal: those of the latest proposal of the
     * site, otherwise the first contractor of our company.
     */
    public static function defaultOurContractorId(int $siteId): ?int
    {
        $last = CmsProposalVersion::find()->alias('v')
            ->innerJoin(['p' => CmsProposal::tableName()], 'p.id = v.cms_proposal_id')
            ->andWhere(['p.cms_site_id' => $siteId])
            ->andWhere(['not', ['v.our_contractor_id' => null]])
            ->orderBy(['v.id' => SORT_DESC])
            ->select('v.our_contractor_id')
            ->scalar();
        if ($last) {
            return (int)$last;
        }
        $id = CmsContractor::find()->our()->andWhere(['cms_site_id' => $siteId])->orderBy(['id' => SORT_ASC])->select('id')->scalar();

        return $id ? (int)$id : null;
    }

    private function write(CmsProposal $proposal, ?CmsProposalVersion $version, array $content): CmsProposalVersion
    {
        if (!$version) {
            $version = new CmsProposalVersion([
                'cms_proposal_id' => (int)$proposal->id,
                'version'         => (int)CmsProposalVersion::find()->andWhere(['cms_proposal_id' => (int)$proposal->id])->max('version') + 1,
            ]);
        }
        $rows = ProposalItems::normalize((array)($content['items'] ?? []));
        $totals = ProposalItems::totals($rows, $content['discount_value'] ?? null);

        $version->intro = $content['intro'] ?? null;
        $version->terms = $content['terms'] ?? null;
        $version->discount_value = trim((string)($content['discount_value'] ?? '')) ?: null;
        $version->our_contractor_id = !empty($content['our_contractor_id']) ? (int)$content['our_contractor_id'] : null;
        $version->currency_code = (string)$proposal->currency_code;
        $version->subtotal_amount = $totals['subtotal'];
        $version->discount_amount = $totals['discount'];
        $version->amount = $totals['total'];
        if (!$version->save()) {
            throw new \RuntimeException('Не удалось сохранить версию КП: '.implode(' ', $version->getErrorSummary(true)));
        }

        CmsProposalItem::deleteAll(['cms_proposal_version_id' => (int)$version->id]);
        foreach ($rows as $index => $row) {
            $item = new CmsProposalItem($row);
            $item->cms_proposal_version_id = (int)$version->id;
            $item->sort = ($index + 1) * 100;
            if (!$item->save()) {
                throw new \RuntimeException('Не удалось сохранить позицию КП: '.implode(' ', $item->getErrorSummary(true)));
            }
        }

        if ((int)$proposal->current_version_id !== (int)$version->id) {
            $proposal->current_version_id = (int)$version->id;
            $proposal->updateAttributes(['current_version_id' => (int)$version->id]);
        }
        unset($proposal->currentVersion);
        unset($version->items);

        return $version;
    }

    private function transaction(callable $callback)
    {
        $db = CmsProposalVersion::getDb();
        if ($db->getTransaction()) {
            return $callback();
        }

        return $db->transaction($callback);
    }
}
