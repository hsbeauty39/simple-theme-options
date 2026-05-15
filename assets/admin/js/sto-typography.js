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

    function destroySelect2($el) {
        if ($.fn.select2 && $el && $el.length && $el.data('select2')) {
            $el.select2('destroy');
        }
    }

    function initSelect2($el) {
        if (typeof $.fn.select2 !== 'function' || !$el || !$el.length) {
            return;
        }
        if ($el.data('select2')) {
            return;
        }
        if (!$el.is(':visible')) {
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
        $el.select2({
            width: '100%',
            allowClear: hasPh && phText.length > 0,
            placeholder: hasPh && phText ? { id: '', text: phText } : undefined,
            minimumResultsForSearch: 0,
            dropdownCssClass: 'sto-select2-dropdown-wrap',
            dropdownParent: $dropdownParent
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

        destroySelect2($fam);
        destroySelect2($var);
        destroySelect2($sub);
        destroySelect2($trans);
        initSelect2($fam);
        initSelect2($var);
        initSelect2($sub);
        initSelect2($trans);

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
        });
    }

    $(document).ready(function() {
        initTypographyIn($(document));
    });

    window.stoInitTypographyPanels = initTypographyIn;

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
