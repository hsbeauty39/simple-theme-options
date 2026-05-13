/**
 * Gradient field — bar + pins, flip, single-stop editor, type/angle, optional popover.
 *
 * @see window.stoInitGradientControls
 */
(function($) {
    'use strict';

    var DEFAULT_SHAPE = {
        type: 'linear',
        angle: '180',
        stops: [
            { color: '#2271b1', position: '0' },
            { color: '#ffffff', position: '100' }
        ]
    };

    function parseHidden($hidden) {
        var raw = String($hidden.val() || '').trim();
        if (!raw) {
            return $.extend(true, {}, DEFAULT_SHAPE);
        }
        try {
            var o = JSON.parse(raw);
            if (!o || typeof o !== 'object') {
                return $.extend(true, {}, DEFAULT_SHAPE);
            }
            var stops = Array.isArray(o.stops) ? o.stops : DEFAULT_SHAPE.stops;
            var clean = [];
            for (var i = 0; i < stops.length; i++) {
                var s = stops[i];
                if (!s || typeof s !== 'object') {
                    continue;
                }
                clean.push({
                    color: s.color != null ? String(s.color) : '#2271b1',
                    position: s.position != null ? String(s.position) : '0'
                });
            }
            if (clean.length < 2) {
                clean = $.extend(true, [], DEFAULT_SHAPE.stops);
            }
            return {
                type: o.type === 'radial' ? 'radial' : 'linear',
                angle: o.angle != null ? String(o.angle) : '180',
                stops: clean
            };
        } catch (e) {
            return $.extend(true, {}, DEFAULT_SHAPE);
        }
    }

    function writeHidden($hidden, data) {
        var payload = $.extend(true, {}, DEFAULT_SHAPE, data || {});
        if (!Array.isArray(payload.stops) || payload.stops.length < 2) {
            payload.stops = $.extend(true, [], DEFAULT_SHAPE.stops);
        }
        $hidden.val(JSON.stringify(payload)).trigger('change');
    }

    function compilePreviewCss(data) {
        var d = $.extend(true, {}, DEFAULT_SHAPE, data || {});
        var parts = [];
        for (var i = 0; i < d.stops.length; i++) {
            var st = d.stops[i];
            if (!st) {
                continue;
            }
            var c = String(st.color || '').trim();
            var p = String(st.position != null ? st.position : '0').trim();
            if (!c) {
                continue;
            }
            parts.push(c + ' ' + p + '%');
        }
        if (parts.length < 2) {
            return 'linear-gradient(180deg, #2271b1 0%, #ffffff 100%)';
        }
        var blob = parts.join(', ');
        if (d.type === 'radial') {
            return 'radial-gradient(circle at center, ' + blob + ')';
        }
        var ang = parseInt(d.angle, 10);
        if (isNaN(ang)) {
            ang = 180;
        }
        ang = ((ang % 360) + 360) % 360;
        return 'linear-gradient(' + ang + 'deg, ' + blob + ')';
    }

    function updatePreview($wrap) {
        var $hidden = $wrap.find('> .sto-gradient-value');
        var cur = parseHidden($hidden);
        var css = compilePreviewCss(cur);
        $wrap.find('[data-sto-gradient-preview]').each(function() {
            this.style.backgroundImage = css;
        });
    }

    function isPopupMode($wrap) {
        return String($wrap.attr('data-sto-gradient-popup') || '') === '1';
    }

    function maxStops($wrap) {
        var m = parseInt(String($wrap.attr('data-sto-gradient-max-stops') || '5'), 10);
        if (isNaN(m) || m < 2) {
            m = 2;
        }
        if (m > 8) {
            m = 8;
        }
        return m;
    }

    function getSelectedIndex($wrap) {
        var n = parseInt(String($wrap.attr('data-sto-gradient-selected') || '0'), 10);
        if (isNaN(n) || n < 0) {
            n = 0;
        }
        return n;
    }

    function setSelectedIndex($wrap, idx) {
        var $hidden = $wrap.find('> .sto-gradient-value');
        var cur = parseHidden($hidden);
        var max = cur.stops.length - 1;
        if (idx < 0) {
            idx = 0;
        }
        if (idx > max) {
            idx = max;
        }
        $wrap.attr('data-sto-gradient-selected', String(idx));
    }

    function readFormIntoState($wrap) {
        var $hidden = $wrap.find('> .sto-gradient-value');
        var cur = parseHidden($hidden);
        var sel = getSelectedIndex($wrap);
        if (sel >= cur.stops.length) {
            sel = cur.stops.length - 1;
        }
        var $c = $wrap.find('[data-sto-gradient-active-color]');
        var $p = $wrap.find('[data-sto-gradient-active-position]');
        if (cur.stops[sel]) {
            cur.stops[sel] = {
                color: $c.val() != null ? String($c.val()) : '',
                position: $p.val() != null ? String($p.val()) : '0'
            };
        }

        var $type = $wrap.find('select[data-sto-gradient-input="type"]');
        if ($type.length) {
            cur.type = String($type.val() || 'linear') === 'radial' ? 'radial' : 'linear';
        }

        var $ang = $wrap.find('input[type="number"][data-sto-gradient-input="angle"]');
        if ($ang.length) {
            var av = String($ang.val() != null ? $ang.val() : '').trim();
            if (av !== '') {
                cur.angle = av;
            }
        }

        writeHidden($hidden, cur);
        return cur;
    }

    function syncFromInputs($wrap) {
        readFormIntoState($wrap);
        updatePreview($wrap);
        refreshPins($wrap);

        var cur = parseHidden($wrap.find('> .sto-gradient-value'));
        var $angleCell = $wrap.find('[data-sto-gradient-section="angle"]');
        if (cur.type === 'radial') {
            $angleCell.attr('hidden', 'hidden');
        } else {
            $angleCell.removeAttr('hidden');
        }

        refreshToolbar($wrap);
    }

    function refreshToolbar($wrap) {
        var cur = parseHidden($wrap.find('> .sto-gradient-value'));
        var n = cur.stops.length;
        var mx = maxStops($wrap);
        var $add = $wrap.find('[data-sto-gradient-add-stop]');
        var $rm = $wrap.find('[data-sto-gradient-remove-stop]');
        if (n >= mx) {
            $add.attr('hidden', 'hidden');
        } else {
            $add.removeAttr('hidden');
        }
        if (n > 2) {
            $rm.removeAttr('hidden');
        } else {
            $rm.attr('hidden', 'hidden');
        }
    }

    function refreshPins($wrap) {
        var $hidden = $wrap.find('> .sto-gradient-value');
        var cur = parseHidden($hidden);
        var $pins = $wrap.find('[data-sto-gradient-pins]');
        $pins.empty();
        var sel = getSelectedIndex($wrap);
        if (sel >= cur.stops.length) {
            sel = 0;
            $wrap.attr('data-sto-gradient-selected', '0');
        }

        for (var i = 0; i < cur.stops.length; i++) {
            (function(stop, idx) {
                var p = parseFloat(String(stop.position != null ? stop.position : '0'), 10);
                if (isNaN(p)) {
                    p = 0;
                }
                p = Math.max(0, Math.min(100, p));
                var col = String(stop.color || '#cccccc');
                var $btn = $('<button type="button" class="sto-gradient-pin" />');
                $btn.attr('data-sto-gradient-pin', String(idx));
                $btn.attr('role', 'tab');
                $btn.attr('aria-selected', idx === sel ? 'true' : 'false');
                $btn.attr('aria-label', 'Stop ' + (idx + 1) + ', ' + Math.round(p) + '%');
                $btn.css('left', p + '%');
                if (idx === sel) {
                    $btn.addClass('sto-gradient-pin--active');
                }
                var $face = $('<span class="sto-gradient-pin__face" />');
                $face.css('background-color', col);
                $btn.append($face);
                $pins.append($btn);
            })(cur.stops[i], i);
        }
    }

    function loadActiveIntoEditors($wrap) {
        var $hidden = $wrap.find('> .sto-gradient-value');
        var cur = parseHidden($hidden);
        var sel = getSelectedIndex($wrap);
        if (sel >= cur.stops.length) {
            sel = 0;
            $wrap.attr('data-sto-gradient-selected', '0');
        }
        var st = cur.stops[sel];
        var col = st && st.color != null ? String(st.color) : '#2271b1';
        var pos = st && st.position != null ? String(st.position) : '0';

        var $c = $wrap.find('[data-sto-gradient-active-color]');
        $c.attr('data-default-color', col);
        $c.attr('data-sto-default', col);
        if ($c.wpColorPicker && typeof $c.wpColorPicker === 'function' && $c.closest('.wp-picker-container').length) {
            try {
                $c.wpColorPicker('color', col);
            } catch (e) {
                $c.val(col);
            }
        } else {
            $c.val(col);
        }
        $wrap.find('[data-sto-gradient-active-position]').val(pos);
    }

    function flipStops(stops) {
        var out = [];
        for (var i = 0; i < stops.length; i++) {
            var s = stops[i];
            var p = parseFloat(String(s.position != null ? s.position : '0'), 10);
            if (isNaN(p)) {
                p = 0;
            }
            out.push({
                color: String(s.color || ''),
                position: String(Math.round((100 - p) * 100) / 100)
            });
        }
        out.sort(function(a, b) {
            return parseFloat(a.position) - parseFloat(b.position);
        });
        return out;
    }

    function bindPinClicks($wrap) {
        $wrap.off('click.stoGradPin').on('click.stoGradPin', '[data-sto-gradient-pin]', function(e) {
            e.preventDefault();
            syncFromInputs($wrap);
            var idx = parseInt(String($(this).attr('data-sto-gradient-pin')), 10);
            if (isNaN(idx)) {
                return;
            }
            $wrap.attr('data-sto-gradient-selected', String(idx));
            loadActiveIntoEditors($wrap);
            refreshPins($wrap);
        });
    }

    function bindFlip($wrap) {
        $wrap.off('click.stoGradFlip', '[data-sto-gradient-flip]').on('click.stoGradFlip', '[data-sto-gradient-flip]', function(e) {
            e.preventDefault();
            var $hidden = $wrap.find('> .sto-gradient-value');
            var cur = parseHidden($hidden);
            var n = cur.stops.length;
            var oldSel = getSelectedIndex($wrap);
            var newSel = n > 0 ? Math.max(0, Math.min(n - 1, n - 1 - oldSel)) : 0;
            cur.stops = flipStops(cur.stops);
            writeHidden($hidden, cur);
            setSelectedIndex($wrap, newSel);
            loadActiveIntoEditors($wrap);
            updatePreview($wrap);
            refreshPins($wrap);
            refreshToolbar($wrap);
            if (typeof window.stoApplyDependentFieldVisibility === 'function') {
                window.stoApplyDependentFieldVisibility();
            }
        });
    }

    function bindActiveEditors($wrap) {
        $wrap
            .off('input.stoGradAct change.stoGradAct', '[data-sto-gradient-active-color], [data-sto-gradient-active-position]')
            .on('input.stoGradAct change.stoGradAct', '[data-sto-gradient-active-color], [data-sto-gradient-active-position]', function() {
                syncFromInputs($wrap);
            });
        $wrap.on('click.stoGradActReset', '.sto-gradient-active-color-wrap .sto-color-reset', function() {
            window.setTimeout(function() {
                syncFromInputs($wrap);
            }, 0);
        });
    }

    function bindTypeAngle($wrap) {
        $wrap.find('select[data-sto-gradient-input="type"]').on('change.stoGradTA', function() {
            syncFromInputs($wrap);
        });
        $wrap.find('input[type="number"][data-sto-gradient-input="angle"]').on('input.stoGradTA change.stoGradTA', function() {
            syncFromInputs($wrap);
        });
    }

    function bindAddRemove($wrap) {
        $wrap.off('click.stoGradAddRm');
        $wrap.on('click.stoGradAddRm', '[data-sto-gradient-add-stop]', function(e) {
            e.preventDefault();
            var $hidden = $wrap.find('> .sto-gradient-value');
            var cur = parseHidden($hidden);
            if (cur.stops.length >= maxStops($wrap)) {
                return;
            }
            syncFromInputs($wrap);
            cur = parseHidden($hidden);
            var last = cur.stops[cur.stops.length - 1];
            var prev = cur.stops[cur.stops.length - 2];
            var p1 = parseFloat(String(prev.position != null ? prev.position : '0'), 10);
            var p2 = parseFloat(String(last.position != null ? last.position : '100'), 10);
            if (isNaN(p1)) {
                p1 = 0;
            }
            if (isNaN(p2)) {
                p2 = 100;
            }
            var mid = Math.round((p1 + p2) / 2);
            cur.stops.splice(cur.stops.length - 1, 0, {
                color: String(last.color || '#ffffff'),
                position: String(mid)
            });
            writeHidden($hidden, cur);
            $wrap.attr('data-sto-gradient-selected', String(cur.stops.length - 2));
            loadActiveIntoEditors($wrap);
            updatePreview($wrap);
            refreshPins($wrap);
            refreshToolbar($wrap);
            if (typeof window.stoApplyDependentFieldVisibility === 'function') {
                window.stoApplyDependentFieldVisibility();
            }
        });

        $wrap.on('click.stoGradAddRm', '[data-sto-gradient-remove-stop]', function(e) {
            e.preventDefault();
            var $hidden = $wrap.find('> .sto-gradient-value');
            var cur = parseHidden($hidden);
            if (cur.stops.length <= 2) {
                return;
            }
            syncFromInputs($wrap);
            cur = parseHidden($hidden);
            var sel = getSelectedIndex($wrap);
            cur.stops.splice(sel, 1);
            writeHidden($hidden, cur);
            if (sel >= cur.stops.length) {
                sel = cur.stops.length - 1;
            }
            $wrap.attr('data-sto-gradient-selected', String(sel));
            loadActiveIntoEditors($wrap);
            updatePreview($wrap);
            refreshPins($wrap);
            refreshToolbar($wrap);
            if (typeof window.stoApplyDependentFieldVisibility === 'function') {
                window.stoApplyDependentFieldVisibility();
            }
        });
    }

    function openPopover($wrap) {
        var $btn = $wrap.find('[data-sto-gradient-edit-toggle]');
        var $pop = $wrap.find('> .sto-gradient-popover');
        if (!$pop.length) {
            return;
        }
        $pop.removeAttr('hidden');
        $btn.attr('aria-expanded', 'true');
        $wrap.attr('data-sto-gradient-open', '1');

        if (!$wrap.data('stoGradColorReady') && typeof window.stoInitColorPickers === 'function') {
            window.stoInitColorPickers($pop);
            $wrap.data('stoGradColorReady', 1);
        }
        window.setTimeout(function() {
            syncFromInputs($wrap);
            loadActiveIntoEditors($wrap);
        }, 50);
    }

    function closePopover($wrap) {
        var $btn = $wrap.find('[data-sto-gradient-edit-toggle]');
        var $pop = $wrap.find('> .sto-gradient-popover');
        if (!$pop.length) {
            return;
        }
        syncFromInputs($wrap);
        $pop.attr('hidden', 'hidden');
        $btn.attr('aria-expanded', 'false');
        $wrap.removeAttr('data-sto-gradient-open');
    }

    function togglePopover($wrap) {
        var $pop = $wrap.find('> .sto-gradient-popover');
        if (!$pop.length) {
            return;
        }
        if ($pop.is('[hidden]')) {
            $('[data-sto-gradient-control][data-sto-gradient-open="1"]').each(function() {
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
        $wrap.find('[data-sto-gradient-edit-toggle]').on('click.stoGrad', function(e) {
            e.preventDefault();
            togglePopover($wrap);
        });
    }

    function applyValuesToInputs($wrap, data) {
        var src = $.extend(true, {}, DEFAULT_SHAPE, data || {});
        if (!Array.isArray(src.stops) || src.stops.length < 2) {
            src.stops = $.extend(true, [], DEFAULT_SHAPE.stops);
        }
        var $hidden = $wrap.find('> .sto-gradient-value');
        writeHidden($hidden, src);
        $wrap.attr('data-sto-gradient-selected', '0');

        $wrap.find('select[data-sto-gradient-input="type"]').val(src.type === 'radial' ? 'radial' : 'linear');
        $wrap.find('input[type="number"][data-sto-gradient-input="angle"]').val(src.angle);

        loadActiveIntoEditors($wrap);
        updatePreview($wrap);
        refreshPins($wrap);
        refreshToolbar($wrap);

        var cur = parseHidden($hidden);
        var $angleCell = $wrap.find('[data-sto-gradient-section="angle"]');
        if (cur.type === 'radial') {
            $angleCell.attr('hidden', 'hidden');
        } else {
            $angleCell.removeAttr('hidden');
        }
    }

    function bindRowReset($row) {
        $row.find('.sto-gradient-row-reset').on('click.stoGrad', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var raw = $btn.attr('data-sto-gradient-defaults') || '';
            var defaults;
            try {
                defaults = raw ? JSON.parse(raw) : null;
            } catch (err) {
                defaults = null;
            }
            if (!defaults || typeof defaults !== 'object') {
                defaults = $.extend(true, {}, DEFAULT_SHAPE);
            }
            $row.find('[data-sto-gradient-control]').each(function() {
                var $wrap = $(this);
                applyValuesToInputs($wrap, defaults);
                syncFromInputs($wrap);
            });
            if (typeof window.stoApplyDependentFieldVisibility === 'function') {
                window.stoApplyDependentFieldVisibility();
            }
        });
    }

    function initOne($wrap) {
        if ($wrap.data('stoGradInit')) {
            syncFromInputs($wrap);
            updatePreview($wrap);
            refreshPins($wrap);
            return;
        }
        $wrap.data('stoGradInit', 1);
        $wrap.attr('data-sto-gradient-selected', $wrap.attr('data-sto-gradient-selected') || '0');

        bindPinClicks($wrap);
        bindFlip($wrap);
        bindActiveEditors($wrap);
        bindTypeAngle($wrap);
        bindAddRemove($wrap);

        if (isPopupMode($wrap)) {
            bindToggle($wrap);
        } else if (typeof window.stoInitColorPickers === 'function') {
            window.stoInitColorPickers($wrap.find('.sto-gradient-ui'));
            $wrap.data('stoGradColorReady', 1);
        }

        syncFromInputs($wrap);
        loadActiveIntoEditors($wrap);
        refreshPins($wrap);
    }

    function initStoGradientControls($scope) {
        var $root = $scope && $scope.length ? $scope : $(document);

        $root.find('.sto-field-row-gradient').each(function() {
            var $row = $(this);
            if (!$row.data('stoGradRowInit')) {
                $row.data('stoGradRowInit', 1);
                bindRowReset($row);
            }
        });

        $root.find('[data-sto-gradient-control]').each(function() {
            initOne($(this));
        });
    }

    $(document).on('click.stoGradOutside', function(e) {
        var $tgt = $(e.target);
        if ($tgt.closest('[data-sto-gradient-control]').length) {
            return;
        }
        if ($tgt.closest('.wp-picker-container, .iris-picker, .wp-color-result').length) {
            return;
        }
        $('[data-sto-gradient-control][data-sto-gradient-open="1"]').each(function() {
            closePopover($(this));
        });
    });

    $(document).on('keydown.stoGradEsc', function(e) {
        if (e.key !== 'Escape' && e.keyCode !== 27) {
            return;
        }
        $('[data-sto-gradient-control][data-sto-gradient-open="1"]').each(function() {
            closePopover($(this));
        });
    });

    $(document).on('submit', '#sto-theme-settings-options-form', function() {
        $(this).find('[data-sto-gradient-control]').each(function() {
            syncFromInputs($(this));
        });
    });

    $(function() {
        initStoGradientControls();
    });

    window.stoInitGradientControls = initStoGradientControls;
})(jQuery);
