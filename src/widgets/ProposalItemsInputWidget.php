<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\proposal\widgets;

use skeeks\cms\proposal\assets\ProposalItemsAsset;
use skeeks\cms\proposal\services\ProposalItems;
use yii\helpers\Html;
use yii\helpers\Json;
use yii\widgets\InputWidget;

/**
 * Editor of proposal items: sections, lines, recalculation of sums,
 * dragging rows, products and services of skeeks/cms-shop when it is
 * installed. The rows are posted as JSON in the hidden attribute (the same
 * rows ProposalItems::normalize() takes); the server recalculates
 * everything, the editor only shows the sums.
 */
class ProposalItemsInputWidget extends InputWidget
{
    /** URL of the product search (JSON `items`), or null without skeeks/cms-shop. */
    public ?string $productSearchUrl = null;

    /** Input of the discount on the whole proposal, to show the total with it. */
    public ?string $discountInputId = null;

    public string $currencyCode = 'RUB';

    public function run()
    {
        ProposalItemsAsset::register($this->view);

        $value = $this->hasModel() ? Html::getAttributeValue($this->model, $this->attribute) : $this->value;
        $rows = is_string($value) && $value !== '' ? Json::decode($value) : [];
        $rows = ProposalItems::normalize(is_array($rows) ? $rows : []);

        $input = $this->hasModel()
            ? Html::activeHiddenInput($this->model, $this->attribute, array_merge($this->options, ['value' => Json::encode($rows), 'data-sx-proposal-items-value' => '']))
            : Html::hiddenInput($this->name, Json::encode($rows), array_merge($this->options, ['data-sx-proposal-items-value' => '']));

        $config = [
            'rows'             => $rows,
            'productSearchUrl' => $this->productSearchUrl,
            'discountInputId'  => $this->discountInputId,
            'currency'         => ['RUB' => '₽', 'USD' => '$', 'EUR' => '€'][$this->currencyCode] ?? $this->currencyCode,
            'defaultMeasure'   => ProposalItems::DEFAULT_MEASURE,
            'defaultVat'       => ProposalItems::DEFAULT_VAT,
        ];

        $actions = Html::button('Позиция', ['class' => 'sx-button sx-button--secondary sx-button--sm', 'data-sx-add' => 'item', 'type' => 'button'])
            .Html::button('Раздел', ['class' => 'sx-button sx-button--secondary sx-button--sm', 'data-sx-add' => 'section', 'type' => 'button']);
        if ($this->productSearchUrl) {
            $actions .= Html::tag('div',
                Html::input('search', null, '', [
                    'class' => 'sx-proposal-items__search',
                    'placeholder' => 'Найти товар или услугу в каталоге',
                    'autocomplete' => 'off',
                    'data-sx-product-search' => '',
                    'aria-label' => 'Найти товар или услугу в каталоге',
                ]).Html::tag('div', '', ['class' => 'sx-proposal-items__suggest', 'data-sx-product-suggest' => '', 'hidden' => true]),
                ['class' => 'sx-proposal-items__search-wrap']
            );
        }

        return Html::tag('div',
            $input
            .Html::tag('div',
                Html::tag('div',
                    Html::tag('span', '', ['class' => 'sx-proposal-items__cell--handle'])
                    .Html::tag('span', 'Наименование', ['class' => 'sx-proposal-items__cell--name'])
                    .Html::tag('span', 'Кол-во', ['class' => 'sx-proposal-items__cell--qty'])
                    .Html::tag('span', 'Ед.', ['class' => 'sx-proposal-items__cell--measure'])
                    .Html::tag('span', 'Цена', ['class' => 'sx-proposal-items__cell--price'])
                    .Html::tag('span', 'Скидка', ['class' => 'sx-proposal-items__cell--discount'])
                    .Html::tag('span', 'Сумма', ['class' => 'sx-proposal-items__cell--amount'])
                    .Html::tag('span', '', ['class' => 'sx-proposal-items__cell--actions']),
                    ['class' => 'sx-proposal-items__head', 'aria-hidden' => 'true']
                )
                .Html::tag('div', '', ['class' => 'sx-proposal-items__rows', 'data-sx-rows' => ''])
                .Html::tag('div', 'Позиций пока нет. Добавьте позицию, раздел или выберите услугу из каталога.', ['class' => 'sx-proposal-items__empty', 'data-sx-empty' => '', 'hidden' => true]),
                ['class' => 'sx-proposal-items__table']
            )
            .Html::tag('div', $actions, ['class' => 'sx-proposal-items__actions'])
            .Html::tag('div', '', ['class' => 'sx-proposal-items__totals', 'data-sx-totals' => '', 'aria-live' => 'polite']),
            [
                'class' => 'sx-proposal-items',
                'data-sx-proposal-items' => Json::encode($config),
            ]
        );
    }
}
