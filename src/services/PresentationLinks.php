<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\proposal\services;

use skeeks\cms\proposal\models\CmsProposalSend;

/**
 * Where the personal link of a presentation leads: the page stored in the
 * proposal (never an address from the link) with marks for Metrika, so the
 * visit is not a direct one: `utm_source=crm`, `utm_medium=<channel>`,
 * `utm_campaign=proposal-<number>`. Marks the employee already put into the
 * address stay as they are.
 */
class PresentationLinks
{
    public const UTM_SOURCE = 'crm';

    /** "proposal-12": a campaign of stage 4 will mark its own visits "campaign-<id>". */
    public static function campaign(int $number): string
    {
        return 'proposal-'.$number;
    }

    public static function targetUrl(CmsProposalSend $send): string
    {
        $proposal = $send->proposal;

        return self::addUtm((string)$proposal->target_url, [
            'utm_source' => self::UTM_SOURCE,
            'utm_medium' => (string)$send->channel,
            'utm_campaign' => self::campaign((int)$proposal->number),
        ]);
    }

    /** Adds the marks that the address does not have yet; the fragment stays at the end. */
    public static function addUtm(string $url, array $utm): string
    {
        $fragment = '';
        if (($hash = strpos($url, '#')) !== false) {
            $fragment = substr($url, $hash);
            $url = substr($url, 0, $hash);
        }
        $query = (string)parse_url($url, PHP_URL_QUERY);
        parse_str($query, $existing);
        $existing = array_change_key_case($existing, CASE_LOWER);
        $add = [];
        foreach ($utm as $key => $value) {
            if ($value !== '' && !array_key_exists(strtolower($key), $existing)) {
                $add[$key] = $value;
            }
        }
        if ($add) {
            $url .= (str_contains($url, '?') ? (str_ends_with($url, '?') || str_ends_with($url, '&') ? '' : '&') : '?').http_build_query($add, '', '&', PHP_QUERY_RFC3986);
        }

        return $url.$fragment;
    }
}
