(function($) {
    'use strict';

    var catalog = null;
    var catalogPromise = null;
    var fontMap = null;
    var customCssByFamily = {};

    function getCfg() {
        return (window.simple_theme_options && window.simple_theme_options.sto_typography) || {};
    }

    function getCatalog() {
        if (catalog !== null) {
            return $.Deferred().resolve(catalog).promise();
        }
        if (catalogPromise) {
            return catalogPromise;
        }

        var cfg = getCfg();
        if (!cfg.ajax_url || !cfg.action || !cfg.nonce) {
            catalog = [];
            fontMap = {};
            return $.Deferred().resolve(catalog).promise();
        }

        catalogPromise = $.post(cfg.ajax_url, {
            action: cfg.action,
            nonce: cfg.nonce
        })
            .then(function(res) {
                if (res && res.success && res.data && Array.isArray(res.data.fonts)) {
                    catalog = res.data.fonts;
                    customCssByFamily =
                        res.data.custom_css_by_family && typeof res.data.custom_css_by_family === 'object'
                            ? res.data.custom_css_by_family
                            : {};
                } else {
                    catalog = [];
                    customCssByFamily = {};
                }
                fontMap = {};
                catalog.forEach(function(entry) {
                    if (entry && entry.family) {
                        fontMap[entry.family] = entry;
                    }
                });
                return catalog;
            })
            .fail(function() {
                catalog = [];
                fontMap = {};
                return catalog;
            });

        return catalogPromise;
    }

    function parseVariant(v) {
        v = String(v || 'regular');
        if (v === 'regular') {
            return { w: 400, i: false };
        }
        if (v === 'italic') {
            return { w: 400, i: true };
        }
        var m = v.match(/^(\d+)italic$/);
        if (m) {
            return { w: parseInt(m[1], 10), i: true };
        }
        if (/^\d+$/.test(v)) {
            return { w: parseInt(v, 10), i: false };
        }
        return { w: 400, i: false };
    }

    function variantLabel(v) {
        var p = parseVariant(v);
        var names = {
            100: 'Thin',
            200: 'Extra Light',
            300: 'Light',
            400: 'Normal',
            500: 'Medium',
            600: 'Semi-Bold',
            700: 'Bold',
            800: 'Extra-Bold',
            900: 'Black'
        };
        var nm = names[p.w] || 'Weight';
        var label = nm + ' ' + p.w;
        if (p.i) {
            label += ' Italic';
        }
        return label;
    }

    function sortVariants(list) {
        return list.slice().sort(function(a, b) {
            var pa = parseVariant(a);
            var pb = parseVariant(b);
            if (pa.w !== pb.w) {
                return pa.w - pb.w;
            }
            return (pa.i ? 1 : 0) - (pb.i ? 1 : 0);
        });
    }

    function buildGoogleHref(family, variant) {
        if (!family) {
            return '';
        }
        var p = parseVariant(variant);
        var enc = encodeURIComponent(family).replace(/%20/g, '+');
        if (p.i) {
            return 'https://fonts.googleapis.com/css2?family=' + enc + ':ital,wght@1,' + p.w + '&display=swap';
        }
        return 'https://fonts.googleapis.com/css2?family=' + enc + ':wght@' + p.w + '&display=swap';
    }

    function removeDynamicFont(ownerId) {
        $('link[data-sto-typography-font="' + ownerId + '"]').remove();
    }

    function injectDynamicFont(ownerId, href) {
        if (!href) {
            removeDynamicFont(ownerId);
            return;
        }
        removeDynamicFont(ownerId);
        var link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = href;
        link.setAttribute('data-sto-typography-font', ownerId);
        document.head.appendChild(link);
    }

    function removeCustomFontCss(ownerId) {
        $('style[data-sto-typography-custom="' + ownerId + '"]').remove();
    }

    function injectCustomFontCss(ownerId, css) {
        removeCustomFontCss(ownerId);
        if (!css) {
            return;
        }
        var style = document.createElement('style');
        style.setAttribute('data-sto-typography-custom', ownerId);
        style.textContent = css;
        document.head.appendChild(style);
    }

    function readJson($hidden, $wrap) {
        var raw = $hidden.val();
        var def = {};
        try {
            def = JSON.parse($wrap.attr('data-default') || '{}');
        } catch (e1) {
            def = {};
        }
        var cur = {};
        try {
            cur = JSON.parse(raw || '{}');
        } catch (e2) {
            cur = {};
        }
        return {
            family: typeof cur.family === 'string' ? cur.family : (def.family || ''),
            variant: typeof cur.variant === 'string' ? cur.variant : (def.variant || 'regular'),
            subset: typeof cur.subset === 'string' && cur.subset ? cur.subset : (def.subset || 'latin'),
            transform: typeof cur.transform === 'string' ? cur.transform : (def.transform || 'none')
        };
    }

    function writeJson($hidden, state) {
        $hidden.val(JSON.stringify({
            family: state.family || '',
            variant: state.variant || 'regular',
            subset: state.subset || 'latin',
            transform: state.transform || 'none'
        }));
    }

    function fillFamilySelect($sel, fonts, placeholder) {
        $sel.empty();
        $sel.append($('<option></option>').attr('value', '').text(placeholder));
        var customTag = (getCfg().i18n && getCfg().i18n.custom) ? getCfg().i18n.custom : 'Custom';
        fonts.forEach(function(f) {
            if (!f || !f.family) {
                return;
            }
            var label = f.family;
            if (f.source === 'custom') {
                label += ' (' + customTag + ')';
            }
            $sel.append($('<option></option>').attr('value', f.family).text(label));
        });
    }

    function fillVariantSelect($sel, variants, current, stylePh) {
        var sorted = sortVariants(variants || []);
        $sel.empty();
        $sel.append($('<option></option>').attr('value', '').text(stylePh || 'Style'));
        sorted.forEach(function(v) {
            $sel.append($('<option></option>').attr('value', v).text(variantLabel(v)));
        });
        if (sorted.length && current && sorted.indexOf(current) !== -1) {
            $sel.val(current);
        } else if (sorted.length) {
            $sel.val(sorted[0]);
        } else {
            $sel.val('');
        }
    }

    function isTypographySelect2Attached($el) {
        return $el && $el.length && $el.hasClass('select2-hidden-accessible');
    }

    function destroySelect2($el) {
        if (!$.fn.select2 || !$el || !$el.length) {
            return;
        }
        if (isTypographySelect2Attached($el)) {
            try {
                $el.select2('destroy');
            } catch (destroyError) {
                $el.removeClass('select2-hidden-accessible').removeAttr('data-select2-id').show();
            }
        }
    }

    function getTypographySelects($wrap) {
        var $w = $wrap && $wrap.length ? $wrap : $(document);
        return $w.find(
            '[data-sto-typography-family], [data-sto-typography-variant], [data-sto-typography-subset], [data-sto-typography-transform]'
        );
    }

    /**
     * Whether the control is in a context where Select2 should stay attached (or be attached).
     *
     * @param {JQuery} $el
     * @return {boolean}
     */
    function isTypographySelectContextAllowed($el) {
        if (!$el || !$el.length) {
            return false;
        }
        if ($el.prop('disabled')) {
            return false;
        }
        if ($el.closest('fieldset:disabled').length) {
            return false;
        }
        if ($el.closest('.sto-option-panel-section.sto-is-hidden').length) {
            return false;
        }
        if ($el.closest('.sto-required-hidden').length) {
            return false;
        }
        var $typoBody = $el.closest('.sto-typography-body');
        if ($typoBody.length && ($typoBody.is('[hidden]') || $typoBody.css('display') === 'none')) {
            return false;
        }
        return true;
    }

    /**
     * True when the control is painted on screen (use Select2 chrome when already enhanced).
     *
     * @param {JQuery} $el
     * @return {boolean}
     */
    function isTypographySelectPainted($el) {
        if (!$el || !$el.length) {
            return false;
        }
        if (isTypographySelect2Attached($el)) {
            var $container = $el.next('.select2-container');
            if ($container.length) {
                return $container.is(':visible') && $container[0].getClientRects().length > 0;
            }
        }
        var $row = $el.closest('.sto-field-row, .sto-typography-cell');
        if ($row.length && ($row.hasClass('sto-required-hidden') || !$row.is(':visible'))) {
            return false;
        }
        var node = $el[0];
        return !!(node && node.getClientRects && node.getClientRects().length);
    }

    /**
     * True when the native select can receive Select2 (panel/fieldset enabled and painted).
     *
     * @param {JQuery} $el
     * @return {boolean}
     */
    function canAttachTypographySelect2($el) {
        if (typeof $.fn.select2 !== 'function') {
            return false;
        }
        return isTypographySelectContextAllowed($el) && isTypographySelectPainted($el);
    }

    function attachTypographySelect2($el) {
        if (!canAttachTypographySelect2($el) || isTypographySelect2Attached($el)) {
            return;
        }
        var $dropdownParent =
            typeof window.stoGetSelect2DropdownParent === 'function'
                ? window.stoGetSelect2DropdownParent($el)
                : $('#wpbody-content');
        if (!$dropdownParent.length) {
            $dropdownParent = $(document.body);
        }
        var $ph = $el.find('option[value=""]').first();
        var phText = $.trim($ph.text() || '');
        var hasPh = $ph.length > 0;
        try {
            $el.select2({
                width: '100%',
                allowClear: hasPh && phText.length > 0,
                placeholder: hasPh && phText ? { id: '', text: phText } : undefined,
                minimumResultsForSearch: 0,
                dropdownCssClass: 'sto-select2-dropdown-wrap',
                dropdownParent: $dropdownParent
            });
        } catch (attachError) {
            if (window.console && typeof window.console.warn === 'function') {
                window.console.warn('STO typography Select2 failed', attachError);
            }
        }
    }

    function initSelect2($el) {
        attachTypographySelect2($el);
    }

    /**
     * Typography often boots while `sto-required-hidden` (Content tab) or inside a disabled
     * fieldset — attach Select2 only when the control is actually interactive, then retry.
     */
    function initTypographySelectsInWrap($wrap) {
        getTypographySelects($wrap).each(function() {
            var $el = $(this);
            var contextOk = isTypographySelectContextAllowed($el);
            var painted = isTypographySelectPainted($el);
            var attached = isTypographySelect2Attached($el);

            if (attached && !contextOk) {
                destroySelect2($el);
                return;
            }
            if (attached) {
                return;
            }
            if (contextOk && painted) {
                attachTypographySelect2($el);
            }
        });
    }

    function scheduleTypographySelect2Retry($wrap, attempt) {
        attempt = typeof attempt === 'number' ? attempt : 0;
        if (attempt > 32) {
            return;
        }
        var pending = false;
        getTypographySelects($wrap).each(function() {
            var $el = $(this);
            if (!canAttachTypographySelect2($el)) {
                return;
            }
            if (!isTypographySelect2Attached($el)) {
                attachTypographySelect2($el);
            }
            if (!isTypographySelect2Attached($el)) {
                pending = true;
            }
        });
        if (pending) {
            window.setTimeout(function() {
                initTypographySelectsInWrap($wrap);
                scheduleTypographySelect2Retry($wrap, attempt + 1);
            }, attempt < 3 ? 0 : 120);
        }
    }

    function refreshTypographySelect2($root) {
        var $scope = ($root && $root.length) ? $root : $(document);
        if ($scope.find('.select2-container--open').length) {
            return;
        }
        $scope.find('[data-sto-typography]').filter(function() {
            return $(this).data('stoTypographyLoaded');
        }).each(function() {
            var $wrap = $(this);
            initTypographySelectsInWrap($wrap);
            scheduleTypographySelect2Retry($wrap, 0);
        });
    }

    function applyPreview($wrap, state) {
        var $text = $wrap.find('.sto-typography-preview-text');
        var ownerId = $wrap.attr('data-sto-font-owner') || $wrap.attr('data-field-id') || 'typo';
        var fam = state.family || '';
        var variant = state.variant || 'regular';
        var tr = state.transform || 'none';

        var entry = fam && fontMap ? fontMap[fam] : null;
        var stack = entry && entry.category ? entry.category : 'sans-serif';

        if (fam && entry && entry.source === 'custom') {
            removeDynamicFont(ownerId);
            injectCustomFontCss(ownerId, customCssByFamily[fam] || '');
        } else if (fam) {
            removeCustomFontCss(ownerId);
            injectDynamicFont(ownerId, buildGoogleHref(fam, variant));
        } else {
            removeDynamicFont(ownerId);
            removeCustomFontCss(ownerId);
        }

        var p = parseVariant(variant || 'regular');
        $text.css({
            fontFamily: fam ? ('"' + fam.replace(/"/g, '') + '", ' + stack) : 'inherit',
            fontWeight: fam ? String(p.w) : '400',
            fontStyle: fam && p.i ? 'italic' : 'normal',
            textTransform: tr === 'inherit' ? 'none' : tr
        });
    }

    function bindWrap($wrap) {
        var $hidden = $wrap.find('.sto-typography-value');
        var $fam = $wrap.find('[data-sto-typography-family]');
        var $var = $wrap.find('[data-sto-typography-variant]');
        var $sub = $wrap.find('[data-sto-typography-subset]');
        var $trans = $wrap.find('[data-sto-typography-transform]');
        var stylePh = (getCfg().i18n && getCfg().i18n.style) ? getCfg().i18n.style : 'Style';

        function stateFromDom() {
            var tf = String($trans.val() || '');
            return {
                family: String($fam.val() || ''),
                variant: String($var.val() || 'regular'),
                subset: String($sub.val() || 'latin'),
                transform: tf === '' ? 'none' : tf
            };
        }

        function syncVariants(preserveVariant) {
            var fam = String($fam.val() || '');
            var entry = fontMap && fontMap[fam];
            var vars = (entry && entry.variants) ? entry.variants : [];
            var cur = preserveVariant;
            if (cur === null || cur === undefined || cur === '') {
                cur = String($var.val() || '');
            }
            fillVariantSelect($var, vars, cur, stylePh);
            destroySelect2($var);
            initSelect2($var);
        }

        $fam.off('change.stoTypo').on('change.stoTypo', function() {
            syncVariants(null);
            var st = stateFromDom();
            writeJson($hidden, st);
            applyPreview($wrap, st);
        });

        $var.off('change.stoTypo').on('change.stoTypo', function() {
            var st = stateFromDom();
            writeJson($hidden, st);
            applyPreview($wrap, st);
        });

        $sub.off('change.stoTypo').on('change.stoTypo', function() {
            var st = stateFromDom();
            writeJson($hidden, st);
        });

        $trans.off('change.stoTypo').on('change.stoTypo', function() {
            var st = stateFromDom();
            writeJson($hidden, st);
            applyPreview($wrap, st);
        });
    }

    function bootWrap($wrap, fonts) {
        var cfg = getCfg();
        var stylePh = (cfg.i18n && cfg.i18n.style) ? cfg.i18n.style : 'Style';
        var $loading = $wrap.find('.sto-typography-loading');
        var $body = $wrap.find('.sto-typography-body');
        var $hidden = $wrap.find('.sto-typography-value');
        var $fam = $wrap.find('[data-sto-typography-family]');
        var $var = $wrap.find('[data-sto-typography-variant]');
        var $sub = $wrap.find('[data-sto-typography-subset]');
        var $trans = $wrap.find('[data-sto-typography-transform]');

        $loading.attr('aria-busy', 'false').attr('hidden', true).hide();
        $body.prop('hidden', false).show();

        fillFamilySelect($fam, fonts, cfg.i18n && cfg.i18n.select_font ? cfg.i18n.select_font : 'Select font');

        var state = readJson($hidden, $wrap);
        $fam.val(state.family || '');

        var entry = state.family && fontMap ? fontMap[state.family] : null;
        var vars = (entry && entry.variants) ? entry.variants : [];
        fillVariantSelect($var, vars, state.variant, stylePh);
        $sub.val(state.subset || 'latin');
        $trans.val(state.transform === 'none' ? 'none' : (state.transform || 'none'));

        initTypographySelectsInWrap($wrap);
        scheduleTypographySelect2Retry($wrap, 0);

        bindWrap($wrap);
        applyPreview($wrap, readJson($hidden, $wrap));
    }

    function initTypographyIn($root) {
        var $scope = ($root && $root.length) ? $root : $(document);
        var $targets = $scope.find('[data-sto-typography]').filter(function() {
            return !$(this).data('stoTypographyLoaded');
        });
        if (!$targets.length) {
            return;
        }

        getCatalog().done(function(fonts) {
            $targets.each(function() {
                var $wrap = $(this);
                $wrap.data('stoTypographyLoaded', true);
                bootWrap($wrap, fonts);
            });
            window.setTimeout(function() {
                refreshTypographySelect2($('.sto-option-panel-section.sto-is-active'));
            }, 0);
            window.setTimeout(function() {
                refreshTypographySelect2($('.sto-option-panel-section.sto-is-active'));
            }, 200);
        });
    }

    $(document).ready(function() {
        initTypographyIn($(document));
    });

    $(document).on('sto:typography-refresh-request', function(_event, scope) {
        refreshTypographySelect2(scope && scope.length ? scope : $('.sto-option-panel-section.sto-is-active'));
    });

    window.stoInitTypographyPanels = initTypographyIn;
    window.stoRefreshTypographySelect2 = refreshTypographySelect2;

    window.stoResetTypographyCatalog = function() {
        catalog = null;
        catalogPromise = null;
        fontMap = null;
        customCssByFamily = {};
    };

    $(document).on('stoCustomFontsChanged', function() {
        window.stoResetTypographyCatalog();
        $('[data-sto-typography]').each(function() {
            $(this).removeData('stoTypographyLoaded');
        });
        initTypographyIn($(document));
    });
})(jQuery);
