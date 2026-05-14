/**
 * Divider composite: Select2 style + Range width + alignment buttons → hidden JSON (sto_options).
 */
(function($) {
    'use strict';

    function parseJson(str) {
        try {
            var o = JSON.parse(str);
            return o && typeof o === 'object' && !Array.isArray(o) ? o : null;
        } catch (e) {
            return null;
        }
    }

    function parseWidthJson(str) {
        var o = parseJson(str);
        if (!o) {
            return { v: '', u: '%', c: '' };
        }
        return {
            v: o.v !== undefined && o.v !== null ? String(o.v).trim() : '',
            u: o.u ? String(o.u).toLowerCase() : '%',
            c: o.c !== undefined && o.c !== null ? String(o.c) : ''
        };
    }

    function mergeToHidden($wrap) {
        var $root = $wrap.closest('.sto-divider');
        if (!$root.length) {
            return;
        }
        var $hidden = $root.find('.sto-divider-value').first();
        var $style = $root.find('.sto-divider__style').first();
        var $wHidden = $root.find('.sto-divider__width-json').first();
        var style = ($style.val && $style.val()) ? String($style.val()) : '';
        var wj = ($wHidden.val && $wHidden.val()) ? String($wHidden.val()).trim() : '';
        var wObj = parseWidthJson(wj);
        var $btn = $root.find('.sto-divider__align-btn.sto-is-active').first();
        var align = ($btn.attr('data-sto-divider-align') || 'center').toLowerCase();
        var out = JSON.stringify({ style: style, width: wObj, align: align });
        $hidden.val(out);
        $hidden.trigger('change');
    }

    function bindAlign($root) {
        $root.find('.sto-divider__align').each(function() {
            var $g = $(this);
            if ($g.data('stoDividerAlign')) {
                return;
            }
            $g.data('stoDividerAlign', 1);
            $g.on('click', '.sto-divider__align-btn', function(ev) {
                ev.preventDefault();
                var $b = $(this);
                var $row = $b.closest('.sto-divider');
                $row.find('.sto-divider__align-btn').removeClass('sto-is-active').attr('aria-pressed', 'false');
                $b.addClass('sto-is-active').attr('aria-pressed', 'true');
                mergeToHidden($row);
            });
        });
    }

    function bindMerge($root) {
        if ($root.data('stoDividerMerge')) {
            return;
        }
        $root.data('stoDividerMerge', 1);
        $root.on('change.stoDivider', '.sto-divider__style', function() {
            mergeToHidden($(this));
        });
        $root.on('change.stoDivider', '.sto-divider__width-json', function() {
            mergeToHidden($(this));
        });
        $root.on('select2:select select2:clear select2:unselect', '.sto-divider__style', function() {
            mergeToHidden($(this));
        });
    }

    window.stoInitDividerControls = function($scope) {
        var $ctx = ($scope && $scope.length) ? $scope : $(document);
        $ctx.find('.sto-divider[data-sto-divider="1"]').each(function() {
            var $root = $(this);
            bindMerge($root);
            bindAlign($root);
            mergeToHidden($root);
        });
    };
})(jQuery);
