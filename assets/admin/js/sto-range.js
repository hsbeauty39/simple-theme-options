/**
 * Range fields: sync slider, number, units, optional custom suffix → hidden JSON (sto_options).
 */
(function($) {
    'use strict';

    function parseJsonObject(str) {
        try {
            var o = JSON.parse(str);
            return o && typeof o === 'object' && !Array.isArray(o) ? o : null;
        } catch (e) {
            return null;
        }
    }

    function clamp(n, min, max) {
        if (n < min) {
            return min;
        }
        if (n > max) {
            return max;
        }
        return n;
    }

    function roundStep(n, step) {
        if (!step || step <= 0) {
            return n;
        }
        var inv = Math.round(n / step);
        return inv * step;
    }

    function formatNum(n, step) {
        if (Math.floor(step) === step && Math.floor(n) === n) {
            return String(Math.round(n));
        }
        var s = String(Math.round(n * 10000) / 10000);
        s = s.replace(/(\.\d*?)0+$/, '$1').replace(/\.$/, '');
        return s === '' ? '0' : s;
    }

    function readMeta($wrap) {
        var min = parseFloat($wrap.attr('data-sto-range-min')) || 0;
        var max = parseFloat($wrap.attr('data-sto-range-max')) || 1000;
        var step = parseFloat($wrap.attr('data-sto-range-step')) || 1;
        return { min: min, max: max, step: step };
    }

    function readAllowedUnits($wrap) {
        try {
            var raw = $wrap.attr('data-sto-range-units');
            if (!raw) {
                var $legacy = $wrap.find('[data-sto-range-units]').first();
                raw = $legacy.attr('data-sto-range-units') || '';
            }
            var allowed = raw ? JSON.parse(raw) : [];
            if (Array.isArray(allowed) && allowed.length) {
                return allowed;
            }
        } catch (e) {
            /* ignore */
        }
        return ['px', '%', 'rem', 'em', 'custom'];
    }

    /** When "0", custom suffix input is omitted (custom-only + unit_label); c is always empty in JSON. */
    function customSuffixUiEnabled($wrap) {
        return $wrap.attr('data-sto-range-custom-suffix') !== '0';
    }

    function activeUnit($wrap) {
        var forced = ($wrap.attr('data-sto-range-forced-unit') || '').toLowerCase();
        if (forced) {
            return forced;
        }
        var $a = $wrap.find('.sto-range__unit.sto-is-active').first();
        var u = ($a.attr('data-sto-range-unit') || 'px').toLowerCase();
        var allowed = readAllowedUnits($wrap);
        if (allowed.indexOf(u) === -1) {
            u = allowed[0] || 'px';
        }
        return u;
    }

    function setSliderFill($slider, min, max, val) {
        var pct = max > min ? ((val - min) / (max - min)) * 100 : 0;
        pct = Math.max(0, Math.min(100, pct));
        $slider.css('--sto-range-pct', pct + '%');
    }

    function applyUnitUi($wrap, unit) {
        $wrap.find('.sto-range__unit').each(function() {
            var $b = $(this);
            var u = ($b.attr('data-sto-range-unit') || '').toLowerCase();
            $b.toggleClass('sto-is-active', u === unit);
        });
        var $suffix = $wrap.find('.sto-range__custom-suffix');
        var suffixOn = unit === 'custom' && customSuffixUiEnabled($wrap);
        if (suffixOn) {
            $suffix.prop('hidden', false).prop('disabled', false);
        } else {
            $suffix.prop('hidden', true).prop('disabled', true);
        }
    }

    /**
     * Build JSON from visible controls and write hidden (+ slider fill).
     */
    function syncHidden($wrap, triggerChange) {
        var meta = readMeta($wrap);
        var $hidden = $wrap.find('.sto-range-value');
        var $num = $wrap.find('.sto-range__number');
        var $slider = $wrap.find('.sto-range__slider');
        var $suffix = $wrap.find('.sto-range__custom-suffix');
        var u = activeUnit($wrap);
        var raw = String($num.val() || '').trim();
        var vOut = '';
        var numForSlider = meta.min;

        if (raw !== '' && !isNaN(parseFloat(raw))) {
            var n = roundStep(parseFloat(raw), meta.step);
            n = clamp(n, meta.min, meta.max);
            vOut = formatNum(n, meta.step);
            numForSlider = parseFloat(vOut);
        }

        var suffixUi = customSuffixUiEnabled($wrap);
        var cOut = (u === 'custom' && suffixUi) ? String($suffix.val() || '').replace(/[^a-zA-Z0-9%]/g, '').slice(0, 12) : '';
        if (u === 'custom' && suffixUi) {
            $suffix.val(cOut);
        }

        var json = JSON.stringify({ v: vOut, u: u, c: cOut });
        $hidden.val(json);

        if (vOut === '') {
            $slider.attr('data-sto-range-empty', '1');
            $slider.val(String(meta.min));
            setSliderFill($slider, meta.min, meta.max, meta.min);
        } else {
            $slider.removeAttr('data-sto-range-empty');
            $slider.val(String(numForSlider));
            setSliderFill($slider, meta.min, meta.max, numForSlider);
        }

        if (triggerChange) {
            window.setTimeout(function() {
                $hidden.trigger('change');
            }, 0);
        }
    }

    function applyHiddenToUi($wrap) {
        var meta = readMeta($wrap);
        var $hidden = $wrap.find('.sto-range-value');
        var $num = $wrap.find('.sto-range__number');
        var $slider = $wrap.find('.sto-range__slider');
        var $suffix = $wrap.find('.sto-range__custom-suffix');
        var allowed = readAllowedUnits($wrap);
        var u0 = allowed[0] || 'px';
        var o = parseJsonObject(($hidden.val() || '').trim()) || { v: '', u: u0, c: '' };
        var u = String(o.u || u0).toLowerCase();
        if (allowed.indexOf(u) === -1) {
            u = allowed[0];
        }
        applyUnitUi($wrap, u);
        var vStr = o.v === undefined || o.v === null ? '' : String(o.v).trim();
        if (vStr !== '' && !isNaN(parseFloat(vStr))) {
            var n = clamp(roundStep(parseFloat(vStr), meta.step), meta.min, meta.max);
            var vs = formatNum(n, meta.step);
            $num.val(vs);
            $slider.val(String(parseFloat(vs)));
            $slider.removeAttr('data-sto-range-empty');
            setSliderFill($slider, meta.min, meta.max, parseFloat(vs));
        } else {
            $num.val('');
            $slider.attr('data-sto-range-empty', '1');
            $slider.val(String(meta.min));
            setSliderFill($slider, meta.min, meta.max, meta.min);
        }
        if (u === 'custom' && customSuffixUiEnabled($wrap)) {
            $suffix.val(String(o.c || ''));
        }
    }

    function bindOne($wrap) {
        var $hidden = $wrap.find('.sto-range-value');
        var $num = $wrap.find('.sto-range__number');
        var $slider = $wrap.find('.sto-range__slider');
        var $suffix = $wrap.find('.sto-range__custom-suffix');

        applyHiddenToUi($wrap);

        $slider.off('input.stoRange').on('input.stoRange', function() {
            var meta = readMeta($wrap);
            var n = parseFloat(String($slider.val()));
            if (isNaN(n)) {
                return;
            }
            n = clamp(roundStep(n, meta.step), meta.min, meta.max);
            var vs = formatNum(n, meta.step);
            $num.val(vs);
            $slider.removeAttr('data-sto-range-empty');
            setSliderFill($slider, meta.min, meta.max, n);
            var u = activeUnit($wrap);
            var suffixUi = customSuffixUiEnabled($wrap);
            var cOut = (u === 'custom' && suffixUi) ? String($suffix.val() || '').replace(/[^a-zA-Z0-9%]/g, '').slice(0, 12) : '';
            $hidden.val(JSON.stringify({ v: vs, u: u, c: cOut }));
            window.setTimeout(function() {
                $hidden.trigger('change');
            }, 0);
        });

        $num.off('input.stoRange change.stoRange').on('input.stoRange change.stoRange', function() {
            syncHidden($wrap, true);
        });

        $wrap.off('click.stoRange').on('click.stoRange', '.sto-range__unit', function(ev) {
            ev.preventDefault();
            var u = ($(this).attr('data-sto-range-unit') || '').toLowerCase();
            if (!u) {
                return;
            }
            applyUnitUi($wrap, u);
            syncHidden($wrap, true);
        });

        $suffix.off('input.stoRange').on('input.stoRange', function() {
            syncHidden($wrap, true);
        });
    }

    window.stoInitRangeControls = function($scope) {
        var $root = $scope && $scope.length ? $scope : $(document);
        $root.find('.sto-range[data-sto-range]').each(function() {
            var $wrap = $(this);
            if ($wrap.data('stoRangeBound')) {
                applyHiddenToUi($wrap);
                return;
            }
            $wrap.data('stoRangeBound', 1);
            bindOne($wrap);
        });
    };

    $(function() {
        if (typeof window.stoInitRangeControls === 'function') {
            window.stoInitRangeControls($('.sto-options-form'));
        }

        $(document).on('submit', '#sto-theme-settings-options-form, .sto-options-form', function() {
            $('.sto-range[data-sto-range]').each(function() {
                syncHidden($(this), false);
            });
        });
    });
})(jQuery);
