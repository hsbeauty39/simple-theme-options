/**
 * Border field — popover toggle + hidden JSON sync.
 *
 * Lifecycle hooks expected by `main.js`:
 *  - `window.stoInitBorderControls( $scope )` — called on initial document ready, on every
 *    section switch, and inside `refreshStoSelect2()`.
 *  - Form submit handler flushes any uncommitted values into the hidden input.
 *
 * Storage (per `BorderControl::sanitize_stored_value`):
 *  - `{ radius, radius_unit, style, width, width_unit, color }` JSON string.
 */
(function($) {
    'use strict';

    var DEFAULT_SHAPE = {
        radius: '0',
        radius_unit: 'px',
        style: 'none',
        width: '1',
        width_unit: 'px',
        color: '#000000'
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
                radius: o.radius != null ? String(o.radius) : DEFAULT_SHAPE.radius,
                radius_unit: o.radius_unit != null ? String(o.radius_unit) : DEFAULT_SHAPE.radius_unit,
                style: o.style != null ? String(o.style) : DEFAULT_SHAPE.style,
                width: o.width != null ? String(o.width) : DEFAULT_SHAPE.width,
                width_unit: o.width_unit != null ? String(o.width_unit) : DEFAULT_SHAPE.width_unit,
                color: o.color != null ? String(o.color) : DEFAULT_SHAPE.color
            });
        } catch (e) {
            return $.extend({}, DEFAULT_SHAPE);
        }
    }

    function writeHidden($hidden, data) {
        var payload = $.extend({}, DEFAULT_SHAPE, data || {});
        $hidden.val(JSON.stringify(payload)).trigger('change');
    }

    function getDefaults($wrap) {
        var raw = $wrap.attr('data-sto-border-defaults') || '';
        if (!raw) {
            return $.extend({}, DEFAULT_SHAPE);
        }
        try {
            var parsed = JSON.parse(raw);
            if (parsed && typeof parsed === 'object') {
                return $.extend({}, DEFAULT_SHAPE, parsed);
            }
        } catch (e) {
            /* ignore */
        }
        return $.extend({}, DEFAULT_SHAPE);
    }

    function updateSliderProgress($slider) {
        var min = parseFloat($slider.attr('min')) || 0;
        var max = parseFloat($slider.attr('max')) || 100;
        var val = parseFloat($slider.val());
        if (isNaN(val)) {
            val = min;
        }
        var pct = max === min ? 0 : ((val - min) / (max - min)) * 100;
        $slider.get(0).style.setProperty('--sto-border-pct', pct + '%');
    }

    function syncFromInputs($wrap) {
        var $hidden = $wrap.find('> .sto-border-value');
        var cur = parseHidden($hidden);

        $wrap.find('[data-sto-border-input]').each(function() {
            var $el = $(this);
            var key = $el.attr('data-sto-border-input');
            if (!key) {
                return;
            }

            if ($el.is('input[type="range"], input[type="number"]')) {
                var v = String($el.val() == null ? '' : $el.val()).trim();
                if (v !== '') {
                    cur[key] = v;
                }
            } else if ($el.is('select')) {
                cur[key] = String($el.val() || '');
            } else if ($el.hasClass('sto-color-input')) {
                cur[key] = String($el.val() || '');
            } else if ($el.is('.sto-border-popover__unit--locked')) {
                cur[key] = String($el.attr('data-sto-border-unit-value') || '');
            }
        });

        writeHidden($hidden, cur);
    }

    /** Pair the slider and number input for one section so they always read the same value. */
    function bindRange($wrap, kind) {
        var $section = $wrap.find('[data-sto-border-section="' + kind + '"]');
        if (!$section.length) {
            return;
        }
        var $slider = $section.find('input[type="range"][data-sto-border-input="' + kind + '"]');
        var $number = $section.find('input[type="number"][data-sto-border-input="' + kind + '"]');

        if ($slider.length) {
            updateSliderProgress($slider);
            $slider.on('input.stoBorder change.stoBorder', function() {
                if ($number.length) {
                    $number.val($(this).val());
                }
                updateSliderProgress($(this));
                syncFromInputs($wrap);
            });
        }

        if ($number.length) {
            $number.on('input.stoBorder change.stoBorder', function() {
                var v = String($(this).val() || '');
                if ($slider.length) {
                    $slider.val(v);
                    updateSliderProgress($slider);
                }
                syncFromInputs($wrap);
            });
        }
    }

    function bindUnitToggles($wrap) {
        $wrap.find('.sto-border-popover__unit--toggle').each(function() {
            var $group = $(this);
            var key = $group.attr('data-sto-border-input') || '';
            if (!key) {
                return;
            }
            $group.find('.sto-border-popover__unit-btn').on('click.stoBorder', function(e) {
                e.preventDefault();
                var $btn = $(this);
                $group.find('.sto-border-popover__unit-btn').removeClass('is-active').attr('aria-pressed', 'false');
                $btn.addClass('is-active').attr('aria-pressed', 'true');
                var $hidden = $wrap.find('> .sto-border-value');
                var cur = parseHidden($hidden);
                cur[key] = String($btn.attr('data-sto-border-unit-value') || '');
                writeHidden($hidden, cur);
            });
        });
    }

    function bindStyleSelect($wrap) {
        $wrap.find('select[data-sto-border-input="style"]').on('change.stoBorder', function() {
            syncFromInputs($wrap);
        });
    }

    function bindColor($wrap) {
        var $color = $wrap.find('input.sto-color-input[data-sto-border-input="color"]');
        if (!$color.length) {
            return;
        }
        $color.on('change.stoBorder input.stoBorder', function() {
            syncFromInputs($wrap);
        });
        // wp-color-picker reset button — flush after Iris commits.
        $wrap.find('.sto-color-reset').on('click.stoBorder', function() {
            window.setTimeout(function() {
                syncFromInputs($wrap);
            }, 0);
        });
    }

    function openPopover($wrap) {
        var $btn = $wrap.find('> .sto-border-edit');
        var $pop = $wrap.find('> .sto-border-popover');
        $pop.removeAttr('hidden');
        $btn.attr('aria-expanded', 'true');
        $wrap.attr('data-sto-border-open', '1');

        // Lazy-init wp-color-picker for the popover's color input (Iris requires the field be visible).
        if (!$wrap.data('stoBorderColorReady') && typeof window.stoInitColorPickers === 'function') {
            window.stoInitColorPickers($pop);
            $wrap.data('stoBorderColorReady', 1);
        }

        // Refresh slider progress in case the value changed while collapsed.
        $pop.find('input[type="range"]').each(function() {
            updateSliderProgress($(this));
        });
    }

    function closePopover($wrap) {
        var $btn = $wrap.find('> .sto-border-edit');
        var $pop = $wrap.find('> .sto-border-popover');
        $pop.attr('hidden', 'hidden');
        $btn.attr('aria-expanded', 'false');
        $wrap.removeAttr('data-sto-border-open');
    }

    function togglePopover($wrap) {
        var $pop = $wrap.find('> .sto-border-popover');
        if ($pop.is('[hidden]')) {
            // Close any other open popovers first.
            $('[data-sto-border-control][data-sto-border-open="1"]').each(function() {
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
        $wrap.find('> .sto-border-edit').on('click.stoBorder', function(e) {
            e.preventDefault();
            togglePopover($wrap);
        });
    }

    function applyValuesToInputs($wrap, data) {
        var src = $.extend({}, DEFAULT_SHAPE, data || {});

        var $r = $wrap.find('[data-sto-border-section="radius"]');
        if ($r.length) {
            $r.find('input[type="range"]').val(src.radius);
            $r.find('input[type="number"]').val(src.radius);
            $r.find('input[type="range"]').each(function() { updateSliderProgress($(this)); });
            applyUnitToggle($r, 'radius_unit', src.radius_unit);
        }

        var $s = $wrap.find('[data-sto-border-section="style"]');
        if ($s.length) {
            $s.find('select').val(src.style);
        }

        var $w = $wrap.find('[data-sto-border-section="width"]');
        if ($w.length) {
            $w.find('input[type="range"]').val(src.width);
            $w.find('input[type="number"]').val(src.width);
            $w.find('input[type="range"]').each(function() { updateSliderProgress($(this)); });
            applyUnitToggle($w, 'width_unit', src.width_unit);
        }

        var $c = $wrap.find('[data-sto-border-section="color"]');
        if ($c.length) {
            var $color = $c.find('input.sto-color-input');
            // wp-color-picker proxy: call API if available, else fall back to plain val().
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
    }

    function applyUnitToggle($section, key, unitValue) {
        var $toggle = $section.find('.sto-border-popover__unit--toggle[data-sto-border-input="' + key + '"]');
        if (!$toggle.length) {
            return;
        }
        $toggle.find('.sto-border-popover__unit-btn').each(function() {
            var $b = $(this);
            var match = ($b.attr('data-sto-border-unit-value') || '') === String(unitValue || '');
            $b.toggleClass('is-active', match).attr('aria-pressed', match ? 'true' : 'false');
        });
    }

    function bindRowReset($row) {
        $row.find('.sto-border-row-reset').on('click.stoBorder', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var raw = $btn.attr('data-sto-border-defaults') || '';
            var defaults;
            try {
                defaults = raw ? JSON.parse(raw) : null;
            } catch (err) {
                defaults = null;
            }
            if (!defaults || typeof defaults !== 'object') {
                defaults = $.extend({}, DEFAULT_SHAPE);
            }
            // Reset every BorderControl wrap inside the row (responsive panes share the same defaults).
            $row.find('[data-sto-border-control]').each(function() {
                var $wrap = $(this);
                applyValuesToInputs($wrap, defaults);
                writeHidden($wrap.find('> .sto-border-value'), defaults);
            });
            if (typeof window.stoApplyDependentFieldVisibility === 'function') {
                window.stoApplyDependentFieldVisibility();
            }
        });
    }

    function initOne($wrap) {
        if ($wrap.data('stoBorderInit')) {
            // Re-run lightweight tasks that benefit from being refreshed (e.g. progress fill).
            $wrap.find('input[type="range"]').each(function() {
                updateSliderProgress($(this));
            });
            return;
        }
        $wrap.data('stoBorderInit', 1);

        bindToggle($wrap);
        bindRange($wrap, 'radius');
        bindRange($wrap, 'width');
        bindUnitToggles($wrap);
        bindStyleSelect($wrap);
        bindColor($wrap);
    }

    function initStoBorderControls($scope) {
        var $root = $scope && $scope.length ? $scope : $(document);

        // Row-level reset is one click handler per row, regardless of how many BorderControl wraps it has.
        $root.find('.sto-field-row-border').each(function() {
            var $row = $(this);
            if (!$row.data('stoBorderRowInit')) {
                $row.data('stoBorderRowInit', 1);
                bindRowReset($row);
            }
        });

        $root.find('[data-sto-border-control]').each(function() {
            initOne($(this));
        });
    }

    /** Close popovers when clicking outside any of them. */
    $(document).on('click.stoBorderOutside', function(e) {
        var $tgt = $(e.target);
        if ($tgt.closest('[data-sto-border-control]').length) {
            return;
        }
        // Don't close while iris (color picker) UI elements are clicked — they're rendered outside the popover.
        if ($tgt.closest('.wp-picker-container, .iris-picker, .wp-color-result').length) {
            return;
        }
        $('[data-sto-border-control][data-sto-border-open="1"]').each(function() {
            closePopover($(this));
        });
    });

    /** Escape closes any open popover. */
    $(document).on('keydown.stoBorderEsc', function(e) {
        if (e.key !== 'Escape' && e.keyCode !== 27) {
            return;
        }
        $('[data-sto-border-control][data-sto-border-open="1"]').each(function() {
            closePopover($(this));
        });
    });

    /** Pre-flush all border hidden inputs before form submit. */
    $(document).on('submit', '#sto-theme-settings-options-form', function() {
        $(this).find('[data-sto-border-control]').each(function() {
            syncFromInputs($(this));
        });
    });

    $(function() {
        initStoBorderControls();
    });

    window.stoInitBorderControls = initStoBorderControls;
})(jQuery);
