/**
 * Theme Settings Advance: full `sto_options` export / import (AJAX, file, clipboard),
 * demo mode toggle, last-import log actions.
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

    function setStatus($zone, $el, text, isError) {
        if (!$el || !$el.length) {
            return;
        }
        $el.text(text || '');
        $el.prop('hidden', !text);
        $el.toggleClass('sto-advance-status--error', !!isError);
        if ($zone && $zone.length) {
            $zone.toggleClass('sto-has-status', !!text);
        }
    }

    function triggerExport(done) {
        var c = cfg();
        $.post(
            c.ajaxUrl,
            {
                action: c.actionExport,
                nonce: c.nonce
            }
        ).done(function(res) {
            if (res && res.success && res.data && res.data.json) {
                done(null, res.data.json, res.data.filename || 'theme-settings-export.json');
            } else {
                var msg = (res && res.data && res.data.message) ? res.data.message : i18n('exportFailed');
                done(msg);
            }
        }).fail(function() {
            done(i18n('exportFailed'));
        });
    }

    function triggerSubsetExport(scope, optionKey, done) {
        var c = cfg();
        var data = {
            action: c.actionImportSubsetExport,
            nonce: c.nonce,
            export_scope: scope
        };
        if (scope === 'single' && optionKey) {
            data.option_key = optionKey;
        }
        $.post(c.ajaxUrl, data).done(function(res) {
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
        var scfg = window.simple_theme_options && window.simple_theme_options.sto_search;
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
            $.post(
                c.ajaxUrl,
                {
                    action: c.actionSetUiDemo,
                    nonce: c.nonce,
                    ui_demo: next ? '1' : '0'
                }
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

    function bindImportLog($root) {
        var c = cfg();
        var $logStatus = $root.find('[data-sto-advance-import-log-status]');
        var $log = $root.find('[data-sto-advance-import-log]');
        if (!$log.length) {
            return;
        }

        function reloadAfterMutation() {
            window.location.reload();
        }

        $root.on('click', '[data-sto-advance-import-download-key]', function(ev) {
            ev.preventDefault();
            setStatus($log, $logStatus, '', false);
            var key = $(this).attr('data-sto-advance-import-download-key') || '';
            triggerSubsetExport('single', key, function(err, jsonString, filename) {
                if (err) {
                    setStatus($log, $logStatus, err, true);
                    return;
                }
                downloadJson(filename, jsonString);
                setStatus($log, $logStatus, i18n('fileDownloaded'), false);
            });
        });

        $root.on('click', '[data-sto-advance-import-download-all]', function(ev) {
            ev.preventDefault();
            setStatus($log, $logStatus, '', false);
            triggerSubsetExport('all', '', function(err, jsonString, filename) {
                if (err) {
                    setStatus($log, $logStatus, err, true);
                    return;
                }
                downloadJson(filename, jsonString);
                setStatus($log, $logStatus, i18n('fileDownloaded'), false);
            });
        });

        $root.on('click', '[data-sto-advance-import-remove-key]', function(ev) {
            ev.preventDefault();
            setStatus($log, $logStatus, '', false);
            var key = $(this).attr('data-sto-advance-import-remove-key') || '';
            if (!key || !window.confirm(i18n('confirmRemoveKey'))) {
                return;
            }
            $.post(
                c.ajaxUrl,
                {
                    action: c.actionImportKeyRemove,
                    nonce: c.nonce,
                    option_key: key
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

        $root.on('click', '[data-sto-advance-import-remove-all]', function(ev) {
            ev.preventDefault();
            setStatus($log, $logStatus, '', false);
            if (!window.confirm(i18n('confirmRemoveAllKeys'))) {
                return;
            }
            $.post(
                c.ajaxUrl,
                {
                    action: c.actionImportKeysRemoveAll,
                    nonce: c.nonce
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

        $root.on('click', '[data-sto-advance-import-dismiss-log]', function(ev) {
            ev.preventDefault();
            setStatus($log, $logStatus, '', false);
            if (!window.confirm(i18n('confirmDismissLog'))) {
                return;
            }
            $.post(
                c.ajaxUrl,
                {
                    action: c.actionImportLogDismiss,
                    nonce: c.nonce
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

        bindDemoSwitch($root);
        bindImportLog($root);

        $root.on('click', '[data-sto-advance-export-copy]', function(ev) {
            ev.preventDefault();
            setStatus($root, $exportStatus, '', false);
            triggerExport(function(err, jsonString) {
                if (err) {
                    setStatus($root, $exportStatus, err, true);
                    return;
                }
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(jsonString).then(function() {
                        setStatus($root, $exportStatus, i18n('clipboardCopied'), false);
                    }).catch(function() {
                        setStatus($root, $exportStatus, i18n('clipboardDenied'), true);
                    });
                } else {
                    setStatus($root, $exportStatus, i18n('clipboardDenied'), true);
                }
            });
        });

        $root.on('click', '[data-sto-advance-export-file]', function(ev) {
            ev.preventDefault();
            setStatus($root, $exportStatus, '', false);
            triggerExport(function(err, jsonString, filename) {
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

        $root.on('change', '[data-sto-advance-file]', function() {
            setStatus($root, $importStatus, '', false);
            var input = this;
            var file = input.files && input.files[0];
            if (!file) {
                return;
            }
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
            if (!window.confirm(i18n('confirmImport'))) {
                return;
            }
            var c = cfg();
            $.post(
                c.ajaxUrl,
                {
                    action: c.actionImport,
                    nonce: c.nonce,
                    import_payload: payload
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
            window.stoInitImportExport($('.sto-options-form'));
        }
    });
})(jQuery);
