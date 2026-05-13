/**
 * Shadow field — optional popover + hidden JSON sync.
 *
 * @see window.stoInitShadowControls
 */
(function($) {
    'use strict';

    var DEFAULT_SHAPE = {
        selector: '',
        color: 'rgba(0, 0, 0, 0.12)',
        horizontal: '0',
        vertical: '2',
        blur: '10',
        spread: '0',
        position: 'outline'
    };

    function parseHidden($hidden) {
        var raw = String($hidden.val() || '').trim();
        if (!raw) {
            return $.extend({}, DEFAULT_SHAPE);
        }
        try {
            var o = JSON.parse(raw);
            if (!o || typeof o !== 'object') {
                return $.extend({}, DEFAULT_SHAPE);
            }
            return $.extend({}, DEFAULT_SHAPE, {
                selector: o.selector != null ? String(o.selector) : DEFAULT_SHAPE.selector,
                color: o.color != null ? String(o.color) : DEFAULT_SHAPE.color,
                horizontal: o.horizontal != null ? String(o.horizontal) : DEFAULT_SHAPE.horizontal,
                vertical: o.vertical != null ? String(o.vertical) : DEFAULT_SHAPE.vertical,
                blur: o.blur != null ? String(o.blur) : DEFAULT_SHAPE.blur,
                spread: o.spread != null ? String(o.spread) : DEFAULT_SHAPE.spread,
                position: o.position != null ? String(o.position) : DEFAULT_SHAPE.position
            });
        } catch (e) {
            return $.extend({}, DEFAULT_SHAPE);
        }
    }

    function writeHidden($hidden, data) {
        var payload = $.extend({}, DEFAULT_SHAPE, data || {});
        $hidden.val(JSON.stringify(payload)).trigger('change');
    }

    function isPopupMode($wrap) {
        return String($wrap.attr('data-sto-shadow-popup') || '') === '1';
    }

    function syncFromInputs($wrap) {
        var $hidden = $wrap.find('> .sto-shadow-value');
        var cur = parseHidden($hidden);

        $wrap.find('[data-sto-shadow-input]').each(function() {
            var $el = $(this);
            var key = $el.attr('data-sto-shadow-input');
            if (!key) {
                return;
            }
            if ($el.is('input[type="text"]')) {
                cur[key] = String($el.val() == null ? '' : $el.val());
            } else if ($el.is('input[type="range"], input[type="number"]')) {
                var v = String($el.val() == null ? '' : $el.val()).trim();
                if (v !== '') {
                    cur[key] = v;
                }
            } else if ($el.is('select')) {
                cur[key] = String($el.val() || '');
            } else if ($el.hasClass('sto-color-input')) {
                cur[key] = String($el.val() || '');
            }
        });

        writeHidden($hidden, cur);
    }

    function bindRange($wrap, kind) {
        var $section = $wrap.find('[data-sto-shadow-section="' + kind + '"]');
        if (!$section.length) {
            return;
        }
        var $slider = $section.find('input[type="range"][data-sto-shadow-input="' + kind + '"]');
        var $number = $section.find('input[type="number"][data-sto-shadow-input="' + kind + '"]');

        if ($slider.length) {
            $slider.on('input.stoShadow change.stoShadow', function() {
                if ($number.length) {
                    $number.val($(this).val());
                }
                syncFromInputs($wrap);
            });
        }

        if ($number.length) {
            $number.on('input.stoShadow change.stoShadow', function() {
                var v = String($(this).val() || '');
                if ($slider.length) {
                    $slider.val(v);
                }
                syncFromInputs($wrap);
            });
        }
    }

    function bindPosition($wrap) {
        $wrap.find('select[data-sto-shadow-input="position"]').on('change.stoShadow', function() {
            syncFromInputs($wrap);
        });
    }

    function bindColor($wrap) {
        var $color = $wrap.find('input.sto-color-input[data-sto-shadow-input="color"]');
        if (!$color.length) {
            return;
        }
        $color.on('change.stoShadow input.stoShadow', function() {
            syncFromInputs($wrap);
        });
        $wrap.find('.sto-color-reset').on('click.stoShadow', function() {
            window.setTimeout(function() {
                syncFromInputs($wrap);
            }, 0);
        });
    }

    function openPopover($wrap) {
        var $btn = $wrap.find('[data-sto-shadow-edit-toggle]');
        var $pop = $wrap.find('> .sto-shadow-popover');
        if (!$pop.length) {
            return;
        }
        $pop.removeAttr('hidden');
        $btn.attr('aria-expanded', 'true');
        $wrap.attr('data-sto-shadow-open', '1');

        if (!$wrap.data('stoShadowColorReady') && typeof window.stoInitColorPickers === 'function') {
            window.stoInitColorPickers($pop);
            $wrap.data('stoShadowColorReady', 1);
        }
    }

    function closePopover($wrap) {
        var $btn = $wrap.find('[data-sto-shadow-edit-toggle]');
        var $pop = $wrap.find('> .sto-shadow-popover');
        if (!$pop.length) {
            return;
        }
        $pop.attr('hidden', 'hidden');
        $btn.attr('aria-expanded', 'false');
        $wrap.removeAttr('data-sto-shadow-open');
    }

    function togglePopover($wrap) {
        var $pop = $wrap.find('> .sto-shadow-popover');
        if (!$pop.length) {
            return;
        }
        if ($pop.is('[hidden]')) {
            $('[data-sto-shadow-control][data-sto-shadow-open="1"]').each(function() {
                if (this !== $wrap.get(0)) {
                    closePopover($(this));
                }
            });
            openPopover($wrap);
        } else {
            closePopover($wrap);
        }
    }

    function bindToggle($wrap) {
        $wrap.find('[data-sto-shadow-edit-toggle]').on('click.stoShadow', function(e) {
            e.preventDefault();
            togglePopover($wrap);
        });
    }

    function applyValuesToInputs($wrap, data) {
        var src = $.extend({}, DEFAULT_SHAPE, data || {});

        var $csec = $wrap.find('[data-sto-shadow-section="color"]');
        if ($csec.length) {
            var $color = $csec.find('input.sto-color-input');
            if ($color.wpColorPicker && typeof $color.wpColorPicker === 'function') {
                try {
                    $color.wpColorPicker('color', src.color);
                } catch (e) {
                    $color.val(src.color);
                }
            } else {
                $color.val(src.color);
            }
        }

        ['horizontal', 'vertical', 'blur', 'spread'].forEach(function(k) {
            var $sec = $wrap.find('[data-sto-shadow-section="' + k + '"]');
            if (!$sec.length) {
                return;
            }
            $sec.find('input[type="range"]').val(src[k]);
            $sec.find('input[type="number"]').val(src[k]);
        });

        $wrap.find('select[data-sto-shadow-input="position"]').val(src.position === 'inset' ? 'inset' : 'outline');
    }

    function bindRowReset($row) {
        $row.find('.sto-shadow-row-reset').on('click.stoShadow', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var raw = $btn.attr('data-sto-shadow-defaults') || '';
            var defaults;
            try {
                defaults = raw ? JSON.parse(raw) : null;
            } catch (err) {
                defaults = null;
            }
            if (!defaults || typeof defaults !== 'object') {
                defaults = $.extend({}, DEFAULT_SHAPE);
            }
            $row.find('[data-sto-shadow-control]').each(function() {
                var $wrap = $(this);
                applyValuesToInputs($wrap, defaults);
                writeHidden($wrap.find('> .sto-shadow-value'), defaults);
            });
            if (typeof window.stoApplyDependentFieldVisibility === 'function') {
                window.stoApplyDependentFieldVisibility();
            }
        });
    }

    function initOne($wrap) {
        if ($wrap.data('stoShadowInit')) {
            syncFromInputs($wrap);
            return;
        }
        $wrap.data('stoShadowInit', 1);

        bindRange($wrap, 'horizontal');
        bindRange($wrap, 'vertical');
        bindRange($wrap, 'blur');
        bindRange($wrap, 'spread');
        bindPosition($wrap);
        bindColor($wrap);

        if (isPopupMode($wrap)) {
            bindToggle($wrap);
        } else if (typeof window.stoInitColorPickers === 'function') {
            window.stoInitColorPickers($wrap);
            $wrap.data('stoShadowColorReady', 1);
        }
    }

    function initStoShadowControls($scope) {
        var $root = $scope && $scope.length ? $scope : $(document);

        $root.find('.sto-field-row-shadow').each(function() {
            var $row = $(this);
            if (!$row.data('stoShadowRowInit')) {
                $row.data('stoShadowRowInit', 1);
                bindRowReset($row);
            }
        });

        $root.find('[data-sto-shadow-control]').each(function() {
            initOne($(this));
        });
    }

    $(document).on('click.stoShadowOutside', function(e) {
        var $tgt = $(e.target);
        if ($tgt.closest('[data-sto-shadow-control]').length) {
            return;
        }
        if ($tgt.closest('.wp-picker-container, .iris-picker, .wp-color-result').length) {
            return;
        }
        $('[data-sto-shadow-control][data-sto-shadow-open="1"]').each(function() {
            closePopover($(this));
        });
    });

    $(document).on('keydown.stoShadowEsc', function(e) {
        if (e.key !== 'Escape' && e.keyCode !== 27) {
            return;
        }
        $('[data-sto-shadow-control][data-sto-shadow-open="1"]').each(function() {
            closePopover($(this));
        });
    });

    $(document).on('submit', '#sto-theme-settings-options-form', function() {
        $(this).find('[data-sto-shadow-control]').each(function() {
            syncFromInputs($(this));
        });
    });

    $(function() {
        initStoShadowControls();
    });

    window.stoInitShadowControls = initStoShadowControls;
})(jQuery);
