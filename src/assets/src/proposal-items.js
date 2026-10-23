/**
 * Items editor of proposals and templates (ProposalItemsInputWidget).
 * Keeps the rows in memory, writes them as JSON into the hidden input on
 * every change and shows the sums; the server recalculates them on save.
 */
(function ($) {
    'use strict';

    var uid = 0;

    function number(value) {
        if (typeof value === 'number') {
            return isFinite(value) ? value : 0;
        }
        var n = parseFloat(String(value || '').replace(/[\s ]/g, '').replace(',', '.'));
        return isFinite(n) ? n : 0;
    }

    function discountAmount(raw, base, quantity) {
        raw = String(raw || '').trim();
        base = Math.max(base, 0);
        if (!raw) {
            return 0;
        }
        var normalized = raw.replace(/[\s ]/g, '').replace(',', '.');
        var amount = normalized.indexOf('%') !== -1
            ? base * number(normalized.replace('%', '')) / 100
            : number(normalized) * Math.max(quantity, 0);
        return Math.round(Math.min(Math.max(amount, 0), base) * 10000) / 10000;
    }

    function escapeHtml(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c];
        });
    }

    function formatNumber(value) {
        var fixed = (Math.round(value * 100) / 100).toFixed(2).replace('.', ',');
        if (fixed.slice(-3) === ',00') {
            fixed = fixed.slice(0, -3);
        }
        return fixed.replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
    }

    function formatInput(value) {
        if (value === '' || value == null) {
            return '';
        }
        return String(Math.round(number(value) * 10000) / 10000).replace('.', ',');
    }

    var ICON_HANDLE = '<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="9" cy="6" r="1.6"/><circle cx="15" cy="6" r="1.6"/><circle cx="9" cy="12" r="1.6"/><circle cx="15" cy="12" r="1.6"/><circle cx="9" cy="18" r="1.6"/><circle cx="15" cy="18" r="1.6"/></svg>';
    var ICON_REMOVE = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>';
    var ICON_MORE = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h10"/></svg>';

    function Editor(root) {
        this.$root = $(root);
        this.config = this.$root.data('sx-proposal-items') || {};
        this.$value = this.$root.find('[data-sx-proposal-items-value]');
        this.$rows = this.$root.find('[data-sx-rows]');
        this.$empty = this.$root.find('[data-sx-empty]');
        this.$totals = this.$root.find('[data-sx-totals]');
        this.currency = this.config.currency || '₽';
        this.rows = (this.config.rows || []).map(function (row) {
            return $.extend({uid: ++uid}, row);
        });
        this.render();
        this.bind();
    }

    Editor.prototype.money = function (value) {
        return formatNumber(value) + ' ' + this.currency;
    };

    Editor.prototype.rowAmount = function (row) {
        var quantity = number(row.quantity) || 1;
        var base = quantity * Math.max(number(row.price), 0);
        return base - discountAmount(row.discount_value, base, quantity);
    };

    Editor.prototype.itemHtml = function (row) {
        var open = !!(row.description || (row.vat_name && row.vat_name !== this.config.defaultVat));
        return '<div class="sx-proposal-items__row" data-uid="' + row.uid + '">'
            + '<span class="sx-proposal-items__cell--handle" data-sx-handle title="Перетащите, чтобы изменить порядок">' + ICON_HANDLE + '</span>'
            + '<div class="sx-proposal-items__cell--name">'
            + '<input type="text" class="sx-proposal-items__input" data-f="name" value="' + escapeHtml(row.name) + '" placeholder="Наименование" aria-label="Наименование" maxlength="255">'
            + (row.shop_product_id ? '<span class="sx-proposal-items__badge" title="Связано с товаром или услугой каталога">из каталога</span>' : '')
            + '<div class="sx-proposal-items__more"' + (open ? '' : ' hidden') + '>'
            + '<textarea class="sx-proposal-items__input sx-proposal-items__description" data-f="description" rows="2" placeholder="Описание: что входит в позицию" aria-label="Описание">' + escapeHtml(row.description) + '</textarea>'
            + '<label class="sx-proposal-items__vat">НДС <input type="text" class="sx-proposal-items__input" data-f="vat_name" value="' + escapeHtml(row.vat_name || this.config.defaultVat) + '" maxlength="32"></label>'
            + '</div>'
            + '</div>'
            + '<label class="sx-proposal-items__cell--qty"><span class="sx-proposal-items__label">Кол-во</span><input type="text" inputmode="decimal" class="sx-proposal-items__input sx-proposal-items__num" data-f="quantity" value="' + escapeHtml(formatInput(row.quantity == null ? 1 : row.quantity)) + '"></label>'
            + '<label class="sx-proposal-items__cell--measure"><span class="sx-proposal-items__label">Ед.</span><input type="text" class="sx-proposal-items__input" data-f="measure_name" value="' + escapeHtml(row.measure_name || this.config.defaultMeasure) + '" maxlength="50"></label>'
            + '<label class="sx-proposal-items__cell--price"><span class="sx-proposal-items__label">Цена</span><input type="text" inputmode="decimal" class="sx-proposal-items__input sx-proposal-items__num" data-f="price" value="' + escapeHtml(formatInput(row.price)) + '" placeholder="0"></label>'
            + '<label class="sx-proposal-items__cell--discount"><span class="sx-proposal-items__label">Скидка</span><input type="text" class="sx-proposal-items__input sx-proposal-items__num" data-f="discount_value" value="' + escapeHtml(row.discount_value) + '" placeholder="0 или 10%" title="Сумма на единицу или процент"></label>'
            + '<span class="sx-proposal-items__cell--amount" data-sx-amount>' + this.money(this.rowAmount(row)) + '</span>'
            + '<span class="sx-proposal-items__cell--actions">'
            + '<button type="button" class="sx-proposal-items__icon" data-sx-more title="Описание и НДС" aria-label="Описание и НДС">' + ICON_MORE + '</button>'
            + '<button type="button" class="sx-proposal-items__icon sx-proposal-items__icon--danger" data-sx-remove title="Удалить строку" aria-label="Удалить строку">' + ICON_REMOVE + '</button>'
            + '</span>'
            + '</div>';
    };

    Editor.prototype.sectionHtml = function (row) {
        return '<div class="sx-proposal-items__row sx-proposal-items__row--section" data-uid="' + row.uid + '">'
            + '<span class="sx-proposal-items__cell--handle" data-sx-handle title="Перетащите, чтобы изменить порядок">' + ICON_HANDLE + '</span>'
            + '<div class="sx-proposal-items__cell--name"><input type="text" class="sx-proposal-items__input sx-proposal-items__section-name" data-f="name" value="' + escapeHtml(row.name) + '" placeholder="Название раздела, например «Дизайн»" aria-label="Название раздела" maxlength="255"></div>'
            + '<span class="sx-proposal-items__cell--amount" data-sx-amount></span>'
            + '<span class="sx-proposal-items__cell--actions">'
            + '<button type="button" class="sx-proposal-items__icon sx-proposal-items__icon--danger" data-sx-remove title="Удалить раздел (строки останутся)" aria-label="Удалить раздел">' + ICON_REMOVE + '</button>'
            + '</span>'
            + '</div>';
    };

    Editor.prototype.render = function () {
        var self = this;
        this.$rows.html(this.rows.map(function (row) {
            return row.type === 'section' ? self.sectionHtml(row) : self.itemHtml(row);
        }).join(''));
        this.update();
    };

    Editor.prototype.find = function (uidValue) {
        uidValue = parseInt(uidValue, 10);
        for (var i = 0; i < this.rows.length; i++) {
            if (this.rows[i].uid === uidValue) {
                return this.rows[i];
            }
        }
        return null;
    };

    /** Sums of rows and sections, totals and the hidden JSON. */
    Editor.prototype.update = function () {
        var self = this;
        var subtotal = 0;
        var section = null;
        var sectionSums = {};
        this.rows.forEach(function (row) {
            if (row.type === 'section') {
                section = row.uid;
                sectionSums[section] = 0;
                return;
            }
            var amount = self.rowAmount(row);
            subtotal += amount;
            if (section !== null) {
                sectionSums[section] += amount;
            }
            self.$rows.find('[data-uid="' + row.uid + '"] [data-sx-amount]').text(self.money(amount));
        });
        Object.keys(sectionSums).forEach(function (key) {
            self.$rows.find('[data-uid="' + key + '"] [data-sx-amount]').text(self.money(sectionSums[key]));
        });

        var discountInput = this.config.discountInputId ? document.getElementById(this.config.discountInputId) : null;
        var discount = discountInput ? discountAmount(discountInput.value, subtotal, 1) : 0;
        var html = '';
        if (discount > 0) {
            html += '<div class="sx-proposal-items__total-line"><span>Сумма позиций</span><span>' + this.money(subtotal) + '</span></div>'
                + '<div class="sx-proposal-items__total-line"><span>Скидка</span><span>−' + this.money(discount) + '</span></div>';
        }
        html += '<div class="sx-proposal-items__total-line sx-proposal-items__total-line--main"><span>Итого</span><span>' + this.money(subtotal - discount) + '</span></div>';
        this.$totals.html(html);
        this.$empty.prop('hidden', this.rows.length > 0);

        this.$value.val(JSON.stringify(this.rows.map(function (row) {
            var copy = $.extend({}, row);
            delete copy.uid;
            return copy;
        }))).trigger('change');
    };

    Editor.prototype.add = function (row, focus) {
        row = $.extend({uid: ++uid, type: 'item', quantity: 1, measure_name: this.config.defaultMeasure, vat_name: this.config.defaultVat}, row);
        this.rows.push(row);
        var $row = $(row.type === 'section' ? this.sectionHtml(row) : this.itemHtml(row));
        this.$rows.append($row);
        this.update();
        if (focus !== false) {
            $row.find('[data-f="name"]').trigger('focus');
        }
    };

    Editor.prototype.bind = function () {
        var self = this;

        this.$root.on('input change', '[data-f]', function (e) {
            var $input = $(this);
            var row = self.find($input.closest('[data-uid]').data('uid'));
            if (!row) {
                return;
            }
            row[$input.data('f')] = $input.val();
            if (e.type === 'input' || e.type === 'change') {
                self.update();
            }
        });

        this.$root.on('blur', '.sx-proposal-items__num', function () {
            var $input = $(this);
            var field = $input.data('f');
            if (field === 'quantity' || field === 'price') {
                $input.val(formatInput($input.val()));
            }
        });

        this.$root.on('click', '[data-sx-add]', function () {
            self.add({type: $(this).data('sx-add')});
        });

        this.$root.on('click', '[data-sx-remove]', function () {
            var uidValue = parseInt($(this).closest('[data-uid]').data('uid'), 10);
            self.rows = self.rows.filter(function (row) {
                return row.uid !== uidValue;
            });
            $(this).closest('[data-uid]').remove();
            self.update();
        });

        this.$root.on('click', '[data-sx-more]', function () {
            var $more = $(this).closest('[data-uid]').find('.sx-proposal-items__more');
            $more.prop('hidden', !$more.prop('hidden'));
            if (!$more.prop('hidden')) {
                $more.find('textarea').trigger('focus');
            }
        });

        // Enter in a field adds the next line instead of submitting the form.
        this.$root.on('keydown', '.sx-proposal-items__row input', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                var $row = $(this).closest('[data-uid]');
                if ($row.is(':last-child')) {
                    self.add({type: 'item'});
                } else {
                    $row.next().find('[data-f="name"]').trigger('focus');
                }
            }
        });

        if (this.config.discountInputId) {
            $(document).on('input change', '#' + this.config.discountInputId, function () {
                self.update();
            });
        }

        if (window.Sortable) {
            window.Sortable.create(this.$rows[0], {
                handle: '[data-sx-handle]',
                animation: 150,
                chosenClass: 'sx-sortable-chosen',
                dragClass: 'sx-sortable-drag',
                ghostClass: 'sx-proposal-items__row--ghost',
                onEnd: function () {
                    var order = self.$rows.children('[data-uid]').map(function () {
                        return parseInt($(this).data('uid'), 10);
                    }).get();
                    self.rows.sort(function (a, b) {
                        return order.indexOf(a.uid) - order.indexOf(b.uid);
                    });
                    self.update();
                }
            });
        }

        this.bindProductSearch();
    };

    Editor.prototype.bindProductSearch = function () {
        var self = this;
        var $search = this.$root.find('[data-sx-product-search]');
        var $suggest = this.$root.find('[data-sx-product-suggest]');
        if (!$search.length || !this.config.productSearchUrl) {
            return;
        }
        var timer = null;
        var request = null;
        var items = [];
        var active = -1;

        function close() {
            $suggest.prop('hidden', true).empty();
            active = -1;
        }

        function choose(index) {
            var item = items[index];
            if (!item) {
                return;
            }
            self.add({
                type: 'item',
                shop_product_id: item.id,
                name: item.name,
                price: item.price,
                measure_name: item.measure || self.config.defaultMeasure
            }, false);
            $search.val('');
            close();
            $search.trigger('focus');
        }

        function highlight(index) {
            active = index;
            $suggest.children().removeClass('is-active').eq(index).addClass('is-active');
        }

        function load() {
            if (request) {
                request.abort();
            }
            request = $.getJSON(self.config.productSearchUrl, {q: $search.val()}).done(function (data) {
                items = (data && data.items) || [];
                if (!items.length) {
                    $suggest.html('<div class="sx-proposal-items__suggest-empty">Ничего не найдено</div>').prop('hidden', false);
                    return;
                }
                $suggest.html(items.map(function (item, index) {
                    return '<button type="button" class="sx-proposal-items__suggest-item" data-index="' + index + '">'
                        + (item.image ? '<img src="' + escapeHtml(item.image) + '" alt="" loading="lazy">' : '<span class="sx-proposal-items__suggest-noimg"></span>')
                        + '<span class="sx-proposal-items__suggest-body"><span class="sx-proposal-items__suggest-name">' + escapeHtml(item.name) + '</span>'
                        + (item.category ? '<span class="sx-proposal-items__suggest-meta">' + escapeHtml(item.category) + '</span>' : '')
                        + '</span><span class="sx-proposal-items__suggest-price">' + escapeHtml(item.priceText || '') + '</span></button>';
                }).join('')).prop('hidden', false);
                highlight(0);
            });
        }

        // The list opens only for typed text: after a choice the empty field
        // gets the focus back and must not cover the fields below.
        $search.on('input focus', function (e) {
            clearTimeout(timer);
            if (e.type === 'focus' && !$search.val().trim()) {
                return;
            }
            timer = setTimeout(load, 250);
        });
        $search.on('keydown', function (e) {
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                if (items.length) {
                    highlight((active + (e.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length);
                }
            } else if (e.key === 'Enter') {
                e.preventDefault();
                choose(active < 0 ? 0 : active);
            } else if (e.key === 'Escape') {
                if (!$suggest.prop('hidden')) {
                    e.preventDefault();
                    e.stopPropagation();
                    close();
                }
            }
        });
        $suggest.on('mousedown', '[data-index]', function (e) {
            e.preventDefault();
            choose(parseInt($(this).data('index'), 10));
        });
        $search.on('blur', function () {
            setTimeout(close, 150);
        });
    };

    function init(context) {
        $(context || document).find('[data-sx-proposal-items]').each(function () {
            if (!$(this).data('sxProposalItemsEditor')) {
                $(this).data('sxProposalItemsEditor', new Editor(this));
            }
        });
    }

    $(function () {
        init(document);
    });
    $(document).on('pjax:end', function (e) {
        init(e.target);
    });
})(jQuery);
