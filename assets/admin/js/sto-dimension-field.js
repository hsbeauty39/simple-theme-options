/**
 * Dimension fields: N numeric slots + shared units + optional link → hidden JSON.
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

    function readSides($wrap) {
        try {
            var raw = $wrap.attr('data-sto-dimension-sides');
            var a = raw ? JSON.parse(raw) : [];
            return Array.isArray(a) ? a : [];
        } catch (e) {
            return [];
        }
    }

    function readAllowedUnits($wrap) {
        try {
            var raw = $wrap.attr('data-sto-dimension-units');
            var allowed = raw ? JSON.parse(raw) : [];
            if (Array.isArray(allowed) && allowed.length) {
                return allowed;
            }
        } catch (e2) {
            /* ignore */
        }
        return ['px', '%', 'rem', 'em', 'custom'];
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
        var min = parseFloat($wrap.attr('data-sto-dimension-min')) || 0;
        var max = parseFloat($wrap.attr('data-sto-dimension-max')) || 1000;
        var step = parseFloat($wrap.attr('data-sto-dimension-step')) || 1;
        return { min: min, max: max, step: step };
    }

    function customSuffixUiEnabled($wrap) {
        return $wrap.attr('data-sto-dimension-custom-suffix') !== '0';
    }

    function activeUnit($wrap) {
        var forced = ($wrap.attr('data-sto-dimension-forced-unit') || '').toLowerCase();
        if (forced) {
            return forced;
        }
        var $a = $wrap.find('.sto-dimension__unit.sto-is-active').first();
        var u = ($a.attr('data-sto-dimension-unit') || 'px').toLowerCase();
        var allowed = readAllowedUnits($wrap);
        if (allowed.indexOf(u) === -1) {
            u = allowed[0] || 'px';
        }
        return u;
    }

    function isLinked($wrap) {
        var $b = $wrap.find('[data-sto-dimension-link]');
        return $b.length && $b.hasClass('sto-is-active');
    }

    function setLinked($wrap, on) {
        var $b = $wrap.find('[data-sto-dimension-link]');
        if (!$b.length) {
            return;
        }
        $b.toggleClass('sto-is-active', on);
        $b.attr('aria-pressed', on ? 'true' : 'false');
        var $i = $b.find('i').first();
        $i.attr('class', 'fa-light ' + (on ? 'fa-link' : 'fa-link-slash'));
    }

    function applyUnitUi($wrap, unit) {
        $wrap.find('.sto-dimension__unit').each(function() {
            var $btn = $(this);
            var u = ($btn.attr('data-sto-dimension-unit') || '').toLowerCase();
            $btn.toggleClass('sto-is-active', u === unit);
        });
        var $suffix = $wrap.find('.sto-dimension__custom-suffix');
        var suffixOn = unit === 'custom' && customSuffixUiEnabled($wrap);
        if (suffixOn) {
            $suffix.prop('hidden', false).prop('disabled', false);
        } else {
            $suffix.prop('hidden', true).prop('disabled', true);
        }
    }

    function sideKeys($wrap) {
        var sides = readSides($wrap);
        var keys = [];
        for (var i = 0; i < sides.length; i++) {
            var k = sides[i] && sides[i].key ? String(sides[i].key) : '';
            if (k) {
                keys.push(k);
            }
        }
        return keys;
    }

    /**
     * @param {JQuery} $wrap
     * @returns {Array<HTMLInputElement>}
     */
    function orderedSideInputElements($wrap) {
        var keys = sideKeys($wrap);
        var els = [];
        for (var i = 0; i < keys.length; i++) {
            var n = $wrap.find('.sto-dimension__input[data-sto-dimension-key="' + keys[i] + '"]').get(0);
            if (n) {
                els.push(n);
            }
        }
        return els;
    }

    /**
     * @param {HTMLInputElement} el
     * @param {JQuery} $wrap
     * @param {number} delta -1 | +1
     * @returns {boolean} true if focus moved
     */
    function focusAdjacentSideInput(el, $wrap, delta) {
        var list = orderedSideInputElements($wrap);
        var idx = list.indexOf(el);
        if (idx < 0) {
            return false;
        }
        var next = list[idx + delta];
        if (next) {
            next.focus();
            return true;
        }
        return false;
    }

    /**
     * @param {JQuery} $wrap
     * @returns {boolean}
     */
    function focusLinkButton($wrap) {
        var el = $wrap.find('[data-sto-dimension-link]').get(0);
        if (el) {
            el.focus();
            return true;
        }
        return false;
    }

    /**
     * @param {JQuery} $wrap
     * @returns {boolean}
     */
    function focusFirstUnitButton($wrap) {
        var el = $wrap.find('.sto-dimension__unit').get(0);
        if (el) {
            el.focus();
            return true;
        }
        return false;
    }

    /**
     * @param {JQuery} $wrap
     * @returns {boolean}
     */
    function focusLastSideInput($wrap) {
        var el = orderedSideInputElements($wrap).slice(-1)[0];
        if (el) {
            el.focus();
            return true;
        }
        return false;
    }

    /**
     * @param {JQuery} $wrap
     * @returns {boolean}
     */
    function focusCustomSuffixIfEnabled($wrap) {
        var el = $wrap.find('.sto-dimension__custom-suffix').get(0);
        if (el && !el.disabled && !el.hidden) {
            el.focus();
            return true;
        }
        return false;
    }

    function readStoredDimension(o, defaultUnit) {
        var unit = defaultUnit || 'px';
        if (o && o.unit) {
            unit = String(o.unit).toLowerCase();
        } else if (o && o.u) {
            unit = String(o.u).toLowerCase();
        }
        var customSuffix = '';
        if (o && o.custom_suffix != null) {
            customSuffix = String(o.custom_suffix);
        } else if (o && o.c != null) {
            customSuffix = String(o.c);
        }
        var linked = !!(o && (o.linked || o.link));
        var values = o && o.values && typeof o.values === 'object' ? o.values : {};
        return { unit: unit, custom_suffix: customSuffix, linked: linked, values: values };
    }

    function syncHidden($wrap, triggerChange) {
        var meta = readMeta($wrap);
        var $hidden = $wrap.find('.sto-dimension-value');
        var unit = activeUnit($wrap);
        var $suffix = $wrap.find('.sto-dimension__custom-suffix');
        var customSuffix = unit === 'custom' && customSuffixUiEnabled($wrap) ? String($suffix.val() || '').trim() : '';
        var linked = isLinked($wrap);
        var values = {};
        var keys = sideKeys($wrap);
        for (var j = 0; j < keys.length; j++) {
            var $inp = $wrap.find('.sto-dimension__input[data-sto-dimension-key="' + keys[j] + '"]');
            values[keys[j]] = String($inp.val() != null ? $inp.val() : '').trim();
        }
        if (linked && keys.length) {
            var first = '';
            for (var k = 0; k < keys.length; k++) {
                if (values[keys[k]] !== '') {
                    first = values[keys[k]];
                    break;
                }
            }
            if (first !== '') {
                var num = parseFloat(first);
                if (!isNaN(num)) {
                    num = clamp(roundStep(num, meta.step), meta.min, meta.max);
                    first = formatNum(num, meta.step);
                }
                for (var m = 0; m < keys.length; m++) {
                    values[keys[m]] = first;
                    $wrap.find('.sto-dimension__input[data-sto-dimension-key="' + keys[m] + '"]').val(first);
                }
            }
        } else {
            for (var n = 0; n < keys.length; n++) {
                var kk = keys[n];
                var raw = values[kk];
                if (raw === '') {
                    continue;
                }
                var nm = parseFloat(raw);
                if (isNaN(nm)) {
                    values[kk] = '';
                    $wrap.find('.sto-dimension__input[data-sto-dimension-key="' + kk + '"]').val('');
                    continue;
                }
                nm = clamp(roundStep(nm, meta.step), meta.min, meta.max);
                var fs = formatNum(nm, meta.step);
                values[kk] = fs;
                $wrap.find('.sto-dimension__input[data-sto-dimension-key="' + kk + '"]').val(fs);
            }
        }
        var payload = {
            unit: unit,
            custom_suffix: unit === 'custom' ? customSuffix : '',
            linked: linked,
            values: values
        };
        var json = JSON.stringify(payload);
        $hidden.val(json);
        if (triggerChange) {
            $hidden.trigger('change');
        }
    }

    function applyFromHidden($wrap) {
        var $hidden = $wrap.find('.sto-dimension-value');
        var raw = $hidden.val();
        var o = parseJsonObject(raw);
        var defRaw = $wrap.attr('data-sto-dimension-default');
        var def = parseJsonObject(defRaw) || {};
        if (!o) {
            o = def;
        }
        var stored = readStoredDimension(o, 'px');
        applyUnitUi($wrap, stored.unit);
        var $suffix = $wrap.find('.sto-dimension__custom-suffix');
        $suffix.val(stored.custom_suffix || '');
        setLinked($wrap, stored.linked && $wrap.find('[data-sto-dimension-link]').length > 0);
        var vals = stored.values;
        var keys = sideKeys($wrap);
        for (var i = 0; i < keys.length; i++) {
            var kk = keys[i];
            var v = vals[kk] != null ? String(vals[kk]) : '';
            $wrap.find('.sto-dimension__input[data-sto-dimension-key="' + kk + '"]').val(v);
        }
    }

    function bindOne($wrap) {
        if ($wrap.data('stoDimensionBound')) {
            applyFromHidden($wrap);
            return;
        }
        $wrap.data('stoDimensionBound', 1);

        $wrap.on('click', '.sto-dimension__unit', function(ev) {
            ev.preventDefault();
            var u = ($(this).attr('data-sto-dimension-unit') || '').toLowerCase();
            applyUnitUi($wrap, u);
            syncHidden($wrap, true);
        });

        $wrap.on('change input', '.sto-dimension__custom-suffix', function() {
            syncHidden($wrap, true);
        });

        $wrap.on('input change', '.sto-dimension__input', function() {
            if (isLinked($wrap)) {
                var v = String($(this).val() != null ? $(this).val() : '').trim();
                var key = $(this).attr('data-sto-dimension-key') || '';
                var keys = sideKeys($wrap);
                for (var i = 0; i < keys.length; i++) {
                    $wrap.find('.sto-dimension__input[data-sto-dimension-key="' + keys[i] + '"]').val(v);
                }
            }
            syncHidden($wrap, true);
        });

        $wrap.on('click', '[data-sto-dimension-link]', function(ev) {
            ev.preventDefault();
            var on = !$(this).hasClass('sto-is-active');
            setLinked($wrap, on);
            if (on) {
                var $first = $wrap.find('.sto-dimension__input').first();
                var v = String($first.val() != null ? $first.val() : '').trim();
                var keys = sideKeys($wrap);
                for (var j = 0; j < keys.length; j++) {
                    $wrap.find('.sto-dimension__input[data-sto-dimension-key="' + keys[j] + '"]').val(v);
                }
            }
            syncHidden($wrap, true);
        });

        /* ---- Keyboard navigation (Theme Settings) ---- */
        $wrap.on('keydown', '.sto-dimension__input', function(ev) {
            var el = this;
            if (el.disabled || el.readOnly) {
                return;
            }
            var key = ev.key;
            if (key === 'ArrowLeft' || key === 'ArrowRight') {
                var val = el.value != null ? String(el.value) : '';
                var len = val.length;
                var rawS = el.selectionStart;
                var rawE = el.selectionEnd;
                var start = typeof rawS === 'number' && rawS >= 0 ? rawS : len;
                var end = typeof rawE === 'number' && rawE >= 0 ? rawE : len;
                if (start !== end) {
                    return;
                }
                if (key === 'ArrowLeft' && start === 0) {
                    if (focusAdjacentSideInput(el, $wrap, -1)) {
                        ev.preventDefault();
                    }
                } else if (key === 'ArrowRight' && end >= len) {
                    if (focusAdjacentSideInput(el, $wrap, 1)) {
                        ev.preventDefault();
                    } else if (focusLinkButton($wrap)) {
                        ev.preventDefault();
                    }
                }
            }
        });

        $wrap.on('keydown', '[data-sto-dimension-link]', function(ev) {
            var key = ev.key;
            if (key === 'ArrowLeft') {
                if (focusLastSideInput($wrap)) {
                    ev.preventDefault();
                }
            } else if (key === 'ArrowRight') {
                if (focusFirstUnitButton($wrap) || focusCustomSuffixIfEnabled($wrap)) {
                    ev.preventDefault();
                }
            }
        });

        $wrap.on('keydown', '.sto-dimension__unit', function(ev) {
            var $units = $wrap.find('.sto-dimension__unit');
            var idx = $units.index(this);
            if (idx < 0) {
                return;
            }
            var key = ev.key;
            if ($units.length >= 2) {
                var nextIdx = -1;
                if (key === 'ArrowDown' || key === 'ArrowRight') {
                    nextIdx = idx + 1;
                } else if (key === 'ArrowUp' || key === 'ArrowLeft') {
                    nextIdx = idx - 1;
                }
                if (nextIdx >= 0 && nextIdx < $units.length) {
                    var el = $units.get(nextIdx);
                    if (el) {
                        el.focus();
                    }
                    ev.preventDefault();
                    return;
                }
            }
            if (key === 'ArrowLeft' && idx === 0) {
                if (focusLinkButton($wrap) || focusLastSideInput($wrap)) {
                    ev.preventDefault();
                }
            } else if ((key === 'ArrowRight' || key === 'ArrowDown') && idx === $units.length - 1) {
                if (focusCustomSuffixIfEnabled($wrap)) {
                    ev.preventDefault();
                }
            }
        });

        $wrap.on('keydown', '.sto-dimension__custom-suffix', function(ev) {
            if (ev.key === 'ArrowLeft') {
                var $units = $wrap.find('.sto-dimension__unit');
                var lastU = $units.last().get(0);
                if (lastU) {
                    lastU.focus();
                    ev.preventDefault();
                } else if (focusLinkButton($wrap)) {
                    ev.preventDefault();
                } else if (focusLastSideInput($wrap)) {
                    ev.preventDefault();
                }
            }
        });

        applyFromHidden($wrap);
        syncHidden($wrap, false);
    }

    window.stoInitDimensionFields = function($scope) {
        var $root = $scope && $scope.length ? $scope : $(document);
        $root.find('.sto-dimension[data-sto-dimension]').each(function() {
            bindOne($(this));
        });
    };

    $(function() {
        if (typeof window.stoInitDimensionFields === 'function') {
            window.stoInitDimensionFields($('.sto-options-form'));
        }
    });
})(jQuery);
