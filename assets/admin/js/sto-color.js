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
        var $links = $container.find('.iris-palette a');
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

        $container.on('click.stoPaletteUi', '.iris-palette a', function() {
            window.setTimeout(sync, 0);
        });
        $container.on('click.stoPaletteUi', '.wp-color-result', function() {
            window.setTimeout(sync, 400);
        });
        $input.on('keyup.stoPaletteUi', sync);
        window.setTimeout(sync, 200);
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

    window.stoInitColorPickers = initStoColorPickers;
})(jQuery);
