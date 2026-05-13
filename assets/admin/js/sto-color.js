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
                        $input.trigger('change');
                    }, 0);
                },
                clear: function() {
                    // Clear runs with `this` = the widget button, not the input — use closure.
                    window.setTimeout(function() {
                        $input.trigger('change');
                    }, 0);
                }
            });
            bindManualColorSync($input);
        });
    }

    window.stoInitColorPickers = initStoColorPickers;
})(jQuery);
