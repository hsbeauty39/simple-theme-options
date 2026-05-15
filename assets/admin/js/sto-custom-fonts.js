/**
 * Tools → Simple Backup — custom font uploads.
 *
 * Flow: drag-drop / browse / media library → upload → detect metadata → Add font(s).
 * Live preview: family + variant selects with @font-face injection (imported + pending).
 */
(function($) {
    'use strict';

    var LIVE_STYLE_ID = 'sto-custom-fonts-live-face';

    function cfg() {
        return window.stoCustomFonts || {};
    }

    function i18n(key, replacements) {
        var bundle = cfg().i18n || {};
        var text = bundle[key] || key;
        if (replacements && typeof replacements === 'object') {
            Object.keys(replacements).forEach(function(k) {
                text = String(text).split('{' + k + '}').join(String(replacements[k]));
            });
        }
        return text;
    }

    function ajaxErrorMessage(res, fallbackKey) {
        if (res && res.data && res.data.message) {
            return res.data.message;
        }
        return i18n(fallbackKey || 'uploadFailed');
    }

    function styleLabel(style) {
        if (style === 'italic') {
            return i18n('styleItalic');
        }
        return i18n('styleNormal');
    }

    function categoryLabel(category) {
        var bundle = cfg().categories || {};
        return bundle[category] || category || '';
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

    function normalizeLiveData(data) {
        if (!data || typeof data !== 'object') {
            return { families: [], cssByFamily: {} };
        }
        return {
            families: Array.isArray(data.families) ? data.families : [],
            cssByFamily: data.cssByFamily && typeof data.cssByFamily === 'object' ? data.cssByFamily : {}
        };
    }

    function removeLiveFontCss() {
        $('#' + LIVE_STYLE_ID).remove();
    }

    function injectLiveFontCss(css) {
        removeLiveFontCss();
        if (!css) {
            return;
        }
        var style = document.createElement('style');
        style.id = LIVE_STYLE_ID;
        style.textContent = css;
        document.head.appendChild(style);
    }

    function setStatus($panel, message, isError) {
        var $status = $panel.find('[data-sto-custom-fonts-status]');
        if (!$status.length) {
            return;
        }
        if (!message) {
            $status.prop('hidden', true).removeClass('sto-advance-status--error').text('');
            return;
        }
        $status
            .removeClass('sto-advance-status--error')
            .toggleClass('sto-advance-status--error', !!isError)
            .text(message)
            .prop('hidden', false);
    }

    function clearPreview($panel) {
        var $preview = $panel.find('[data-sto-custom-fonts-preview]');
        $preview.prop('hidden', true);
        $preview.find('[data-sto-preview-file]').text('');
        $preview.find('[data-sto-preview-family]').text('');
        $preview.find('[data-sto-preview-weight]').text('');
        $preview.find('[data-sto-preview-style]').text('');
        $preview.find('[data-sto-preview-category]').text('');
        $preview.find('[data-sto-preview-note]').prop('hidden', true).text('');
        $preview.find('[data-sto-preview-single]').prop('hidden', false);
        $preview.find('[data-sto-preview-zip]').prop('hidden', true);
        $preview.find('[data-sto-preview-zip-tbody]').empty();
        $panel.find('[data-sto-custom-font-add-label]').text(i18n('addOne'));
        $panel.removeData('stoPreviewFontCount');
        $panel.removeData('stoPendingLiveData');
        $panel.removeData('stoPreviewDuplicates');
        restoreImportedLivePreview($panel);
    }

    function formatDuplicateLine(dup) {
        return i18n('duplicatePreview', {
            family: dup.family || '',
            weight: String(dup.weight || ''),
            style: styleLabel(dup.style),
            file: dup.file_name || '—'
        });
    }

    function buildReplaceConfirmMessage(duplicates) {
        var lines = (duplicates || []).map(formatDuplicateLine);
        return i18n('confirmReplace') + '\n\n' + lines.join('\n') + '\n\n' + i18n('confirmReplaceContinue');
    }

    function setAddLabel($panel, count) {
        var label = count > 1
            ? i18n('addMany', { count: count })
            : i18n('addOne');
        $panel.find('[data-sto-custom-font-add-label]').text(label);
        $panel.data('stoPreviewFontCount', count);
    }

    function renderPreview($panel, data) {
        var $preview = $panel.find('[data-sto-custom-fonts-preview]');
        var fonts = Array.isArray(data.fonts) ? data.fonts : [];
        var count = fonts.length || (data.font_count ? parseInt(data.font_count, 10) : 0);

        $preview.find('[data-sto-preview-file]').text(data.file_name || '');
        setAddLabel($panel, count > 0 ? count : 1);

        if (data.is_zip && fonts.length > 1) {
            $preview.find('[data-sto-preview-single]').prop('hidden', true);
            var $zip = $preview.find('[data-sto-preview-zip]');
            $zip.find('[data-sto-preview-zip-count]').text(
                i18n('zipFound', { count: fonts.length })
            );
            var $tbody = $zip.find('[data-sto-preview-zip-tbody]');
            $tbody.empty();
            fonts.forEach(function(font) {
                $tbody.append(
                    '<tr>' +
                    '<td>' + $('<div>').text(font.file_name || '').html() + '</td>' +
                    '<td><strong>' + $('<div>').text(font.family || '').html() + '</strong></td>' +
                    '<td>' + $('<div>').text(String(font.weight || '')).html() + '</td>' +
                    '<td>' + $('<div>').text(styleLabel(font.style)).html() + '</td>' +
                    '<td>' + $('<div>').text(categoryLabel(font.category)).html() + '</td>' +
                    '</tr>'
                );
            });
            $zip.prop('hidden', false);
        } else {
            $preview.find('[data-sto-preview-single]').prop('hidden', false);
            $preview.find('[data-sto-preview-zip]').prop('hidden', true);
            var first = fonts[0] || data;
            $preview.find('[data-sto-preview-family]').text(first.family || '—');
            $preview.find('[data-sto-preview-weight]').text(first.weight || '—');
            $preview.find('[data-sto-preview-style]').text(styleLabel(first.style));
            $preview.find('[data-sto-preview-category]').text(categoryLabel(first.category));
        }

        var dupes = Array.isArray(data.duplicates) ? data.duplicates : [];
        $panel.data('stoPreviewDuplicates', dupes);

        var $note = $preview.find('[data-sto-preview-note]');
        var noteParts = [];
        if (data.note) {
            noteParts.push(data.note);
        }
        if (dupes.length) {
            noteParts.push(i18n('duplicatesHeading'));
            dupes.forEach(function(dup) {
                noteParts.push(formatDuplicateLine(dup));
            });
        }
        if (noteParts.length) {
            $note.html(noteParts.map(function(line) {
                return $('<div>').text(line).html();
            }).join('<br>')).prop('hidden', false);
        } else {
            $note.prop('hidden', true).text('');
        }
        $preview.prop('hidden', false);

        if (data.live_preview) {
            $panel.data('stoPendingLiveData', normalizeLiveData(data.live_preview));
            applyLivePreview($panel, $panel.data('stoPendingLiveData'), 'pending');
        }
    }

    function setAddEnabled($panel, enabled) {
        $panel.find('[data-sto-custom-font-add]').prop('disabled', !enabled);
    }

    function resetPick($panel) {
        $panel.find('[data-sto-custom-font-attachment]').val('');
        $panel.find('[data-sto-custom-font-file]').val('');
        clearPreview($panel);
        setAddEnabled($panel, false);
    }

    function isAllowedFile(file) {
        if (!file || !file.name) {
            return false;
        }
        var ext = String(file.name).toLowerCase().split('.').pop();
        var allowed = ['woff2', 'woff', 'ttf', 'otf', 'eot', 'zip'];
        return allowed.indexOf(ext) !== -1;
    }

    function getImportedLiveData($panel) {
        var stored = $panel.data('stoImportedLiveData');
        if (stored) {
            return stored;
        }
        return normalizeLiveData(cfg().liveData);
    }

    function setImportedLiveData($panel, data) {
        var normalized = normalizeLiveData(data);
        $panel.data('stoImportedLiveData', normalized);
        if (cfg()) {
            cfg().liveData = normalized;
        }
    }

    function restoreImportedLivePreview($panel) {
        var imported = getImportedLiveData($panel);
        if (imported.families.length) {
            applyLivePreview($panel, imported, 'imported');
        } else {
            hideLivePreview($panel);
            removeLiveFontCss();
        }
    }

    function hideLivePreview($panel) {
        $panel.find('[data-sto-custom-fonts-live]').prop('hidden', true);
    }

    function showLivePreview($panel) {
        $panel.find('[data-sto-custom-fonts-live]').prop('hidden', false);
    }

    function fillLiveFamilySelect($sel, families) {
        $sel.empty();
        families.forEach(function(entry) {
            if (!entry || !entry.family) {
                return;
            }
            $sel.append(
                $('<option></option>').attr('value', entry.family).text(entry.family)
            );
        });
    }

    function fillLiveVariantSelect($sel, variants, current) {
        var sorted = sortVariants(variants || []);
        $sel.empty();
        sorted.forEach(function(v) {
            $sel.append(
                $('<option></option>').attr('value', v).text(variantLabel(v))
            );
        });
        if (sorted.length && current && sorted.indexOf(current) !== -1) {
            $sel.val(current);
        } else if (sorted.length) {
            $sel.val(sorted[0]);
        }
    }

    function syncLiveVariants($panel, liveData, preserveVariant) {
        var $fam = $panel.find('[data-sto-live-family]');
        var $var = $panel.find('[data-sto-live-variant]');
        var family = String($fam.val() || '');
        var entry = null;
        liveData.families.forEach(function(f) {
            if (f && f.family === family) {
                entry = f;
            }
        });
        var variants = entry && entry.variants ? entry.variants : [];
        var cur = preserveVariant !== undefined && preserveVariant !== null
            ? preserveVariant
            : String($var.val() || '');
        fillLiveVariantSelect($var, variants, cur);
    }

    function applyLiveSample($panel, family, variant, category) {
        var $sample = $panel.find('[data-sto-custom-fonts-live-sample]');
        if (!$sample.length) {
            return;
        }
        var p = parseVariant(variant || 'regular');
        var stack = category || 'sans-serif';
        var famCss = family ? ('"' + String(family).replace(/"/g, '') + '", ' + stack) : 'inherit';
        $sample.find('[data-sto-live-heading], [data-sto-live-body], .sto-custom-fonts-live__glyphs').css({
            fontFamily: famCss,
            fontWeight: family ? String(p.w) : '400',
            fontStyle: family && p.i ? 'italic' : 'normal'
        });
    }

    function applyLivePreview($panel, liveData, mode) {
        liveData = normalizeLiveData(liveData);
        if (!liveData.families.length) {
            if (mode === 'pending') {
                restoreImportedLivePreview($panel);
            } else {
                hideLivePreview($panel);
                removeLiveFontCss();
            }
            return;
        }

        showLivePreview($panel);

        var $modeLabel = $panel.find('[data-sto-live-mode-label]');
        $modeLabel
            .text(mode === 'pending' ? i18n('livePending') : i18n('liveImported'))
            .prop('hidden', false);

        var $fam = $panel.find('[data-sto-live-family]');
        var prevFamily = String($fam.val() || '');
        fillLiveFamilySelect($fam, liveData.families);
        if (prevFamily && liveData.families.some(function(f) { return f.family === prevFamily; })) {
            $fam.val(prevFamily);
        } else if (liveData.families[0] && liveData.families[0].family) {
            $fam.val(liveData.families[0].family);
        }

        syncLiveVariants($panel, liveData, null);

        var family = String($fam.val() || '');
        var variant = String($panel.find('[data-sto-live-variant]').val() || 'regular');
        var entry = null;
        liveData.families.forEach(function(f) {
            if (f && f.family === family) {
                entry = f;
            }
        });
        var css = (liveData.cssByFamily && liveData.cssByFamily[family]) || '';
        injectLiveFontCss(css);
        applyLiveSample($panel, family, variant, entry ? entry.category : 'sans-serif');

        $panel.data('stoActiveLiveData', liveData);
        $panel.data('stoActiveLiveMode', mode);
    }

    function bindLivePreview($panel) {
        if ($panel.data('stoLivePreviewBound')) {
            return;
        }
        $panel.data('stoLivePreviewBound', 1);

        setImportedLiveData($panel, cfg().liveData);
        if (getImportedLiveData($panel).families.length) {
            applyLivePreview($panel, getImportedLiveData($panel), 'imported');
        }

        $panel.on('change.stoCustomFontsLive', '[data-sto-live-family]', function() {
            var liveData = $panel.data('stoActiveLiveData') || getImportedLiveData($panel);
            syncLiveVariants($panel, liveData, null);
            var family = String($(this).val() || '');
            var variant = String($panel.find('[data-sto-live-variant]').val() || 'regular');
            var entry = null;
            liveData.families.forEach(function(f) {
                if (f && f.family === family) {
                    entry = f;
                }
            });
            injectLiveFontCss((liveData.cssByFamily && liveData.cssByFamily[family]) || '');
            applyLiveSample($panel, family, variant, entry ? entry.category : 'sans-serif');
        });

        $panel.on('change.stoCustomFontsLive', '[data-sto-live-variant]', function() {
            var liveData = $panel.data('stoActiveLiveData') || getImportedLiveData($panel);
            var family = String($panel.find('[data-sto-live-family]').val() || '');
            var variant = String($(this).val() || 'regular');
            var entry = null;
            liveData.families.forEach(function(f) {
                if (f && f.family === family) {
                    entry = f;
                }
            });
            applyLiveSample($panel, family, variant, entry ? entry.category : 'sans-serif');
        });
    }

    function uploadLocalFile($panel, file) {
        var c = cfg();
        if (!file || !c.ajaxUrl || !c.nonce || !c.actionUpload) {
            return;
        }
        if (!isAllowedFile(file)) {
            setStatus($panel, i18n('invalidFile'), true);
            return;
        }

        var formData = new FormData();
        formData.append('action', c.actionUpload);
        formData.append('nonce', c.nonce);
        formData.append('file', file, file.name);

        setStatus($panel, i18n('uploading'), false);
        setAddEnabled($panel, false);
        clearPreview($panel);

        $.ajax({
            url: c.ajaxUrl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false
        })
            .done(function(res) {
                if (res && res.success && res.data) {
                    var id = parseInt(res.data.attachment_id, 10) || 0;
                    if (id <= 0) {
                        setStatus($panel, i18n('invalidFile'), true);
                        return;
                    }
                    $panel.find('[data-sto-custom-font-attachment]').val(String(id));
                    previewAttachment($panel, id);
                } else {
                    setStatus($panel, ajaxErrorMessage(res, 'uploadFailed'), true);
                }
            })
            .fail(function(xhr) {
                setStatus($panel, ajaxErrorMessage(xhr && xhr.responseJSON, 'uploadFailed'), true);
            });
    }

    function bindDropzone($panel) {
        var $drop = $panel.find('[data-sto-custom-font-dropzone]');
        var $file = $panel.find('[data-sto-custom-font-file]');
        if (!$drop.length) {
            return;
        }

        $drop.on('dragover dragenter', function(ev) {
            ev.preventDefault();
            ev.stopPropagation();
            $drop.addClass('sto-is-dragover');
        });

        $drop.on('dragleave dragend drop', function(ev) {
            ev.preventDefault();
            ev.stopPropagation();
            $drop.removeClass('sto-is-dragover');
        });

        $drop.on('drop', function(ev) {
            var dt = ev.originalEvent && ev.originalEvent.dataTransfer;
            var file = dt && dt.files && dt.files[0];
            if (!file) {
                return;
            }
            uploadLocalFile($panel, file);
        });

        $file.on('change.stoCustomFonts', function() {
            var input = this;
            var file = input.files && input.files[0];
            if (!file) {
                return;
            }
            uploadLocalFile($panel, file);
            input.value = '';
        });
    }

    function replaceTable($panel, html) {
        var $wrap = $panel.find('[data-sto-custom-fonts-table-wrap]');
        if ($wrap.length) {
            $wrap.replaceWith(html);
        }
        updateBulkDeleteUi($panel);
    }

    function getSelectedFaceIds($panel) {
        return $panel.find('[data-sto-custom-font-select]:checked').map(function() {
            return String($(this).val() || '');
        }).get().filter(function(id) {
            return id !== '';
        });
    }

    function updateBulkDeleteUi($panel) {
        var ids = getSelectedFaceIds($panel);
        var $btn = $panel.find('[data-sto-custom-fonts-bulk-delete]');
        $btn.prop('disabled', ids.length === 0);
        var $all = $panel.find('[data-sto-custom-font-select-all]');
        var $rows = $panel.find('[data-sto-custom-font-select]');
        if (!$rows.length) {
            $all.prop('checked', false).prop('indeterminate', false);
            return;
        }
        $all.prop('checked', ids.length === $rows.length);
        $all.prop('indeterminate', ids.length > 0 && ids.length < $rows.length);
    }

    function deleteFaces($panel, faceIds) {
        var c = cfg();
        if (!c.ajaxUrl || !c.nonce || !faceIds.length) {
            return;
        }
        setStatus($panel, i18n('saving'), false);
        var formData = new FormData();
        formData.append('action', c.actionDelete);
        formData.append('nonce', c.nonce);
        faceIds.forEach(function(id) {
            formData.append('face_ids[]', id);
        });
        $.ajax({
            url: c.ajaxUrl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false
        })
            .done(function(res) {
                if (res && res.success) {
                    if (res.data && res.data.table_html) {
                        replaceTable($panel, res.data.table_html);
                    }
                    if (res.data && res.data.live_data) {
                        setImportedLiveData($panel, res.data.live_data);
                        applyLivePreview($panel, res.data.live_data, 'imported');
                    }
                    setStatus($panel, (res.data && res.data.message) || i18n('deleted'), false);
                    notifyTypography();
                } else {
                    setStatus($panel, ajaxErrorMessage(res, 'deleteFailed'), true);
                }
            })
            .fail(function(xhr) {
                setStatus($panel, ajaxErrorMessage(xhr && xhr.responseJSON, 'deleteFailed'), true);
            });
    }

    function addFonts($panel, replaceDuplicates) {
        var c = cfg();
        if (!c.ajaxUrl || !c.nonce) {
            return;
        }
        var attachmentId = parseInt($panel.find('[data-sto-custom-font-attachment]').val(), 10) || 0;
        if (attachmentId <= 0) {
            return;
        }
        var $btn = $panel.find('[data-sto-custom-font-add]');
        $btn.prop('disabled', true);
        setStatus($panel, i18n('saving'), false);
        $.post(c.ajaxUrl, {
            action: c.actionAdd,
            nonce: c.nonce,
            attachment_id: attachmentId,
            replace_duplicates: replaceDuplicates ? 1 : 0
        })
            .done(function(res) {
                if (res && res.success) {
                    if (res.data && res.data.table_html) {
                        replaceTable($panel, res.data.table_html);
                    }
                    if (res.data && res.data.live_data) {
                        setImportedLiveData($panel, res.data.live_data);
                        applyLivePreview($panel, res.data.live_data, 'imported');
                    }
                    $panel.removeData('stoPendingLiveData');
                    $panel.removeData('stoPreviewDuplicates');
                    resetPick($panel);
                    setStatus($panel, (res.data && res.data.message) || i18n('added'), false);
                    $panel.find('[data-sto-custom-fonts-preview]').prop('hidden', true);
                    notifyTypography();
                } else if (res && res.data && res.data.requires_replace) {
                    var dupes = Array.isArray(res.data.duplicates) ? res.data.duplicates : [];
                    if (dupes.length && window.confirm(buildReplaceConfirmMessage(dupes))) {
                        addFonts($panel, true);
                    } else {
                        setStatus($panel, (res.data && res.data.message) || i18n('addFailed'), true);
                        setAddEnabled($panel, true);
                    }
                } else {
                    var msg = (res && res.data && res.data.message) ? res.data.message : i18n('addFailed');
                    setStatus($panel, msg, true);
                    setAddEnabled($panel, true);
                }
            })
            .fail(function() {
                setStatus($panel, i18n('addFailed'), true);
                setAddEnabled($panel, true);
            });
    }

    function notifyTypography() {
        if (typeof window.stoResetTypographyCatalog === 'function') {
            window.stoResetTypographyCatalog();
        }
        $(document).trigger('stoCustomFontsChanged');
    }

    function previewAttachment($panel, attachmentId) {
        var c = cfg();
        if (!c.ajaxUrl || !c.nonce || !c.actionPreview) {
            return;
        }
        setStatus($panel, i18n('detecting'), false);
        $.post(c.ajaxUrl, {
            action: c.actionPreview,
            nonce: c.nonce,
            attachment_id: attachmentId
        })
            .done(function(res) {
                if (res && res.success && res.data) {
                    renderPreview($panel, res.data);
                    setStatus($panel, '', false);
                    setAddEnabled($panel, true);
                } else {
                    setStatus($panel, ajaxErrorMessage(res, 'invalidFile'), true);
                    resetPick($panel);
                }
            })
            .fail(function(xhr) {
                setStatus($panel, ajaxErrorMessage(xhr && xhr.responseJSON, 'invalidFile'), true);
                resetPick($panel);
            });
    }

    function openMediaPicker($panel) {
        if (typeof wp === 'undefined' || !wp.media) {
            setStatus($panel, i18n('mediaUnavailable'), true);
            return;
        }
        var frame = wp.media({
            title: i18n('pickTitle'),
            button: { text: i18n('pickButton') },
            multiple: false
        });
        frame.on('select', function() {
            var attachment = frame.state().get('selection').first();
            if (!attachment) {
                return;
            }
            var json = attachment.toJSON();
            var id = parseInt(json.id, 10) || 0;
            if (id <= 0) {
                setStatus($panel, i18n('invalidFile'), true);
                return;
            }
            $panel.find('[data-sto-custom-font-attachment]').val(String(id));
            clearPreview($panel);
            setAddEnabled($panel, false);
            previewAttachment($panel, id);
        });
        frame.open();
    }

    function bindPanel($panel) {
        if ($panel.data('stoCustomFontsBound')) {
            return;
        }
        $panel.data('stoCustomFontsBound', 1);
        bindDropzone($panel);
        bindLivePreview($panel);

        $panel.on('click.stoCustomFonts', '[data-sto-custom-font-pick]', function(e) {
            e.preventDefault();
            openMediaPicker($panel);
        });

        $panel.on('click.stoCustomFonts', '[data-sto-custom-font-add]', function(e) {
            e.preventDefault();
            var dupes = $panel.data('stoPreviewDuplicates') || [];
            if (dupes.length && !window.confirm(buildReplaceConfirmMessage(dupes))) {
                return;
            }
            addFonts($panel, dupes.length > 0);
        });

        $panel.on('click.stoCustomFonts', '[data-sto-custom-font-delete]', function(e) {
            e.preventDefault();
            if (!window.confirm(i18n('confirmDelete'))) {
                return;
            }
            var faceId = $(this).attr('data-face-id') || '';
            if (!faceId) {
                return;
            }
            deleteFaces($panel, [faceId]);
        });

        $panel.on('change.stoCustomFontsTable', '[data-sto-custom-font-select], [data-sto-custom-font-select-all]', function() {
            var $target = $(this);
            if ($target.is('[data-sto-custom-font-select-all]')) {
                var checked = $target.prop('checked');
                $panel.find('[data-sto-custom-font-select]').prop('checked', checked);
            }
            updateBulkDeleteUi($panel);
        });

        $panel.on('click.stoCustomFontsTable', '[data-sto-custom-fonts-bulk-delete]', function(e) {
            e.preventDefault();
            var faceIds = getSelectedFaceIds($panel);
            if (!faceIds.length) {
                setStatus($panel, i18n('bulkDeleteNone'), true);
                return;
            }
            if (!window.confirm(i18n('confirmDeleteMany', { count: faceIds.length }))) {
                return;
            }
            deleteFaces($panel, faceIds);
        });

        setAddEnabled($panel, false);
        updateBulkDeleteUi($panel);
    }

    window.stoInitCustomFonts = function($scope) {
        var $root = $scope && $scope.length ? $scope : $(document);
        $root.find('[data-sto-custom-fonts-panel]').each(function() {
            bindPanel($(this));
        });
    };

    $(function() {
        if (typeof window.stoInitCustomFonts === 'function') {
            window.stoInitCustomFonts($(document.body));
        }
    });
})(jQuery);
