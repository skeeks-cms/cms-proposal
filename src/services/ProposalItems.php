<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\proposal\services;

use skeeks\cms\proposal\models\CmsProposalItem;
use yii\helpers\ArrayHelper;

/**
 * Rows of proposal items as the editor, templates and versions exchange
 * them, and the totals. Amounts follow the bill (ShopBill): line amount =
 * quantity × price − line discount; a discount is a sum per unit ("500") or
 * a percent ("10%"). The discount on the whole proposal is a sum or a
 * percent of the items total. VAT is only a label.
 *
 * A row: type (item|section), name, description, measure_name, quantity,
 * price, discount_value, vat_name, shop_product_id; normalize() adds
 * discount_amount and amount.
 */
class ProposalItems
{
    public const DEFAULT_MEASURE = 'шт';
    public const DEFAULT_VAT = 'Без НДС';

    /**
     * Clean rows: empty lines and sections without a name are dropped,
     * numbers parsed ("1 500,50"), amounts calculated.
     * @return array[]
     */
    public static function normalize(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $type = ArrayHelper::getValue($row, 'type') === CmsProposalItem::TYPE_SECTION ? CmsProposalItem::TYPE_SECTION : CmsProposalItem::TYPE_ITEM;
            $name = self::text(ArrayHelper::getValue($row, 'name'), 255);
            if ($type === CmsProposalItem::TYPE_SECTION) {
                if ($name !== '') {
                    $result[] = ['type' => $type, 'name' => $name];
                }
                continue;
            }

            $productId = (int)ArrayHelper::getValue($row, 'shop_product_id') ?: null;
            $price = max(self::number(ArrayHelper::getValue($row, 'price', 0)), 0);
            if ($name === '' && !$productId && !$price) {
                continue;
            }
            $quantity = self::number(ArrayHelper::getValue($row, 'quantity', 1));
            if ($quantity <= 0) {
                $quantity = 1;
            }
            $base = $quantity * $price;
            $discountValue = self::text(ArrayHelper::getValue($row, 'discount_value'), 32);
            $discount = self::discountAmount($discountValue, $base, $quantity);

            $result[] = [
                'type'            => $type,
                'shop_product_id' => $productId,
                'name'            => $name !== '' ? $name : 'Позиция',
                'description'     => trim(str_replace("\r\n", "\n", (string)ArrayHelper::getValue($row, 'description', ''))),
                'measure_name'    => self::text(ArrayHelper::getValue($row, 'measure_name'), 50) ?: self::DEFAULT_MEASURE,
                'quantity'        => round($quantity, 4),
                'price'           => round($price, 4),
                'discount_value'  => $discountValue,
                'discount_amount' => $discount,
                'amount'          => round($base - $discount, 4),
                'vat_name'        => self::text(ArrayHelper::getValue($row, 'vat_name'), 32) ?: self::DEFAULT_VAT,
            ];
        }

        return $result;
    }

    /**
     * Discount of a line or of the whole proposal: "10%" of the base, or a
     * sum per unit times the quantity; never below zero or above the base.
     */
    public static function discountAmount(string $value, float $base, float $quantity = 1, float $fallback = 0): float
    {
        $base = max($base, 0);
        $value = trim($value);
        if ($value === '') {
            $amount = $fallback;
        } else {
            $normalized = str_replace([' ', "\u{00a0}", ','], ['', '', '.'], $value);
            $amount = str_contains($normalized, '%')
                ? $base * (float)str_replace('%', '', $normalized) / 100
                : (float)$normalized * max($quantity, 0);
        }

        return round(min(max($amount, 0), $base), 4);
    }

    /**
     * @param array[] $rows normalized rows
     * @return array{subtotal: float, discount: float, total: float}
     */
    public static function totals(array $rows, ?string $discountValue): array
    {
        $subtotal = 0.0;
        foreach ($rows as $row) {
            if (($row['type'] ?? '') !== CmsProposalItem::TYPE_SECTION) {
                $subtotal += (float)($row['amount'] ?? 0);
            }
        }
        $subtotal = round($subtotal, 4);
        $discount = self::discountAmount((string)$discountValue, $subtotal);

        return ['subtotal' => $subtotal, 'discount' => $discount, 'total' => round($subtotal - $discount, 4)];
    }

    /**
     * Rows grouped by sections for the card, the PDF and the client page:
     * [['name' => section name or '', 'rows' => [...], 'amount' => section total], ...].
     */
    public static function groups(array $rows): array
    {
        $groups = [];
        $current = ['name' => '', 'rows' => [], 'amount' => 0.0];
        foreach ($rows as $row) {
            if (($row['type'] ?? '') === CmsProposalItem::TYPE_SECTION) {
                if ($current['rows'] || $current['name'] !== '') {
                    $groups[] = $current;
                }
                $current = ['name' => (string)$row['name'], 'rows' => [], 'amount' => 0.0];
                continue;
            }
            $current['rows'][] = $row;
            $current['amount'] += (float)($row['amount'] ?? 0);
        }
        if ($current['rows'] || $current['name'] !== '') {
            $groups[] = $current;
        }

        return $groups;
    }

    /** "1 500,5" → 1500.5 */
    public static function number($value): float
    {
        if (is_int($value) || is_float($value)) {
            return (float)$value;
        }

        return (float)str_replace([' ', "\u{00a0}", ','], ['', '', '.'], trim((string)$value));
    }

    /** "12 500 ₽", "1 250,50 $"; without signs (the PDF font has no ₽): "12 500 руб." */
    public static function money($amount, string $currencyCode = 'RUB', bool $signs = true): string
    {
        $result = number_format((float)$amount, 2, ',', ' ');
        if (substr($result, -3) === ',00') {
            $result = substr($result, 0, -3);
        }
        $symbols = $signs ? ['RUB' => '₽', 'USD' => '$', 'EUR' => '€'] : ['RUB' => 'руб.'];

        return $result.' '.($symbols[$currencyCode] ?? $currencyCode);
    }

    /** "1,5" or "2" */
    public static function quantity($value): string
    {
        return rtrim(rtrim(number_format((float)$value, 4, ',', ' '), '0'), ',');
    }

    private static function text($value, int $max): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', (string)$value));

        return mb_substr($value, 0, $max);
    }
}
