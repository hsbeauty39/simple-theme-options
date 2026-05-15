/**
 * Multi text repeater: sortable rows, hidden JSON (sto_options).
 */
(function($) {
    'use strict';

    function parseI18n($wrap) {
        var raw = $wrap.attr('data-sto-multi-text-i18n') || '{}';
        try {
            var o = JSON.parse(raw);
            return o && typeof o === 'object' ? o : {};
        } catch (e) {
            return {};
        }
    }

    function maxRows($wrap) {
        return parseInt($wrap.attr('data-sto-multi-text-max') || '0', 10);
    }

    function readLines($wrap) {
        var $hidden = $wrap.find('.sto-multi-text__value').first();
        var raw = String($hidden.val() || '').trim();
        if (!raw) {
            return [];
        }
        try {
            var arr = JSON.parse(raw);
            if (!Array.isArray(arr)) {
                return [];
            }
            return arr.map(function(x) {
                return String(x != null ? x : '');
            });
        } catch (e2) {
            return [];
        }
    }

    function setHiddenLines($wrap, lines) {
        var cap = maxRows($wrap);
        if (cap > 0 && lines.length > cap) {
            lines = lines.slice(0, cap);
        }
        var $hidden = $wrap.find('.sto-multi-text__value').first();
        $hidden.val(JSON.stringify(lines)).trigger('change');
    }

    function collectFromDom($wrap) {
        var out = [];
        $wrap.find('[data-sto-multi-text-item]').each(function() {
            var v = $(this).find('[data-sto-multi-text-input]').first().val();
            out.push(v === undefined || v === null ? '' : String(v));
        });
        return out;
    }

    function updateHiddenFromDom($wrap) {
        setHiddenLines($wrap, collectFromDom($wrap));
    }

    function rowTemplate($wrap, value) {
        var i18n = parseI18n($wrap);
        var ph = '';
        var $firstInput = $wrap.find('.sto-multi-text__input').first();
        if ($firstInput.length && $firstInput.attr('placeholder')) {
            ph = $firstInput.attr('placeholder');
        }
        var drag = i18n.drag != null ? String(i18n.drag) : '';
        var rem = i18n.remove != null ? String(i18n.remove) : '';
        var rowLbl = i18n.rowLabel != null ? String(i18n.rowLabel) : '';
        var esc = String(value != null ? value : '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/"/g, '&quot;');
        return (
            '<li class="sto-multi-text__item" data-sto-multi-text-item>' +
            '<button type="button" class="sto-multi-text__drag" data-sto-multi-text-drag aria-label="' +
            String(drag).replace(/"/g, '&quot;') +
            '" title="' +
            String(drag).replace(/"/g, '&quot;') +
            '"><i class="fa-light fa-grip-dots-vertical" aria-hidden="true"></i></button>' +
            '<input type="text" class="sto-multi-text__input sto-input-text" data-sto-multi-text-input value="' +
            esc +
            '" placeholder="' +
            String(ph).replace(/"/g, '&quot;') +
            '" aria-label="' +
            String(rowLbl).replace(/"/g, '&quot;') +
            '" />' +
            '<button type="button" class="sto-multi-text__remove" data-sto-multi-text-remove aria-label="' +
            String(rem).replace(/"/g, '&quot;') +
            '" title="' +
            String(rem).replace(/"/g, '&quot;') +
            '"><i class="fa-light fa-trash-can" aria-hidden="true"></i></button>' +
            '</li>'
        );
    }

    function bindSortable($wrap) {
        var $list = $wrap.find('[data-sto-multi-text-list]').first();
        if ($list.data('stoMultiTextSortable')) {
            if ($list.hasClass('ui-sortable')) {
                $list.sortable('refresh');
            }
            return;
        }
        if (!$.fn.sortable) {
            return;
        }
        $list.data('stoMultiTextSortable', 1);
        $list.sortable({
            handle: '[data-sto-multi-text-drag]',
            items: '> [data-sto-multi-text-item]',
            tolerance: 'pointer',
            cursor: 'grabbing',
            // Core default cancel is "input,textarea,button,select,option,…" — the grip is a
            // <button>, so drags never started. Keep cancel for real form controls only.
            cancel: 'input,textarea,select,option',
            placeholder: 'sto-multi-text__item sto-multi-text__item--placeholder',
            forcePlaceholderSize: true,
            update: function() {
                updateHiddenFromDom($wrap);
                if (typeof window.stoApplyDependentFieldVisibility === 'function') {
                    window.stoApplyDependentFieldVisibility();
                }
            }
        });
    }

    function bindOne($wrap) {
        if ($wrap.data('stoMultiTextBound')) {
            return;
        }
        $wrap.data('stoMultiTextBound', 1);

        var $list = $wrap.find('[data-sto-multi-text-list]').first();

        $wrap.on('click', '[data-sto-multi-text-add]', function(ev) {
            ev.preventDefault();
            var lines = readLines($wrap);
            var cap = maxRows($wrap);
            if (cap > 0 && lines.length >= cap) {
                return;
            }
            lines.push('');
            setHiddenLines($wrap, lines);
            $list.append(rowTemplate($wrap, ''));
            if ($list.hasClass('ui-sortable')) {
                $list.sortable('refresh');
            }
            var $inputs = $wrap.find('[data-sto-multi-text-input]');
            if ($inputs.length) {
                $inputs.last().trigger('focus');
            }
            if (typeof window.stoApplyDependentFieldVisibility === 'function') {
                window.stoApplyDependentFieldVisibility();
            }
        });

        $wrap.on('click', '[data-sto-multi-text-remove]', function(ev) {
            ev.preventDefault();
            var $li = $(this).closest('[data-sto-multi-text-item]');
            $li.remove();
            updateHiddenFromDom($wrap);
            var $listRm = $wrap.find('[data-sto-multi-text-list]').first();
            if ($listRm.hasClass('ui-sortable')) {
                $listRm.sortable('refresh');
            }
            if (typeof window.stoApplyDependentFieldVisibility === 'function') {
                window.stoApplyDependentFieldVisibility();
            }
        });

        $wrap.on('input change', '[data-sto-multi-text-input]', function() {
            updateHiddenFromDom($wrap);
            if (typeof window.stoApplyDependentFieldVisibility === 'function') {
                window.stoApplyDependentFieldVisibility();
            }
        });

        bindSortable($wrap);
    }

    window.stoInitMultiTextFields = function($scope) {
        var $ctx = $scope && $scope.length ? $scope : $(document);
        $ctx.find('.sto-multi-text[data-sto-multi-text="1"]').each(function() {
            bindOne($(this));
        });
    };
})(jQuery);
