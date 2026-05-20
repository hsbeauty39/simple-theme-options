(function($) {
    var DEFAULT_PALETTES = [
        '#000000',
        '#ffffff',
        '#d63638',
        '#ff7900',
        '#ffcc00',
        '#00a32a',
        '#2271b1',
        '#7c3aed'
    ];

    function parsePalettes($wrap) {
        var raw = $wrap.attr('data-sto-palettes');
        if (!raw) {
            return DEFAULT_PALETTES;
        }
        try {
            var arr = JSON.parse(raw);
            return Array.isArray(arr) && arr.length ? arr : DEFAULT_PALETTES;
        } catch (e) {
            return DEFAULT_PALETTES;
        }
    }

    function bindManualColorSync($input) {
        if ($input.data('stoManualColorBound')) {
            return;
        }
        $input.data('stoManualColorBound', 1);
        $input.on('input.stoColorManual', function() {
            var $el = $(this);
            var val = String($el.val() || '').trim();
            clearTimeout($el.data('stoManualDebounce'));
            if (val.length < 3) {
                return;
            }
            $el.data(
                'stoManualDebounce',
                setTimeout(function() {
                    if (typeof $el.wpColorPicker !== 'function') {
                        return;
                    }
                    try {
                        $el.wpColorPicker('color', val);
                    } catch (ignore) {}
                }, 280)
            );
        });
        $input.on('blur.stoColorManual', function() {
            var $el = $(this);
            var val = String($el.val() || '').trim();
            clearTimeout($el.data('stoManualDebounce'));
            if (!val || typeof $el.wpColorPicker !== 'function') {
                return;
            }
            try {
                $el.wpColorPicker('color', val);
            } catch (ignore) {}
        });
    }

    function clampByte(n) {
        n = parseInt(n, 10);
        if (isNaN(n)) {
            return 0;
        }
        return Math.max(0, Math.min(255, n));
    }

    function rgbStringToHex(s) {
        s = String(s || '').trim();
        var m = s.toLowerCase().match(/^rgba?\(\s*([0-9]{1,3})\s*,\s*([0-9]{1,3})\s*,\s*([0-9]{1,3})/);
        if (!m) {
            m = s.toLowerCase().match(/^rgba?\(\s*([0-9]{1,3})\s+([0-9]{1,3})\s+([0-9]{1,3})(?:\s*\/|\s*\))/);
        }
        if (!m) {
            return '';
        }
        var r = clampByte(m[1]);
        var g = clampByte(m[2]);
        var b = clampByte(m[3]);
        return (
            '#' +
            ('0' + r.toString(16)).slice(-2) +
            ('0' + g.toString(16)).slice(-2) +
            ('0' + b.toString(16)).slice(-2)
        ).toLowerCase();
    }

    function normalizeCompareColor(val) {
        val = String(val || '').trim().toLowerCase();
        if (!val) {
            return '';
        }
        if (val.indexOf('rgb') === 0) {
            return rgbStringToHex(val);
        }
        if (val.charAt(0) === '#') {
            if (val.length === 4) {
                return (
                    '#' +
                    val.charAt(1) +
                    val.charAt(1) +
                    val.charAt(2) +
                    val.charAt(2) +
                    val.charAt(3) +
                    val.charAt(3)
                ).toLowerCase();
            }
            if (val.length === 9) {
                return val.slice(0, 7);
            }
            if (val.length > 7) {
                return val.slice(0, 7);
            }
        }
        return val;
    }

    function syncPaletteSwatchActive($input) {
        var $wrap = $input.closest('.sto-color-wrap');
        if (!$wrap.attr('data-sto-palette-ui')) {
            return;
        }
        var want = normalizeCompareColor($input.val());
        var $container = $input.closest('.wp-picker-container');
        var $links = $container.find('.iris-palette-container a.iris-palette');
        if (!$links.length) {
            return;
        }
        $links.removeClass('sto-palette-swatch--active');
        if (!want) {
            return;
        }
        $links.each(function() {
            var $a = $(this);
            var got = rgbStringToHex($a.css('background-color'));
            if (got && got === want) {
                $a.addClass('sto-palette-swatch--active');
                return false;
            }
        });
    }

    /**
     * Iris positions `.iris-palette-container` absolutely at the bottom of the square (single-row presets).
     * Advanced `palette_ui` uses a full-width grid below the square + strips — clear Iris inline height /
     * paddingBottom and set each `.iris-strip` height to the square height so sliders match the SV panel.
     */
    /**
     * Match hue / alpha rail height to the saturation square (Iris can mis-size when opened hidden).
     *
     * @param {jQuery} $input
     */
    function syncIrisStripHeights($input) {
        var $picker = $input.closest('.wp-picker-container').find('.iris-picker').first();
        if (!$picker.length) {
            return;
        }
        var $square = $picker.find('.iris-square').first();
        var squareHeight = $square.outerHeight();
        if (!squareHeight || squareHeight < 8 || squareHeight > 280) {
            return;
        }
        /* Vertical hue/alpha rails only — do not stretch horizontal strips. */
        $picker.find('.iris-strip').not('.iris-strip-horiz').each(function() {
            $(this).css('height', squareHeight);
        });
    }

    /**
     * Floating gradient dock: clear Iris inline sizing from off-screen init and re-sync rails.
     *
     * @param {jQuery} $input
     */
    function reflowGradientDockIrisLayout($input) {
        var $dock = $input.closest('.sto-gradient-color-dock');
        if (!$dock.length || $dock.hasClass('sto-gradient-color-dock--idle')) {
            return;
        }
        var $container = $input.closest('.wp-picker-container');
        var $picker = $container.find('.iris-picker').first();
        if (!$picker.length) {
            return;
        }

        $picker.css({ height: '', paddingBottom: '0' });

        $picker.find('.iris-picker-inner, .iris-border .iris-picker-inner').css({
            position: 'relative',
            top: 'auto',
            right: 'auto',
            left: 'auto',
            bottom: 'auto'
        });

        if (typeof $input.iris === 'function') {
            try {
                $input.iris('resize');
            } catch (ignore) {}
        }

        syncIrisStripHeights($input);

        var $square = $picker.find('.iris-square').first();
        if (!$square.length || $square.outerHeight() < 16) {
            window.setTimeout(function() {
                if (typeof $input.iris === 'function') {
                    try {
                        $input.iris('resize');
                    } catch (ignoreResize) {}
                }
                syncIrisStripHeights($input);
            }, 60);
        }
    }

    function openGradientDockPicker($input) {
        if (!$input || !$input.length) {
            return;
        }
        var $dock = $input.closest('.sto-gradient-color-dock');
        if (!$dock.length || $dock.hasClass('sto-gradient-color-dock--idle')) {
            return;
        }
        var $container = $input.closest('.wp-picker-container');
        if (!$container.length) {
            return;
        }
        var $holder = $container.find('.wp-picker-holder');
        var $toggle = $container.find('.wp-color-result').first();

        /* Build Iris once via WP toggle if missing; then keep open without toggle (toggle would close). */
        if (!$container.find('.iris-picker').length && $toggle.length) {
            $toggle.trigger('click');
        }

        $container.addClass('wp-picker-active');
        $holder.css('display', 'block');

        if (typeof $input.iris === 'function') {
            try {
                $input.iris('resize');
            } catch (ignoreShow) {}
        }
        reflowGradientDockIrisLayout($input);
        scheduleIrisReflow($input);
    }

    function closeGradientDockPicker($input) {
        if (!$input || !$input.length) {
            return;
        }
        var $container = $input.closest('.wp-picker-container');
        if (!$container.length) {
            return;
        }
        $container.removeClass('wp-picker-active');
        $container.find('.wp-picker-holder').hide();
    }

    function isGradientDockColorInput($input) {
        return $input.closest('[data-sto-gradient-color-dock]').length > 0;
    }

    /**
     * Init wpColorPicker for the gradient stop dock only (visible dock; no Iris preset row).
     *
     * @param {jQuery} $input `.sto-gradient-active-color`
     * @return {boolean} True when a new picker was created.
     */
    function initGradientDockColorPicker($input) {
        if (!$input || !$input.length) {
            return false;
        }
        if ($input.closest('.wp-picker-container').length) {
            return false;
        }
        var $dock = $input.closest('.sto-gradient-color-dock');
        if (!$dock.length || $dock.hasClass('sto-gradient-color-dock--idle')) {
            return false;
        }

        var $wrap = $input.closest('.sto-color-wrap');
        bindResetOnce($wrap, $input);

        $input.wpColorPicker({
            hide: true,
            type: 'full',
            width: 248,
            /* Suggested colors live in `.sto-gradient-dock-palette` — Iris presets overlap the SV square. */
            palettes: [],
            change: function() {
                window.setTimeout(function() {
                    $input.trigger('change');
                }, 0);
            },
            clear: function() {
                window.setTimeout(function() {
                    $input.trigger('change');
                }, 0);
            }
        });
        bindManualColorSync($input);
        openGradientDockPicker($input);
        return true;
    }

    function reflowPaletteUiIrisLayout($input) {
        var $wrap = $input.closest('.sto-color-wrap');
        if (!$wrap.attr('data-sto-palette-ui')) {
            return;
        }
        var $container = $input.closest('.wp-picker-container');
        var $picker = $container.find('.iris-picker').first();
        if (!$picker.length) {
            return;
        }
        syncIrisStripHeights($input);
        $picker.css({ height: '', paddingBottom: '' });
    }

    /**
     * Re-measure Iris after the popover is visible (gradient dock, narrow columns, palette_ui).
     *
     * @param {jQuery} $input `.sto-color-input` / `.wp-color-picker`
     */
    function reflowIrisColorPicker($input) {
        if (!$input || !$input.length) {
            return;
        }
        var $container = $input.closest('.wp-picker-container');
        if (!$container.hasClass('wp-picker-active')) {
            return;
        }
        if (typeof $input.iris === 'function') {
            try {
                $input.iris('resize');
            } catch (ignore) {}
        }
        syncIrisStripHeights($input);
        reflowGradientDockIrisLayout($input);
        reflowPaletteUiIrisLayout($input);
    }

    function scheduleIrisReflow($input) {
        var delays = isGradientDockColorInput($input) ? [0, 40, 120, 280, 480, 720] : [0, 50, 200, 400];
        for (var index = 0; index < delays.length; index++) {
            (function(delayMs) {
                window.setTimeout(function() {
                    reflowIrisColorPicker($input);
                }, delayMs);
            })(delays[index]);
        }
    }

    function schedulePaletteUiReflow($input) {
        scheduleIrisReflow($input);
    }

    /**
     * Tear down wp-color-picker so it can be rebuilt after the gradient dock becomes visible.
     *
     * @param {jQuery} $input
     */
    function destroyStoColorPicker($input) {
        if (!$input || !$input.length) {
            return;
        }
        if (!$input.closest('.wp-picker-container').length) {
            return;
        }
        if (typeof $input.wpColorPicker !== 'function') {
            return;
        }
        try {
            $input.wpColorPicker('destroy');
        } catch (ignore) {}
        $input.removeData('stoManualColorBound');
        $input.closest('.sto-color-wrap').removeData('stoColorResetBound');
        $input.closest('.wp-picker-container').removeData('stoPaletteUiBound');
    }

    function irisSquareTooSmall($input) {
        var $square = $input.closest('.wp-picker-container').find('.iris-square').first();
        return !$square.length || $square.outerHeight() < 16;
    }

    function bindAdvancedPaletteUi($input) {
        var $wrap = $input.closest('.sto-color-wrap');
        if (!$wrap.attr('data-sto-palette-ui')) {
            return;
        }
        var $container = $input.closest('.wp-picker-container');
        if ($container.data('stoPaletteUiBound')) {
            return;
        }
        $container.data('stoPaletteUiBound', 1);

        function sync() {
            window.requestAnimationFrame(function() {
                syncPaletteSwatchActive($input);
            });
        }

        $container.on('click.stoPaletteUi', '.iris-palette-container a.iris-palette', function() {
            window.setTimeout(sync, 0);
        });
        $container.on('click.stoPaletteUi', '.wp-color-result', function() {
            scheduleIrisReflow($input);
            window.setTimeout(sync, 400);
        });
        $input.on('keyup.stoPaletteUi', sync);
        window.setTimeout(function() {
            schedulePaletteUiReflow($input);
            sync();
        }, 200);
    }

    function bindResetOnce($wrap, $input) {
        if ($wrap.data('stoColorResetBound')) {
            return;
        }
        $wrap.data('stoColorResetBound', 1);
        $wrap.find('.sto-color-reset').on('click.stoColor', function() {
            var def =
                $input.attr('data-sto-default') ||
                $input.attr('data-default-color') ||
                '#ffffff';
            if ($input.closest('.wp-picker-container').length && typeof $input.wpColorPicker === 'function') {
                try {
                    $input.wpColorPicker('color', def);
                    // Same ordering as Iris `change`: defer so background hidden JSON syncs before
                    // `required` reads `getOptionFieldValue` (see wpColorPicker `change` below).
                    window.setTimeout(function() {
                        syncPaletteSwatchActive($input);
                        $input.trigger('change');
                    }, 0);
                } catch (ignore) {
                    $input.val(def).trigger('change');
                }
            } else {
                $input.val(def).trigger('change');
            }
        });
    }

    function initStoColorPickers($scope) {
        if (typeof $.fn.wpColorPicker !== 'function' || typeof $.fn.iris !== 'function') {
            return;
        }

        var $root = $scope && $scope.length ? $scope : $(document);
        $root.find('.sto-color-input').each(function() {
            var $input = $(this);
            if ($input.closest('.wp-picker-container').length) {
                return;
            }
            /* Gradient dock is off-screen while idle — Iris sizes to 0 if inited there. */
            if ($input.closest('.sto-gradient-color-dock--idle').length) {
                return;
            }
            if (!$input.is(':visible')) {
                return;
            }

            var $wrap = $input.closest('.sto-color-wrap');
            var palettes = parsePalettes($wrap);

            bindResetOnce($wrap, $input);

            $input.wpColorPicker({
                hide: true,
                type: 'full',
                palettes: palettes,
                change: function() {
                    // Iris updates the input value but does not always fire a native/jQuery
                    // `change` event. Background control syncs hidden JSON from that event.
                    // Defer: (1) avoid re-entrancy inside Iris; (2) run after Iris flushes the input
                    // so `required` / `getOptionFieldValue` see the new color (do not call
                    // `notifyVisibility` synchronously here — it ran before the deferred trigger
                    // and read stale background JSON). `trigger('change')` runs sto-background sync
                    // then `main.js` applies `required` + debounced widget refresh.
                    window.setTimeout(function() {
                        syncPaletteSwatchActive($input);
                        $input.trigger('change');
                    }, 0);
                },
                clear: function() {
                    // Clear runs with `this` = the widget button, not the input — use closure.
                    window.setTimeout(function() {
                        syncPaletteSwatchActive($input);
                        $input.trigger('change');
                    }, 0);
                }
            });
            bindAdvancedPaletteUi($input);
            bindManualColorSync($input);
        });
    }

    $(document).on('click.stoIrisReflow', '.sto-option-panel-wrapper .wp-color-result', function() {
        var $input = $(this).closest('.wp-picker-container').find('input.wp-color-picker, input.sto-color-input').first();
        if ($input.length) {
            scheduleIrisReflow($input);
        }
    });

    window.stoInitColorPickers = initStoColorPickers;
    window.stoInitGradientDockColorPicker = initGradientDockColorPicker;
    window.stoOpenGradientDockPicker = openGradientDockPicker;
    window.stoCloseGradientDockPicker = closeGradientDockPicker;
    window.stoReflowIrisColorPicker = reflowIrisColorPicker;
    window.stoScheduleIrisReflow = scheduleIrisReflow;
    window.stoDestroyColorPicker = destroyStoColorPicker;
    window.stoIrisSquareTooSmall = irisSquareTooSmall;
})(jQuery);
