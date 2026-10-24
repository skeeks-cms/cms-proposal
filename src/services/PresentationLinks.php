<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\proposal\services;

use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\services\LinkMarks;
use skeeks\cms\proposal\models\CmsProposalSend;

/**
 * Where the personal link of a presentation leads: the page stored in the
 * proposal (never an address from the link) with marks for Metrika, so the
 * visit is not a direct one: `utm_source=crm`, `utm_medium=<channel>`,
 * `utm_campaign=proposal-<number>` (`campaign-<id>` for a letter of a mailing
 * campaign). Marks the employee already put into the
 * address stay as they are.
 */
class PresentationLinks
{
    public const UTM_SOURCE = LinkMarks::UTM_SOURCE;

    /** "proposal-12"; the letters of a mailing campaign mark their visits "campaign-<id>". */
    public static function campaign(int $number): string
    {
        return 'proposal-'.$number;
    }

    public static function targetUrl(CmsProposalSend $send): string
    {
        $proposal = $send->proposal;
        // A letter of a mailing campaign marks its visits with the campaign.
        $campaignId = $send->cms_comm_message_id
            ? CmsCommMessage::find()->select('cms_comm_campaign_id')->andWhere(['id' => (int)$send->cms_comm_message_id])->scalar()
            : null;

        return self::addUtm((string)$proposal->target_url, [
            'utm_source' => self::UTM_SOURCE,
            'utm_medium' => (string)$send->channel,
            'utm_campaign' => $campaignId ? 'campaign-'.(int)$campaignId : self::campaign((int)$proposal->number),
        ]);
    }

    /**
     * Adds the marks that the address does not have yet; the fragment stays
     * at the end. The same rule as links of letters (skeeks/cms-communication
     * LinkMarks).
     */
    public static function addUtm(string $url, array $utm): string
    {
        return LinkMarks::addParams($url, $utm);
    }
}
