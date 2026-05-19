/**
 * Advanced repeater: nested lists, hidden JSON (sto_options), sortable, collapse.
 *
 * UI events are delegated once per `.sto-field-row-advanced-repeater` so every nesting level
 * shares one handler path (avoids per-wrap bind gaps and duplicate toggle handling).
 * After a row body is expanded or a new item is appended, calls `window.stoInitSelect2ForScope`
 * (from main.js) so selects inside formerly hidden bodies get Select2 — `initStoSelect2` skips
 * `:hidden` controls on first paint when `default_collapsed` is true. Also calls **`window.stoInitIconSelectFields`** for **`icon_select`** leaves.
 */
(function ($) {
    'use strict';

    function fieldRow($el) {
        return $el.closest('[data-sto-field-id]');
    }

    function parseI18n($fieldRow) {
        var raw = $fieldRow.find('.sto-adv-rep').first().attr('data-sto-adv-rep-i18n') || '{}';
        try {
            var o = JSON.parse(raw);
            return o && typeof o === 'object' ? o : {};
        } catch (e) {
            return {};
        }
    }

    function rootRepeaterWrap($fieldRow) {
        return $fieldRow.find('.sto-adv-rep').first();
    }

    function rootHidden($fieldRow) {
        return rootRepeaterWrap($fieldRow).find('.sto-adv-rep__value').first();
    }

    function normalizeAdvRepRequiredGroups(parsed) {
        if (!parsed || typeof parsed !== 'object') {
            return [];
        }
        var keys = Object.keys(parsed);
        var isList = keys.length > 0 && keys.every(function (key, index) {
            return String(index) === String(key);
        });
        if (!isList) {
            return [parsed];
        }
        return parsed.filter(function (group) {
            return group && typeof group === 'object';
        });
    }

    function advRepGroupMatches($rowBody, group) {
        var fieldKeys = Object.keys(group || {});
        if (!fieldKeys.length) {
            return false;
        }
        return fieldKeys.every(function (leafKey) {
            var $dep = $rowBody.find('[data-sto-adv-rep-leaf][data-sto-adv-rep-key="' + leafKey + '"]').first();
            if (!$dep.length) {
                return false;
            }
            return String(readLeaf($dep)) === String(group[leafKey]);
        });
    }

    function applyAdvRepLeafRequiredVisibility($scope) {
        var $ctx = $scope && $scope.length ? $scope : $(document);
        $ctx.find('.sto-field-row-advanced-repeater').each(function () {
            var $fieldRow = $(this);
            $fieldRow.find('li[data-sto-adv-rep-item]').each(function () {
                var $rowBody = rowBody($(this));
                if (!$rowBody.length) {
                    return;
                }
                $rowBody.find('[data-sto-adv-rep-required]').each(function () {
                    var $leaf = $(this);
                    var rawRule = $leaf.attr('data-sto-adv-rep-required');
                    if (!rawRule) {
                        return;
                    }
                    var parsed;
                    try {
                        parsed = JSON.parse(rawRule);
                    } catch (parseError) {
                        return;
                    }
                    var groups = normalizeAdvRepRequiredGroups(parsed);
                    var isVisible = groups.length
                        ? groups.some(function (group) {
                              return advRepGroupMatches($rowBody, group);
                          })
                        : true;
                    $leaf.toggleClass('sto-required-hidden', !isVisible);
                });
            });
        });
    }

    function readLeaf($leaf) {
        var k = $leaf.attr('data-sto-adv-rep-kind') || '';
        if (k === 'switcher') {
            return $leaf.find('[data-sto-adv-rep-switcher]').prop('checked') ? '1' : '0';
        }
        if (k === 'select') {
            return String($leaf.find('[data-sto-adv-rep-select]').val() || '');
        }
        if (k === 'icon_select') {
            return String($leaf.find('[data-sto-adv-rep-icon]').val() || '');
        }
        if (k === 'rich_modern_editor') {
            return String($leaf.find('.sto-rich-modern-editor__input').first().val() || '');
        }
        if (k === 'textarea') {
            return String($leaf.find('[data-sto-adv-rep-input]').val() || '');
        }
        return String($leaf.find('[data-sto-adv-rep-input]').val() || '');
    }

    function collectFieldset($fs) {
        var o = {};
        $fs.children().not('.sto-adv-rep__fieldset-title').each(function () {
            mergePart($(this), o);
        });
        return o;
    }

    /**
     * Body element for one repeater row (direct child of li[data-sto-adv-rep-item]).
     *
     * @param {JQuery} $li
     * @return {JQuery}
     */
    function rowBody($li) {
        var $b = $li.children('[data-sto-adv-rep-body]').first();
        if ($b.length) {
            return $b;
        }
        var $head = $li.children('.sto-adv-rep__head').first();
        return $head.nextAll('[data-sto-adv-rep-body]').first();
    }

    function collectRepItems($nest) {
        var out = [];
        $nest.find('> ul[data-sto-adv-rep-list] > li[data-sto-adv-rep-item]').each(function () {
            var $b = rowBody($(this));
            out.push(collectItemBody($b));
        });
        return out;
    }

    function mergePart($node, target) {
        if ($node.is('[data-sto-adv-rep-fieldset]')) {
            var fid = String($node.attr('data-sto-adv-rep-fieldset') || '').trim();
            if (fid) {
                target[fid] = collectFieldset($node);
            }
            return;
        }
        if ($node.is('[data-sto-adv-rep-leaf]')) {
            var key = String($node.attr('data-sto-adv-rep-key') || '').trim();
            if (key) {
                target[key] = readLeaf($node);
            }
            return;
        }
        if ($node.hasClass('sto-adv-rep--nested')) {
            var nk = String($node.attr('data-sto-adv-rep-nested-key') || '').trim();
            if (nk) {
                target[nk] = collectRepItems($node);
            }
        }
    }

    function collectItemBody($body) {
        var o = {};
        $body.children().each(function () {
            mergePart($(this), o);
        });
        return o;
    }

    function collectRoot($wrap) {
        var $list = $wrap.find('> ul[data-sto-adv-rep-list]').first();
        var rows = [];
        $list.children('li[data-sto-adv-rep-item]').each(function () {
            var $b = rowBody($(this));
            rows.push(collectItemBody($b));
        });
        return rows;
    }

    function syncFromAny($el) {
        var $fr = fieldRow($el);
        var $top = rootRepeaterWrap($fr);
        if (!$top.length) {
            return;
        }
        var $hid = rootHidden($fr);
        if (!$hid.length) {
            return;
        }
        var rows = collectRoot($top);
        $hid.val(JSON.stringify(rows)).trigger('change');
    }

    function clearLeaf($leaf) {
        var k = $leaf.attr('data-sto-adv-rep-kind') || '';
        if (k === 'switcher') {
            var $cb = $leaf.find('[data-sto-adv-rep-switcher]');
            $cb.prop('checked', false);
            $cb.closest('.sto-switcher').removeClass('sto-switcher--on');
        } else if (k === 'select') {
            $leaf.find('[data-sto-adv-rep-select]').prop('selectedIndex', 0);
        } else if (k === 'icon_select') {
            var def = '';
            var $h = $leaf.find('[data-sto-adv-rep-icon]').first();
            def = String($h.attr('data-sto-adv-rep-icon-default') || '');
            var $iso = $h.closest('.sto-icon-select[data-sto-icon-select]');
            $h.val(def);
            if ($iso.length && typeof window.stoIconSelectApplyClass === 'function') {
                window.stoIconSelectApplyClass($iso.first(), def);
            } else {
                $h.trigger('change');
            }
        } else if (k === 'rich_modern_editor') {
            $leaf.find('.sto-rich-modern-editor__input').first().val('').trigger('change');
            if (typeof window.stoDestroyRichModernEditors === 'function') {
                window.stoDestroyRichModernEditors($leaf);
            }
            $leaf.find('.sto-rich-modern-editor').each(function () {
                var $wrap = $(this);
                $wrap.removeData('stoRichModernMounted stoRichModernRoot');
                $wrap.removeClass('sto-rich-modern-editor--initialized');
                $wrap.find('.sto-rich-modern-editor__mount').empty();
            });
        } else {
            $leaf.find('[data-sto-adv-rep-input]').val('');
        }
    }

    function clearItem($li) {
        $li.find('[data-sto-adv-rep-leaf]').each(function () {
            clearLeaf($(this));
        });
        $li.find('.sto-adv-rep--nested').each(function () {
            var $n = $(this);
            var $ul = $n.find('> ul[data-sto-adv-rep-list]').first();
            $ul.find('> li').slice(1).remove();
            $ul.find('> li').first().each(function () {
                clearItem($(this));
            });
        });
        $li.find('[data-sto-adv-rep-fieldset]').each(function () {
            $(this)
                .children()
                .not('.sto-adv-rep__fieldset-title')
                .each(function () {
                    var $p = $(this);
                    if ($p.is('[data-sto-adv-rep-leaf]')) {
                        clearLeaf($p);
                    } else if ($p.hasClass('sto-adv-rep--nested')) {
                        var $ul = $p.find('> ul[data-sto-adv-rep-list]').first();
                        $ul.find('> li').slice(1).remove();
                        $ul.find('> li').first().each(function () {
                            clearItem($(this));
                        });
                    }
                });
        });
    }

    function stripRepData($root) {
        $root.find('ul[data-sto-adv-rep-list]').each(function () {
            var $l = $(this);
            if ($l.hasClass('ui-sortable')) {
                try {
                    $l.sortable('destroy');
                } catch (err) {
                    /* ignore */
                }
            }
            $l.removeData('stoAdvRepSortable');
        });
    }

    /**
     * Reset repeater `<select>` leaves to native markup before Select2 init.
     * `clone(true, true)` copies Select2 containers + stale instance data, which breaks `open()`.
     *
     * @param {JQuery} $root
     */
    function prepareRepeaterRichModernLeaves($root) {
        if (!$root || !$root.length) {
            return;
        }
        $root.find('.sto-rich-modern-editor[data-sto-rich-modern-editor]').each(function () {
            var $wrap = $(this);
            if (typeof window.stoDestroyRichModernEditors === 'function') {
                window.stoDestroyRichModernEditors($wrap);
            }
            $wrap.removeData('stoRichModernMounted stoRichModernRoot');
            $wrap.removeClass('sto-rich-modern-editor--initialized');
            $wrap.find('.sto-rich-modern-editor__mount').empty();
        });
    }

    function refreshRichModernEditorsForScope($scope) {
        if (!$scope || !$scope.length || typeof window.stoInitRichModernEditors !== 'function') {
            return;
        }
        window.stoInitRichModernEditors($scope);
    }

    function prepareRepeaterSelectLeaves($root) {
        if (!$root || !$root.length) {
            return;
        }
        $root.find('.sto-select-wrap').each(function () {
            var $wrap = $(this);
            $wrap.find('.select2-container').remove();
            var $select = $wrap.find('select.sto-input-select').first();
            if (!$select.length) {
                return;
            }
            if (typeof $.fn.select2 === 'function' && $select.data('select2')) {
                try {
                    $select.select2('destroy');
                } catch (err) {
                    /* ignore */
                }
            }
            $select
                .removeClass('select2-hidden-accessible')
                .removeAttr('aria-hidden')
                .removeAttr('tabindex')
                .removeData('select2');
        });
    }

    function escapeRegExp(str) {
        return String(str).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    /**
     * Root repeater rows use ids like `{fieldId}_{rowIndex}_{leafId}`. Clones keep the prototype
     * row index, which duplicates ids and breaks Select2 / label clicks on new items.
     *
     * @param {JQuery} $li
     * @param {string} fieldId
     * @param {number} newIndex 0-based index in the root list
     */
    function reindexRootItemDomIds($li, fieldId, newIndex) {
        if (!fieldId) {
            return;
        }
        var rowPrefix = fieldId + '_';
        $li.find('[id]').addBack().filter('[id]').each(function () {
            var $el = $(this);
            var id = String($el.attr('id') || '');
            if (id.indexOf(rowPrefix) !== 0) {
                return;
            }
            var after = id.slice(rowPrefix.length);
            var sep = after.indexOf('_');
            var rest = sep === -1 ? '' : after.slice(sep + 1);
            var newId = rest === '' ? fieldId + '_' + newIndex : fieldId + '_' + newIndex + '_' + rest;
            if (newId !== id) {
                $el.attr('id', newId);
            }
        });
        $li.find('label[for]').each(function () {
            var $label = $(this);
            var forAttr = String($label.attr('for') || '');
            if (forAttr.indexOf(rowPrefix) !== 0) {
                return;
            }
            var after = forAttr.slice(rowPrefix.length);
            var sep = after.indexOf('_');
            var rest = sep === -1 ? '' : after.slice(sep + 1);
            var newFor = rest === '' ? fieldId + '_' + newIndex : fieldId + '_' + newIndex + '_' + rest;
            if (newFor !== forAttr) {
                $label.attr('for', newFor);
            }
        });
    }

    /**
     * @param {JQuery} $nestedWrap `.sto-adv-rep--nested`
     * @return {JQuery}
     */
    function rootRepeaterItemForNested($nestedWrap) {
        var $fr = fieldRow($nestedWrap);
        var $rootList = rootRepeaterWrap($fr).children('ul[data-sto-adv-rep-list]').first();
        return $nestedWrap
            .parents('li[data-sto-adv-rep-item]')
            .filter(function () {
                return $(this).parent().is($rootList);
            })
            .first();
    }

    /**
     * Nested repeater rows: `{fieldId}_{rootIndex}_{nestedKey}_{nestedIndex}_{leafId}`.
     *
     * @param {JQuery} $li
     * @param {string} fieldId
     * @param {JQuery} $nestedWrap
     */
    function reindexNestedItemDomIds($li, fieldId, $nestedWrap) {
        var nestedKey = String($nestedWrap.attr('data-sto-adv-rep-nested-key') || '');
        if (!fieldId || !nestedKey) {
            return;
        }
        var $rootLi = rootRepeaterItemForNested($nestedWrap);
        var rootIndex = $rootLi.length ? $rootLi.index() : 0;
        var nestedIndex = $li.index();
        var sampleId = String($li.find('[id]').first().attr('id') || '');
        var match = sampleId.match(
            new RegExp('^' + escapeRegExp(fieldId + '_' + rootIndex + '_' + nestedKey) + '_(\\d+)')
        );
        var oldNestedIndex = match ? match[1] : '0';
        var oldPrefix = fieldId + '_' + rootIndex + '_' + nestedKey + '_' + oldNestedIndex + '_';
        var newPrefix = fieldId + '_' + rootIndex + '_' + nestedKey + '_' + nestedIndex + '_';

        $li.find('[id]').addBack().filter('[id]').each(function () {
            var $el = $(this);
            var id = String($el.attr('id') || '');
            if (id.indexOf(oldPrefix) === 0) {
                $el.attr('id', newPrefix + id.slice(oldPrefix.length));
            }
        });
        $li.find('label[for]').each(function () {
            var $label = $(this);
            var forAttr = String($label.attr('for') || '');
            if (forAttr.indexOf(oldPrefix) === 0) {
                $label.attr('for', newPrefix + forAttr.slice(oldPrefix.length));
            }
        });
    }

    function expandRepeaterRow($li) {
        var $body = rowBody($li);
        var $toggle = $li.children('.sto-adv-rep__head').find('[data-sto-adv-rep-toggle]').first();
        if (!$body.length || !$toggle.length) {
            return;
        }
        if ($body.is(':visible')) {
            return;
        }
        $body.show().css({
            display: '',
            height: '',
            overflow: '',
            paddingTop: '',
            paddingBottom: '',
            marginTop: '',
            marginBottom: ''
        });
        $toggle.attr('aria-expanded', 'true');
        $toggle.find('.sto-adv-rep__chev').removeClass('fa-chevron-down').addClass('fa-chevron-up');
        refreshRichModernEditorsForScope($body);
    }

    function refreshSelect2ForFieldRow($fromEl) {
        var $fr = $fromEl.closest('.sto-field-row-advanced-repeater');
        if (!$fr.length || typeof window.stoInitSelect2ForScope !== 'function') {
            return;
        }
        prepareRepeaterSelectLeaves($fr);
        window.stoInitSelect2ForScope($fr);
    }

    function refreshSelect2ForScope($scope) {
        if (!$scope || !$scope.length || typeof window.stoInitSelect2ForScope !== 'function') {
            return;
        }
        prepareRepeaterSelectLeaves($scope);
        window.stoInitSelect2ForScope($scope);
    }

    function refreshIconSelectForScope($scope) {
        if (typeof window.stoInitIconSelectFields !== 'function') {
            return;
        }
        window.stoInitIconSelectFields($scope);
    }

    function maxRows($wrap) {
        return parseInt($wrap.attr('data-sto-adv-rep-max') || '0', 10);
    }

    function bindSortable($wrap) {
        var $list = $wrap.find('> ul[data-sto-adv-rep-list]').first();
        if ($list.data('stoAdvRepSortable')) {
            if ($list.hasClass('ui-sortable')) {
                $list.sortable('refresh');
            }
            return;
        }
        if (!$.fn.sortable) {
            return;
        }
        $list.data('stoAdvRepSortable', 1);
        $list.sortable({
            handle: '[data-sto-adv-rep-drag]',
            items: '> li[data-sto-adv-rep-item]',
            tolerance: 'pointer',
            cursor: 'grabbing',
            // jQuery UI default cancel includes `button` — the grip is a <button>, so drags never start (same fix as multi_text).
            cancel: 'input,textarea,select,option',
            placeholder: 'sto-adv-rep__item sto-adv-rep__item--placeholder',
            forcePlaceholderSize: true,
            update: function () {
                syncFromAny($list);
            }
        });
    }

    function titleViewKey($wrap) {
        return String($wrap.attr('data-sto-adv-rep-title-view') || '').trim();
    }

    function readTitleFromRow($li, titleKey) {
        if (!titleKey) {
            return '';
        }
        var $body = rowBody($li);
        if (!$body.length) {
            return '';
        }
        var $leaf = $body
            .find('[data-sto-adv-rep-leaf][data-sto-adv-rep-key="' + titleKey + '"]')
            .filter(':visible')
            .first();
        if (!$leaf.length) {
            $leaf = $body.find('[data-sto-adv-rep-leaf][data-sto-adv-rep-key="' + titleKey + '"]').first();
        }
        if (!$leaf.length) {
            return '';
        }
        var kind = String($leaf.attr('data-sto-adv-rep-kind') || '');
        var text = '';
        if (kind === 'select') {
            var $select = $leaf.find('[data-sto-adv-rep-select]').first();
            text = String($select.find('option:selected').text() || $select.val() || '');
        } else if (kind === 'switcher') {
            text = $leaf.find('[data-sto-adv-rep-switcher]').prop('checked') ? 'On' : 'Off';
        } else {
            text = String($leaf.find('[data-sto-adv-rep-input]').first().val() || '');
        }
        text = text.replace(/\s+/g, ' ').trim();
        if (text.length > 100) {
            text = text.slice(0, 97) + '...';
        }
        return text;
    }

    function resolveRowToggleLabel($li, index, baseLabel, titleKey) {
        var custom = readTitleFromRow($li, titleKey);
        if (custom) {
            return custom;
        }
        return baseLabel + ' ' + (index + 1);
    }

    function rowToggleTextEl($li, isSublist) {
        if (isSublist) {
            return $li.find('.sto-adv-rep__toggle-text[data-sto-adv-rep-nested-label]').first();
        }
        return $li.children('.sto-adv-rep__head').find('.sto-adv-rep__toggle-text').not('[data-sto-adv-rep-nested-label]').first();
    }

    function updateRowToggleTitle($target) {
        var $leaf = $target.closest('[data-sto-adv-rep-leaf]');
        if (!$leaf.length) {
            return;
        }
        var changedKey = String($leaf.attr('data-sto-adv-rep-key') || '').trim();
        if (!changedKey) {
            return;
        }
        var $li = $leaf.closest('li[data-sto-adv-rep-item]');
        if (!$li.length) {
            return;
        }
        var $list = $li.parent();
        var $wrap = $list.closest('.sto-adv-rep');
        var titleKey = titleViewKey($wrap);
        if (!titleKey || titleKey !== changedKey) {
            return;
        }
        var isSublist = $list.attr('data-sto-adv-rep-sublist') === '1';
        var $fr = fieldRow($wrap);
        var i18n = parseI18n($fr);
        var base = isSublist
            ? String(i18n.nestedItemLabel != null ? i18n.nestedItemLabel : 'Nested item')
            : String(i18n.itemLabel != null ? i18n.itemLabel : 'Item');
        var index = $list.children('li[data-sto-adv-rep-item]').index($li);
        rowToggleTextEl($li, isSublist).text(resolveRowToggleLabel($li, index, base, titleKey));
    }

    function renumberItems($list) {
        var $wrap = $list.closest('.sto-adv-rep');
        var $fr = fieldRow($list);
        var i18n = parseI18n($fr);
        var sub = $list.attr('data-sto-adv-rep-sublist') === '1';
        var base = sub
            ? String(i18n.nestedItemLabel != null ? i18n.nestedItemLabel : 'Nested item')
            : String(i18n.itemLabel != null ? i18n.itemLabel : 'Item');
        var titleKey = titleViewKey($wrap);
        $list.children('li[data-sto-adv-rep-item]').each(function (idx) {
            rowToggleTextEl($(this), sub).text(resolveRowToggleLabel($(this), idx, base, titleKey));
        });
    }

    /**
     * Resolve the body block for the row whose toggle was clicked (all nesting depths).
     *
     * @param {JQuery} $btn
     * @return {JQuery}
     */
    function toggleRowBody($btn) {
        var $head = $btn.closest('.sto-adv-rep__head');
        var $body = $head.next('[data-sto-adv-rep-body]');
        if ($body.length && $body.is('[data-sto-adv-rep-body]')) {
            return $body;
        }
        var $li = $btn.closest('li[data-sto-adv-rep-item]');
        return $li.length ? rowBody($li) : $();
    }

    function bindSortablesUnderFieldRow($fieldRow) {
        $fieldRow.find('.sto-adv-rep').each(function () {
            bindSortable($(this));
        });
    }

    /**
     * One delegated listener tree per advanced-repeater field row (covers root + unlimited nesting).
     *
     * @param {JQuery} $fieldRow `.sto-field-row-advanced-repeater`
     */
    function bindDelegatedToFieldRow($fieldRow) {
        $fieldRow.on('click.stoAdvRep', '[data-sto-adv-rep-toggle]', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var $btn = $(this);
            var $body = toggleRowBody($btn);
            if (!$body.length) {
                return;
            }
            var isExpanded = String($btn.attr('aria-expanded') || '').toLowerCase() === 'true';
            $body.stop(true, true);
            if (isExpanded) {
                $body.slideUp(120);
                $btn.attr('aria-expanded', 'false');
                $btn.find('.sto-adv-rep__chev').removeClass('fa-chevron-up').addClass('fa-chevron-down');
            } else {
                $body.slideDown(120, function () {
                    $body.css({
                        display: '',
                        height: '',
                        overflow: '',
                        paddingTop: '',
                        paddingBottom: '',
                        marginTop: '',
                        marginBottom: ''
                    });
                    refreshSelect2ForFieldRow($btn);
                    refreshIconSelectForScope($body);
                    refreshRichModernEditorsForScope($body);
                });
                $btn.attr('aria-expanded', 'true');
                $btn.find('.sto-adv-rep__chev').removeClass('fa-chevron-down').addClass('fa-chevron-up');
            }
        });

        $fieldRow.on('click.stoAdvRep', '[data-sto-adv-rep-add]', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var $w = $(this).closest('.sto-adv-rep');
            var $list = $w.find('> ul[data-sto-adv-rep-list]').first();
            var cap = maxRows($w);
            var n = $list.children('li[data-sto-adv-rep-item]').length;
            if (cap > 0 && n >= cap) {
                return;
            }
            // Deep clone structure + events only — not jQuery `.data()` (Select2 instances break on copy).
            var $proto = $list.children('li[data-sto-adv-rep-item]').first().clone(true, false);
            stripRepData($proto);
            prepareRepeaterSelectLeaves($proto);
            prepareRepeaterRichModernLeaves($proto);
            clearItem($proto);
            var newIndex = $list.children('li[data-sto-adv-rep-item]').length;
            var isSublist = $list.attr('data-sto-adv-rep-sublist') === '1';
            var $fieldRow = fieldRow($w);
            var fieldId = String($fieldRow.attr('data-sto-field-id') || '');
            $list.append($proto);
            if (isSublist) {
                reindexNestedItemDomIds($proto, fieldId, $w);
            } else {
                reindexRootItemDomIds($proto, fieldId, newIndex);
            }
            renumberItems($list);
            expandRepeaterRow($proto);
            bindSortable($w);
            $proto.find('.sto-adv-rep').each(function () {
                bindSortable($(this));
            });
            syncFromAny($w);
            window.setTimeout(function () {
                refreshSelect2ForScope($proto);
                refreshIconSelectForScope($proto);
                refreshRichModernEditorsForScope($proto);
                applyAdvRepLeafRequiredVisibility($fieldRow);
            }, 0);
        });

        /**
         * Repeater switchers use a clipped checkbox inside `<label>`. Without preventing
         * default focus, the browser scrolls the admin page to the checkbox (often y=0).
         */
        $fieldRow.on('mousedown.stoAdvRep', 'label.sto-adv-rep__switcher', function (e) {
            if (e.which !== 1) {
                return;
            }
            e.preventDefault();
        });

        $fieldRow.on('click.stoAdvRep', 'label.sto-adv-rep__switcher', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var $label = $(this);
            var $checkbox = $label.find('[data-sto-adv-rep-switcher]').first();
            if (!$checkbox.length) {
                return;
            }
            var isChecked = !$checkbox.prop('checked');
            $checkbox.prop('checked', isChecked);
            $label.toggleClass('sto-switcher--on', isChecked);
            $checkbox.trigger('change');
        });

        $fieldRow.on('click.stoAdvRep', '[data-sto-adv-rep-remove]', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var $li = $(this).closest('li[data-sto-adv-rep-item]');
            var $list = $li.parent();
            if ($list.children('li[data-sto-adv-rep-item]').length <= 1) {
                clearItem($li);
                syncFromAny($li);
                return;
            }
            $li.remove();
            renumberItems($list);
            syncFromAny($list);
        });

        $fieldRow.on(
            'input.stoAdvRep change.stoAdvRep',
            '[data-sto-adv-rep-input], [data-sto-adv-rep-select], [data-sto-adv-rep-switcher], [data-sto-adv-rep-icon], .sto-rich-modern-editor__input',
            function () {
                var $t = $(this);
                if ($t.is('[data-sto-adv-rep-switcher]')) {
                    $t.closest('.sto-switcher').toggleClass('sto-switcher--on', !!$t.prop('checked'));
                }
                syncFromAny($(this));
                updateRowToggleTitle($t);
                applyAdvRepLeafRequiredVisibility($t.closest('.sto-field-row-advanced-repeater'));
                if (typeof window.stoApplyDependentFieldVisibility === 'function') {
                    window.stoApplyDependentFieldVisibility();
                }
            }
        );

        $fieldRow.on(
            'select2:select.stoAdvRep select2:clear.stoAdvRep select2:unselect.stoAdvRep',
            '[data-sto-adv-rep-select]',
            function () {
                var $select = $(this);
                window.setTimeout(function () {
                    syncFromAny($select);
                    updateRowToggleTitle($select);
                    applyAdvRepLeafRequiredVisibility($select.closest('.sto-field-row-advanced-repeater'));
                }, 0);
            }
        );
    }

    window.stoApplyAdvRepLeafRequiredVisibility = applyAdvRepLeafRequiredVisibility;

    /**
     * Flush repeater leaf values into hidden `sto_options[…]` JSON (call before product #post submit).
     *
     * @param {JQuery} [$scope]
     */
    window.stoSyncAdvancedRepeaterFields = function ($scope) {
        var $ctx = $scope && $scope.length ? $scope : $(document);
        $ctx.find('.sto-field-row-advanced-repeater').each(function () {
            syncFromAny($(this));
        });
    };

    window.stoInitAdvancedRepeaterFields = function ($scope) {
        var $ctx = $scope && $scope.length ? $scope : $(document);
        $ctx.find('.sto-field-row-advanced-repeater').each(function () {
            var $fieldRow = $(this);
            if (!$fieldRow.data('stoAdvRepUiBound')) {
                $fieldRow.data('stoAdvRepUiBound', 1);
                bindDelegatedToFieldRow($fieldRow);
            }
            bindSortablesUnderFieldRow($fieldRow);
            applyAdvRepLeafRequiredVisibility($fieldRow);
            $fieldRow.find('[data-sto-adv-rep-body]:visible').each(function () {
                refreshRichModernEditorsForScope($(this));
            });
        });
    };
})(jQuery);
