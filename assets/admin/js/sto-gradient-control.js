/**
 * Gradient field — preview strip, popover / inline, draggable pins, click bar to add stop, Flip, type/angle.
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

    var activeDrag = null;
    /** Press-and-hold on a pin before drag arms; quick click = select + color only. */
    var pinPending = null;

    var PIN_HOLD_MS = 220;
    var PIN_PRE_HOLD_CANCEL_PX = 10;
    var PIN_DRAG_ARM_PX = 4;

    /**
     * Cached dock node (moved to document.body while open).
     *
     * @param {jQuery} $wrap
     * @return {jQuery}
     */
    function getColorDock($wrap) {
        var $cached = $wrap.data('stoGradColorDock');
        if ($cached && $cached.length) {
            return $cached;
        }
        var $dock = $wrap.find('[data-sto-gradient-color-dock]').first();
        if ($dock.length) {
            $wrap.data('stoGradColorDock', $dock);
            if (!$dock.data('stoGradDockHome')) {
                $dock.data('stoGradDockHome', $dock.parent());
            }
        }
        return $dock;
    }

    function portalColorDockToBody($wrap) {
        var $dock = getColorDock($wrap);
        if (!$dock.length) {
            return;
        }
        if (!$dock.data('stoGradDockHome')) {
            $dock.data('stoGradDockHome', $dock.parent());
        }
        if ($dock.parent()[0] !== document.body) {
            $(document.body).append($dock);
        }
    }

    function restoreColorDockPortal($wrap) {
        var $dock = getColorDock($wrap);
        var $home = $dock.data('stoGradDockHome');
        if (!$dock.length || !$home || !$home.length) {
            return;
        }
        if ($dock.parent()[0] !== document.body) {
            return;
        }
        $home.append($dock);
    }

    /**
     * Active stop color input (lives in the dock, which may be on document.body).
     *
     * @param {jQuery} $wrap
     * @return {jQuery}
     */
    function getActiveColorInput($wrap) {
        var $dock = getColorDock($wrap);
        if ($dock.length) {
            var $inDock = $dock.find('[data-sto-gradient-active-color]').first();
            if ($inDock.length) {
                return $inDock;
            }
        }
        return $wrap.find('[data-sto-gradient-active-color]').first();
    }

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

    /** Thin stop rail: always left → right so a 14px bar never paints a steep linear or radial as a “page stripe”. */
    function compileStopRailCss(data) {
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
            return 'linear-gradient(90deg, #2271b1 0%, #ffffff 100%)';
        }
        return 'linear-gradient(90deg, ' + parts.join(', ') + ')';
    }

    function updatePreview($wrap) {
        var $hidden = $wrap.find('> .sto-gradient-value');
        var cur = parseHidden($hidden);
        var css = compilePreviewCss(cur);
        var railCss = compileStopRailCss(cur);
        $wrap.find('[data-sto-gradient-preview]').each(function() {
            this.style.backgroundImage = css;
        });
        $wrap.find('[data-sto-gradient-bar]').each(function() {
            this.style.backgroundImage = railCss;
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
        if (m > 32) {
            m = 32;
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

    function hexToRgb(hex) {
        var h = String(hex || '').replace(/^#/, '').trim();
        if (h.length === 3) {
            h = h[0] + h[0] + h[1] + h[1] + h[2] + h[2];
        }
        if (h.length !== 6) {
            return { r: 136, g: 136, b: 136 };
        }
        var n = parseInt(h, 16);
        if (isNaN(n)) {
            return { r: 136, g: 136, b: 136 };
        }
        return { r: (n >> 16) & 255, g: (n >> 8) & 255, b: n & 255 };
    }

    function rgbToHex(r, g, b) {
        function c(x) {
            var s = Math.round(Math.max(0, Math.min(255, x))).toString(16);
            return s.length === 1 ? '0' + s : s;
        }
        return '#' + c(r) + c(g) + c(b);
    }

    function interpolateColor(stops, t) {
        var arr = [];
        for (var i = 0; i < stops.length; i++) {
            var s = stops[i];
            arr.push({
                p: parseFloat(String(s.position != null ? s.position : '0'), 10) || 0,
                c: String(s.color || '#888888')
            });
        }
        arr.sort(function(a, b) {
            return a.p - b.p;
        });
        if (arr.length === 0) {
            return '#888888';
        }
        if (t <= arr[0].p) {
            return arr[0].c;
        }
        if (t >= arr[arr.length - 1].p) {
            return arr[arr.length - 1].c;
        }
        for (var j = 0; j < arr.length - 1; j++) {
            if (t >= arr[j].p && t <= arr[j + 1].p) {
                var a = arr[j];
                var b = arr[j + 1];
                if (Math.abs(b.p - a.p) < 0.0001) {
                    return a.c;
                }
                var r = (t - a.p) / (b.p - a.p);
                var A = hexToRgb(a.c);
                var B = hexToRgb(b.c);
                return rgbToHex(A.r + (B.r - A.r) * r, A.g + (B.g - A.g) * r, A.b + (B.b - A.b) * r);
            }
        }
        return arr[arr.length - 1].c;
    }

    function sortStopsReselect(stops, originalIndex) {
        var tagged = stops.map(function(s, i) {
            return {
                color: String(s.color || ''),
                position: String(s.position != null ? s.position : '0'),
                _i: i
            };
        });
        tagged.sort(function(a, b) {
            var pa = parseFloat(a.position);
            var pb = parseFloat(b.position);
            if (isNaN(pa)) {
                pa = 0;
            }
            if (isNaN(pb)) {
                pb = 0;
            }
            if (pa !== pb) {
                return pa - pb;
            }
            return a._i - b._i;
        });
        var newSel = 0;
        for (var k = 0; k < tagged.length; k++) {
            if (tagged[k]._i === originalIndex) {
                newSel = k;
                break;
            }
        }
        var out = tagged.map(function(t) {
            return { color: t.color, position: t.position };
        });
        return { stops: out, newIndex: newSel };
    }

    function getBarwrapEl($wrap) {
        var el = $wrap.find('[data-sto-gradient-rail] .sto-gradient-viz__barwrap').get(0);
        return el || null;
    }

    function percentFromClientX($wrap, clientX) {
        var el = getBarwrapEl($wrap);
        if (!el) {
            return 0;
        }
        var rect = el.getBoundingClientRect();
        if (rect.width <= 0) {
            return 0;
        }
        var pct = ((clientX - rect.left) / rect.width) * 100;
        return Math.round(Math.max(0, Math.min(100, pct)) * 100) / 100;
    }

    function isCustomizerContext() {
        return $('body').hasClass('wp-customizer');
    }

    function showColorDock($wrap) {
        var $dock = getColorDock($wrap);
        if (!$dock.length) {
            return;
        }
        $dock.removeClass('sto-gradient-color-dock--idle').attr('aria-hidden', 'false');
        $wrap.attr('data-sto-gradient-dock-open', '1');
        $wrap.closest('.sto-field-row-gradient').addClass('sto-field-row-gradient--dock-open');
        $dock.data('stoGradControlWrap', $wrap);
        portalColorDockToBody($wrap);
        positionColorDock($wrap);
        ensureColorPicker($wrap);
        refreshDockPalette($wrap);
        var $input = getActiveColorInput($wrap);
        if (typeof window.stoOpenGradientDockPicker === 'function') {
            window.stoOpenGradientDockPicker($input);
        }
    }

    function hideColorDock($wrap) {
        var $dock = getColorDock($wrap);
        if (!$dock.length) {
            return;
        }
        var $input = getActiveColorInput($wrap);
        if (typeof window.stoCloseGradientDockPicker === 'function') {
            window.stoCloseGradientDockPicker($input);
        }
        $dock.addClass('sto-gradient-color-dock--idle').attr('aria-hidden', 'true');
        $dock.css({ left: '', top: '', right: '' });
        $wrap.removeAttr('data-sto-gradient-dock-open');
        $wrap.closest('.sto-field-row-gradient').removeClass('sto-field-row-gradient--dock-open');
        restoreColorDockPortal($wrap);
    }

    function positionColorDock($wrap) {
        var $dock = getColorDock($wrap);
        if (!$dock.length || $dock.hasClass('sto-gradient-color-dock--idle')) {
            return;
        }
        var $pin = $wrap.find('.sto-gradient-pin--active');
        if (!$pin.length) {
            return;
        }
        var pr = $pin[0].getBoundingClientRect();
        var gap = 10;
        var vh = window.innerHeight || document.documentElement.clientHeight || 800;
        var est = 320;
        var placeBelow = pr.top < est + 72;
        var cx = pr.left + pr.width / 2;
        var top = placeBelow ? pr.bottom + gap : pr.top - gap;
        $dock.removeClass('sto-gradient-color-dock--below sto-gradient-color-dock--above');
        if (placeBelow) {
            $dock.addClass('sto-gradient-color-dock--below');
        } else {
            $dock.addClass('sto-gradient-color-dock--above');
        }
        $dock.css({ left: cx + 'px', top: top + 'px', right: 'auto' });
        if (isCustomizerContext()) {
            $dock.css('z-index', '500110');
        } else {
            $dock.css('z-index', '');
        }
    }

    function refreshDockPalette($wrap) {
        var $pal = getColorDock($wrap).find('[data-sto-gradient-dock-palette]');
        if (!$pal.length) {
            return;
        }
        var $cw = getColorDock($wrap).find('.sto-gradient-active-color-wrap');
        var raw = $cw.attr('data-sto-palettes') || '';
        var list = [];
        if (raw) {
            try {
                list = JSON.parse(raw);
            } catch (err) {
                list = [];
            }
        }
        if (!Array.isArray(list) || !list.length) {
            $pal.empty().attr('hidden', 'hidden');
            return;
        }
        $pal.removeAttr('hidden').empty();
        var label = $pal.attr('data-sto-gradient-palette-label');
        if (label) {
            var $span = $('<span class="sto-gradient-dock-palette__label" />');
            $span.text(label);
            $pal.append($span);
        }
        for (var i = 0; i < list.length; i++) {
            var hex = String(list[i] || '').trim();
            if (!hex) {
                continue;
            }
            var $b = $('<button type="button" class="sto-gradient-dock-swatch" data-sto-gradient-dock-swatch />');
            $b.attr('data-sto-gradient-swatch-color', hex);
            $b.attr('title', hex);
            $b.attr('aria-label', hex);
            $b.css('background-color', hex);
            $pal.append($b);
        }
    }

    function bindDockPalette($wrap) {
        var $dock = getColorDock($wrap);
        $dock.off('click.stoGradPal', '[data-sto-gradient-dock-swatch]');
        $dock.on('click.stoGradPal', '[data-sto-gradient-dock-swatch]', function(e) {
            e.preventDefault();
            e.stopPropagation();
            var $b = $(this);
            var $w = $b.closest('[data-sto-gradient-control]');
            if (!$w.length) {
                $w = $b.closest('[data-sto-gradient-color-dock]').data('stoGradControlWrap');
            }
            if (!$w || !$w.length) {
                return;
            }
            var hex = String($b.attr('data-sto-gradient-swatch-color') || '').trim();
            if (!hex) {
                return;
            }
            var $input = getActiveColorInput($w);
            $input.val(hex).trigger('change');
            if ($input.wpColorPicker && typeof $input.wpColorPicker === 'function' && $input.closest('.wp-picker-container').length) {
                try {
                    $input.wpColorPicker('color', hex);
                } catch (err) {
                    /* ignore */
                }
            }
            syncFromInputs($w);
        });
    }

    function bindDockDismissals($wrap) {
        $wrap.off('click.stoGradDockDismiss', '.sto-gradient-flip, [data-sto-gradient-remove-stop], select[data-sto-gradient-input="type"]');
        $wrap.on('click.stoGradDockDismiss', '.sto-gradient-flip, [data-sto-gradient-remove-stop], select[data-sto-gradient-input="type"]', function() {
            hideColorDock($wrap);
        });
    }

    /** Clicks inside the portaled dock must not bubble to `click.stoGradOutside`. */
    function bindDockOutsideGuard($wrap) {
        var $dock = getColorDock($wrap);
        $dock.off('mousedown.stoGradDockGuard click.stoGradDockGuard');
        $dock.on('mousedown.stoGradDockGuard click.stoGradDockGuard', function(e) {
            e.stopPropagation();
        });
    }

    function ensureColorPicker($wrap) {
        var $input = getActiveColorInput($wrap);
        if (!$input.length) {
            return;
        }

        var $existingContainer = $input.closest('.wp-picker-container');
        var needsRebuild =
            $existingContainer.length &&
            typeof window.stoIrisSquareTooSmall === 'function' &&
            window.stoIrisSquareTooSmall($input);
        if (needsRebuild && typeof window.stoDestroyColorPicker === 'function') {
            window.stoDestroyColorPicker($input);
            $wrap.removeData('stoGradColorReady');
        }

        if (!$input.closest('.wp-picker-container').length) {
            if (typeof window.stoInitGradientDockColorPicker === 'function') {
                window.stoInitGradientDockColorPicker($input);
            } else if (typeof window.stoInitColorPickers === 'function') {
                var $dock = getColorDock($wrap);
                window.stoInitColorPickers($dock.length ? $dock : $wrap);
            }
        }

        if ($input.closest('.wp-picker-container').length) {
            $wrap.data('stoGradColorReady', 1);
            if (typeof window.stoScheduleIrisReflow === 'function') {
                window.stoScheduleIrisReflow($input);
            }
        }
    }

    function openColorPicker($wrap) {
        showColorDock($wrap);
        refreshDockPalette($wrap);
        ensureColorPicker($wrap);

        window.requestAnimationFrame(function() {
            positionColorDock($wrap);

            var $input = getActiveColorInput($wrap);
            if (!$input.closest('.wp-picker-container').length) {
                ensureColorPicker($wrap);
            }

            if (typeof window.stoOpenGradientDockPicker === 'function') {
                window.stoOpenGradientDockPicker($input);
            }

            window.setTimeout(function() {
                positionColorDock($wrap);
            }, 80);
        });
    }

    function readFormIntoState($wrap) {
        var $hidden = $wrap.find('> .sto-gradient-value');
        var cur = parseHidden($hidden);
        var sel = getSelectedIndex($wrap);
        if (sel >= cur.stops.length) {
            sel = cur.stops.length - 1;
        }
        var $c = getActiveColorInput($wrap);
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
        if (!getColorDock($wrap).hasClass('sto-gradient-color-dock--idle')) {
            positionColorDock($wrap);
        }

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
        var $rm = $wrap.find('[data-sto-gradient-remove-stop]');
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
                $btn.attr('aria-label', 'Stop ' + (idx + 1) + ', ' + Math.round(p) + '% — click to pick color; hold, then drag to move');
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

        var $c = getActiveColorInput($wrap);
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

    function clearPinDocumentListeners() {
        $(document).off(
            'mousemove.stoGradPin touchmove.stoGradPin mouseup.stoGradPin touchend.stoGradPin pointercancel.stoGradPin'
        );
    }

    function clearPinPending() {
        if (!pinPending) {
            return;
        }
        window.clearTimeout(pinPending.holdTimer);
        pinPending.$wrap.removeClass('sto-gradient-control--hold-ready');
        pinPending = null;
        clearPinDocumentListeners();
    }

    function pinDocumentMoveUnified(e) {
        if (activeDrag) {
            onPinPointerMove(e);
            return;
        }
        if (!pinPending) {
            return;
        }
        var xy = getEventClientXY(e);
        var dist = Math.abs(xy.x - pinPending.startX) + Math.abs(xy.y - pinPending.startY);
        if (!pinPending.holdArmed) {
            if (dist > PIN_PRE_HOLD_CANCEL_PX) {
                clearPinPending();
            }
            return;
        }
        if (dist > PIN_DRAG_ARM_PX) {
            beginDragFromPending(xy);
        }
    }

    function beginDragFromPending(xy) {
        if (!pinPending) {
            return;
        }
        var p = pinPending;
        var $w = p.$wrap;
        var idx = p.idx;
        window.clearTimeout(p.holdTimer);
        $w.removeClass('sto-gradient-control--hold-ready');
        pinPending = null;
        activeDrag = {
            $wrap: $w,
            idx: idx,
            startClientX: p.startX,
            startClientY: p.startY,
            moved: true
        };
        $w.addClass('sto-gradient-control--is-dragging');
        onPinPointerMove({ clientX: xy.x, clientY: xy.y, type: 'mousemove' });
    }

    function pinDocumentUpUnified(e) {
        if (activeDrag) {
            onPinPointerUp();
            return;
        }
        if (!pinPending) {
            return;
        }
        var $w = pinPending.$wrap;
        window.clearTimeout(pinPending.holdTimer);
        $w.removeClass('sto-gradient-control--hold-ready');
        var xy = getEventClientXY(e || { clientX: pinPending.startX, clientY: pinPending.startY });
        var dist = Math.abs(xy.x - pinPending.startX) + Math.abs(xy.y - pinPending.startY);
        var wasArmed = pinPending.holdArmed;
        pinPending = null;
        clearPinDocumentListeners();
        if (dist <= PIN_PRE_HOLD_CANCEL_PX + 2 || wasArmed) {
            window.setTimeout(function() {
                openColorPicker($w);
            }, 0);
        }
    }

    function getEventClientXY(e) {
        var o = e.originalEvent;
        if (o && o.touches && o.touches.length) {
            return { x: o.touches[0].clientX, y: o.touches[0].clientY };
        }
        if (o && o.changedTouches && o.changedTouches.length) {
            return { x: o.changedTouches[0].clientX, y: o.changedTouches[0].clientY };
        }
        return { x: e.clientX, y: e.clientY };
    }

    function onPinPointerMove(e) {
        if (!activeDrag) {
            return;
        }
        var xy = getEventClientXY(e);
        var dx = Math.abs(xy.x - activeDrag.startClientX);
        var dy = Math.abs(xy.y - activeDrag.startClientY);
        if (dx + dy > 4) {
            activeDrag.moved = true;
            activeDrag.$wrap.addClass('sto-gradient-control--is-dragging');
            if (e.type === 'touchmove') {
                e.preventDefault();
            }
        }
        if (!activeDrag.moved) {
            return;
        }
        var pct = percentFromClientX(activeDrag.$wrap, xy.x);
        var $hidden = activeDrag.$wrap.find('> .sto-gradient-value');
        var cur = parseHidden($hidden);
        var idx = activeDrag.idx;
        if (!cur.stops[idx]) {
            return;
        }
        cur.stops[idx].position = String(pct);
        var sorted = sortStopsReselect(cur.stops, idx);
        cur.stops = sorted.stops;
        activeDrag.idx = sorted.newIndex;
        $hidden.val(JSON.stringify(cur)).trigger('change');
        activeDrag.$wrap.attr('data-sto-gradient-selected', String(sorted.newIndex));
        loadActiveIntoEditors(activeDrag.$wrap);
        updatePreview(activeDrag.$wrap);
        refreshPins(activeDrag.$wrap);
        positionColorDock(activeDrag.$wrap);
        refreshToolbar(activeDrag.$wrap);
    }

    function onPinPointerUp() {
        if (!activeDrag) {
            return;
        }
        var $wrap = activeDrag.$wrap;
        var moved = activeDrag.moved;
        $(document).off(
            'mousemove.stoGradPin touchmove.stoGradPin mouseup.stoGradPin touchend.stoGradPin pointercancel.stoGradPin'
        );
        $wrap.removeClass('sto-gradient-control--is-dragging');
        if (moved) {
            syncFromInputs($wrap);
            if (typeof window.stoApplyDependentFieldVisibility === 'function') {
                window.stoApplyDependentFieldVisibility();
            }
        }
        activeDrag = null;
    }

    function bindPinsAndRail($wrap) {
        $wrap.off('mousedown.stoGradPin pointerdown.stoGradPin', '[data-sto-gradient-pin]');
        $wrap.on('mousedown.stoGradPin pointerdown.stoGradPin', '[data-sto-gradient-pin]', function(e) {
            if (e.type === 'pointerdown' && e.pointerType && e.pointerType !== 'mouse' && e.pointerType !== 'pen') {
                return;
            }
            if (e.button !== 0 && e.button !== undefined) {
                return;
            }
            e.preventDefault();
            if (activeDrag) {
                return;
            }
            if (pinPending) {
                clearPinPending();
            }
            var $pin = $(this);
            var $w = $pin.closest('[data-sto-gradient-control]');
            syncFromInputs($w);
            var idx = parseInt(String($pin.attr('data-sto-gradient-pin')), 10);
            if (isNaN(idx)) {
                return;
            }
            $w.attr('data-sto-gradient-selected', String(idx));
            loadActiveIntoEditors($w);
            showColorDock($w);
            refreshPins($w);
            pinPending = {
                $wrap: $w,
                idx: idx,
                startX: getEventClientXY(e).x,
                startY: getEventClientXY(e).y,
                holdArmed: false,
                holdTimer: window.setTimeout(function() {
                    if (!pinPending) {
                        return;
                    }
                    pinPending.holdArmed = true;
                    pinPending.$wrap.addClass('sto-gradient-control--hold-ready');
                }, PIN_HOLD_MS)
            };
            $(document).on('mousemove.stoGradPin touchmove.stoGradPin', pinDocumentMoveUnified);
            $(document).on('mouseup.stoGradPin touchend.stoGradPin pointercancel.stoGradPin', pinDocumentUpUnified);
        });

        $wrap.off('click.stoGradHit', '[data-sto-gradient-hit]');
        $wrap.on('click.stoGradHit', '[data-sto-gradient-hit]', function(e) {
            e.preventDefault();
            var $w = $(this).closest('[data-sto-gradient-control]');
            if (pinPending) {
                clearPinPending();
            }
            if (activeDrag && activeDrag.moved) {
                return;
            }
            var $rail = $(this).closest('[data-sto-gradient-rail]');
            var $bw = $rail.find('.sto-gradient-viz__barwrap');
            if (!$bw.length) {
                return;
            }
            var rect = $bw[0].getBoundingClientRect();
            var pct = Math.round(Math.max(0, Math.min(100, ((e.clientX - rect.left) / Math.max(rect.width, 1)) * 100)) * 100) / 100;
            syncFromInputs($w);
            var $hidden = $w.find('> .sto-gradient-value');
            var cur = parseHidden($hidden);
            var mx = maxStops($w);
            var minD = 100;
            var closeIdx = -1;
            for (var i = 0; i < cur.stops.length; i++) {
                var dp = Math.abs(parseFloat(String(cur.stops[i].position != null ? cur.stops[i].position : '0'), 10) - pct);
                if (dp < minD) {
                    minD = dp;
                    closeIdx = i;
                }
            }
            if (minD <= 5 && closeIdx >= 0) {
                $w.attr('data-sto-gradient-selected', String(closeIdx));
                loadActiveIntoEditors($w);
                refreshPins($w);
                openColorPicker($w);
                return;
            }
            if (cur.stops.length >= mx) {
                return;
            }
            var col = interpolateColor(cur.stops, pct);
            cur.stops.push({ color: col, position: String(pct) });
            var sorted = sortStopsReselect(cur.stops, cur.stops.length - 1);
            cur.stops = sorted.stops;
            writeHidden($hidden, cur);
            $w.attr('data-sto-gradient-selected', String(sorted.newIndex));
            loadActiveIntoEditors($w);
            updatePreview($w);
            refreshPins($w);
            refreshToolbar($w);
            openColorPicker($w);
            if (typeof window.stoApplyDependentFieldVisibility === 'function') {
                window.stoApplyDependentFieldVisibility();
            }
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
        var $dock = getColorDock($wrap);
        $dock.data('stoGradControlWrap', $wrap);

        $wrap
            .off('input.stoGradAct change.stoGradAct', '[data-sto-gradient-active-position]')
            .on('input.stoGradAct change.stoGradAct', '[data-sto-gradient-active-position]', function() {
                syncFromInputs($wrap);
            });

        $dock
            .off('input.stoGradAct change.stoGradAct', '[data-sto-gradient-active-color]')
            .on('input.stoGradAct change.stoGradAct', '[data-sto-gradient-active-color]', function() {
                syncFromInputs($wrap);
            });
        $dock.off('click.stoGradActReset', '.sto-color-reset');
        $dock.on('click.stoGradActReset', '.sto-color-reset', function() {
            window.setTimeout(function() {
                syncFromInputs($wrap);
            }, 0);
        });
    }

    function bindTypeAngle($wrap) {
        $wrap.off('change.stoGradTA', 'select[data-sto-gradient-input="type"]');
        $wrap.on('change.stoGradTA', 'select[data-sto-gradient-input="type"]', function() {
            syncFromInputs($wrap);
        });
        $wrap.off('input.stoGradTA change.stoGradTA', 'input[type="number"][data-sto-gradient-input="angle"]');
        $wrap.on('input.stoGradTA change.stoGradTA', 'input[type="number"][data-sto-gradient-input="angle"]', function() {
            syncFromInputs($wrap);
        });
    }

    function bindRemoveStop($wrap) {
        $wrap.off('click.stoGradRm', '[data-sto-gradient-remove-stop]');
        $wrap.on('click.stoGradRm', '[data-sto-gradient-remove-stop]', function(e) {
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
            if (getActiveColorInput($wrap).closest('.wp-picker-container').length) {
                $wrap.data('stoGradColorReady', 1);
            }
        }
        window.setTimeout(function() {
            syncFromInputs($wrap);
            loadActiveIntoEditors($wrap);
            refreshDockPalette($wrap);
        }, 50);
    }

    function closePopover($wrap) {
        var $btn = $wrap.find('[data-sto-gradient-edit-toggle]');
        var $pop = $wrap.find('> .sto-gradient-popover');
        if (!$pop.length) {
            return;
        }
        syncFromInputs($wrap);
        hideColorDock($wrap);
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
        $wrap.find('[data-sto-gradient-edit-toggle]').off('click.stoGrad').on('click.stoGrad', function(e) {
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
        hideColorDock($wrap);

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
                var $w = $(this);
                applyValuesToInputs($w, defaults);
                syncFromInputs($w);
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
        getColorDock($wrap);

        bindPinsAndRail($wrap);
        bindFlip($wrap);
        bindActiveEditors($wrap);
        bindTypeAngle($wrap);
        bindRemoveStop($wrap);
        bindDockPalette($wrap);
        bindDockDismissals($wrap);
        bindDockOutsideGuard($wrap);

        if (isPopupMode($wrap)) {
            bindToggle($wrap);
        } else if (typeof window.stoInitColorPickers === 'function') {
            window.stoInitColorPickers($wrap.find('.sto-gradient-ui'));
            if (getActiveColorInput($wrap).closest('.wp-picker-container').length) {
                $wrap.data('stoGradColorReady', 1);
            }
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
        if ($tgt.closest('.sto-gradient-color-dock:not(.sto-gradient-color-dock--idle)').length) {
            return;
        }
        if ($tgt.closest('.wp-picker-container, .iris-picker, .wp-color-result').length) {
            return;
        }
        $('[data-sto-gradient-control]').each(function() {
            var $w = $(this);
            var $dock = getColorDock($w);
            if (!$tgt.closest($w).length && (!$dock.length || !$tgt.closest($dock).length)) {
                hideColorDock($w);
            }
        });
        if ($tgt.closest('[data-sto-gradient-control]').length) {
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
        $('[data-sto-gradient-control]').each(function() {
            hideColorDock($(this));
        });
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
        var dockResizeTimer;
        function scheduleDockReposition() {
            window.clearTimeout(dockResizeTimer);
            dockResizeTimer = window.setTimeout(function() {
                $('[data-sto-gradient-control]').each(function() {
                    var $w = $(this);
                    if (!getColorDock($w).hasClass('sto-gradient-color-dock--idle')) {
                        positionColorDock($w);
                    }
                });
            }, 80);
        }

        $(window).on('resize.stoGradDock scroll.stoGradDock', scheduleDockReposition);

        /* Customizer sidebar scroll does not bubble to window. */
        if ($('body').hasClass('wp-customizer')) {
            $('#customize-theme-controls').on(
                'scroll.stoGradDock',
                '.wp-full-overlay-sidebar-content, .customize-pane-child',
                scheduleDockReposition
            );
        }
    });

    window.stoInitGradientControls = initStoGradientControls;
})(jQuery);
