/**
 * Alignment segmented control: buttons (+ optional clear) → hidden input (sto_options).
 */
(function($) {
    'use strict';

    function setActive($wrap, value) {
        var $hidden = $wrap.find('.sto-alignment-value').first();
        $hidden.val(value);
        $wrap.find('.sto-alignment__choice').each(function() {
            var $b = $(this);
            var v = $b.attr('data-sto-alignment-value') || '';
            var on = v === value;
            $b.toggleClass('sto-is-active', on).attr('aria-pressed', on ? 'true' : 'false');
            $b.closest('.sto-alignment__segment').toggleClass('sto-alignment__segment--selected', on);
        });
        window.setTimeout(function() {
            $hidden.trigger('change');
        }, 0);
    }

    function bindOne($wrap) {
        if ($wrap.data('stoAlignmentBound')) {
            return;
        }
        $wrap.data('stoAlignmentBound', 1);

        $wrap.on('click', '.sto-alignment__choice', function(ev) {
            ev.preventDefault();
            var v = $(this).attr('data-sto-alignment-value') || '';
            setActive($wrap, v);
        });

        $wrap.on('click', '[data-sto-alignment-clear]', function(ev) {
            ev.preventDefault();
            setActive($wrap, '');
        });

        var $tb = $wrap.find('.sto-alignment__toolbar').first();
        $tb.off('keydown.stoAlignment').on('keydown.stoAlignment', function(ev) {
            var key = ev.key;
            if (key !== 'ArrowLeft' && key !== 'ArrowRight' && key !== 'Home' && key !== 'End') {
                return;
            }
            var $choices = $wrap.find('.sto-alignment__choice:visible');
            if (!$choices.length) {
                return;
            }
            var $cur = $choices.filter('.sto-is-active').first();
            var idx = $cur.length ? $choices.index($cur) : 0;
            if (key === 'Home') {
                idx = 0;
            } else if (key === 'End') {
                idx = $choices.length - 1;
            } else if (key === 'ArrowRight') {
                idx = Math.min($choices.length - 1, idx + 1);
            } else {
                idx = Math.max(0, idx - 1);
            }
            var $t = $choices.eq(idx);
            if ($t.length) {
                ev.preventDefault();
                $t.trigger('focus');
            }
        });
    }

    window.stoInitAlignmentFields = function($scope) {
        var $ctx = ($scope && $scope.length) ? $scope : $(document);
        $ctx.find('.sto-alignment[data-sto-alignment="1"]').each(function() {
            bindOne($(this));
        });
    };
})(jQuery);
