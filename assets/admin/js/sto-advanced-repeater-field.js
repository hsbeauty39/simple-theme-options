/**
 * Advanced repeater: nested lists, hidden JSON (sto_options), sortable, collapse.
 */
(function ($) {
    'use strict';

    function fieldRow($el) {
        return $el.closest('[data-sto-field-id]');
    }

    function parseI18n($fieldRow) {
        var raw = $fieldRow.find('.sto-adv-rep').first().attr('data-sto-adv-rep-i18n') || '{}';
        try {
            var o = JSON.parse(raw);
            return o && typeof o === 'object' ? o : {};
        } catch (e) {
            return {};
        }
    }

    function rootRepeaterWrap($fieldRow) {
        return $fieldRow.find('.sto-adv-rep').first();
    }

    function rootHidden($fieldRow) {
        return rootRepeaterWrap($fieldRow).find('.sto-adv-rep__value').first();
    }

    function readLeaf($leaf) {
        var k = $leaf.attr('data-sto-adv-rep-kind') || '';
        if (k === 'switcher') {
            return $leaf.find('[data-sto-adv-rep-switcher]').prop('checked') ? '1' : '0';
        }
        if (k === 'select') {
            return String($leaf.find('[data-sto-adv-rep-select]').val() || '');
        }
        if (k === 'textarea') {
            return String($leaf.find('[data-sto-adv-rep-input]').val() || '');
        }
        return String($leaf.find('[data-sto-adv-rep-input]').val() || '');
    }

    function collectFieldset($fs) {
        var o = {};
        $fs.children().not('.sto-adv-rep__fieldset-title').each(function () {
            mergePart($(this), o);
        });
        return o;
    }

    function collectRepItems($nest) {
        var out = [];
        $nest.find('> ul[data-sto-adv-rep-list] > li[data-sto-adv-rep-item]').each(function () {
            var $b = $(this).find('[data-sto-adv-rep-body]').first();
            out.push(collectItemBody($b));
        });
        return out;
    }

    function mergePart($node, target) {
        if ($node.is('[data-sto-adv-rep-fieldset]')) {
            var fid = String($node.attr('data-sto-adv-rep-fieldset') || '').trim();
            if (fid) {
                target[fid] = collectFieldset($node);
            }
            return;
        }
        if ($node.is('[data-sto-adv-rep-leaf]')) {
            var key = String($node.attr('data-sto-adv-rep-key') || '').trim();
            if (key) {
                target[key] = readLeaf($node);
            }
            return;
        }
        if ($node.hasClass('sto-adv-rep--nested')) {
            var nk = String($node.attr('data-sto-adv-rep-nested-key') || '').trim();
            if (nk) {
                target[nk] = collectRepItems($node);
            }
        }
    }

    function collectItemBody($body) {
        var o = {};
        $body.children().each(function () {
            mergePart($(this), o);
        });
        return o;
    }

    function collectRoot($wrap) {
        var $list = $wrap.find('> ul[data-sto-adv-rep-list]').first();
        var rows = [];
        $list.children('li[data-sto-adv-rep-item]').each(function () {
            var $b = $(this).find('[data-sto-adv-rep-body]').first();
            rows.push(collectItemBody($b));
        });
        return rows;
    }

    function syncFromAny($el) {
        var $fr = fieldRow($el);
        var $top = rootRepeaterWrap($fr);
        if (!$top.length) {
            return;
        }
        var $hid = rootHidden($fr);
        if (!$hid.length) {
            return;
        }
        var rows = collectRoot($top);
        $hid.val(JSON.stringify(rows)).trigger('change');
    }

    function clearLeaf($leaf) {
        var k = $leaf.attr('data-sto-adv-rep-kind') || '';
        if (k === 'switcher') {
            $leaf.find('[data-sto-adv-rep-switcher]').prop('checked', false);
        } else if (k === 'select') {
            $leaf.find('[data-sto-adv-rep-select]').prop('selectedIndex', 0);
        } else {
            $leaf.find('[data-sto-adv-rep-input]').val('');
        }
    }

    function clearItem($li) {
        $li.find('[data-sto-adv-rep-leaf]').each(function () {
            clearLeaf($(this));
        });
        $li.find('.sto-adv-rep--nested').each(function () {
            var $n = $(this);
            var $ul = $n.find('> ul[data-sto-adv-rep-list]').first();
            $ul.find('> li').slice(1).remove();
            $ul.find('> li').first().each(function () {
                clearItem($(this));
            });
        });
        $li.find('[data-sto-adv-rep-fieldset]').each(function () {
            $(this)
                .children()
                .not('.sto-adv-rep__fieldset-title')
                .each(function () {
                    var $p = $(this);
                    if ($p.is('[data-sto-adv-rep-leaf]')) {
                        clearLeaf($p);
                    } else if ($p.hasClass('sto-adv-rep--nested')) {
                        var $ul = $p.find('> ul[data-sto-adv-rep-list]').first();
                        $ul.find('> li').slice(1).remove();
                        $ul.find('> li').first().each(function () {
                            clearItem($(this));
                        });
                    }
                });
        });
    }

    function stripRepData($root) {
        $root.find('ul[data-sto-adv-rep-list]').each(function () {
            var $l = $(this);
            if ($l.hasClass('ui-sortable')) {
                try {
                    $l.sortable('destroy');
                } catch (err) {
                    /* ignore */
                }
            }
            $l.removeData('stoAdvRepSortable');
        });
        $root.find('.sto-adv-rep').each(function () {
            $(this).removeData('stoAdvRepBound');
        });
    }

    function maxRows($wrap) {
        return parseInt($wrap.attr('data-sto-adv-rep-max') || '0', 10);
    }

    function bindSortable($wrap) {
        var $list = $wrap.find('> ul[data-sto-adv-rep-list]').first();
        if ($list.data('stoAdvRepSortable')) {
            if ($list.hasClass('ui-sortable')) {
                $list.sortable('refresh');
            }
            return;
        }
        if (!$.fn.sortable) {
            return;
        }
        $list.data('stoAdvRepSortable', 1);
        $list.sortable({
            handle: '[data-sto-adv-rep-drag]',
            items: '> li[data-sto-adv-rep-item]',
            tolerance: 'pointer',
            cursor: 'grabbing',
            // jQuery UI default cancel includes `button` — the grip is a <button>, so drags never start (same fix as multi_text).
            cancel: 'input,textarea,select,option',
            placeholder: 'sto-adv-rep__item sto-adv-rep__item--placeholder',
            forcePlaceholderSize: true,
            update: function () {
                syncFromAny($list);
            }
        });
    }

    function renumberItems($list) {
        var $fr = fieldRow($list);
        var i18n = parseI18n($fr);
        var sub = $list.attr('data-sto-adv-rep-sublist') === '1';
        var base = sub
            ? String(i18n.nestedItemLabel != null ? i18n.nestedItemLabel : 'Nested item')
            : String(i18n.itemLabel != null ? i18n.itemLabel : 'Item');
        $list.children('li[data-sto-adv-rep-item]').each(function (idx) {
            if (sub) {
                $(this)
                    .find('.sto-adv-rep__toggle-text[data-sto-adv-rep-nested-label]')
                    .text(base + ' ' + (idx + 1));
            } else {
                $(this)
                    .find('.sto-adv-rep__toggle-text')
                    .not('[data-sto-adv-rep-nested-label]')
                    .first()
                    .text(base + ' ' + (idx + 1));
            }
        });
    }

    function bindOne($wrap) {
        if ($wrap.data('stoAdvRepBound')) {
            return;
        }
        $wrap.data('stoAdvRepBound', 1);

        bindSortable($wrap);

        $wrap.on('click', '[data-sto-adv-rep-toggle]', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var $btn = $(this);
            var $li = $btn.closest('li[data-sto-adv-rep-item]');
            var $body = $li.find('[data-sto-adv-rep-body]').first();
            var open = $btn.attr('aria-expanded') !== 'false';
            $body.stop(true, true);
            if (open) {
                $body.slideUp(120);
                $btn.attr('aria-expanded', 'false');
                $btn.find('.sto-adv-rep__chev').removeClass('fa-chevron-up').addClass('fa-chevron-down');
            } else {
                $body.slideDown(120);
                $btn.attr('aria-expanded', 'true');
                $btn.find('.sto-adv-rep__chev').removeClass('fa-chevron-down').addClass('fa-chevron-up');
            }
        });

        $wrap.on('click', '[data-sto-adv-rep-add]', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var $w = $(this).closest('.sto-adv-rep');
            var $list = $w.find('> ul[data-sto-adv-rep-list]').first();
            var cap = maxRows($w);
            var n = $list.children('li[data-sto-adv-rep-item]').length;
            if (cap > 0 && n >= cap) {
                return;
            }
            var $proto = $list.children('li[data-sto-adv-rep-item]').first().clone(true, true);
            stripRepData($proto);
            clearItem($proto);
            $list.append($proto);
            renumberItems($list);
            bindSortable($w);
            $proto.find('.sto-adv-rep--nested').each(function () {
                bindSortable($(this));
                bindOne($(this));
            });
            syncFromAny($w);
        });

        $wrap.on('click', '[data-sto-adv-rep-remove]', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var $li = $(this).closest('li[data-sto-adv-rep-item]');
            var $list = $li.parent();
            if ($list.children('li[data-sto-adv-rep-item]').length <= 1) {
                clearItem($li);
                syncFromAny($li);
                return;
            }
            $li.remove();
            renumberItems($list);
            syncFromAny($list);
        });

        $wrap.on('input change', '[data-sto-adv-rep-input], [data-sto-adv-rep-select], [data-sto-adv-rep-switcher]', function () {
            syncFromAny($wrap);
            if (typeof window.stoApplyDependentFieldVisibility === 'function') {
                window.stoApplyDependentFieldVisibility();
            }
        });

        $wrap.find('.sto-adv-rep--nested').each(function () {
            bindSortable($(this));
            bindOne($(this));
        });
    }

    window.stoInitAdvancedRepeaterFields = function ($scope) {
        var $ctx = $scope && $scope.length ? $scope : $(document);
        $ctx.find('.sto-field-row-advanced-repeater .sto-adv-rep').each(function () {
            var $w = $(this);
            if ($w.attr('data-sto-adv-rep-nested') === '1') {
                return;
            }
            bindOne($w);
        });
    };
})(jQuery);
