(function($) {
    /**
     * Select2 attaches its dropdown to `dropdownParent`. Using only `#wpbody-content` breaks
     * AJAX search, multi chips, and positioning inside the post editor metabox, block-editor
     * sidebars, and other scroll-clipped regions.
     *
     * @param {JQuery|HTMLElement} [$select] The `<select>` being upgraded (optional).
     * @return {JQuery}
     */
    window.stoGetSelect2DropdownParent = function($select) {
        var $s = $select && $select.jquery ? $select : $($select);
        if ($s && $s.length) {
            var $code = $s.closest('.sto-code-editor');
            if ($code.length && $code.hasClass('sto-code-editor--is-fullscreen')) {
                return $(document.body);
            }
            var $inner = $s.closest('.sto-theme-settings-metabox-inner');
            if ($inner.length) {
                // Block editor (WP 6.7+ split view): the meta box region clips overflow — Select2 must
                // portal to body or dropdowns / AJAX results are clipped and controls look "broken".
                // Classic postbox can clip too; body parent matches WP dev-note guidance for popovers.
                return $(document.body);
            }
            var $sidebar = $s.closest('.edit-post-sidebar, .interface-interface-skeleton__sidebar');
            if ($sidebar.length) {
                return $sidebar;
            }
        }
        var $wp = $('#wpbody-content');
        if ($wp.length) {
            return $wp;
        }
        return $(document.body);
    };

    function isPlainObject(value) {
        return Object.prototype.toString.call(value) === '[object Object]';
    }

    function normalizeRequiredGroups(rawRule) {
        if (!rawRule) {
            return [];
        }

        // Direct format: { layout_header: 'none' }
        if (isPlainObject(rawRule)) {
            return [rawRule];
        }

        // Nested format: [ { layout_header: 'none' }, { another_key: 'x' } ]
        if (Array.isArray(rawRule)) {
            return rawRule.filter(function(group) {
                return isPlainObject(group);
            });
        }

        return [];
    }

    /**
     * Inputs named `sto_options[…]` exist only once per form, but the Theme Settings UI keeps
     * every section panel in the DOM; prefer the active leaf panel so we never read a stale clone
     * or the wrong node when markup evolves.
     *
     * @param {string} nameAttr
     * @return {JQuery}
     */
    function findOptionInputsByName(nameAttr) {
        if (!nameAttr) {
            return $();
        }
        var $active = $('.sto-option-panel-section.sto-is-active');
        var $inActive = $active.find(':input').filter(function() {
            return this.name === nameAttr;
        });
        if ($inActive.length) {
            return $inActive;
        }
        var $form = $('.sto-options-form');
        if ($form.length) {
            return $form.find(':input').filter(function() {
                return this.name === nameAttr;
            });
        }
        return $(':input').filter(function() {
            return this.name === nameAttr;
        });
    }

    /**
     * Read current value for a sto_options[field] control. Must handle:
     * - Radio groups (same name, multiple inputs): use :checked
     * - Checkboxes: checked state; multiple with same name → joined values
     * - Single text/hidden/select: .val()
     * - Multi Dynamic Object: name sto_options[key][] → joined ids
     *
     * For **required** visibility, always call with empty `contextBp` so responsive dependencies
     * read the **PC / eval** slice (`data-sto-require-eval-bp` on the dependency row, default `xxl`),
     * not the currently selected device tab.
     *
     * @param {string} fieldKey
     * @param {string} [contextBp] If non-empty, responsive fields read `sto_options[key][contextBp]` first.
     */
    function getOptionFieldValue(fieldKey, contextBp) {
        contextBp = contextBp ? String(contextBp) : '';
        var $rowTarget = $('#sto-field-' + fieldKey);
        if ($rowTarget.length && $rowTarget.attr('data-sto-responsive') === '1') {
            var sliceBp = contextBp;
            if (!sliceBp) {
                sliceBp = $rowTarget.attr('data-sto-require-eval-bp') || 'xxl';
            }
            var nameBp = 'sto_options[' + fieldKey + '][' + sliceBp + ']';
            var nameBpMulti = 'sto_options[' + fieldKey + '][' + sliceBp + '][]';
            var $bpFields = findOptionInputsByName(nameBp);
            if (!$bpFields.length) {
                $bpFields = findOptionInputsByName(nameBpMulti);
            }
            if (!$bpFields.length && contextBp) {
                sliceBp = $rowTarget.attr('data-sto-require-eval-bp') || 'xxl';
                nameBp = 'sto_options[' + fieldKey + '][' + sliceBp + ']';
                nameBpMulti = 'sto_options[' + fieldKey + '][' + sliceBp + '][]';
                $bpFields = findOptionInputsByName(nameBp);
                if (!$bpFields.length) {
                    $bpFields = findOptionInputsByName(nameBpMulti);
                }
            }
            if ($bpFields.length) {
                var t0 = ($bpFields.first().attr('type') || '').toLowerCase();
                if (t0 === 'radio') {
                    var $rchk = $bpFields.filter(':checked');
                    if (!$rchk.length) {
                        return '';
                    }
                    var rpv = $rchk.val();
                    return rpv === undefined || rpv === null ? '' : String(rpv);
                }
                if (t0 === 'checkbox' && $bpFields.length > 1) {
                    return $bpFields
                        .filter(':checked')
                        .map(function() {
                            var v = $(this).val();
                            return v === undefined || v === null ? '' : String(v);
                        })
                        .get()
                        .filter(Boolean)
                        .join(',');
                }
                if (t0 === 'checkbox') {
                    return $bpFields.first().prop('checked') ? String($bpFields.first().val() || '1') : '';
                }
                return readInputValue($bpFields.first());
            }
        }

        var name = 'sto_options[' + fieldKey + ']';
        var nameMulti = 'sto_options[' + fieldKey + '][]';
        var $fields = findOptionInputsByName(name);
        if (!$fields.length) {
            $fields = findOptionInputsByName(nameMulti);
        }

        if (!$fields.length) {
            var $byId = $('#' + fieldKey).first();
            if (!$byId.length) {
                return '';
            }
            return readInputValue($byId);
        }

        var type = ($fields.first().attr('type') || '').toLowerCase();

        if (type === 'radio') {
            var $chk = $fields.filter(':checked');
            if (!$chk.length) {
                return '';
            }
            var rv = $chk.val();
            return rv === undefined || rv === null ? '' : String(rv);
        }

        if (type === 'checkbox' && $fields.length > 1) {
            return $fields
                .filter(':checked')
                .map(function() {
                    var v = $(this).val();
                    return v === undefined || v === null ? '' : String(v);
                })
                .get()
                .filter(Boolean)
                .join(',');
        }

        if (type === 'checkbox') {
            return $fields.first().prop('checked') ? String($fields.first().val() || '1') : '';
        }

        var value = readInputValue($fields.first());
        if (Array.isArray(value)) {
            return value.join(',');
        }
        if (value === undefined || value === null) {
            return '';
        }
        return String(value);
    }

    /**
     * Breakpoint tabs: show one pane, sync `data-sto-active-bp` (UI state for the row).
     *
     * @param {JQuery} [$panel] Optional section panel scope.
     */
    function initStoResponsiveTabs($panel) {
        var $scope = ($panel && $panel.length) ? $panel : $('.sto-option-panel-section.sto-is-active');
        if (!$scope.length) {
            return;
        }
        $scope.find('[data-sto-responsive="1"]').each(function() {
            var $row = $(this);
            if ($row.data('stoRspBound')) {
                return;
            }
            $row.data('stoRspBound', 1);
            $row.on('click', '[data-sto-responsive-tab]', function(ev) {
                ev.preventDefault();
                var bp = $(this).attr('data-sto-responsive-tab') || '';
                if (!bp || !$row.length) {
                    return;
                }
                $row.attr('data-sto-active-bp', bp);
                $row.find('[data-sto-responsive-tab]').removeClass('sto-is-active').attr('aria-selected', 'false');
                $(this).addClass('sto-is-active').attr('aria-selected', 'true');
                $row.find('[data-sto-responsive-pane]').each(function() {
                    var $pane = $(this);
                    var pbp = $pane.attr('data-sto-responsive-pane') || '';
                    var show = pbp === bp;
                    $pane.toggleClass('sto-is-active', show);
                    if (show) {
                        $pane.removeAttr('hidden');
                    } else {
                        $pane.attr('hidden', 'hidden');
                    }
                });
                var $ap = $row.closest('.sto-option-panel-section.sto-is-active');
                if ($ap.length) {
                    initStoSelect2($ap);
                    initStoImageSelectRadios($ap);
                    initStoButtonGroups($ap);
                    if (typeof window.stoInitColorPickers === 'function') {
                        window.stoInitColorPickers($ap);
                    }
                    if (typeof window.stoInitTypographyPanels === 'function') {
                        window.stoInitTypographyPanels($ap);
                    }
                    if (typeof window.stoInitBackgroundControls === 'function') {
                        window.stoInitBackgroundControls($ap);
                    }
                    if (typeof window.stoInitBorderControls === 'function') {
                        window.stoInitBorderControls($ap);
                    }
                    if (typeof window.stoInitShadowControls === 'function') {
                        window.stoInitShadowControls($ap);
                    }
                    if (typeof window.stoInitGradientControls === 'function') {
                        window.stoInitGradientControls($ap);
                    }
                    if (typeof window.stoInitLinkColors === 'function') {
                        window.stoInitLinkColors($ap);
                    }
                    if (typeof window.stoInitRangeControls === 'function') {
                        window.stoInitRangeControls($ap);
                    }
                    if (typeof window.stoInitDateFields === 'function') {
                        window.stoInitDateFields($ap);
                    }
                    if (typeof window.stoInitDateTimeFields === 'function') {
                        window.stoInitDateTimeFields($ap);
                    }
                    if (typeof window.stoInitDimensionFields === 'function') {
                        window.stoInitDimensionFields($ap);
                    }
                    if (typeof window.stoInitGalleryFields === 'function') {
                        window.stoInitGalleryFields($ap);
                    }
                    if (typeof window.stoInitMultiTextFields === 'function') {
                        window.stoInitMultiTextFields($ap);
                    }
                    if (typeof window.stoInitGoogleMapFields === 'function') {
                        window.stoInitGoogleMapFields($ap);
                    }
                    if (typeof window.stoInitIconSelectFields === 'function') {
                        window.stoInitIconSelectFields($ap);
                    }
                    if (typeof window.stoInitImportExport === 'function') {
                        window.stoInitImportExport($ap);
                    }
                    if (typeof window.stoInitAlignmentFields === 'function') {
                        window.stoInitAlignmentFields($ap);
                    }
                    if (typeof window.stoInitCheckboxControls === 'function') {
                        window.stoInitCheckboxControls($ap);
                    }
					if (typeof window.stoInitTabsControls === 'function') {
                        window.stoInitTabsControls($ap);
                    }
                    if (typeof window.stoInitAccordionControls === 'function') {
                        window.stoInitAccordionControls($ap);
                    }
                    if (typeof window.stoInitCodeEditors === 'function') {
                        window.stoInitCodeEditors($ap);
                    }
                    applyRequiredVisibility($ap);
                }
            });
        });
    }

    function readInputValue($field) {
        if (!$field.length) {
            return '';
        }
        var type = ($field.attr('type') || '').toLowerCase();
        if (type === 'radio') {
            var name = $field.attr('name');
            if (name) {
                var $c = findOptionInputsByName(name).filter(':checked');
                return $c.length ? String($c.val() != null ? $c.val() : '') : '';
            }
        }
        if (type === 'checkbox') {
            return $field.prop('checked') ? String($field.val() || '1') : '';
        }

        var tag = ($field.prop('tagName') || '').toLowerCase();
        if (tag === 'textarea' && $field.hasClass('wp-editor-area') && window.tinymce) {
            var edId = $field.attr('id') || '';
            var ed = edId ? tinymce.get(edId) : null;
            if (ed && !ed.isHidden()) {
                var txt = ed.getContent({ format: 'text' }) || '';
                return String(txt).replace(/\u00a0/g, ' ').trim();
            }
        }

        var value = $field.val();
        if (Array.isArray(value)) {
            return value.join(',');
        }
        if (value === undefined || value === null) {
            return '';
        }
        return String(value);
    }

    function doesGroupMatch(group) {
        var keys = Object.keys(group || {});
        if (!keys.length) {
            return false;
        }

        return keys.every(function(fieldKey) {
            var expected = group[fieldKey];
            var currentValue = getOptionFieldValue(fieldKey, '');
            return String(currentValue) === String(expected);
        });
    }

    function applyRequiredVisibility($scope) {
        var $context = ($scope && $scope.length) ? $scope : $('.sto-option-panel-section.sto-is-active');
        if (!$context.length) {
            return;
        }

        // One pass is not always enough: row A may depend on row B's value while B's visibility
        // is toggled in the same sweep (DOM order). Re-run until stable (capped).
        var maxPasses = 12;
        var pass;
        for (pass = 0; pass < maxPasses; pass++) {
            var anyVisibilityChange = false;
            $context.find('[data-sto-required]').each(function() {
                var $row = $(this);
                var rawRule = $row.attr('data-sto-required');
                if (!rawRule) {
                    return;
                }

                var parsed;
                try {
                    parsed = JSON.parse(rawRule);
                } catch (error) {
                    return;
                }

                var groups = normalizeRequiredGroups(parsed);
                var isVisible = groups.length
                    ? groups.some(function(g) {
                          return doesGroupMatch(g);
                      })
                    : true;

                var wasHidden = $row.hasClass('sto-required-hidden');
                $row.toggleClass('sto-required-hidden', !isVisible);
                $row.find(':input').prop('disabled', !isVisible);
                // Mirror hidden state to the wrapping `.sto-field-group-cell` (grid mode) so the
                // 12-column grid does not leave an empty gap where a hidden conditional row would
                // otherwise still occupy span columns.
                var $cellWrap = $row.closest('.sto-field-group-cell');
                if ($cellWrap.length) {
                    $cellWrap.toggleClass('sto-required-hidden', !isVisible);
                }
                if (wasHidden !== $row.hasClass('sto-required-hidden')) {
                    anyVisibilityChange = true;
                }
            });
            if (!anyVisibilityChange) {
                break;
            }
        }

        markEmptyFieldGroups($context);
    }

    /**
     * Inactive section panels stay in the DOM with display:none but are still part of the same
     * options form. HTML5 constraint validation still runs on required fields in those panels and
     * blocks submit when the user saves another section. Each panel wraps fields in a fieldset
     * (see Menu::render_section_panel); toggling disabled on that fieldset matches HTML behavior
     * and keeps Select2/TinyMCE sane. Fallback: disable :input if no fieldset (legacy markup).
     */
    function syncSectionPanelsDomDisabled() {
        $('.sto-option-panel-section').each(function() {
            var $p = $(this);
            var hide = $p.hasClass('sto-is-hidden');
            var $fs = $p.children('fieldset.sto-panel-section-fields');
            if ($fs.length) {
                $fs.prop('disabled', hide);
            } else {
                $p.find(':input').prop('disabled', hide);
            }
        });
    }

    window.stoApplyDependentFieldVisibility = function() {
        applyRequiredVisibility($('.sto-option-panel-section.sto-is-active'));
    };

    /**
     * Hide group panels whose inner has no visible fields/subgroups (e.g. all children
     * sto-required-hidden) so empty .sto-field-group-inner shells do not show.
     *
     * `.sto-field-group-inner` is now a 12-column grid host with `.sto-field-group-cell`
     * wrappers as direct children — each cell contains exactly one `.sto-field-row` or
     * one `.sto-field-group`. Treat the cell layer as transparent so the "any visible
     * descendant" check matches the legacy stacked layout.
     */
    function innerHasVisibleContent($inner) {
        var visible = false;

        // Either legacy (.sto-field-row direct child) or grid mode (.sto-field-group-cell > .sto-field-row).
        $inner.children('.sto-field-group-cell, .sto-field-row').each(function() {
            var $node = $(this);
            if ($node.hasClass('sto-required-hidden')) {
                return;
            }
            if ($node.hasClass('sto-field-group-cell')) {
                var $row = $node.children('.sto-field-row').first();
                if ($row.length && !$row.hasClass('sto-required-hidden')) {
                    visible = true;
                    return;
                }
                var $childGroup = $node.children('.sto-field-group').first();
                if ($childGroup.length && !$childGroup.hasClass('sto-required-hidden') && !$childGroup.hasClass('sto-field-group--empty-body')) {
                    var $cin2 = $childGroup.children('.sto-field-group-inner');
                    if ($cin2.length && innerHasVisibleContent($cin2)) {
                        visible = true;
                    }
                }
                return;
            }
            visible = true;
        });

        // Legacy fallback: direct .sto-field-group children (no cell wrap) — keep working unchanged.
        $inner.children('.sto-field-group').each(function() {
            var $cg = $(this);
            if ($cg.hasClass('sto-required-hidden') || $cg.hasClass('sto-field-group--empty-body')) {
                return;
            }
            var $cin = $cg.children('.sto-field-group-inner');
            if ($cin.length && innerHasVisibleContent($cin)) {
                visible = true;
            }
        });

        return visible;
    }

    function markEmptyFieldGroups($scope) {
        var $context = ($scope && $scope.length) ? $scope : $('.sto-option-panel-section.sto-is-active');
        if (!$context.length) {
            return;
        }

        var $groups = $context.find('.sto-field-group').get();
        $groups.sort(function(a, b) {
            return $(b).parents('.sto-field-group').length - $(a).parents('.sto-field-group').length;
        });

        $groups.forEach(function(el) {
            var $g = $(el);
            if ($g.hasClass('sto-required-hidden')) {
                $g.removeClass('sto-field-group--empty-body');
                return;
            }

            var $inner = $g.children('.sto-field-group-inner');
            if (!$inner.length) {
                $g.addClass('sto-field-group--empty-body');
                return;
            }

            var has = innerHasVisibleContent($inner);
            $g.toggleClass('sto-field-group--empty-body', !has);
        });
    }

    function initStoSelect2($panel) {
        if (typeof $.fn.select2 !== 'function') {
            return;
        }

        var $scope = ($panel && $panel.length) ? $panel : $('.sto-option-panel-section.sto-is-active');
        if (!$scope.length) {
            return;
        }

        $scope.find('select.sto-input-select:not(.sto-dynamic-object)').each(function() {
            var $s = $(this);
            if ($s.data('select2')) {
                return;
            }
            if (!$s.is(':visible')) {
                return;
            }

            var isMultiple = $s.attr('data-multiple') === '1' || $s.prop('multiple') === true;
            var maxSel = parseInt($s.attr('data-max-selections') || '0', 10) || 0;

            var placeholderText;
            var hasPlaceholder;
            if (isMultiple) {
                placeholderText = $.trim($s.attr('data-placeholder-text') || '');
                hasPlaceholder = placeholderText.length > 0;
            } else {
                var $placeholderOpt = $s.find('option[value=""]').first();
                placeholderText = $.trim($placeholderOpt.text() || '');
                hasPlaceholder = $placeholderOpt.length > 0;
            }

            var $dropdownParent = window.stoGetSelect2DropdownParent
                ? window.stoGetSelect2DropdownParent($s)
                : $('#wpbody-content');
            if (!$dropdownParent.length) {
                $dropdownParent = $(document.body);
            }

            var s2opts = {
                width: '100%',
                allowClear: !isMultiple && hasPlaceholder && placeholderText.length > 0,
                placeholder: hasPlaceholder ? { id: '', text: placeholderText } : undefined,
                minimumResultsForSearch: 0,
                dropdownCssClass: 'sto-select2-dropdown-wrap',
                dropdownParent: $dropdownParent
            };
            if (isMultiple) {
                s2opts.multiple = true;
                if (maxSel > 0) {
                    s2opts.maximumSelectionLength = maxSel;
                }
            }
            $s.select2(s2opts);
        });

        $scope.find('select.sto-dynamic-object').each(function() {
            var $s = $(this);
            if ($s.data('select2')) {
                return;
            }
            if (!$s.is(':visible')) {
                return;
            }

            var cfg = window.simple_theme_options && window.simple_theme_options.sto_dynamic_object;
            if (!cfg || !cfg.ajax_url || !cfg.action || !cfg.nonce) {
                return;
            }

            var fieldId = $s.attr('data-field-id') || $s.attr('id') || '';
            var postType = $s.attr('data-post-type') || 'post';
            var isMultiple = $s.attr('data-multiple') === '1';
            var maxSel = parseInt($s.attr('data-max-selections') || '0', 10) || 0;
            var minSearch = parseInt($s.attr('data-search-min') || '3', 10) || 3;

            var $placeholderOpt = $s.find('option[value=""]').first();
            var placeholderText = $.trim($s.attr('data-placeholder-text') || $placeholderOpt.text() || '');
            var hasPlaceholder = placeholderText.length > 0;
            if (!isMultiple) {
                hasPlaceholder = hasPlaceholder && $placeholderOpt.length > 0;
            }

            var $dropdownParentDyn = window.stoGetSelect2DropdownParent
                ? window.stoGetSelect2DropdownParent($s)
                : $('#wpbody-content');
            if (!$dropdownParentDyn.length) {
                $dropdownParentDyn = $(document.body);
            }

            var s2opts = {
                width: '100%',
                allowClear: hasPlaceholder && placeholderText.length > 0,
                placeholder: hasPlaceholder ? { id: '', text: placeholderText } : undefined,
                minimumResultsForSearch: 0,
                minimumInputLength: minSearch,
                language: {
                    inputTooShort: function() {
                        var tpl =
                            cfg.i18n && cfg.i18n.input_too_short
                                ? String(cfg.i18n.input_too_short)
                                : 'Type at least %d characters to search for posts.';
                        return tpl.indexOf('%d') !== -1 ? tpl.replace('%d', String(minSearch)) : tpl;
                    }
                },
                dropdownCssClass: 'sto-select2-dropdown-wrap',
                dropdownParent: $dropdownParentDyn,
                ajax: {
                    type: 'POST',
                    url: cfg.ajax_url,
                    dataType: 'json',
                    delay: 250,
                    data: function(params) {
                        return {
                            action: cfg.action,
                            nonce: cfg.nonce,
                            field_id: fieldId,
                            post_type: postType,
                            search: params.term || '',
                            page: params.page || 1
                        };
                    },
                    processResults: function(data) {
                        if (!data || !data.success || !data.data) {
                            return { results: [], pagination: { more: false } };
                        }
                        return {
                            results: data.data.results || [],
                            pagination: { more: !!data.data.more }
                        };
                    }
                }
            };
            if (isMultiple) {
                s2opts.multiple = true;
                if (maxSel > 0) {
                    s2opts.maximumSelectionLength = maxSel;
                }
            }
            $s.select2(s2opts);
        });
    }

    function initStoImageSelectRadios($panel) {
        var $scope = ($panel && $panel.length) ? $panel : $('.sto-option-panel-section.sto-is-active');
        if (!$scope.length) {
            return;
        }
        $scope.find('.sto-image-select').each(function() {
            var $group = $(this);
            function sync() {
                $group.find('.sto-image-select__item').removeClass('sto-image-select__item--selected');
                $group.find('.sto-image-select__input:checked').closest('.sto-image-select__item').addClass('sto-image-select__item--selected');
            }
            sync();
            $group.off('change.stoImgSel').on('change.stoImgSel', '.sto-image-select__input', sync);
        });
    }

    function initStoButtonGroups($panel) {
        var $scope = ($panel && $panel.length) ? $panel : $('.sto-option-panel-section.sto-is-active');
        if (!$scope.length) {
            return;
        }
        $scope.find('[data-sto-button-group-wrap]').each(function() {
            var $wrap = $(this);

            function syncVisual() {
                $wrap.find('[data-sto-bg-segment]').removeClass('sto-button-group__segment--selected');
                var $chk = $wrap.find('[data-sto-button-group-input]:checked').first();
                if (!$chk.length) {
                    $chk = $wrap.find('[data-sto-button-group-input]').first();
                    if ($chk.length) {
                        $chk.prop('checked', true);
                    }
                }
                $chk.closest('[data-sto-bg-segment]').addClass('sto-button-group__segment--selected');
            }

            $wrap.off('change.stoBtnGrp').on('change.stoBtnGrp', '[data-sto-button-group-input]', syncVisual);
            syncVisual();
        });
    }

    function refreshStoWpEditors($panel) {
        if (!window.tinymce) {
            return;
        }
        var $scope = ($panel && $panel.length) ? $panel : $('.sto-option-panel-section.sto-is-active');
        $scope.find('textarea.wp-editor-area').each(function() {
            var id = this.id;
            if (!id) {
                return;
            }
            var ed = tinymce.get(id);
            if (ed && !ed.isHidden()) {
                try {
                    ed.execCommand('mceRepaint');
                } catch (e0) {
                    /* ignore */
                }
            }
        });
    }

    function refreshStoSelect2() {
        window.setTimeout(function() {
            var $activePanel = $('.sto-option-panel-section.sto-is-active');
            initStoResponsiveTabs($activePanel);
            initStoSelect2($activePanel);
            initStoImageSelectRadios($activePanel);
            initStoButtonGroups($activePanel);
            applyRequiredVisibility($activePanel);
            refreshStoWpEditors($activePanel);
            if (typeof window.stoInitTypographyPanels === 'function') {
                window.stoInitTypographyPanels($activePanel);
            }
            if (typeof window.stoInitColorPickers === 'function') {
                window.stoInitColorPickers($activePanel);
            }
            if (typeof window.stoInitBackgroundControls === 'function') {
                window.stoInitBackgroundControls($activePanel);
            }
            if (typeof window.stoInitBorderControls === 'function') {
                window.stoInitBorderControls($activePanel);
            }
            if (typeof window.stoInitShadowControls === 'function') {
                window.stoInitShadowControls($activePanel);
            }
            if (typeof window.stoInitGradientControls === 'function') {
                window.stoInitGradientControls($activePanel);
            }
            if (typeof window.stoInitLinkColors === 'function') {
                window.stoInitLinkColors($activePanel);
            }
            if (typeof window.stoInitRangeControls === 'function') {
                window.stoInitRangeControls($activePanel);
            }
            if (typeof window.stoInitDateFields === 'function') {
                window.stoInitDateFields($activePanel);
            }
            if (typeof window.stoInitDateTimeFields === 'function') {
                window.stoInitDateTimeFields($activePanel);
            }
            if (typeof window.stoInitDimensionFields === 'function') {
                window.stoInitDimensionFields($activePanel);
            }
            if (typeof window.stoInitGalleryFields === 'function') {
                window.stoInitGalleryFields($activePanel);
            }
            if (typeof window.stoInitMultiTextFields === 'function') {
                window.stoInitMultiTextFields($activePanel);
            }
            if (typeof window.stoInitGoogleMapFields === 'function') {
                window.stoInitGoogleMapFields($activePanel);
            }
            if (typeof window.stoInitIconSelectFields === 'function') {
                window.stoInitIconSelectFields($activePanel);
            }
            if (typeof window.stoInitImportExport === 'function') {
                window.stoInitImportExport($activePanel);
            }
            if (typeof window.stoInitAlignmentFields === 'function') {
                window.stoInitAlignmentFields($activePanel);
            }
            if (typeof window.stoInitCheckboxControls === 'function') {
                window.stoInitCheckboxControls($activePanel);
            }
			if (typeof window.stoInitTabsControls === 'function') {
                window.stoInitTabsControls($activePanel);
            }
            if (typeof window.stoInitAccordionControls === 'function') {
                window.stoInitAccordionControls($activePanel);
            }
            if (typeof window.stoInitCodeEditors === 'function') {
                window.stoInitCodeEditors($activePanel);
            }
        }, 0);
    }

    /**
     * Image help tooltips: one floating popover, in-memory load cache per image URL,
     * optional preloader image/video URL or CSS spinner.
     */
    (function stoFieldHelpTooltips() {
        var CACHE = {};
        var $popover;
        var hideTimer;
        var reposTimer;
        var activeUrl = '';
        /** @type {HTMLElement|null} */
        var activeTriggerEl = null;
        var PLACEMENT_CLASS = 'sto-field-help-popover--at-above sto-field-help-popover--at-below sto-field-help-popover--at-right sto-field-help-popover--at-left';

        function ensurePopover() {
            if ($popover && $popover.length) {
                return $popover;
            }
            $popover = $(
                '<div id="sto-field-help-popover" class="sto-field-help-popover" role="tooltip" aria-hidden="true" hidden>' +
                    '<div class="sto-field-help-popover__surface">' +
                        '<div class="sto-field-help-popover__inner">' +
                            '<div class="sto-field-help-popover__body">' +
                                '<div class="sto-tooltip-preloader-slot" data-sto-tooltip-preloader-slot></div>' +
                                '<img class="sto-field-help-popover__img" alt="" style="display:none" />' +
                            '</div>' +
                        '</div>' +
                        '<span class="sto-field-help-popover__arrow" aria-hidden="true"></span>' +
                    '</div>' +
                '</div>'
            );
            $('body').append($popover);

            $popover.on('mouseenter', function() {
                window.clearTimeout(hideTimer);
            });
            $popover.on('mouseleave', function() {
                scheduleHide();
            });

            return $popover;
        }

        function stopReposTicker() {
            if (reposTimer) {
                window.clearInterval(reposTimer);
                reposTimer = null;
            }
        }

        function startReposTicker() {
            stopReposTicker();
            reposTimer = window.setInterval(function() {
                if (activeTriggerEl && $popover && $popover.hasClass('sto-is-visible')) {
                    layoutPopover();
                }
            }, 48);
        }

        var POPVIS =
            'sto-field-help-popover--state-loading sto-field-help-popover--state-error sto-field-help-popover--state-ready';

        function fieldHelpStrings() {
            var cfg = window.simple_theme_options && window.simple_theme_options.sto_field_help;
            return {
                loading: (cfg && cfg.loading) || 'Loading preview…',
                loading_hint: (cfg && cfg.loading_hint) || 'Please wait.',
                not_found: (cfg && cfg.not_found) || 'Preview not found',
                error_hint: (cfg && cfg.error_hint) || 'This image could not be loaded.'
            };
        }

        function escFieldHelp(s) {
            return String(s == null ? '' : s)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        }

        function setPopoverVisualState(state) {
            var $p = ensurePopover();
            $p.removeClass(POPVIS);
            if (state === 'loading') {
                $p.addClass('sto-field-help-popover--state-loading');
            } else if (state === 'error') {
                $p.addClass('sto-field-help-popover--state-error');
            } else if (state === 'ready') {
                $p.addClass('sto-field-help-popover--state-ready');
            }
        }

        function isVideoUrl(url) {
            return /\.(mp4|webm|ogg)(\?|$)/i.test(String(url || ''));
        }

        function appendLoadingCard($slot, preloadUrl) {
            var str = fieldHelpStrings();
            var $card = $('<div class="sto-tooltip-help-card sto-tooltip-help-card--phase-loading"></div>');
            var $media = $('<div class="sto-tooltip-help-card__media"></div>');
            if (!preloadUrl) {
                $media.append($('<div class="sto-tooltip-spinner" aria-hidden="true"></div>'));
            } else if (isVideoUrl(preloadUrl)) {
                $('<video>', {
                    class: 'sto-tooltip-preloader-video',
                    src: preloadUrl,
                    muted: true,
                    playsinline: true,
                    loop: true,
                    autoplay: true,
                    'aria-hidden': 'true'
                }).appendTo($media);
            } else {
                var $pimg = $('<img>', { src: preloadUrl, alt: '', class: 'sto-tooltip-preloader-img' });
                $pimg.on('error', function() {
                    $media.empty().append($('<div class="sto-tooltip-spinner" aria-hidden="true"></div>'));
                });
                $media.append($pimg);
            }
            $card.append($media);
            $card.append(
                '<p class="sto-tooltip-help-card__title">' + escFieldHelp(str.loading) + '</p>' +
                    '<p class="sto-tooltip-help-card__hint">' +
                    escFieldHelp(str.loading_hint) +
                    '</p>'
            );
            $slot.append($card);
        }

        function renderTooltipErrorIntoSlot($slot) {
            var str = fieldHelpStrings();
            $slot.removeClass('sto-is-hidden').empty();
            $slot.append(
                '<div class="sto-tooltip-help-card sto-tooltip-help-card--error" role="alert">' +
                    '<div class="sto-tooltip-help-card__icon" aria-hidden="true">!</div>' +
                    '<p class="sto-tooltip-help-card__title">' +
                    escFieldHelp(str.not_found) +
                    '</p>' +
                    '<p class="sto-tooltip-help-card__hint">' +
                    escFieldHelp(str.error_hint) +
                    '</p>' +
                '</div>'
            );
        }

        function fillPreloaderSlot($slot, preloadUrl) {
            $slot.removeClass('sto-is-hidden').empty();
            appendLoadingCard($slot, preloadUrl);
        }

        /**
         * Anchor popover to the help trigger (viewport coords), never the cursor.
         * Uses a minimum height while the preview is still loading so we do not clamp
         * the box to the top of the window (tiny ph + bottom clamp bug).
         */
        function layoutPopover() {
            var $p = ensurePopover();
            if (!activeTriggerEl || !document.documentElement.contains(activeTriggerEl)) {
                hidePopover();
                return;
            }

            var tr = activeTriggerEl.getBoundingClientRect();
            var tcx = tr.left + tr.width / 2;
            var tcy = tr.top + tr.height / 2;
            var vw = window.innerWidth;
            var vh = window.innerHeight;
            var gap = 10;
            var margin = 10;
            var minLayoutH = $p.hasClass('sto-field-help-popover--state-ready') ? 120 : 152;

            $p.removeAttr('hidden').attr('aria-hidden', 'false').addClass('sto-is-visible');
            $p.removeClass(PLACEMENT_CLASS);

            var pw = $p.outerWidth();
            var phRaw = $p.outerHeight();
            var phLayout = Math.max(phRaw, minLayoutH);
            var phPos = Math.max(phRaw, 130);

            var fitAbove = tr.top - phLayout - gap >= margin;
            var fitBelow = tr.bottom + phLayout + gap <= vh - margin;
            var fitRight = tr.right + pw + gap <= vw - margin;
            var fitLeft = tr.left - pw - gap >= margin;

            var placement = 'above';
            var left = 0;
            var top = 0;

            if (fitAbove) {
                placement = 'above';
                top = tr.top - phPos - gap;
                left = tcx - pw / 2;
            } else if (fitBelow) {
                placement = 'below';
                top = tr.bottom + gap;
                left = tcx - pw / 2;
            } else if (fitRight) {
                placement = 'right';
                left = tr.right + gap;
                top = tcy - phPos / 2;
            } else if (fitLeft) {
                placement = 'left';
                left = tr.left - pw - gap;
                top = tcy - phPos / 2;
            } else {
                placement = 'below';
                top = tr.bottom + gap;
                left = tcx - pw / 2;
            }

            left = Math.max(margin, Math.min(left, vw - pw - margin));
            if (placement === 'above' || placement === 'below') {
                if (top + phRaw > vh - margin) {
                    top = Math.max(margin, vh - phRaw - margin);
                }
                if (top < margin) {
                    top = margin;
                }
            } else {
                if (top + phRaw > vh - margin) {
                    top = Math.max(margin, vh - phRaw - margin);
                }
                if (top < margin) {
                    top = margin;
                }
            }

            $p.addClass('sto-field-help-popover--at-' + placement);
            $p.css({ left: Math.round(left), top: Math.round(top) });

            var $arrow = $p.find('.sto-field-help-popover__arrow');
            var surface = $p.find('.sto-field-help-popover__surface')[0];
            var sr = surface ? surface.getBoundingClientRect() : $p[0].getBoundingClientRect();

            $arrow.css({
                left: '',
                right: '',
                top: '',
                bottom: '',
                transform: ''
            });

            if (placement === 'above' || placement === 'below') {
                var tipX = tcx - sr.left;
                var edge = 20;
                tipX = Math.max(edge, Math.min(sr.width - edge, tipX));
                $arrow.css({
                    left: Math.round(tipX) + 'px',
                    bottom: placement === 'above' ? '-9px' : 'auto',
                    top: placement === 'below' ? '-9px' : 'auto',
                    transform: 'translateX(-50%)'
                });
            } else if (placement === 'right' || placement === 'left') {
                var tipY = tcy - sr.top;
                var edgeY = 20;
                tipY = Math.max(edgeY, Math.min(sr.height - edgeY, tipY));
                $arrow.css({
                    top: Math.round(tipY) + 'px',
                    left: placement === 'right' ? '-9px' : 'auto',
                    right: placement === 'left' ? '-9px' : 'auto',
                    transform: 'translateY(-50%)'
                });
            }
        }

        function showPreloaderSlot(preloadUrl) {
            var $p = ensurePopover();
            setPopoverVisualState('loading');
            var $slot = $p.find('[data-sto-tooltip-preloader-slot]');
            fillPreloaderSlot($slot, preloadUrl);
            $p.find('.sto-field-help-popover__img').hide().removeAttr('src');
        }

        function hidePreloaderSlot() {
            if (!$popover || !$popover.length) {
                return;
            }
            var $slot = $popover.find('[data-sto-tooltip-preloader-slot]');
            $slot.addClass('sto-is-hidden').empty();
        }

        function showLoadedImage(url) {
            var $p = ensurePopover();
            var $img = $p.find('.sto-field-help-popover__img');
            $img.attr('src', url).css('display', 'block');
            hidePreloaderSlot();
            setPopoverVisualState('ready');
        }

        function showTooltipImageError() {
            if (!$popover || !$popover.length) {
                return;
            }
            setPopoverVisualState('error');
            var $slot = $popover.find('[data-sto-tooltip-preloader-slot]');
            renderTooltipErrorIntoSlot($slot);
            $popover.find('.sto-field-help-popover__img').hide().removeAttr('src');
        }

        function loadMainImage(url, preloadUrl, triggerEl) {
            activeUrl = url;
            activeTriggerEl = triggerEl || activeTriggerEl;

            if (CACHE[url] === 'loaded') {
                showLoadedImage(url);
                window.requestAnimationFrame(function() {
                    layoutPopover();
                });
                return;
            }

            if (CACHE[url] === 'error') {
                ensurePopover();
                showTooltipImageError();
                window.requestAnimationFrame(function() {
                    layoutPopover();
                });
                return;
            }

            showPreloaderSlot(preloadUrl);
            window.requestAnimationFrame(function() {
                layoutPopover();
            });

            if (CACHE[url] === 'loading') {
                return;
            }

            CACHE[url] = 'loading';
            var img = new Image();
            img.onload = function() {
                CACHE[url] = 'loaded';
                if (activeUrl === url) {
                    showLoadedImage(url);
                    window.requestAnimationFrame(function() {
                        layoutPopover();
                    });
                }
            };
            img.onerror = function() {
                CACHE[url] = 'error';
                if (activeUrl === url) {
                    showTooltipImageError();
                    window.requestAnimationFrame(function() {
                        layoutPopover();
                    });
                }
            };
            img.src = url;
        }

        function hidePopover() {
            activeUrl = '';
            activeTriggerEl = null;
            stopReposTicker();
            if ($popover && $popover.length) {
                $popover
                    .removeClass('sto-is-visible')
                    .removeClass(PLACEMENT_CLASS)
                    .removeClass(POPVIS)
                    .attr('aria-hidden', 'true')
                    .attr('hidden', 'hidden');
                $popover.find('.sto-field-help-popover__img').removeAttr('src').hide();
                $popover.find('[data-sto-tooltip-preloader-slot]').addClass('sto-is-hidden').empty();
                $popover.find('.sto-field-help-popover__arrow').removeAttr('style');
            }
        }

        function scheduleHide() {
            window.clearTimeout(hideTimer);
            hideTimer = window.setTimeout(hidePopover, 160);
        }

        var HELP_TRIGGER_SELECTOR = '.sto-field-help-trigger, .sto-button-group__hint[data-sto-tooltip-image]';

        $(document).on('mouseenter focusin', HELP_TRIGGER_SELECTOR, function() {
            var el = this;
            var $t = $(el);
            var url = $t.attr('data-sto-tooltip-image') || '';
            if (!url) {
                return;
            }
            window.clearTimeout(hideTimer);
            activeTriggerEl = el;
            ensurePopover();
            startReposTicker();
            var preload = $t.attr('data-sto-tooltip-preloader') || '';
            loadMainImage(url, preload, el);
        });

        $(document).on('mouseleave focusout', HELP_TRIGGER_SELECTOR, function() {
            scheduleHide();
        });

        $(window).on('resize.stoFieldHelp', function() {
            if (!$popover || !$popover.hasClass('sto-is-visible')) {
                return;
            }
            layoutPopover();
        });

        $(document).on('keydown.stoFieldHelp', function(e) {
            if (e.key === 'Escape') {
                hidePopover();
            }
        });
    })();

    /**
     * Lightweight text tooltip used by per-option hints (button_group `tooltip` plain text).
     * Single shared element appended to <body>; positioned above the trigger with fallback below.
     */
    (function stoTextTooltips() {
        var $tip;
        var hideTimer;
        /** @type {HTMLElement|null} */
        var activeEl = null;
        var PLACEMENT_CLASS = 'sto-text-tooltip--above sto-text-tooltip--below';

        function ensureTip() {
            if ($tip && $tip.length) {
                return $tip;
            }
            $tip = $(
                '<div id="sto-text-tooltip" class="sto-text-tooltip" role="tooltip" aria-hidden="true" hidden>' +
                    '<span class="sto-text-tooltip__body" data-sto-text-tooltip-body></span>' +
                    '<span class="sto-text-tooltip__arrow" aria-hidden="true"></span>' +
                '</div>'
            );
            $('body').append($tip);

            $tip.on('mouseenter', function() {
                window.clearTimeout(hideTimer);
            });
            $tip.on('mouseleave', function() {
                scheduleHide();
            });
            return $tip;
        }

        function layoutTip() {
            var $t = ensureTip();
            if (!activeEl || !document.documentElement.contains(activeEl)) {
                hideTip();
                return;
            }

            $t.removeAttr('hidden').attr('aria-hidden', 'false').addClass('sto-is-visible');
            $t.removeClass(PLACEMENT_CLASS);

            var tr = activeEl.getBoundingClientRect();
            var tw = $t.outerWidth();
            var th = $t.outerHeight();
            var vw = window.innerWidth;
            var vh = window.innerHeight;
            var gap = 8;
            var margin = 8;
            var tcx = tr.left + tr.width / 2;

            var placement = 'above';
            var top = tr.top - th - gap;
            var left = tcx - tw / 2;

            if (top < margin) {
                placement = 'below';
                top = tr.bottom + gap;
            }
            left = Math.max(margin, Math.min(left, vw - tw - margin));
            if (top + th > vh - margin) {
                top = Math.max(margin, vh - th - margin);
            }

            $t.addClass('sto-text-tooltip--' + placement);
            $t.css({ left: Math.round(left), top: Math.round(top) });

            // Center arrow on trigger, clamped to tooltip edges.
            var tipRect = $t[0].getBoundingClientRect();
            var ax = tcx - tipRect.left;
            var edge = 12;
            ax = Math.max(edge, Math.min(tipRect.width - edge, ax));
            $t.find('.sto-text-tooltip__arrow').css('left', Math.round(ax) + 'px');
        }

        function showTip(el) {
            var $el = $(el);
            var text = $el.attr('data-sto-text-tip') || '';
            if (!text) {
                hideTip();
                return;
            }
            window.clearTimeout(hideTimer);
            activeEl = el;
            ensureTip().find('[data-sto-text-tooltip-body]').text(text);
            window.requestAnimationFrame(layoutTip);
        }

        function hideTip() {
            activeEl = null;
            if ($tip && $tip.length) {
                $tip.removeClass('sto-is-visible').removeClass(PLACEMENT_CLASS)
                    .attr('aria-hidden', 'true')
                    .attr('hidden', 'hidden');
            }
        }

        function scheduleHide() {
            window.clearTimeout(hideTimer);
            hideTimer = window.setTimeout(hideTip, 120);
        }

        $(document).on('mouseenter focusin', '[data-sto-text-tip]:not([data-sto-tooltip-image])', function() {
            showTip(this);
        });
        $(document).on('mouseleave focusout', '[data-sto-text-tip]:not([data-sto-tooltip-image])', function() {
            scheduleHide();
        });
        $(window).on('resize.stoTextTip scroll.stoTextTip', function() {
            if ($tip && $tip.hasClass('sto-is-visible')) {
                layoutTip();
            }
        });
        $(document).on('keydown.stoTextTip', function(e) {
            if (e.key === 'Escape') {
                hideTip();
            }
        });
    })();

    $(document).on('click', '[data-sto-switcher]', function(ev) {
        ev.preventDefault();
        var $btn = $(this);
        if ($btn.closest('.sto-required-hidden').length) {
            return;
        }
        var fid = $btn.attr('data-sto-switcher-for') || '';
        if (!fid) {
            return;
        }
        var $input = $('#sto-switcher-input-' + fid);
        if (!$input.length) {
            return;
        }
        var on = $input.val() === '1';
        var next = on ? '0' : '1';
        $input.val(next);
        $btn.toggleClass('sto-switcher--on', !on);
        $btn.attr('aria-pressed', !on ? 'true' : 'false');
        $input.trigger('change');
    });

    $(document).ready(function() {
        /**
         * Brief highlight after in-page jumps (quick search + accordion demo type cards).
         * Adds `.sto-field-flash` — see `style.css` `stoFieldFlash`. `token` drops stale timers.
         *
         * @param {JQuery} $target Row or group wrapper.
         */
        var stoFieldFlashToken = 0;
        function stoFlashFieldTarget($target) {
            if (!$target || !$target.length) {
                return;
            }
            stoFieldFlashToken += 1;
            var myToken = stoFieldFlashToken;
            $target.removeClass('sto-field-flash');
            if ($target[0]) {
                /* eslint-disable no-unused-expressions */
                $target[0].offsetWidth;
                /* eslint-enable no-unused-expressions */
            }
            $target.addClass('sto-field-flash');
            window.setTimeout(function() {
                if (myToken !== stoFieldFlashToken) {
                    return;
                }
                $target.removeClass('sto-field-flash');
            }, 1700);
        }

        function getSectionFromUrl(urlString) {
            try {
                var url = new URL(urlString, window.location.origin);
                return url.searchParams.get('section') || '';
            } catch (err) {
                return '';
            }
        }

        /**
         * WP submenu uses section=general while the real panel is section=layout — walk nested sidebar rows
         * until we hit a leaf slug that matches a panel + sidebar row.
         */
        function resolveSectionToLeafSlug(rawSection) {
            var section = rawSection || '';
            if (!section) {
                return section;
            }
            var guard = 0;
            while (guard++ < 25) {
                var $row = $('.sto-option-panel-sidebar-item').filter(function() {
                    return ($(this).attr('data-sto-section') || '') === section;
                }).first();
                if (!$row.length) {
                    break;
                }
                var $nested = $row.children('.sto-option-panel-sidebar-children');
                if (!$nested.length) {
                    break;
                }
                var $firstLink = $nested.children('.sto-option-panel-sidebar-item').first().children('.sto-option-panel-sidebar-item-link').first();
                if (!$firstLink.length) {
                    break;
                }
                var next = getSectionFromUrl($firstLink.attr('href') || '');
                if (!next || next === section) {
                    break;
                }
                section = next;
            }
            return section;
        }

        function syncActiveState(urlString) {
            var rawSection = getSectionFromUrl(urlString);
            if (!rawSection) {
                rawSection = $('.sto-option-panel-wrapper').attr('data-sto-default-leaf') || '';
                if (!rawSection && window.simple_theme_options && window.simple_theme_options.sto_nav) {
                    rawSection = window.simple_theme_options.sto_nav.default_leaf || '';
                }
            }
            var section = resolveSectionToLeafSlug(rawSection);

            if (section && getSectionFromUrl(urlString) !== section) {
                try {
                    var u = new URL(urlString, window.location.origin);
                    u.searchParams.set('section', section);
                    window.history.replaceState({}, '', u.toString());
                } catch (e1) {
                    /* ignore */
                }
            }

            $('.sto-option-panel-sidebar-item, .sto-option-panel-sidebar-item-link')
                .removeClass('sto-is-active sto-is-parent-active');

            function switchSectionContent(targetSection) {
                var $panels = $('.sto-option-panel-section');
                if (!$panels.length) {
                    return;
                }

                $panels.removeClass('sto-is-active').addClass('sto-is-hidden');

                var $targetPanel = $panels.filter('[data-section="' + targetSection + '"]').first();
                if (!$targetPanel.length) {
                    $targetPanel = $panels.first();
                }

                $targetPanel.removeClass('sto-is-hidden').addClass('sto-is-active');

                syncSectionPanelsDomDisabled();

                var leafForSave = (targetSection || '').trim();
                if (!leafForSave) {
                    leafForSave = $('.sto-option-panel-wrapper').attr('data-sto-default-leaf') || '';
                    if (!leafForSave && window.simple_theme_options && window.simple_theme_options.sto_nav) {
                        leafForSave = window.simple_theme_options.sto_nav.default_leaf || '';
                    }
                }
                var $saveForm = $('#sto-theme-settings-options-form');
                if ($saveForm.length && leafForSave) {
                    $saveForm.find('input[name="sto_ts_section"]').val(leafForSave);
                    var scfg = window.simple_theme_options && window.simple_theme_options.sto_search;
                    var mbx = window.simple_theme_options && window.simple_theme_options.sto_metabox;
                    if (mbx && mbx.active && mbx.post_edit_base) {
                        try {
                            var pu = new URL(mbx.post_edit_base, window.location.origin);
                            pu.searchParams.set('section', leafForSave);
                            pu.searchParams.delete('sto_saved');
                            pu.searchParams.delete('sto_imported');
                            pu.searchParams.delete('sto_validation_error');
                            pu.searchParams.delete('sto-metabox-saved');
                            $saveForm.attr('action', pu.toString());
                            window.history.replaceState({}, '', pu.toString());
                        } catch (eForm2) {
                            /* ignore */
                        }
                    } else if (scfg && scfg.admin_base && scfg.page) {
                        try {
                            var fu = new URL(scfg.admin_base, window.location.origin);
                            fu.searchParams.set('page', scfg.page);
                            fu.searchParams.set('section', leafForSave);
                            fu.searchParams.delete('sto_saved');
                            fu.searchParams.delete('sto_imported');
                            fu.searchParams.delete('sto_validation_error');
                            $saveForm.attr('action', fu.toString());
                        } catch (eForm) {
                            /* ignore */
                        }
                    }
                }
            }

            if (!section) {
                switchSectionContent(section);
                refreshStoSelect2();
                return;
            }

            var $customLinks = $('.sto-option-panel-sidebar .sto-option-panel-sidebar-item-link');
            // Parent rows may share the same href section=… as their first child; match owning item slug (data-sto-section).
            var $activeCustomLink = $customLinks.filter(function() {
                var linkHref = $(this).attr('href') || '';
                var $item = $(this).closest('.sto-option-panel-sidebar-item');
                var itemSlug = $item.attr('data-sto-section') || '';
                return getSectionFromUrl(linkHref) === section && itemSlug === section;
            }).first();

            if ($activeCustomLink.length) {
                $activeCustomLink.addClass('sto-is-active');
                var $activeItem = $activeCustomLink.closest('.sto-option-panel-sidebar-item');
                $activeItem.addClass('sto-is-active');

                // Mark top-level parent as ancestor of the active leaf (CSS: soft tint, not blue).
                var $topLevelParent = $activeItem
                    .parents('.sto-option-panel-sidebar-children')
                    .last()
                    .closest('.sto-option-panel-sidebar-item');
                if ($topLevelParent.length) {
                    $topLevelParent.addClass('sto-is-parent-active');
                    $topLevelParent
                        .children('.sto-option-panel-sidebar-item-link')
                        .addClass('sto-is-parent-active');
                }

                // Expand all parent groups for current active item.
                $('.sto-option-panel-sidebar-children').removeClass('sto-is-open').addClass('sto-is-collapsed');
                $activeItem.parents('.sto-option-panel-sidebar-children').removeClass('sto-is-collapsed').addClass('sto-is-open');
                $activeItem.children('.sto-option-panel-sidebar-children').removeClass('sto-is-collapsed').addClass('sto-is-open');

                // Keep chevrons synced with open/collapsed state.
                $('.sto-option-panel-sidebar-toggle')
                    .removeClass('fa-angle-up')
                    .addClass('fa-angle-down');
                $activeItem
                    .parents('.sto-option-panel-sidebar-item')
                    .children('.sto-option-panel-sidebar-item-link')
                    .find('.sto-option-panel-sidebar-toggle')
                    .removeClass('fa-angle-down')
                    .addClass('fa-angle-up');
                $activeItem
                    .children('.sto-option-panel-sidebar-item-link')
                    .find('.sto-option-panel-sidebar-toggle')
                    .removeClass('fa-angle-down')
                    .addClass('fa-angle-up');

                // Update right panel heading (icon + name) without page refresh.
                var label = $activeCustomLink.find('.sto-option-panel-sidebar-item-title').text().trim();
                if (label) {
                    $('.sto-option-panel-content-title').text(label);
                }

                var iconClass = ($activeCustomLink.find('.sto-option-panel-icon').first().attr('class') || '').trim();
                if (iconClass) {
                    $('.sto-option-panel-content-icon')
                        .attr('class', iconClass + ' sto-option-panel-content-icon')
                        .removeClass('sto-option-panel-sidebar-toggle fa-angle-up fa-angle-down');
                }

                switchSectionContent(section);

            }

            switchSectionContent(section);
            refreshStoSelect2();

            var currentPage = '';
            try {
                currentPage = new URL(urlString, window.location.origin).searchParams.get('page') || '';
            } catch (e2) {
                currentPage = '';
            }
            if (currentPage && !(window.simple_theme_options && window.simple_theme_options.sto_metabox && window.simple_theme_options.sto_metabox.active)) {
                var cfgNav = window.simple_theme_options && window.simple_theme_options.sto_nav;
                var wpSubHighlight = (cfgNav && cfgNav.wp_submenu_for_leaf && cfgNav.wp_submenu_for_leaf[section]) || section;
                var $wpParent = $('#toplevel_page_' + currentPage);
                $wpParent.find('li').removeClass('current');
                $wpParent.find('a').filter(function() {
                    try {
                        var h = $(this).attr('href') || '';
                        var u = new URL(h, window.location.origin);
                        return (u.searchParams.get('section') || '') === wpSubHighlight;
                    } catch (e3) {
                        return false;
                    }
                }).first().parent('li').addClass('current');
            }
        }

        window.stoSyncThemeSettings = syncActiveState;

        (function initStoQuickSearch() {
            var cfg = window.simple_theme_options && window.simple_theme_options.sto_search;
            if (!cfg || !Array.isArray(cfg.items) || !cfg.items.length) {
                return;
            }

            var $wrap = $('[data-sto-quick-search]');
            if (!$wrap.length) {
                return;
            }

            var maxRes = cfg.max_results && cfg.max_results > 0 ? cfg.max_results : 50;
            var $input = $wrap.find('.sto-quick-search-input');
            var $panel = $wrap.find('.sto-quick-search-results');

            // Keyboard-navigation state. `activeIdx` points at the currently highlighted row
            // inside the rendered results (`-1` when the panel is closed or empty). We
            // re-query `$items` after every render so it always reflects the live DOM.
            var $items = $();
            var activeIdx = -1;

            function escapeHtml(str) {
                return String(str == null ? '' : str)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;');
            }

            function filterItems(q) {
                var needle = (q || '').trim().toLowerCase();
                if (!needle) {
                    return [];
                }
                return cfg.items.filter(function(it) {
                    var t = (it.title || '').toLowerCase();
                    var p = (it.path || '').toLowerCase();
                    var s = (it.section || '').toLowerCase();
                    var id = (it.id || '').toLowerCase();
                    return t.indexOf(needle) !== -1 || p.indexOf(needle) !== -1 || s.indexOf(needle) !== -1 || id.indexOf(needle) !== -1;
                }).slice(0, maxRes);
            }

            function renderList(list) {
                $panel.empty();
                $items = $();
                activeIdx = -1;
                $input.removeAttr('aria-activedescendant');

                if (!list.length) {
                    $panel.prop('hidden', true);
                    $input.attr('aria-expanded', 'false');
                    return;
                }
                list.forEach(function(it, idx) {
                    var icon = it.icon || 'fa-light fa-circle';
                    var $btn = $('<button type="button" class="sto-quick-search-item" role="option"></button>');
                    $btn.attr('id', 'sto-quick-search-item-' + idx);
                    $btn.attr('data-section', it.section || '');
                    $btn.attr('data-focus', it.focus || '');
                    $btn.attr('data-menu-page', it.page || '');
                    $btn.html(
                        '<span class="sto-quick-search-item-icon"><i class="' + escapeHtml(icon) + '" aria-hidden="true"></i></span>' +
                        '<span class="sto-quick-search-item-text">' +
                            '<span class="sto-quick-search-item-title">' + escapeHtml(it.title || '') + '</span>' +
                            '<span class="sto-quick-search-item-path">' + escapeHtml(it.path || '') + '</span>' +
                        '</span>'
                    );
                    $panel.append($btn);
                });
                $panel.prop('hidden', false);
                $input.attr('aria-expanded', 'true');

                // Pre-arm the first row so a single Enter press activates the most relevant
                // hit without forcing the user to press ArrowDown first.
                $items = $panel.find('.sto-quick-search-item');
                setActive(0, /* scrollIntoView */ false);
            }

            function setActive(idx, scrollIntoView) {
                if (!$items.length) {
                    activeIdx = -1;
                    return;
                }
                // Wrap-around navigation — pressing Down on the last row lands on the first
                // and vice-versa. Mirrors the affordance VS Code / Linear / Sublime command
                // palettes ship with.
                var len = $items.length;
                if (idx < 0) {
                    idx = len - 1;
                } else if (idx >= len) {
                    idx = 0;
                }
                activeIdx = idx;
                $items.removeClass('sto-quick-search-item--active').attr('aria-selected', 'false');
                var $active = $items.eq(activeIdx).addClass('sto-quick-search-item--active').attr('aria-selected', 'true');
                $input.attr('aria-activedescendant', $active.attr('id') || '');
                if (scrollIntoView && $active.length && typeof $active[0].scrollIntoView === 'function') {
                    $active[0].scrollIntoView({ block: 'nearest' });
                }
            }

            function closePanel() {
                $panel.prop('hidden', true).empty();
                $items = $();
                activeIdx = -1;
                $input.attr('aria-expanded', 'false').removeAttr('aria-activedescendant');
            }

            function openForQuery() {
                renderList(filterItems($input.val()));
            }

            /**
             * Activate a result row — push the section URL, sync the active-section state,
             * scroll the destination field / group into view, and trigger `stoFlashFieldTarget()` so
             * the admin can spot the row they landed on. Called from both `click` and
             * `Enter` so the two paths stay in lockstep.
             *
             * @param {jQuery} $btn The clicked / Enter-activated `.sto-quick-search-item`.
             */
            function activateItem($btn) {
                if (!$btn || !$btn.length) {
                    return;
                }
                var section = $btn.attr('data-section') || '';
                var focus = $btn.attr('data-focus') || '';
                var itemPage = ($btn.attr('data-menu-page') || '').trim();
                var pageSlug = itemPage || (cfg.page || '');
                var mbxGlobal = window.simple_theme_options && window.simple_theme_options.sto_metabox;
                var url;
                if (mbxGlobal && mbxGlobal.active && mbxGlobal.post_edit_base) {
                    try {
                        var uq = new URL(mbxGlobal.post_edit_base, window.location.origin);
                        uq.searchParams.set('section', section);
                        url = uq.toString();
                    } catch (eMq) {
                        url = cfg.admin_base + '?page=' + encodeURIComponent(pageSlug) + '&section=' + encodeURIComponent(section);
                    }
                } else {
                    url = cfg.admin_base + '?page=' + encodeURIComponent(pageSlug) + '&section=' + encodeURIComponent(section);
                }

                window.history.pushState({}, '', url);
                if (typeof window.stoSyncThemeSettings === 'function') {
                    window.stoSyncThemeSettings(url);
                }

                closePanel();
                $input.val('');
                $input.blur();

                // 250ms gives `syncActiveState()` time to swap the active panel before we
                // scroll — otherwise `scrollIntoView()` runs against the previous panel
                // and the target is briefly off-screen.
                window.setTimeout(function() {
                    var $target = $();
                    if (focus.indexOf('field:') === 0) {
                        var fid = focus.slice(6);
                        $target = $('#sto-field-' + fid);
                    } else if (focus.indexOf('group:') === 0) {
                        var gid = focus.slice(6);
                        $target = $('#sto-group-' + gid);
                    }
                    if (!$target.length) {
                        return;
                    }
                    var node = $target[0];
                    if (typeof node.scrollIntoView === 'function') {
                        node.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                    stoFlashFieldTarget($target);
                }, 250);
            }

            $input.on('input', function() {
                var q = $input.val();
                if (!String(q).trim()) {
                    closePanel();
                    return;
                }
                openForQuery();
            });

            $input.on('focus', function() {
                if (String($input.val()).trim()) {
                    openForQuery();
                }
            });

            $panel.on('click', '.sto-quick-search-item', function() {
                activateItem($(this));
            });

            // Keep the keyboard cursor in sync when the user mouses over a row — so pressing
            // Enter after pointing at a row activates **that** row, not the previous
            // arrow-key target. Standard combo-box convention (Algolia, Linear, VS Code).
            $panel.on('mouseenter', '.sto-quick-search-item', function() {
                var idx = $items.index(this);
                if (idx >= 0 && idx !== activeIdx) {
                    setActive(idx, /* scrollIntoView */ false);
                }
            });

            $(document).on('click.stoQuickSearch', function(e) {
                if (!$wrap.is(e.target) && $wrap.has(e.target).length === 0) {
                    closePanel();
                }
            });

            $input.on('keydown', function(e) {
                if (e.key === 'Escape') {
                    closePanel();
                    $input.blur();
                    return;
                }
                // Other keys only matter while the panel is rendered with results.
                if ($panel.prop('hidden') || !$items.length) {
                    return;
                }

                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    setActive(activeIdx + 1, /* scrollIntoView */ true);
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    setActive(activeIdx - 1, /* scrollIntoView */ true);
                } else if (e.key === 'Home') {
                    e.preventDefault();
                    setActive(0, /* scrollIntoView */ true);
                } else if (e.key === 'End') {
                    e.preventDefault();
                    setActive($items.length - 1, /* scrollIntoView */ true);
                } else if (e.key === 'Enter') {
                    if (activeIdx >= 0) {
                        e.preventDefault();
                        activateItem($items.eq(activeIdx));
                    }
                }
            });
        })();

        (function initStoAccordionDemoJumpCards() {
            function jumpToTarget($card) {
                var sel = $card.attr('data-sto-jump-target') || '';
                if (!sel) {
                    return;
                }
                var $t = $(sel);
                if (!$t.length) {
                    return;
                }
                var node = $t[0];
                if (typeof node.scrollIntoView === 'function') {
                    node.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
                window.setTimeout(function() {
                    stoFlashFieldTarget($t);
                }, 250);
            }

            $(document).on('click', '.sto-acc-demo-type[data-sto-jump-target]', function(e) {
                e.preventDefault();
                jumpToTarget($(this));
            });

            $(document).on('keydown', '.sto-acc-demo-type[data-sto-jump-target]', function(e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    jumpToTarget($(this));
                }
            });
        })();

        $(document).on('submit', 'form.sto-options-form--metabox', function(e) {
            e.preventDefault();
        });

        $('.sto-option-panel-sidebar').on('click', '.sto-option-panel-sidebar-item-link', function(e) {
            var $link = $(this);
            var href = $link.attr('href') || '';
            var $item = $link.closest('.sto-option-panel-sidebar-item');
            var $directChildren = $item.children('.sto-option-panel-sidebar-children');

            if ($directChildren.length && !$(e.target).closest('.sto-option-panel-sidebar-toggle').length) {
                var firstChildHref = $directChildren
                    .find('> .sto-option-panel-sidebar-item > .sto-option-panel-sidebar-item-link')
                    .first()
                    .attr('href');
                if (firstChildHref) {
                    $directChildren.removeClass('sto-is-collapsed').addClass('sto-is-open');
                    $link.find('.sto-option-panel-sidebar-toggle')
                        .removeClass('fa-angle-down')
                        .addClass('fa-angle-up');
                    href = firstChildHref;
                } else {
                    $directChildren.toggleClass('sto-is-open sto-is-collapsed');
                    $link.find('.sto-option-panel-sidebar-toggle')
                        .toggleClass('fa-angle-up fa-angle-down');
                }
            }

            if (!href) {
                return;
            }

            e.preventDefault();

            window.history.pushState({}, '', href);
            syncActiveState(href);
        });

        $('.sto-option-panel-sidebar').on('click', '.sto-option-panel-sidebar-toggle', function(e) {
            e.preventDefault();
            e.stopPropagation();

            var $toggle = $(this);
            var $item = $toggle.closest('.sto-option-panel-sidebar-item');
            var $children = $item.children('.sto-option-panel-sidebar-children');
            if (!$children.length) {
                return;
            }

            $children.toggleClass('sto-is-open sto-is-collapsed');
            $toggle.toggleClass('fa-angle-up fa-angle-down');
        });

        $(document).on('click', '#adminmenu .wp-submenu a', function(e) {
            var href = $(this).attr('href') || '';
            if (!href) {
                return;
            }

            var url = new URL(href, window.location.origin);
            var page = url.searchParams.get('page') || '';
            var section = url.searchParams.get('section') || '';
            if (!page || !section) {
                return;
            }

            // Keep normal behavior for other admin menus.
            if (page !== 'theme-settings') {
                return;
            }

            e.preventDefault();
            window.history.pushState({}, '', href);
            syncActiveState(href);
        });

        syncActiveState(window.location.href);
        applyRequiredVisibility($('.sto-option-panel-section.sto-is-active'));

        /**
         * Persist Theme Settings metabox fields to post meta (AJAX to sto_save_theme_options_metabox).
         *
         * @param {Object} [options]
         * @param {boolean} [options.silent] When true, success notices are suppressed (post save path); errors still show.
         * @return {Promise}
         */
        function stoRunMetaboxThemeSettingsAjaxSave(options) {
            options = options || {};
            var silent = !!options.silent;
            var mbx = window.simple_theme_options && window.simple_theme_options.sto_metabox;
            if (!mbx || !mbx.active || !mbx.ajax_save_nonce || !mbx.ajax_action || !mbx.post_id) {
                return Promise.resolve({ skipped: true });
            }
            var $form = $('form#sto-theme-settings-options-form.sto-options-form--metabox');
            if (!$form.length) {
                return Promise.resolve({ skipped: true });
            }
            var formEl = $form[0];
            $(formEl).trigger('submit');
            var $inner = $form.closest('.sto-theme-settings-metabox-inner');
            var $ok = $inner.find('.sto-metabox-inline-notice--success');
            var $err = $inner.find('.sto-metabox-inline-notice--error');
            return new Promise(function(resolve, reject) {
                window.setTimeout(function() {
                    var fd = new window.FormData(formEl);
                    fd.append('action', mbx.ajax_action);
                    fd.append('nonce', mbx.ajax_save_nonce);
                    fd.append('post_id', String(mbx.post_id || ''));
                    var ajaxUrl = (window.simple_theme_options && window.simple_theme_options.ajax_url) || '';
                    window
                        .fetch(ajaxUrl, {
                            method: 'POST',
                            credentials: 'same-origin',
                            body: fd
                        })
                        .then(function(r) {
                            return r.json();
                        })
                        .then(function(json) {
                            if (json && json.success) {
                                if (!silent) {
                                    if ($ok.length) {
                                        $ok
                                            .text((mbx.i18n && mbx.i18n.saved) || (json.data && json.data.message) || '')
                                            .prop('hidden', false);
                                    }
                                    if ($err.length) {
                                        $err.text('').prop('hidden', true);
                                    }
                                }
                                resolve(json);
                            } else {
                                var msg =
                                    (json && json.data && json.data.message) || (mbx.i18n && mbx.i18n.save_failed) || '';
                                if ($err.length) {
                                    $err.text(msg).prop('hidden', false);
                                }
                                if ($ok.length) {
                                    $ok.text('').prop('hidden', true);
                                }
                                reject(new Error(msg || 'sto_metabox_save_failed'));
                            }
                        })
                        .catch(function() {
                            if ($err.length) {
                                $err.text((mbx.i18n && mbx.i18n.save_failed) || '').prop('hidden', false);
                            }
                            reject(new Error('sto_metabox_save_network'));
                        });
                }, 60);
            });
        }

        function initStoMetaboxSaveOnPostSave() {
            var mbx = window.simple_theme_options && window.simple_theme_options.sto_metabox;
            if (!mbx || !mbx.active || !mbx.post_id) {
                return;
            }
            var $body = $('body');
            if (!$body.hasClass('block-editor-page')) {
                var $postForm = $('#post');
                if ($postForm.length) {
                    var stoClassicResubmitting = false;
                    $postForm.on('submit.stoMetaboxPersist', function(ev) {
                        if (stoClassicResubmitting) {
                            stoClassicResubmitting = false;
                            return;
                        }
                        if (!$('form#sto-theme-settings-options-form.sto-options-form--metabox').length) {
                            return;
                        }
                        ev.preventDefault();
                        stoRunMetaboxThemeSettingsAjaxSave({ silent: true })
                            .then(function() {
                                stoClassicResubmitting = true;
                                if ($postForm[0]) {
                                    window.HTMLFormElement.prototype.submit.call($postForm[0]);
                                }
                            })
                            .catch(function() {
                                stoClassicResubmitting = true;
                                if ($postForm[0]) {
                                    window.HTMLFormElement.prototype.submit.call($postForm[0]);
                                }
                            });
                    });
                }
                return;
            }
            if (!window.wp || !window.wp.data || typeof window.wp.data.subscribe !== 'function') {
                return;
            }
            var tries = 0;
            function stoTryMetaboxBlockSubscribe() {
                tries += 1;
                if (!window.wp || !window.wp.data || typeof window.wp.data.subscribe !== 'function' || tries > 50) {
                    return;
                }
                if (!window.wp.data.select('core/editor')) {
                    window.setTimeout(stoTryMetaboxBlockSubscribe, 120);
                    return;
                }
                var prevSaving = false;
                window.wp.data.subscribe(function() {
                    var sel = window.wp.data.select('core/editor');
                    if (!sel || typeof sel.isSavingPost !== 'function') {
                        return;
                    }
                    var nowSaving = !!sel.isSavingPost();
                    var auto = typeof sel.isAutosavingPost === 'function' ? !!sel.isAutosavingPost() : false;
                    if (prevSaving && !nowSaving && !auto) {
                        var failed = false;
                        if (typeof sel.didPostSaveRequestFail === 'function') {
                            failed = !!sel.didPostSaveRequestFail();
                        }
                        if (!failed && $('form#sto-theme-settings-options-form.sto-options-form--metabox').length) {
                            stoRunMetaboxThemeSettingsAjaxSave({ silent: true });
                        }
                    }
                    prevSaving = nowSaving;
                });
            }
            stoTryMetaboxBlockSubscribe();
        }
        initStoMetaboxSaveOnPostSave();

        /**
         * Block editor: Theme Settings may start off-screen or inside a clipped panel; `:visible`
         * skips Select2 init until the user scrolls/opens Meta boxes. Re-init when the shell enters view.
         */
        function initStoMetaboxIntersectionRefresh() {
            var mbx = window.simple_theme_options && window.simple_theme_options.sto_metabox;
            if (!mbx || !mbx.active || typeof window.IntersectionObserver !== 'function') {
                return;
            }
            var el = document.querySelector('.sto-theme-settings-metabox-inner');
            if (!el) {
                return;
            }
            var deb = null;
            var obs = new window.IntersectionObserver(
                function(entries) {
                    var hit = false;
                    for (var i = 0; i < entries.length; i++) {
                        if (entries[i] && entries[i].isIntersecting) {
                            hit = true;
                            break;
                        }
                    }
                    if (!hit) {
                        return;
                    }
                    window.clearTimeout(deb);
                    deb = window.setTimeout(function() {
                        deb = null;
                        refreshStoSelect2();
                    }, 100);
                },
                { root: null, rootMargin: '80px 0px 80px 0px', threshold: 0 }
            );
            obs.observe(el);
        }
        initStoMetaboxIntersectionRefresh();

        (function initStoMetaboxIntroDismiss() {
            var $alerts = $('.sto-metabox-alert[data-sto-metabox-intro-dismiss]');
            if (!$alerts.length) {
                return;
            }
            $alerts.each(function() {
                var $a = $(this);
                var token = $a.attr('data-sto-metabox-intro-dismiss') || '';
                if (!token) {
                    return;
                }
                var key = 'sto_metabox_intro_' + token;
                try {
                    if (window.localStorage && window.localStorage.getItem(key) === '1') {
                        $a.attr('hidden', true).addClass('sto-metabox-alert--dismissed');
                        return;
                    }
                } catch (e1) {
                    /* storage blocked */
                }
                $a.on('click', '.sto-metabox-alert__dismiss', function(ev) {
                    ev.preventDefault();
                    try {
                        if (window.localStorage) {
                            window.localStorage.setItem(key, '1');
                        }
                    } catch (e2) {
                        /* ignore */
                    }
                    $a.attr('hidden', true).addClass('sto-metabox-alert--dismissed');
                });
            });
        })();

        window.addEventListener('popstate', function() {
            syncActiveState(window.location.href);
        });

        $(document).on('postbox-toggled', function(ev, postbox) {
            var $pb = postbox && postbox.jquery ? postbox : $(postbox);
            if ($pb && $pb.length && $pb.find('.sto-theme-settings-metabox-inner').length) {
                window.setTimeout(function() {
                    refreshStoSelect2();
                }, 80);
            }
        });

        /**
         * wp-color-picker / Iris can emit many `change` events per second while dragging.
         * Running `refreshStoSelect2()` (Select2, typography, color re-init, background) on each
         * tick breaks Iris and leaves the text value out of sync with the UI.
         */
        var stoOptionsFormWidgetRefreshTimer = null;
        function onStoOptionsFormControlChanged() {
            applyRequiredVisibility($('.sto-option-panel-section.sto-is-active'));
            window.clearTimeout(stoOptionsFormWidgetRefreshTimer);
            stoOptionsFormWidgetRefreshTimer = window.setTimeout(function() {
                stoOptionsFormWidgetRefreshTimer = null;
                refreshStoSelect2();
            }, 180);
        }
        $(document).on('change', '.sto-options-form :input', onStoOptionsFormControlChanged);
        // Select2 does not always surface the same bubbling `change` timing as native selects;
        // hook explicit events so `required` + dependents stay in sync after AJAX picks / clear.
        $(document).on(
            'select2:select select2:clear select2:unselect',
            '.sto-options-form select.sto-input-select, .sto-options-form select.sto-dynamic-object',
            function() {
                window.setTimeout(onStoOptionsFormControlChanged, 0);
            }
        );
    });
})(jQuery);