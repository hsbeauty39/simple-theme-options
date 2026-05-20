/**
 * Theme Settings Advance: full `sto_options` export / import (AJAX, file, clipboard),
 * demo mode toggle, per-import history (filename row).
 */
(function($) {
    'use strict';

    function cfg() {
        return window.stoThemeSettingsImportExport || {};
    }

    function i18n(key) {
        var bundle = cfg().i18n || {};
        return bundle[key] || key;
    }

    function setStatus($ctx, $statusEl, message, isError) {
        if (!$statusEl || !$statusEl.length) {
            return;
        }
        if (!message) {
            $statusEl.prop('hidden', true).removeClass('sto-advance-status--error').text('');
            return;
        }
        $statusEl
            .removeClass('sto-advance-status--error')
            .toggleClass('sto-advance-status--error', !!isError)
            .text(message)
            .prop('hidden', false);
    }

    function defaultImportLabel() {
        return i18n('pasteImportLabel') || 'pasted-backup.json';
    }

    function getSourceName($root) {
        var $h = $root.find('[data-sto-advance-source-name]');
        var v = ($h.val && typeof $h.val === 'function') ? String($h.val()).trim() : '';
        if (!v && $h.length) {
            v = String($h.attr('value') || '').trim();
        }
        return v || defaultImportLabel();
    }

    function setSourceName($root, name) {
        var $h = $root.find('[data-sto-advance-source-name]');
        if ($h.is('input')) {
            $h.val(name || '');
        } else {
            $h.attr('value', name || '');
        }
    }

    function exportErrorMessage(xhr, fallbackKey) {
        var msg = i18n(fallbackKey || 'exportFailed');
        try {
            if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                return xhr.responseJSON.data.message;
            }
            if (xhr && typeof xhr.responseText === 'string' && xhr.responseText !== '' && xhr.responseText !== '0' && xhr.responseText !== '-1') {
                var parsed = JSON.parse(xhr.responseText);
                if (parsed && parsed.data && parsed.data.message) {
                    return parsed.data.message;
                }
            }
        } catch (ignore) {
            /* use fallback */
        }
        if (xhr && (xhr.status === 403 || xhr.status === 401)) {
            return i18n('exportFailed') + ' (' + (xhr.status || '') + ')';
        }
        return msg;
    }

    function triggerExport($root, done) {
        var c = cfg();
        if (!c.ajaxUrl || !c.actionExport || !c.nonce) {
            done(i18n('exportConfigMissing'));
            return;
        }
        var post = {
            action: c.actionExport,
            nonce: c.nonce
        };
        if ($root && $root.length && $root.attr('data-sto-advance-import-export-from') === 'settings') {
            var $scopeBoxes = $root.find('[data-sto-export-menu-slug]');
            if ($scopeBoxes.length) {
                var slugs = [];
                $scopeBoxes.filter(':checked').each(function() {
                    var v = $(this).val();
                    if (v) {
                        slugs.push(String(v));
                    }
                });
                if (!slugs.length) {
                    done(i18n('exportScopeRequired'));
                    return;
                }
                post.export_menu_slugs = JSON.stringify(slugs);
            }
        }
        $.ajax({
            url: c.ajaxUrl,
            type: 'POST',
            dataType: 'json',
            data: post
        }).done(function(res) {
            if (res && res.success && res.data && typeof res.data.json === 'string' && res.data.json !== '') {
                done(null, res.data.json, res.data.filename || 'theme-settings-export.json');
            } else {
                var msg = (res && res.data && res.data.message) ? res.data.message : i18n('exportFailed');
                done(msg);
            }
        }).fail(function(xhr) {
            done(exportErrorMessage(xhr, 'exportFailed'));
        });
    }

    function triggerImportEntryExport(importId, done) {
        var c = cfg();
        $.post(
            c.ajaxUrl,
            {
                action: c.actionImportEntryExport,
                nonce: c.nonce,
                import_id: importId
            }
        ).done(function(res) {
            if (res && res.success && res.data && res.data.json) {
                done(null, res.data.json, res.data.filename || 'theme-settings-subset.json');
            } else {
                var msg = (res && res.data && res.data.message) ? res.data.message : i18n('exportFailed');
                done(msg);
            }
        }).fail(function() {
            done(i18n('exportFailed'));
        });
    }

    /**
     * Copy text from a direct user gesture. Clipboard API often fails on HTTP (non-secure) admin;
     * execCommand + textarea fallback works there; last resort fills the import box for Ctrl+C.
     */
    function copyTextToClipboard(jsonString, $root, $exportStatus) {
        function showCopied() {
            setStatus($root, $exportStatus, i18n('clipboardCopied'), false);
        }
        function showDenied() {
            setStatus($root, $exportStatus, i18n('clipboardDenied'), true);
        }
        function tryExecCommand() {
            var ta = document.createElement('textarea');
            ta.value = jsonString;
            ta.setAttribute('readonly', '');
            ta.style.cssText = 'position:fixed;left:-9999px;top:0;opacity:0;font-size:12px';
            document.body.appendChild(ta);
            ta.focus();
            ta.select();
            try {
                ta.setSelectionRange(0, jsonString.length);
            } catch (e) {
                /* IE / old */
            }
            var ok = false;
            try {
                ok = document.execCommand('copy');
            } catch (err) {
                ok = false;
            }
            document.body.removeChild(ta);
            return ok;
        }
        function fillImportBoxHint() {
            var $ta = $root.find('[data-sto-advance-textarea]');
            if ($ta.length) {
                $ta.val(jsonString).trigger('input');
                try {
                    $ta[0].focus();
                    $ta[0].select();
                } catch (e2) {
                    /* ignore */
                }
                setStatus($root, $exportStatus, i18n('clipboardManualHint'), false);
                return true;
            }
            return false;
        }
        if (tryExecCommand()) {
            showCopied();
            return;
        }
        if (typeof window.isSecureContext !== 'undefined' && window.isSecureContext && navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(jsonString).then(showCopied).catch(function() {
                if (!fillImportBoxHint()) {
                    showDenied();
                }
            });
            return;
        }
        if (!fillImportBoxHint()) {
            showDenied();
        }
    }

    function downloadJson(filename, jsonString) {
        var blob = new Blob([jsonString], { type: 'application/json;charset=utf-8' });
        var url = URL.createObjectURL(blob);
        var anchor = document.createElement('a');
        anchor.href = url;
        anchor.download = filename;
        anchor.style.display = 'none';
        document.body.appendChild(anchor);
        anchor.click();
        document.body.removeChild(anchor);
        window.setTimeout(function() {
            URL.revokeObjectURL(url);
        }, 2500);
    }

    function buildReloadUrl() {
        var c = cfg();
        if (c.importReloadUrl) {
            try {
                var url = new URL(c.importReloadUrl, window.location.origin);
                url.searchParams.set('sto_imported', '1');
                return url.toString();
            } catch (e) {
                var base = String(c.importReloadUrl || '');
                var join = base.indexOf('?') >= 0 ? '&' : '?';
                return base + join + 'sto_imported=1';
            }
        }
        var scfg = window.battery_simple_theme_options && window.battery_simple_theme_options.sto_search;
        var slug = cfg().sectionSlug || 'advance';
        if (!scfg || !scfg.admin_base || !scfg.page) {
            return window.location.href.split('#')[0];
        }
        try {
            var url = new URL(scfg.admin_base, window.location.origin);
            url.searchParams.set('page', scfg.page);
            url.searchParams.set('section', slug);
            url.searchParams.set('sto_imported', '1');
            url.searchParams.delete('sto_saved');
            url.searchParams.delete('sto_validation_error');
            return url.toString();
        } catch (e) {
            return window.location.href.split('#')[0];
        }
    }

    function setDemoUi($root, $btn, $inp, on) {
        $inp.val(on ? '1' : '0');
        $btn.toggleClass('sto-switcher--on', !!on);
        $btn.attr('aria-pressed', on ? 'true' : 'false');
    }

    function setLocationUi($card, key, on) {
        var $inp = $card.find('[data-sto-display-location-input="' + key + '"]');
        var $btn = $card.find('[data-sto-display-location-switch="' + key + '"]');
        var $body = $card.find('[data-sto-display-location-body="' + key + '"]');
        $inp.val(on ? '1' : '0');
        $btn.toggleClass('sto-switcher--on', !!on);
        $btn.attr('aria-pressed', on ? 'true' : 'false');
        if ($body.length) {
            $body.toggleClass('sto-is-hidden', !on);
            if (on) {
                $body.prop('hidden', false);
            } else {
                $body.prop('hidden', true);
            }
        }
    }

    function collectImportDisplayLocations($panel) {
        var payload = {
            admin: { enabled: false },
            customizer: { enabled: false },
            metabox: { enabled: false, post_types: [] },
            taxonomy: { enabled: false, taxonomies: [] }
        };
        var $adminInp = $panel.find('[data-sto-display-location-input="admin"]');
        if ($adminInp.length) {
            payload.admin.enabled = $adminInp.val() === '1';
        }
        var $customizerInp = $panel.find('[data-sto-display-location-input="customizer"]');
        if ($customizerInp.length) {
            payload.customizer.enabled = $customizerInp.val() === '1';
        }
        var $metaboxInp = $panel.find('[data-sto-display-location-input="metabox"]');
        if ($metaboxInp.length) {
            payload.metabox.enabled = $metaboxInp.val() === '1';
            $panel.find('[data-sto-display-post-type]:checked').each(function() {
                var slug = $(this).attr('data-sto-display-post-type');
                if (slug) {
                    payload.metabox.post_types.push(slug);
                }
            });
        }
        var $taxInp = $panel.find('[data-sto-display-location-input="taxonomy"]');
        if ($taxInp.length) {
            payload.taxonomy.enabled = $taxInp.val() === '1';
            $panel.find('[data-sto-display-taxonomy]:checked').each(function() {
                var slug = $(this).attr('data-sto-display-taxonomy');
                if (slug) {
                    payload.taxonomy.taxonomies.push(slug);
                }
            });
        }
        return payload;
    }

    function saveImportDisplayLocations($root, $panel, importId) {
        var c = cfg();
        if (!c.actionSetImportDisplayLocations || !importId) {
            return;
        }
        var $status = $root.find('[data-sto-import-display-status="' + importId + '"]');
        setStatus($root, $status, i18n('savingDisplayLocations'), false);
        $.post(c.ajaxUrl, {
            action: c.actionSetImportDisplayLocations,
            nonce: c.nonce,
            import_id: importId,
            display_locations: JSON.stringify(collectImportDisplayLocations($panel))
        })
            .done(function(res) {
                if (res && res.success) {
                    setStatus(
                        $root,
                        $status,
                        (res.data && res.data.message) || i18n('displayLocationsSaved'),
                        false
                    );
                } else {
                    var msg =
                        (res && res.data && res.data.message) ? res.data.message : i18n('displayLocationsFailed');
                    setStatus($root, $status, msg, true);
                }
            })
            .fail(function() {
                setStatus($root, $status, i18n('displayLocationsFailed'), true);
            });
    }

    function bindImportDisplayLocations($root) {
        $root.find('[data-sto-import-display-locations]').each(function() {
            var $panel = $(this);
            var importId = $panel.attr('data-sto-import-display-locations') || '';
            if (!importId) {
                return;
            }
            var saveTimer = null;

            function queueSave() {
                window.clearTimeout(saveTimer);
                saveTimer = window.setTimeout(function() {
                    saveImportDisplayLocations($root, $panel, importId);
                }, 350);
            }

            $panel.on('click', '[data-sto-display-location-switch]', function(ev) {
                ev.preventDefault();
                ev.stopPropagation();
                var key = $(this).attr('data-sto-display-location-switch');
                if (!key) {
                    return;
                }
                var $inp = $panel.find('[data-sto-display-location-input="' + key + '"]');
                var next = $inp.val() !== '1';
                setLocationUi($panel, key, next);
                queueSave();
            });

            $panel.on('change', '.sto-display-location__check', function() {
                queueSave();
            });
        });
    }

    function bindImportRowToggle($log) {
        function toggleRow($row) {
            var id = $row.attr('data-sto-advance-import-row') || '';
            if (!id) {
                return;
            }
            var $detail = $log.find('[data-sto-import-row-detail="' + id + '"]');
            if (!$detail.length) {
                return;
            }
            var open = $detail.hasClass('sto-is-hidden');
            $detail.toggleClass('sto-is-hidden', !open);
            if (open) {
                $detail.prop('hidden', false);
            } else {
                $detail.prop('hidden', true);
            }
            $row.attr('aria-expanded', open ? 'true' : 'false');
            $row.toggleClass('sto-advance-import-row--open', open);
        }

        $log.on('click', '[data-sto-import-row-toggle]', function(ev) {
            if ($(ev.target).closest('[data-sto-import-ignore-toggle]').length) {
                return;
            }
            ev.preventDefault();
            toggleRow($(this));
        });

        $log.on('keydown', '[data-sto-import-row-toggle]', function(ev) {
            if (ev.key !== 'Enter' && ev.key !== ' ') {
                return;
            }
            if ($(ev.target).closest('[data-sto-import-ignore-toggle]').length) {
                return;
            }
            ev.preventDefault();
            toggleRow($(this));
        });
    }

    function bindDemoSwitch($root) {
        var c = cfg();
        if (!c.demoCapability) {
            return;
        }
        var $btn = $root.find('[data-sto-advance-demo-switch]');
        var $inp = $root.find('[data-sto-advance-demo-input]');
        var $st = $root.find('[data-sto-advance-demo-status]');
        if (!$btn.length || !$inp.length) {
            return;
        }

        $root.on('click', '[data-sto-advance-demo-switch]', function(ev) {
            ev.preventDefault();
            var cur = $inp.val() === '1';
            var next = !cur;
            setDemoUi($root, $btn, $inp, next);
            $st.text(i18n('savingDemo'));
            var post = {
                action: c.actionSetUiDemo,
                nonce: c.nonce,
                ui_demo: next ? '1' : '0'
            };
            if ($root.attr('data-sto-advance-import-export-from') === 'settings') {
                post.from_settings = '1';
            }
            $.post(
                c.ajaxUrl,
                post
            ).done(function(res) {
                if (res && res.success) {
                    window.location.reload();
                } else {
                    var msg = (res && res.data && res.data.message) ? res.data.message : i18n('demoSaveFailed');
                    setDemoUi($root, $btn, $inp, cur);
                    $st.text(msg);
                }
            }).fail(function() {
                setDemoUi($root, $btn, $inp, cur);
                $st.text(i18n('demoSaveFailed'));
            });
        });
    }

    function bindImportHistory($root) {
        var c = cfg();
        var $logStatus = $root.find('[data-sto-advance-import-log-status]');
        var $log = $root.find('[data-sto-advance-import-log]');
        if (!$log.length) {
            return;
        }

        var $bulkBtn = $log.find('[data-sto-advance-import-bulk-remove]');
        var $selectAll = $log.find('[data-sto-advance-import-select-all]');

        function selectedIds() {
            var ids = [];
            $log.find('[data-sto-advance-import-cb]:checked').each(function() {
                var v = $(this).val();
                if (v) {
                    ids.push(String(v));
                }
            });
            return ids;
        }

        function syncBulkUi() {
            var n = selectedIds().length;
            $bulkBtn.prop('disabled', n < 1);
        }

        function syncSelectAll() {
            var $rows = $log.find('[data-sto-advance-import-cb]');
            var total = $rows.length;
            var checked = $log.find('[data-sto-advance-import-cb]:checked').length;
            if (total === 0) {
                $selectAll.prop('checked', false).prop('indeterminate', false);
                return;
            }
            $selectAll.prop('checked', checked === total);
            $selectAll.prop('indeterminate', checked > 0 && checked < total);
        }

        $log.on('change', '[data-sto-advance-import-select-all]', function() {
            var on = $(this).prop('checked');
            $log.find('[data-sto-advance-import-cb]').prop('checked', !!on);
            syncBulkUi();
            syncSelectAll();
        });

        $log.on('change', '[data-sto-advance-import-cb]', function() {
            syncBulkUi();
            syncSelectAll();
        });

        function reloadAfterMutation() {
            window.location.reload();
        }

        $log.on('click', '[data-sto-advance-import-bulk-remove]', function(ev) {
            ev.preventDefault();
            setStatus($log, $logStatus, '', false);
            var ids = selectedIds();
            if (!ids.length || !window.confirm(i18n('confirmBulkDeleteImport'))) {
                return;
            }
            $.post(
                c.ajaxUrl,
                {
                    action: c.actionImportEntriesRemove,
                    nonce: c.nonce,
                    import_ids: ids
                }
            ).done(function(res) {
                if (res && res.success) {
                    reloadAfterMutation();
                } else {
                    var msg = (res && res.data && res.data.message) ? res.data.message : i18n('importFailed');
                    setStatus($log, $logStatus, msg, true);
                }
            }).fail(function() {
                setStatus($log, $logStatus, i18n('importFailed'), true);
            });
        });

        $root.on('click', '[data-sto-advance-import-download-entry]', function(ev) {
            ev.preventDefault();
            setStatus($log, $logStatus, '', false);
            var id = $(this).attr('data-sto-advance-import-download-entry') || '';
            if (!id) {
                return;
            }
            triggerImportEntryExport(id, function(err, jsonString, filename) {
                if (err) {
                    setStatus($log, $logStatus, err, true);
                    return;
                }
                downloadJson(filename, jsonString);
                setStatus($log, $logStatus, i18n('fileDownloaded'), false);
            });
        });

        $root.on('click', '[data-sto-advance-import-remove-entry]', function(ev) {
            ev.preventDefault();
            setStatus($log, $logStatus, '', false);
            var id = $(this).attr('data-sto-advance-import-remove-entry') || '';
            if (!id || !window.confirm(i18n('confirmDeleteImport'))) {
                return;
            }
            $.post(
                c.ajaxUrl,
                {
                    action: c.actionImportEntryRemove,
                    nonce: c.nonce,
                    import_id: id
                }
            ).done(function(res) {
                if (res && res.success) {
                    reloadAfterMutation();
                } else {
                    var msg = (res && res.data && res.data.message) ? res.data.message : i18n('importFailed');
                    setStatus($log, $logStatus, msg, true);
                }
            }).fail(function() {
                setStatus($log, $logStatus, i18n('importFailed'), true);
            });
        });

        bindImportRowToggle($log);

        syncBulkUi();
        syncSelectAll();
    }

    function bindOne($root) {
        if ($root.data('stoAdvanceImportExportBound')) {
            return;
        }
        $root.data('stoAdvanceImportExportBound', 1);

        var $exportStatus = $root.find('[data-sto-advance-export-status]');
        var $importStatus = $root.find('[data-sto-advance-import-status]');
        var $textarea = $root.find('[data-sto-advance-textarea]');
        var $file = $root.find('[data-sto-advance-file]');
        var $drop = $root.find('[data-sto-advance-dropzone]');

        bindImportDisplayLocations($root);
        bindDemoSwitch($root);
        bindImportHistory($root);
        setSourceName($root, defaultImportLabel());

        $root.on('click', '[data-sto-advance-export-copy]', function(ev) {
            ev.preventDefault();
            setStatus($root, $exportStatus, '', false);
            triggerExport($root, function(err, jsonString) {
                if (err) {
                    setStatus($root, $exportStatus, err, true);
                    return;
                }
                copyTextToClipboard(jsonString, $root, $exportStatus);
            });
        });

        $root.on('click', '[data-sto-advance-export-file]', function(ev) {
            ev.preventDefault();
            setStatus($root, $exportStatus, '', false);
            triggerExport($root, function(err, jsonString, filename) {
                if (err) {
                    setStatus($root, $exportStatus, err, true);
                    return;
                }
                downloadJson(filename, jsonString);
                setStatus($root, $exportStatus, i18n('fileDownloaded'), false);
            });
        });

        $root.on('click', '[data-sto-advance-paste-clipboard]', function(ev) {
            ev.preventDefault();
            setStatus($root, $importStatus, '', false);
            setSourceName($root, defaultImportLabel());
            if (!navigator.clipboard || !navigator.clipboard.readText) {
                setStatus($root, $importStatus, i18n('readClipboardFailed'), true);
                return;
            }
            navigator.clipboard.readText().then(function(text) {
                $textarea.val(text);
            }).catch(function() {
                setStatus($root, $importStatus, i18n('readClipboardFailed'), true);
            });
        });

        $root.on('input', '[data-sto-advance-textarea]', function() {
            setSourceName($root, defaultImportLabel());
        });

        $root.on('change', '[data-sto-advance-file]', function() {
            setStatus($root, $importStatus, '', false);
            var input = this;
            var file = input.files && input.files[0];
            if (!file) {
                return;
            }
            setSourceName($root, file.name || 'import.json');
            var reader = new FileReader();
            reader.onload = function() {
                var text = typeof reader.result === 'string' ? reader.result : '';
                $textarea.val(text);
            };
            reader.onerror = function() {
                setStatus($root, $importStatus, i18n('invalidFile'), true);
            };
            reader.readAsText(file, 'UTF-8');
            input.value = '';
        });

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
            setStatus($root, $importStatus, '', false);
            setSourceName($root, file.name || 'import.json');
            var reader = new FileReader();
            reader.onload = function() {
                var text = typeof reader.result === 'string' ? reader.result : '';
                $textarea.val(text);
            };
            reader.onerror = function() {
                setStatus($root, $importStatus, i18n('invalidFile'), true);
            };
            reader.readAsText(file, 'UTF-8');
        });

        $root.on('click', '[data-sto-advance-import-clear]', function(ev) {
            ev.preventDefault();
            $textarea.val('');
            setSourceName($root, defaultImportLabel());
            setStatus($root, $importStatus, '', false);
        });

        $root.on('click', '[data-sto-advance-import-apply]', function(ev) {
            ev.preventDefault();
            setStatus($root, $importStatus, '', false);
            var payload = ($textarea.val() || '').trim();
            if (!payload) {
                setStatus($root, $importStatus, i18n('emptyPayload'), true);
                return;
            }
            var replaceAll = $root.find('[data-sto-advance-import-replace-all]').is(':checked');
            var confirmMsg = replaceAll ? i18n('confirmImportReplace') : i18n('confirmImport');
            if (!window.confirm(confirmMsg)) {
                return;
            }
            var c = cfg();
            $.post(
                c.ajaxUrl,
                {
                    action: c.actionImport,
                    nonce: c.nonce,
                    import_payload: payload,
                    import_source_name: getSourceName($root),
                    import_replace_all: replaceAll ? '1' : '0'
                }
            ).done(function(res) {
                if (res && res.success) {
                    window.location.href = buildReloadUrl();
                } else {
                    var msg = (res && res.data && res.data.message) ? res.data.message : i18n('importFailed');
                    setStatus($root, $importStatus, msg, true);
                }
            }).fail(function(xhr) {
                var msg = i18n('importFailed');
                try {
                    var parsed = xhr.responseJSON;
                    if (parsed && parsed.data && parsed.data.message) {
                        msg = parsed.data.message;
                    }
                } catch (e) {
                    /* ignore */
                }
                setStatus($root, $importStatus, msg, true);
            });
        });
    }

    window.stoInitImportExport = function($scope) {
        var $root = $scope && $scope.length ? $scope : $(document);
        $root.find('[data-sto-advance-import-export]').each(function() {
            bindOne($(this));
        });
    };

    $(function() {
        if (typeof window.stoInitImportExport === 'function') {
            window.stoInitImportExport($(document.body));
        }
    });
})(jQuery);
