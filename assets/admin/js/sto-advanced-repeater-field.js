/**
 * Advanced repeater: nested lists, hidden JSON (sto_options), sortable, collapse.
 *
 * UI events are delegated once per `.sto-field-row-advanced-repeater` so every nesting level
 * shares one handler path (avoids per-wrap bind gaps and duplicate toggle handling).
 * After a row body is expanded or a new item is appended, calls `window.stoInitSelect2ForScope`
 * (from main.js) so selects inside formerly hidden bodies get Select2 — `initStoSelect2` skips
 * `:hidden` controls on first paint when `default_collapsed` is true.
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

    /**
     * Body element for one repeater row (direct child of li[data-sto-adv-rep-item]).
     *
     * @param {JQuery} $li
     * @return {JQuery}
     */
    function rowBody($li) {
        var $b = $li.children('[data-sto-adv-rep-body]').first();
        if ($b.length) {
            return $b;
        }
        var $head = $li.children('.sto-adv-rep__head').first();
        return $head.nextAll('[data-sto-adv-rep-body]').first();
    }

    function collectRepItems($nest) {
        var out = [];
        $nest.find('> ul[data-sto-adv-rep-list] > li[data-sto-adv-rep-item]').each(function () {
            var $b = rowBody($(this));
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
            var $b = rowBody($(this));
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
            var $cb = $leaf.find('[data-sto-adv-rep-switcher]');
            $cb.prop('checked', false);
            $cb.closest('.sto-switcher').removeClass('sto-switcher--on');
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
    }

    /**
     * Remove Select2 from a cloned subtree so new repeater rows start from native selects.
     */
    function stripSelect2FromRepSubtree($root) {
        if (typeof $.fn.select2 !== 'function') {
            return;
        }
        $root.find('select.sto-input-select').each(function () {
            var $s = $(this);
            if ($s.data('select2')) {
                try {
                    $s.select2('destroy');
                } catch (err) {
                    /* ignore */
                }
            }
        });
    }

    function refreshSelect2ForFieldRow($fromEl) {
        var $fr = $fromEl.closest('.sto-field-row-advanced-repeater');
        if (!$fr.length || typeof window.stoInitSelect2ForScope !== 'function') {
            return;
        }
        window.stoInitSelect2ForScope($fr);
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

    /**
     * Resolve the body block for the row whose toggle was clicked (all nesting depths).
     *
     * @param {JQuery} $btn
     * @return {JQuery}
     */
    function toggleRowBody($btn) {
        var $head = $btn.closest('.sto-adv-rep__head');
        var $body = $head.next('[data-sto-adv-rep-body]');
        if ($body.length && $body.is('[data-sto-adv-rep-body]')) {
            return $body;
        }
        var $li = $btn.closest('li[data-sto-adv-rep-item]');
        return $li.length ? rowBody($li) : $();
    }

    function bindSortablesUnderFieldRow($fieldRow) {
        $fieldRow.find('.sto-adv-rep').each(function () {
            bindSortable($(this));
        });
    }

    /**
     * One delegated listener tree per advanced-repeater field row (covers root + unlimited nesting).
     *
     * @param {JQuery} $fieldRow `.sto-field-row-advanced-repeater`
     */
    function bindDelegatedToFieldRow($fieldRow) {
        $fieldRow.on('click.stoAdvRep', '[data-sto-adv-rep-toggle]', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var $btn = $(this);
            var $body = toggleRowBody($btn);
            if (!$body.length) {
                return;
            }
            var isExpanded = String($btn.attr('aria-expanded') || '').toLowerCase() === 'true';
            $body.stop(true, true);
            if (isExpanded) {
                $body.slideUp(120);
                $btn.attr('aria-expanded', 'false');
                $btn.find('.sto-adv-rep__chev').removeClass('fa-chevron-up').addClass('fa-chevron-down');
            } else {
                $body.slideDown(120, function () {
                    $body.css({
                        display: '',
                        height: '',
                        overflow: '',
                        paddingTop: '',
                        paddingBottom: '',
                        marginTop: '',
                        marginBottom: ''
                    });
                    refreshSelect2ForFieldRow($btn);
                });
                $btn.attr('aria-expanded', 'true');
                $btn.find('.sto-adv-rep__chev').removeClass('fa-chevron-down').addClass('fa-chevron-up');
            }
        });

        $fieldRow.on('click.stoAdvRep', '[data-sto-adv-rep-add]', function (e) {
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
            stripSelect2FromRepSubtree($proto);
            clearItem($proto);
            $list.append($proto);
            renumberItems($list);
            bindSortable($w);
            $proto.find('.sto-adv-rep').each(function () {
                bindSortable($(this));
            });
            syncFromAny($w);
            refreshSelect2ForFieldRow($w);
        });

        $fieldRow.on('click.stoAdvRep', '[data-sto-adv-rep-remove]', function (e) {
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

        $fieldRow.on(
            'input.stoAdvRep change.stoAdvRep',
            '[data-sto-adv-rep-input], [data-sto-adv-rep-select], [data-sto-adv-rep-switcher]',
            function () {
                var $t = $(this);
                if ($t.is('[data-sto-adv-rep-switcher]')) {
                    $t.closest('.sto-switcher').toggleClass('sto-switcher--on', !!$t.prop('checked'));
                }
                syncFromAny($(this));
                if (typeof window.stoApplyDependentFieldVisibility === 'function') {
                    window.stoApplyDependentFieldVisibility();
                }
            }
        );
    }

    window.stoInitAdvancedRepeaterFields = function ($scope) {
        var $ctx = $scope && $scope.length ? $scope : $(document);
        $ctx.find('.sto-field-row-advanced-repeater').each(function () {
            var $fieldRow = $(this);
            if (!$fieldRow.data('stoAdvRepUiBound')) {
                $fieldRow.data('stoAdvRepUiBound', 1);
                bindDelegatedToFieldRow($fieldRow);
            }
            bindSortablesUnderFieldRow($fieldRow);
        });
    };
})(jQuery);
