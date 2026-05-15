/**
 * Radio lists repeater: sortable rows, hidden JSON (sto_options).
 */
(function ($) {
    'use strict';

    function parseI18n($wrap) {
        var raw = $wrap.attr('data-sto-radio-lists-i18n') || '{}';
        try {
            var o = JSON.parse(raw);
            return o && typeof o === 'object' ? o : {};
        } catch (e) {
            return {};
        }
    }

    function parseOptions($wrap) {
        var raw = $wrap.attr('data-sto-radio-lists-options') || '{}';
        try {
            var o = JSON.parse(raw);
            return o && typeof o === 'object' ? o : {};
        } catch (e2) {
            return {};
        }
    }

    function maxRows($wrap) {
        return parseInt($wrap.attr('data-sto-radio-lists-max') || '0', 10);
    }

    function showTitles($wrap) {
        return $wrap.attr('data-sto-radio-lists-show-titles') === '1';
    }

    function layoutMode($wrap) {
        var m = String($wrap.attr('data-sto-radio-lists-layout') || 'stack');
        return m === 'inline' ? 'inline' : 'stack';
    }

    function readRows($wrap) {
        var $hidden = $wrap.find('.sto-radio-lists__value').first();
        var raw = String($hidden.val() || '').trim();
        if (!raw) {
            return [];
        }
        try {
            var arr = JSON.parse(raw);
            if (!Array.isArray(arr)) {
                return [];
            }
            return arr.map(function (x) {
                if (!x || typeof x !== 'object') {
                    return { title: '', value: '' };
                }
                return {
                    title: x.title != null ? String(x.title) : '',
                    value: x.value != null ? String(x.value) : ''
                };
            });
        } catch (e3) {
            return [];
        }
    }

    function setHiddenRows($wrap, rows) {
        var cap = maxRows($wrap);
        if (cap > 0 && rows.length > cap) {
            rows = rows.slice(0, cap);
        }
        var $hidden = $wrap.find('.sto-radio-lists__value').first();
        $hidden.val(JSON.stringify(rows)).trigger('change');
    }

    function collectFromDom($wrap) {
        var opts = parseOptions($wrap);
        var keys = Object.keys(opts);
        var firstKey = keys.length ? keys[0] : '';
        var out = [];
        $wrap.find('[data-sto-radio-lists-item]').each(function () {
            var $li = $(this);
            var title = '';
            if (showTitles($wrap)) {
                title = String($li.find('[data-sto-radio-lists-title]').first().val() || '');
            }
            var $chk = $li.find('[data-sto-radio-lists-choice]:checked').first();
            var v = $chk.length ? String($chk.val() || '') : '';
            if (!v || !Object.prototype.hasOwnProperty.call(opts, v)) {
                v = firstKey;
            }
            out.push({ title: title, value: v });
        });
        return out;
    }

    function updateHiddenFromDom($wrap) {
        setHiddenRows($wrap, collectFromDom($wrap));
    }

    function escapeAttr(s) {
        return String(s != null ? s : '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/"/g, '&quot;');
    }

    function escapeHtml(s) {
        return String(s != null ? s : '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function fieldIdForWrap($wrap) {
        var $row = $wrap.closest('[data-sto-field-id]');
        return String($row.attr('data-sto-field-id') || '').trim();
    }

    function rowHtml($wrap, row, rowKey) {
        var opts = parseOptions($wrap);
        var keys = Object.keys(opts);
        var i18n = parseI18n($wrap);
        var show = showTitles($wrap);
        var layout = layoutMode($wrap);
        var drag = i18n.drag != null ? String(i18n.drag) : '';
        var rem = i18n.remove != null ? String(i18n.remove) : '';
        var ph = i18n.rowTitlePh != null ? String(i18n.rowTitlePh) : '';
        var tlbl = i18n.rowTitleLbl != null ? String(i18n.rowTitleLbl) : '';
        var choose = i18n.chooseLbl != null ? String(i18n.chooseLbl) : '';
        var title = row && row.title != null ? String(row.title) : '';
        var val = row && row.value != null ? String(row.value) : '';
        if (!keys.length) {
            return '';
        }
        if (keys.indexOf(val) === -1) {
            val = keys[0];
        }
        var fid = fieldIdForWrap($wrap) || 'sto';
        var rname = 'sto_rl_' + fid + '_' + String(rowKey);
        var uid = 'sto-rl-' + Math.random().toString(36).slice(2, 10) + '-' + String(rowKey);
        var titleBlock = '';
        if (show) {
            titleBlock =
                '<div class="sto-radio-lists__title-wrap">' +
                '<input type="text" class="sto-radio-lists__title-input sto-input-text" data-sto-radio-lists-title value="' +
                escapeAttr(title) +
                '" placeholder="' +
                escapeAttr(ph) +
                '" aria-label="' +
                escapeAttr(tlbl) +
                '" />' +
                '</div>';
        }
        var radios = '';
        for (var k = 0; k < keys.length; k++) {
            var ov = keys[k];
            var lab = String(opts[ov] != null ? opts[ov] : ov);
            var rid = uid + '-' + ov;
            var checked = String(val) === String(ov);
            radios +=
                '<label class="sto-radio-lists__opt' +
                (checked ? ' sto-radio-lists__opt--checked' : '') +
                '" data-sto-radio-lists-opt>' +
                '<input type="radio" class="sto-radio-lists__opt-input" data-sto-radio-lists-choice name="' +
                escapeAttr(rname) +
                '" id="' +
                escapeAttr(rid) +
                '" value="' +
                escapeAttr(ov) +
                '"' +
                (checked ? ' checked="checked"' : '') +
                ' />' +
                '<span class="sto-radio-lists__opt-ui" aria-hidden="true"><span class="sto-radio-lists__opt-dot"></span></span>' +
                '<span class="sto-radio-lists__opt-label">' +
                escapeHtml(lab) +
                '</span></label>';
        }
        return (
            '<li class="sto-radio-lists__item" data-sto-radio-lists-item>' +
            '<button type="button" class="sto-radio-lists__drag" data-sto-radio-lists-drag aria-label="' +
            escapeAttr(drag) +
            '" title="' +
            escapeAttr(drag) +
            '"><i class="fa-light fa-grip-dots-vertical" aria-hidden="true"></i></button>' +
            '<div class="sto-radio-lists__body">' +
            titleBlock +
            '<div class="sto-radio-lists__radios sto-radio-lists__radios--' +
            escapeAttr(layout) +
            '" role="radiogroup" aria-label="' +
            escapeAttr(choose) +
            '">' +
            radios +
            '</div></div>' +
            '<button type="button" class="sto-radio-lists__remove" data-sto-radio-lists-remove aria-label="' +
            escapeAttr(rem) +
            '" title="' +
            escapeAttr(rem) +
            '"><i class="fa-light fa-trash-can" aria-hidden="true"></i></button>' +
            '</li>'
        );
    }

    function syncOptClasses($li) {
        $li.find('[data-sto-radio-lists-opt]').each(function () {
            var $lab = $(this);
            var on = $lab.find('[data-sto-radio-lists-choice]').prop('checked');
            $lab.toggleClass('sto-radio-lists__opt--checked', !!on);
        });
    }

    function bindSortable($wrap) {
        var $list = $wrap.find('[data-sto-radio-lists-list]').first();
        if ($list.data('stoRadioListsSortable')) {
            if ($list.hasClass('ui-sortable')) {
                $list.sortable('refresh');
            }
            return;
        }
        if (!$.fn.sortable) {
            return;
        }
        $list.data('stoRadioListsSortable', 1);
        $list.sortable({
            handle: '[data-sto-radio-lists-drag]',
            items: '> [data-sto-radio-lists-item]',
            tolerance: 'pointer',
            cursor: 'grabbing',
            cancel: 'input,textarea,select,option',
            placeholder: 'sto-radio-lists__item sto-radio-lists__item--placeholder',
            forcePlaceholderSize: true,
            update: function () {
                updateHiddenFromDom($wrap);
                if (typeof window.stoApplyDependentFieldVisibility === 'function') {
                    window.stoApplyDependentFieldVisibility();
                }
            }
        });
    }

    function bindOne($wrap) {
        if ($wrap.data('stoRadioListsBound')) {
            return;
        }
        $wrap.data('stoRadioListsBound', 1);

        var $list = $wrap.find('[data-sto-radio-lists-list]').first();

        $wrap.on('click', '[data-sto-radio-lists-add]', function (ev) {
            ev.preventDefault();
            var rows = readRows($wrap);
            var cap = maxRows($wrap);
            if (cap > 0 && rows.length >= cap) {
                return;
            }
            var opts = parseOptions($wrap);
            var keys = Object.keys(opts);
            if (!keys.length) {
                return;
            }
            rows.push({ title: '', value: keys[0] });
            setHiddenRows($wrap, rows);
            var nextKey = $list.children('[data-sto-radio-lists-item]').length;
            $list.append(rowHtml($wrap, rows[rows.length - 1], nextKey));
            if ($list.hasClass('ui-sortable')) {
                $list.sortable('refresh');
            }
            if (showTitles($wrap)) {
                $list.find('[data-sto-radio-lists-item]').last().find('[data-sto-radio-lists-title]').trigger('focus');
            }
            if (typeof window.stoApplyDependentFieldVisibility === 'function') {
                window.stoApplyDependentFieldVisibility();
            }
        });

        $wrap.on('click', '[data-sto-radio-lists-remove]', function (ev) {
            ev.preventDefault();
            var $li = $(this).closest('[data-sto-radio-lists-item]');
            $li.remove();
            updateHiddenFromDom($wrap);
            var $listRm = $wrap.find('[data-sto-radio-lists-list]').first();
            if ($listRm.hasClass('ui-sortable')) {
                $listRm.sortable('refresh');
            }
            if (typeof window.stoApplyDependentFieldVisibility === 'function') {
                window.stoApplyDependentFieldVisibility();
            }
        });

        $wrap.on('input change', '[data-sto-radio-lists-title]', function () {
            updateHiddenFromDom($wrap);
            if (typeof window.stoApplyDependentFieldVisibility === 'function') {
                window.stoApplyDependentFieldVisibility();
            }
        });

        $wrap.on('change', '[data-sto-radio-lists-choice]', function () {
            var $li = $(this).closest('[data-sto-radio-lists-item]');
            syncOptClasses($li);
            updateHiddenFromDom($wrap);
            if (typeof window.stoApplyDependentFieldVisibility === 'function') {
                window.stoApplyDependentFieldVisibility();
            }
        });

        bindSortable($wrap);

        $wrap.find('[data-sto-radio-lists-item]').each(function () {
            syncOptClasses($(this));
        });
    }

    window.stoInitRadioListsFields = function ($scope) {
        var $ctx = $scope && $scope.length ? $scope : $(document);
        $ctx.find('.sto-radio-lists[data-sto-radio-lists="1"]').each(function () {
            bindOne($(this));
        });
    };
})(jQuery);
