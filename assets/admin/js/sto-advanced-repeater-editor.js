/**
 * Advanced repeater classic wp_editor — preserve Code-tab HTML when switching to Visual.
 */
(function ($) {
    'use strict';

    var bound = false;

    /**
     * Fix literal "rn" / "rnrn" from corrupted \\r\\n (must match PHP repair_editor_rn_corruption).
     *
     * @param {string} html
     * @return {string}
     */
    function repairStoRepeaterEditorRn(html) {
        html = String(html || '');
        if (!html || html.indexOf('<!-- wp:') !== -1 || !/rn/i.test(html)) {
            return html;
        }

        html = html.replace(/<p>\s*(?:rn\s*)+<\/p>/gi, '');
        html = html.replace(/<div>\s*(?:rn\s*)+<\/div>/gi, '');

        while (html.indexOf('rnrn') !== -1) {
            html = html.split('rnrn').join('\n\n');
        }

        html = html.replace(/,rn(?=[a-zA-Z<])/g, ',\n');
        html = html.replace(/rn(?=[a-zA-Z<])/g, '\n');
        html = html.replace(/rn(?=\s*<\/?)/g, '\n');
        html = html.replace(/rn(?=\s*$)/g, '\n');
        html = html.replace(/(?:^|>|\s)(?:rn\s*){2,}(?=<|\s|$)/gi, '\n\n');

        return html;
    }

    /**
     * Wrap bare `<tr>text</tr>` cells in `<td>` so TinyMCE does not flatten tables to plain text.
     *
     * @param {string} html
     * @return {string}
     */
    function normalizeStoRepeaterEditorHtml(html) {
        html = String(html || '');
        if (!html || html.toLowerCase().indexOf('<table') === -1) {
            return html;
        }

        return html.replace(/<tr\b([^>]*)>([\s\S]*?)<\/tr>/gi, function (match, attrs, inner) {
            if (/<t[dh]\b/i.test(inner)) {
                return match;
            }
            inner = String(inner || '').trim();
            if (!inner) {
                return '<tr' + attrs + '></tr>';
            }
            return '<tr' + attrs + '><td>' + inner + '</td></tr>';
        });
    }

    function finalizeEditorHtml(html) {
        html = repairStoRepeaterEditorRn(html);
        return normalizeStoRepeaterEditorHtml(html);
    }

    /**
     * Match PHP {@see Input::classic_editor_html_has_meaningful_content} — img/media-only HTML counts.
     *
     * @param {string} html
     * @return {boolean}
     */
    function editorHtmlHasMeaningfulContent(html) {
        html = String(html || '').trim();
        if (!html) {
            return false;
        }
        if (/<(img|picture|video|audio|iframe|embed|object|figure|svg)\b/i.test(html)) {
            return true;
        }
        return html.replace(/<[^>]+>/g, '').replace(/\s+/g, ' ').trim() !== '';
    }

    /**
     * Read HTML from a TinyMCE instance (getContent, then body innerHTML for Add Media edge cases).
     *
     * @param {object|null} tinyEditor
     * @return {string}
     */
    function readTinyMceEditorHtml(tinyEditor) {
        if (!tinyEditor || tinyEditor.isHidden()) {
            return '';
        }
        var html = '';
        if (typeof tinyEditor.getContent === 'function') {
            html = String(tinyEditor.getContent() || '');
        }
        if (editorHtmlHasMeaningfulContent(html)) {
            return html;
        }
        if (typeof tinyEditor.getBody === 'function') {
            var body = tinyEditor.getBody();
            if (body && body.innerHTML) {
                var bodyHtml = String(body.innerHTML || '');
                if (editorHtmlHasMeaningfulContent(bodyHtml)) {
                    return bodyHtml;
                }
            }
        }
        return html;
    }

    /**
     * Set repeater editor textarea without firing the Visual mirror handler (TinyMCE save/input).
     *
     * @param {JQuery} $textarea
     * @param {string} html
     */
    function setRepeaterEditorTextareaHtml($textarea, html) {
        if (!$textarea || !$textarea.length) {
            return;
        }
        html = finalizeEditorHtml(html);
        $textarea.data('stoAdvRepSilentInput', 1);
        $textarea.val(html);
        $textarea.removeData('stoAdvRepSilentInput');
        var $leaf = $textarea.closest('.sto-adv-rep__field--editor');
        if ($leaf.length && editorHtmlHasMeaningfulContent(html)) {
            $leaf.attr('data-sto-adv-rep-editor-html', html);
        }
    }

    /**
     * Last known good HTML for a repeater editor leaf (survives TinyMCE save/input races).
     *
     * @param {JQuery} $leaf
     * @return {string}
     */
    function readRepeaterEditorHtmlBackup($leaf) {
        if (!$leaf || !$leaf.length) {
            return '';
        }
        return String($leaf.attr('data-sto-adv-rep-editor-html') || '');
    }

    /**
     * Prefer the longest meaningful HTML among textarea, TinyMCE, and backup mirror.
     *
     * @param {string} areaHtml
     * @param {string} tinyHtml
     * @param {string} [backupHtml]
     * @return {string}
     */
    function pickRichestRepeaterEditorHtml(areaHtml, tinyHtml, backupHtml) {
        var bestHtml = '';
        var candidateIndex;
        var candidates = [areaHtml, tinyHtml, backupHtml];
        for (candidateIndex = 0; candidateIndex < candidates.length; candidateIndex++) {
            var candidateHtml = String(candidates[candidateIndex] || '');
            if (!editorHtmlHasMeaningfulContent(candidateHtml)) {
                continue;
            }
            if (!bestHtml || candidateHtml.trim().length > bestHtml.trim().length) {
                bestHtml = candidateHtml;
            }
        }
        return bestHtml;
    }

    /**
     * True when Visual TinyMCE must not overwrite the textarea (tables, missing plugins, stripped markup).
     *
     * @param {string} textareaHtml
     * @param {string} tinyHtml
     * @param {object|null} [tinyEditor] TinyMCE instance when available.
     * @return {boolean}
     */
    function shouldKeepTextareaOverTinyMce(textareaHtml, tinyHtml, tinyEditor) {
        textareaHtml = String(textareaHtml || '');
        tinyHtml = String(tinyHtml || '');
        if (textareaHtml.trim() === '') {
            return false;
        }
        if (
            tinyEditor &&
            typeof tinyEditor.hasFocus === 'function' &&
            tinyEditor.hasFocus()
        ) {
            return false;
        }
        var areaHasTable = leafHasTableMarkup(textareaHtml);
        var tinyHasTable = leafHasTableMarkup(tinyHtml);
        if (areaHasTable && !tinyHasTable) {
            return true;
        }
        if (areaHasTable && tinyHtml.trim().length < Math.max(48, textareaHtml.trim().length * 0.35)) {
            return true;
        }
        if (textareaHtml.trim().length > 80 && tinyHtml.trim() === '') {
            return true;
        }
        return false;
    }

    /**
     * Read classic editor markup from a repeater leaf (Code tab / textarea wins for tables).
     *
     * @param {JQuery} $leaf `.sto-adv-rep__field--editor`
     * @return {string}
     */
    function readClassicEditorHtmlFromLeaf($leaf) {
        var $editorWrap = $leaf.find('.wp-editor-wrap').first();
        var $editorArea = $leaf.find('textarea.wp-editor-area').first();
        var html = String($editorArea.val() || '');

        if ($editorWrap.hasClass('html-active') || !window.tinymce) {
            if ($editorWrap.hasClass('html-active') && window.tinymce) {
                var editorId = String($editorArea.attr('id') || '');
                var tinyEditor = editorId ? window.tinymce.get(editorId) : null;
                if (tinyEditor && !tinyEditor.isHidden()) {
                    var liveTinyHtml = readTinyMceEditorHtml(tinyEditor);
                    if (html.trim() === '' && liveTinyHtml.trim() !== '') {
                        html = liveTinyHtml;
                    } else if (shouldKeepTextareaOverTinyMce(html, liveTinyHtml, tinyEditor)) {
                        /* keep textarea (tables) */
                    } else if (
                        liveTinyHtml.trim().length > html.trim().length &&
                        !leafHasTableMarkup(html)
                    ) {
                        html = liveTinyHtml;
                    }
                }
            }
            return finalizeEditorHtml(html);
        }

        if ($editorWrap.hasClass('tmce-active')) {
            var editorIdVisual = String($editorArea.attr('id') || '');
            var tinyEditorVisual = editorIdVisual ? window.tinymce.get(editorIdVisual) : null;
            var backupHtmlVisual = readRepeaterEditorHtmlBackup($leaf);
            if (tinyEditorVisual && !tinyEditorVisual.isHidden()) {
                var tinyHtmlVisual = readTinyMceEditorHtml(tinyEditorVisual);
                if (shouldKeepTextareaOverTinyMce(html, tinyHtmlVisual, tinyEditorVisual)) {
                    html = pickRichestRepeaterEditorHtml(html, tinyHtmlVisual, backupHtmlVisual) || html;
                } else {
                    html = pickRichestRepeaterEditorHtml(html, tinyHtmlVisual, backupHtmlVisual);
                }
                if (
                    !editorHtmlHasMeaningfulContent(html) &&
                    typeof tinyEditorVisual.save === 'function'
                ) {
                    tinyEditorVisual.save();
                    html = pickRichestRepeaterEditorHtml(
                        String($editorArea.val() || ''),
                        tinyHtmlVisual,
                        backupHtmlVisual
                    );
                }
            } else if (editorHtmlHasMeaningfulContent(backupHtmlVisual)) {
                html = backupHtmlVisual;
            }
        }

        if (editorHtmlHasMeaningfulContent(html)) {
            $leaf.attr('data-sto-adv-rep-editor-html', finalizeEditorHtml(html));
        }

        return finalizeEditorHtml(html);
    }

    /**
     * Flush Visual editors before submit without wiping Code-tab / table HTML.
     *
     * @param {JQuery} [$scope]
     */
    /**
     * Flush every classic editor inside advanced repeater rows (including collapsed / Code tab).
     *
     * @param {JQuery} [$scope]
     */
    function stoSaveRepeaterTinyMceEditors($scope) {
        if (!window.tinymce) {
            return;
        }
        var $ctx = $scope && $scope.length ? $scope : $(document);
        $ctx.find('.sto-adv-rep__field--editor .wp-editor-wrap').each(function () {
            var $wrap = $(this);
            var $textarea = $wrap.find('textarea.wp-editor-area').first();
            var editorId = String($textarea.attr('id') || '');
            if (!editorId) {
                return;
            }
            if ($wrap.hasClass('tmce-active')) {
                stoFlushRepeaterVisualEditorToTextarea($wrap);
                return;
            }
            if (typeof window.stoReadClassicEditorHtmlFromLeaf === 'function') {
                var $leaf = $wrap.closest('.sto-adv-rep__field--editor');
                if ($leaf.length) {
                    $textarea.val(window.stoReadClassicEditorHtmlFromLeaf($leaf));
                }
            }
        });
    }

    function stoSaveVisibleTinyMceEditors($scope) {
        if (!window.tinymce) {
            return;
        }
        var $ctx = $scope && $scope.length ? $scope : $(document);
        stoSaveRepeaterTinyMceEditors($ctx);
        $ctx.find('.sto-classic-editor-field .wp-editor-wrap.tmce-active').each(function () {
            var $wrap = $(this);
            var $textarea = $wrap.find('textarea.wp-editor-area').first();
            var editorId = String($textarea.attr('id') || '');
            if (!editorId) {
                return;
            }
            var tinyEditor = window.tinymce.get(editorId);
            if (!tinyEditor || tinyEditor.isHidden() || typeof tinyEditor.save !== 'function') {
                return;
            }
            var areaHtml = String($textarea.val() || '');
            var tinyHtml = tinyEditor.getContent() || '';
            if (shouldKeepTextareaOverTinyMce(areaHtml, tinyHtml, tinyEditor)) {
                return;
            }
            tinyEditor.save();
        });
    }

    /**
     * Copy Visual TinyMCE HTML into the textarea without losing images (Add Media) or tables.
     *
     * @param {JQuery} $editorWrap `.wp-editor-wrap`
     */
    function stoFlushRepeaterVisualEditorToTextarea($editorWrap) {
        if (!window.tinymce || !$editorWrap || !$editorWrap.length) {
            return;
        }
        if (!$editorWrap.hasClass('tmce-active')) {
            return;
        }
        var $textarea = $editorWrap.find('textarea.wp-editor-area').first();
        var editorId = String($textarea.attr('id') || '');
        if (!editorId) {
            return;
        }
        var tinyEditor = window.tinymce.get(editorId);
        if (!tinyEditor || tinyEditor.isHidden()) {
            return;
        }
        var $leaf = $textarea.closest('.sto-adv-rep__field--editor');
        var areaHtml = String($textarea.val() || '');
        var tinyHtml = readTinyMceEditorHtml(tinyEditor);
        var backupHtml = $leaf.length ? readRepeaterEditorHtmlBackup($leaf) : '';
        if (shouldKeepTextareaOverTinyMce(areaHtml, tinyHtml, tinyEditor)) {
            var keptHtml = pickRichestRepeaterEditorHtml(areaHtml, tinyHtml, backupHtml);
            if (editorHtmlHasMeaningfulContent(keptHtml)) {
                setRepeaterEditorTextareaHtml($textarea, keptHtml);
            }
            return;
        }
        var richestHtml = pickRichestRepeaterEditorHtml(areaHtml, tinyHtml, backupHtml);
        if (editorHtmlHasMeaningfulContent(richestHtml)) {
            setRepeaterEditorTextareaHtml($textarea, richestHtml);
            return;
        }
        if (typeof tinyEditor.save === 'function') {
            tinyEditor.save();
            richestHtml = pickRichestRepeaterEditorHtml(
                String($textarea.val() || ''),
                tinyHtml,
                backupHtml
            );
            if (editorHtmlHasMeaningfulContent(richestHtml)) {
                setRepeaterEditorTextareaHtml($textarea, richestHtml);
            }
        }
    }

    function stoSaveTinyMceEditorWrap($editorWrap) {
        stoFlushRepeaterVisualEditorToTextarea($editorWrap);
    }

    /**
     * True when HTML contains a complete <table …> opening tag (not a partial "<table" while typing).
     *
     * @param {string} html
     * @return {boolean}
     */
    function leafHasTableMarkup(html) {
        return /<table(?:\s[^>]*)?\s*>/i.test(String(html || ''));
    }

    /**
     * Force Code mode without triggering a second `.switch-html` click (avoids dual-toolbar races).
     *
     * @param {JQuery} $wrap `.wp-editor-wrap`
     */
    function ensureRepeaterEditorCodeMode($wrap) {
        if (!$wrap || !$wrap.length) {
            return;
        }

        var $textarea = $wrap.find('textarea.wp-editor-area').first();
        if (!$textarea.length) {
            return;
        }

        var editorId = String($textarea.attr('id') || '');
        var html = finalizeEditorHtml(
            String($wrap.data('stoEditorHtmlBackup') || $textarea.val() || '')
        );
        $textarea.val(html);

        if (window.switchEditors && editorId && $wrap.hasClass('tmce-active')) {
            if (window.tinymce) {
                stoSaveTinyMceEditorWrap($wrap);
            }
            $textarea.val(html);
            window.switchEditors.go(editorId, 'html');
        }

        repairRepeaterEditorWrapState($wrap);
        $textarea.val(html);
        toggleRepeaterTableCodeNotice($wrap, leafHasTableMarkup(html));
    }

    /**
     * Fix half-switched editors (both Quicktags + TinyMCE toolbars visible, empty textarea).
     *
     * @param {JQuery} $wrap `.wp-editor-wrap`
     */
    function isRepeaterCodeTextareaUsable($wrap) {
        if (!$wrap || !$wrap.length) {
            return false;
        }
        var $textarea = $wrap.find('textarea.wp-editor-area').first();
        if (!$textarea.length) {
            return false;
        }
        if ($textarea.attr('aria-hidden') === 'true' || $textarea.prop('disabled') || $textarea.prop('readonly')) {
            return false;
        }
        if ($wrap.find('.mce-tinymce:visible').length) {
            return false;
        }
        return $textarea.is(':visible');
    }

    function clearRepeaterEditorInlineChromeStyles($wrap) {
        if (!$wrap || !$wrap.length) {
            return;
        }
        $wrap.find('.mce-tinymce, .mce-toolbar-grp, .mce-statusbar, textarea.wp-editor-area').each(function () {
            this.removeAttribute('style');
        });
    }

    function syncRepeaterVisualHtmlToTextarea($wrap) {
        stoFlushRepeaterVisualEditorToTextarea($wrap);
    }

    /**
     * Mirror Visual canvas HTML into the live source textarea (repeater editors only).
     *
     * @param {object} editor TinyMCE editor instance.
     */
    function bindRepeaterTinyMceLiveSync(editor) {
        if (!editor || !editor.id) {
            return;
        }
        if (editor._stoAdvRepLiveSyncBound) {
            return;
        }

        var $textarea = $('#' + editor.id);
        if (!$textarea.length || !$textarea.closest('.sto-adv-rep__field--editor').length) {
            return;
        }

        editor._stoAdvRepLiveSyncBound = true;
        var $wrap = $textarea.closest('.wp-editor-wrap');

        editor.on('keyup change input SetContent NodeChange Undo Redo', function () {
            if (!$wrap.hasClass('tmce-active')) {
                return;
            }
            syncRepeaterVisualHtmlToTextarea($wrap);
        });
    }

    /**
     * Attach live-sync handlers to every repeater TinyMCE instance in scope.
     *
     * @param {JQuery} [$scope]
     */
    function seedRepeaterEditorHtmlBackupInScope($scope) {
        var $ctx = $scope && $scope.length ? $scope : $(document);
        $ctx.find('.sto-adv-rep__field--editor').each(function () {
            var $leaf = $(this);
            var html = String($leaf.find('textarea.wp-editor-area').first().val() || '');
            if (editorHtmlHasMeaningfulContent(html)) {
                $leaf.attr('data-sto-adv-rep-editor-html', finalizeEditorHtml(html));
            }
        });
    }

    function bindRepeaterTinyMceLiveSyncInScope($scope) {
        seedRepeaterEditorHtmlBackupInScope($scope);
        if (!window.tinymce) {
            return;
        }
        var $ctx = $scope && $scope.length ? $scope : $(document);
        $ctx.find('.sto-adv-rep__field--editor textarea.wp-editor-area').each(function () {
            var editorId = String(this.id || '');
            if (!editorId) {
                return;
            }
            var tinyEditor = window.tinymce.get(editorId);
            if (tinyEditor) {
                bindRepeaterTinyMceLiveSync(tinyEditor);
            }
        });
    }

    function repairRepeaterVisualWrapState($wrap) {
        if (!$wrap || !$wrap.length) {
            return;
        }

        var $textarea = $wrap.find('textarea.wp-editor-area').first();
        var editorId = String($textarea.attr('id') || '');
        var html = finalizeEditorHtml($textarea.val() || '');

        clearRepeaterEditorInlineChromeStyles($wrap);

        $wrap.addClass('tmce-active sto-adv-rep__editor--visual-ready').removeClass('html-active sto-adv-rep__editor--code-ready');
        $wrap.find('.switch-tmce').attr('aria-pressed', 'true');
        $wrap.find('.switch-html').attr('aria-pressed', 'false');

        $wrap.find('.quicktags-toolbar').hide();

        if (window.tinymce && editorId) {
            var tinyEditor = window.tinymce.get(editorId);
            if (tinyEditor) {
                if (tinyEditor.isHidden() && typeof tinyEditor.show === 'function') {
                    tinyEditor.show();
                }
                if (html !== tinyEditor.getContent()) {
                    tinyEditor.setContent(html);
                }
                try {
                    tinyEditor.execCommand('mceRepaint');
                } catch (ignore) {
                    /* ignore */
                }
                syncRepeaterVisualHtmlToTextarea($wrap);
            }
        }

        $textarea.attr('aria-hidden', 'false').prop('readonly', false).prop('disabled', false);
    }

    function repairRepeaterEditorWrapState($wrap, focusTextarea) {
        if (!$wrap || !$wrap.length) {
            return;
        }

        var $textarea = $wrap.find('textarea.wp-editor-area').first();
        var editorId = String($textarea.attr('id') || '');

        clearRepeaterEditorInlineChromeStyles($wrap);

        $wrap.addClass('html-active sto-adv-rep__editor--code-ready').removeClass('tmce-active sto-adv-rep__editor--visual-ready');
        $wrap.find('.switch-html').attr('aria-pressed', 'true');
        $wrap.find('.switch-tmce').attr('aria-pressed', 'false');

        if (window.tinymce && editorId) {
            var tinyEditor = window.tinymce.get(editorId);
            if (tinyEditor && !tinyEditor.isHidden() && typeof tinyEditor.hide === 'function') {
                tinyEditor.hide();
            }
        }

        $wrap.find('.quicktags-toolbar').show();

        $textarea.attr('aria-hidden', 'false').prop('readonly', false).prop('disabled', false);

        if (focusTextarea && $textarea.length) {
            window.setTimeout(function () {
                $textarea.trigger('focus');
            }, 0);
        }
    }

    function markRepeaterEditorTableCodeOnly($wrap, isTableMarkup) {
        if (!$wrap || !$wrap.length) {
            return;
        }
        $wrap.toggleClass('sto-adv-rep__editor--table-code-only', !!isTableMarkup);
    }

    function toggleRepeaterTableCodeNotice($wrap, showNotice) {
        if (!$wrap || !$wrap.length) {
            return;
        }
        markRepeaterEditorTableCodeOnly($wrap, showNotice);
        var $leaf = $wrap.closest('.sto-adv-rep__field--editor');
        var $notice = $leaf.find('.sto-adv-rep__editor-table-notice').first();
        if (!$notice.length) {
            $notice = $(
                '<p class="sto-field-description sto-adv-rep__hint sto-adv-rep__editor-table-notice" role="status" hidden></p>'
            );
            $notice.text(
                'Table markup stays in Code mode only — Visual cannot edit complex tables without losing structure.'
            );
            $wrap.before($notice);
        }
        if (showNotice) {
            $notice.removeAttr('hidden');
        } else {
            $notice.attr('hidden', 'hidden');
        }
    }

    function isBrokenRepeaterVisualWrap($wrap) {
        if (!$wrap || !$wrap.length || !$wrap.hasClass('tmce-active')) {
            return false;
        }
        return $wrap.find('.mce-tinymce:visible').length === 0 || $wrap.find('.mce-toolbar-grp:visible').length === 0;
    }

    function isBrokenRepeaterEditorWrap($wrap) {
        if (!$wrap || !$wrap.length) {
            return false;
        }
        if ($wrap.hasClass('tmce-active')) {
            return isBrokenRepeaterVisualWrap($wrap);
        }
        if (!$wrap.hasClass('html-active')) {
            return true;
        }
        return !isRepeaterCodeTextareaUsable($wrap);
    }

    /**
     * Switch repeater classic editor tabs without leaving TinyMCE chrome over the textarea.
     *
     * @param {JQuery} $wrap
     * @param {'html'|'tmce'} mode
     * @param {boolean} [focusTextarea]
     */
    function switchRepeaterEditorMode($wrap, mode, focusTextarea) {
        if (!$wrap || !$wrap.length) {
            return;
        }

        var $textarea = $wrap.find('textarea.wp-editor-area').first();
        var editorId = String($textarea.attr('id') || '');
        var $leaf = $textarea.closest('.sto-adv-rep__field--editor');
        var html = readClassicEditorHtmlFromLeaf($leaf);

        $textarea.val(html);

        if (mode === 'html') {
            if (window.switchEditors && editorId && $wrap.hasClass('tmce-active')) {
                if (window.tinymce) {
                    stoSaveTinyMceEditorWrap($wrap);
                }
                $textarea.val(html);
                window.switchEditors.go(editorId, 'html');
            }
            repairRepeaterEditorWrapState($wrap, !!focusTextarea);
            $textarea.val(html);
            toggleRepeaterTableCodeNotice($wrap, leafHasTableMarkup(html));
            return;
        }

        if (leafHasTableMarkup(html)) {
            ensureRepeaterEditorCodeMode($wrap);
            return;
        }

        clearRepeaterEditorInlineChromeStyles($wrap);
        html = finalizeEditorHtml(html);
        $textarea.val(html);
        toggleRepeaterTableCodeNotice($wrap, false);

        if (window.switchEditors && editorId) {
            window.switchEditors.go(editorId, 'tmce');
        }

        window.setTimeout(function () {
            repairRepeaterVisualWrapState($wrap);
            if (isBrokenRepeaterVisualWrap($wrap)) {
                switchRepeaterEditorMode($wrap, 'html', true);
            }
        }, 80);
    }

    function preferCodeModeForTableMarkup($scope) {
        var $ctx = $scope && $scope.length ? $scope : $(document);
        $ctx.find('.sto-adv-rep__field--editor .wp-editor-wrap').each(function () {
            var $wrap = $(this);
            var html = String($wrap.find('textarea.wp-editor-area').first().val() || '');
            if (leafHasTableMarkup(html)) {
                ensureRepeaterEditorCodeMode($wrap);
            } else if ($wrap.hasClass('tmce-active')) {
                if (isBrokenRepeaterVisualWrap($wrap)) {
                    repairRepeaterVisualWrapState($wrap);
                }
                toggleRepeaterTableCodeNotice($wrap, false);
            } else if (isBrokenRepeaterEditorWrap($wrap)) {
                repairRepeaterEditorWrapState($wrap);
                toggleRepeaterTableCodeNotice($wrap, false);
            } else {
                toggleRepeaterTableCodeNotice($wrap, false);
            }
        });
    }

    /**
     * True when a TinyMCE instance for `editorId` belongs to a node inside `$scope`.
     *
     * @param {string} editorId
     * @param {JQuery} $scope
     * @return {boolean}
     */
    function tinymceInstanceLivesInScope(editorId, $scope) {
        if (!editorId || !$scope || !$scope.length || !window.tinymce) {
            return false;
        }
        var tinyEditor = window.tinymce.get(editorId);
        if (!tinyEditor || typeof tinyEditor.getContainer !== 'function') {
            return false;
        }
        var container = tinyEditor.getContainer();
        return !!(container && $scope[0].contains(container));
    }

    /**
     * Strip cloned wp_editor chrome to a bare textarea so `wp.editor.initialize` can build a fresh shell.
     *
     * @param {JQuery} $root
     */
    function stripRepeaterClassicEditorLeaves($root) {
        if (!$root || !$root.length) {
            return;
        }

        $root.find('.sto-adv-rep__field--editor').each(function () {
            var $leaf = $(this);
            var $textarea = $leaf.find('textarea.wp-editor-area').first();
            if (!$textarea.length) {
                return;
            }

            var editorId = String($textarea.attr('id') || '');
            if (!editorId) {
                return;
            }

            var storedHtml = String($textarea.val() || '');
            if (window.tinymce) {
                var scopedEditorBeforeStrip = window.tinymce.get(editorId);
                if (scopedEditorBeforeStrip && !scopedEditorBeforeStrip.isHidden()) {
                    var tinyHtmlBeforeStrip = readTinyMceEditorHtml(scopedEditorBeforeStrip);
                    if (
                        editorHtmlHasMeaningfulContent(tinyHtmlBeforeStrip) &&
                        !editorHtmlHasMeaningfulContent(storedHtml)
                    ) {
                        storedHtml = tinyHtmlBeforeStrip;
                    }
                }
            }
            var fieldName = $textarea.attr('name') || '';

            if (tinymceInstanceLivesInScope(editorId, $root)) {
                var scopedEditor = window.tinymce.get(editorId);
                if (scopedEditor && typeof scopedEditor.remove === 'function') {
                    scopedEditor.remove();
                }
            }

            if (window.wp && window.wp.editor && typeof window.wp.editor.remove === 'function') {
                try {
                    window.wp.editor.remove(editorId);
                } catch (ignore) {
                    /* ignore */
                }
            }

            $leaf.find('.wp-editor-wrap, .mce-tinymce, .mce-toolbar-grp, .mce-statusbar, .quicktags-toolbar').remove();

            var $mount = $leaf.find('.sto-adv-rep__editor-wrap').first();
            if (!$mount.length) {
                $mount = $leaf.find('.sto-input-wrap').first();
            }
            if (!$mount.length) {
                return;
            }

            var $fresh = $('<textarea>', {
                id: editorId,
                class: 'wp-editor-area',
                'data-sto-repeater-editor-pending': '1'
            });
            if (fieldName) {
                $fresh.attr('name', fieldName);
            }
            $fresh.val(storedHtml);
            $mount.empty().append($fresh);
        });
    }

    /**
     * Register TinyMCE / quicktags settings for a new repeater editor id (clone of row 0).
     *
     * @param {string} protoId
     * @param {string} editorId
     * @return {boolean}
     */
    function copyEditorPreinit(protoId, editorId) {
        if (!protoId || !editorId || protoId === editorId || !window.tinyMCEPreInit || !window.tinyMCEPreInit.mceInit) {
            return false;
        }
        if (!window.tinyMCEPreInit.mceInit[protoId]) {
            return false;
        }

        var mceInit = $.extend(true, {}, window.tinyMCEPreInit.mceInit[protoId]);
        mceInit.selector = '#' + editorId;
        if (mceInit.body_class) {
            mceInit.body_class = String(mceInit.body_class).replace(
                new RegExp('\\b' + protoId.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\b', 'g'),
                editorId
            );
        }
        window.tinyMCEPreInit.mceInit[editorId] = mceInit;

        if (window.tinyMCEPreInit.qtInit && window.tinyMCEPreInit.qtInit[protoId]) {
            var qtInit = $.extend(true, {}, window.tinyMCEPreInit.qtInit[protoId]);
            qtInit.id = editorId;
            window.tinyMCEPreInit.qtInit[editorId] = qtInit;
        }

        return true;
    }

    /**
     * Drop duplicate TinyMCE chrome left in a leaf after a bad re-init.
     *
     * @param {JQuery} $leaf
     * @param {string} editorId
     */
    function cleanupOrphanEditorChromeInLeaf($leaf, editorId) {
        if (!$leaf || !$leaf.length || !editorId) {
            return;
        }

        $leaf.find('.wp-editor-wrap').each(function () {
            var $wrap = $(this);
            if (!$wrap.find('textarea.wp-editor-area[id="' + editorId.replace(/"/g, '') + '"]').length) {
                $wrap.remove();
            }
        });

        $leaf.find('.mce-tinymce, .mce-toolbar-grp, .mce-statusbar').each(function () {
            var $node = $(this);
            if ($node.closest('.wp-editor-wrap').find('textarea#' + editorId).length) {
                return;
            }
            $node.remove();
        });
    }

    /**
     * Boot `wp.editor` on a cloned repeater leaf (bare textarea only).
     *
     * @param {JQuery} $leaf `.sto-adv-rep__field--editor`
     * @param {number} [retryCount]
     */
    function initRepeaterClassicEditorOnLeaf($leaf, retryCount) {
        if (!$leaf || !$leaf.length) {
            return;
        }

        var attempts = typeof retryCount === 'number' ? retryCount : 0;

        var $textarea = $leaf.find('textarea.wp-editor-area[data-sto-repeater-editor-pending="1"]').first();
        if (!$textarea.length) {
            $textarea = $leaf.find('textarea.wp-editor-area').first();
            if (!$textarea.length || $textarea.attr('data-sto-repeater-editor-pending') !== '1') {
                return;
            }
        }

        var editorId = String($textarea.attr('id') || '');
        if (!editorId) {
            return;
        }

        if (window.tinymce && window.tinymce.get(editorId)) {
            $textarea.removeAttr('data-sto-repeater-editor-pending');
            $leaf.find('.wp-editor-wrap').first().removeClass('sto-adv-rep-editor-pending');
            var existingEditor = window.tinymce.get(editorId);
            bindRepeaterTinyMceLiveSync(existingEditor);
            var $existingWrap = $leaf.find('.wp-editor-wrap').first();
            if ($existingWrap.hasClass('tmce-active')) {
                repairRepeaterVisualWrapState($existingWrap);
            }
            return;
        }

        if (!$textarea.is(':visible') && attempts < 12) {
            window.setTimeout(function () {
                initRepeaterClassicEditorOnLeaf($leaf, attempts + 1);
            }, 100);
            return;
        }

        cleanupOrphanEditorChromeInLeaf($leaf, editorId);

        var leafKey = String($leaf.attr('data-sto-adv-rep-key') || '');
        var $fieldRow = $leaf.closest('.sto-field-row-advanced-repeater');
        var fieldId = String($fieldRow.attr('data-sto-field-id') || '');
        var protoId = fieldId && leafKey ? fieldId + '_0_' + leafKey : '';

        if (protoId && editorId !== protoId) {
            copyEditorPreinit(protoId, editorId);
        }

        if (!window.tinyMCEPreInit || !window.tinyMCEPreInit.mceInit || !window.tinyMCEPreInit.mceInit[editorId]) {
            return;
        }

        if (!window.wp || !window.wp.editor || typeof window.wp.editor.initialize !== 'function') {
            return;
        }

        var initSettings = {
            tinymce: window.tinyMCEPreInit.mceInit[editorId],
            quicktags:
                window.tinyMCEPreInit.qtInit && window.tinyMCEPreInit.qtInit[editorId]
                    ? window.tinyMCEPreInit.qtInit[editorId]
                    : true,
            mediaButtons: true
        };

        window.wp.editor.initialize(editorId, initSettings);
        $textarea.removeAttr('data-sto-repeater-editor-pending');

        window.setTimeout(function () {
            if (!window.tinymce) {
                return;
            }
            var tinyEditor = window.tinymce.get(editorId);
            if (tinyEditor && typeof tinyEditor.execCommand === 'function') {
                bindRepeaterTinyMceLiveSync(tinyEditor);
                try {
                    tinyEditor.execCommand('mceRepaint');
                } catch (ignore) {
                    /* ignore */
                }
                var $wrap = $leaf.find('.wp-editor-wrap').first();
                var initialHtml = String($textarea.val() || '');
                if (leafHasTableMarkup(initialHtml)) {
                    ensureRepeaterEditorCodeMode($wrap);
                } else if (isBrokenRepeaterEditorWrap($wrap)) {
                    repairRepeaterEditorWrapState($wrap, false);
                } else if ($wrap.hasClass('tmce-active')) {
                    repairRepeaterVisualWrapState($wrap);
                }
            } else if (attempts < 12) {
                $textarea.attr('data-sto-repeater-editor-pending', '1');
                initRepeaterClassicEditorOnLeaf($leaf, attempts + 1);
            }
        }, 120);
    }

    /**
     * Initialize classic editors inside repeater rows (after Add item / expand).
     *
     * @param {JQuery} [$scope]
     */
    function refreshClassicEditorsForScope($scope) {
        if (!$scope || !$scope.length) {
            return;
        }

        $scope.find('.sto-adv-rep__field--editor textarea.wp-editor-area[data-sto-repeater-editor-pending="1"]').each(
            function () {
                initRepeaterClassicEditorOnLeaf($(this).closest('.sto-adv-rep__field--editor'));
            }
        );
    }

    function bindRepeaterEditorTabCapture() {
        if (window.stoAdvRepEditorCaptureBound) {
            return;
        }
        window.stoAdvRepEditorCaptureBound = true;

        document.addEventListener(
            'mousedown',
            function (event) {
                var target = event.target;
                if (!target || !target.classList || !target.classList.contains('switch-tmce')) {
                    return;
                }
                if (!target.closest('.sto-adv-rep__field--editor')) {
                    return;
                }
                var wrap = target.closest('.wp-editor-wrap');
                if (!wrap) {
                    return;
                }
                var textarea = wrap.querySelector('textarea.wp-editor-area');
                if (textarea) {
                    $(wrap).data('stoEditorHtmlBackup', textarea.value);
                }
            },
            true
        );

        document.addEventListener(
            'click',
            function (event) {
                var target = event.target;
                if (!target || !target.classList || !target.classList.contains('wp-switch-editor')) {
                    return;
                }
                if (!target.closest('.sto-adv-rep__field--editor')) {
                    return;
                }

                var wrap = target.closest('.wp-editor-wrap');
                if (!wrap) {
                    return;
                }

                var $wrap = $(wrap);
                var $textarea = $wrap.find('textarea.wp-editor-area').first();
                var editorId = String($textarea.attr('id') || '');
                var $leaf = $textarea.closest('.sto-adv-rep__field--editor');

                event.preventDefault();
                event.stopImmediatePropagation();

                if (target.classList.contains('switch-tmce')) {
                    var visualHtml = finalizeEditorHtml(
                        String($wrap.data('stoEditorHtmlBackup') || $textarea.val() || '')
                    );
                    if (leafHasTableMarkup(visualHtml)) {
                        $textarea.val(visualHtml);
                        ensureRepeaterEditorCodeMode($wrap);
                        return;
                    }

                    switchRepeaterEditorMode($wrap, 'tmce', false);
                    return;
                }

                if (target.classList.contains('switch-html')) {
                    switchRepeaterEditorMode($wrap, 'html', true);
                }
            },
            true
        );
    }

    /**
     * After Add Media inserts into a repeater classic editor, flush Visual HTML into the textarea + JSON hidden.
     */
    function bindWpMediaInsertSyncForRepeaters() {
        if (window.stoAdvRepWpMediaBound || !window.wp || !window.wp.media || !window.wp.media.editor) {
            return;
        }
        var originalInsert = window.wp.media.editor.insert;
        if (typeof originalInsert !== 'function') {
            return;
        }
        window.stoAdvRepWpMediaBound = true;
        window.wp.media.editor.insert = function (html) {
            var activeEditorId =
                typeof window.wpActiveEditor === 'string' && window.wpActiveEditor
                    ? window.wpActiveEditor
                    : '';
            var result = originalInsert.apply(this, arguments);
            window.setTimeout(function () {
                if (!activeEditorId) {
                    return;
                }
                var $textarea = $('#' + activeEditorId);
                if (!$textarea.length || !$textarea.closest('.sto-adv-rep__field--editor').length) {
                    return;
                }
                var $wrap = $textarea.closest('.wp-editor-wrap');
                stoFlushRepeaterVisualEditorToTextarea($wrap);
                var $fieldRow = $textarea.closest('.sto-field-row-advanced-repeater');
                if ($fieldRow.length && typeof window.stoSyncAdvancedRepeaterFields === 'function') {
                    window.stoSyncAdvancedRepeaterFields($fieldRow);
                }
            }, 0);
            return result;
        };
    }

    function bindRepeaterEditorTabs() {
        if (bound) {
            return;
        }
        bound = true;

        bindRepeaterEditorTabCapture();
        bindWpMediaInsertSyncForRepeaters();

        $(document).on('input.stoAdvRepEditor', '.sto-adv-rep__field--editor textarea.wp-editor-area', function () {
            var $textarea = $(this);
            var $wrap = $textarea.closest('.wp-editor-wrap');
            var html = String($textarea.val() || '');
            if (leafHasTableMarkup(html)) {
                ensureRepeaterEditorCodeMode($wrap);
            } else {
                toggleRepeaterTableCodeNotice($wrap, false);
            }
        });

        $(document).on(
            'focusin.stoAdvRepEditor',
            '.sto-adv-rep__field--editor textarea.wp-editor-area',
            function () {
                var $wrap = $(this).closest('.wp-editor-wrap');
                if ($wrap.hasClass('tmce-active')) {
                    return;
                }
                if (isBrokenRepeaterEditorWrap($wrap)) {
                    switchRepeaterEditorMode($wrap, 'html', false);
                }
            }
        );

        // Code tab only: push textarea HTML into TinyMCE. Never mirror Visual → textarea here;
        // TinyMCE.save() fires input on a hidden textarea and used to call setContent(''), wiping Add Media.
        $(document).on(
            'input.stoAdvRepEditorVisualMirror',
            '.sto-adv-rep__field--editor .wp-editor-wrap.html-active textarea.wp-editor-area',
            function () {
                var $textarea = $(this);
                if ($textarea.data('stoAdvRepSilentInput')) {
                    return;
                }
                var editorId = String($textarea.attr('id') || '');
                if (!editorId || !window.tinymce) {
                    return;
                }
                var tinyEditor = window.tinymce.get(editorId);
                if (!tinyEditor || tinyEditor.isHidden()) {
                    return;
                }
                var areaHtml = String($textarea.val() || '');
                if (leafHasTableMarkup(areaHtml)) {
                    ensureRepeaterEditorCodeMode($textarea.closest('.wp-editor-wrap'));
                    return;
                }
                if (areaHtml !== tinyEditor.getContent()) {
                    tinyEditor.setContent(finalizeEditorHtml(areaHtml));
                }
            }
        );

        if (window.tinymce && window.tinymce.on) {
            window.tinymce.on('AddEditor', function (event) {
                var editor = event && event.editor ? event.editor : null;
                if (!editor || !editor.id) {
                    return;
                }
                var $textarea = $('#' + editor.id);
                if (!$textarea.length || !$textarea.closest('.sto-adv-rep__field--editor').length) {
                    return;
                }
                var $wrap = $textarea.closest('.wp-editor-wrap');
                var html = finalizeEditorHtml($textarea.val() || '');
                if (html !== $textarea.val()) {
                    $textarea.val(html);
                }
                if (leafHasTableMarkup(html)) {
                    window.setTimeout(function () {
                        ensureRepeaterEditorCodeMode($wrap);
                    }, 0);
                    return;
                }
                if (html !== editor.getContent()) {
                    editor.setContent(html);
                }

                bindRepeaterTinyMceLiveSync(editor);
            });
        }

        bindRepeaterTinyMceLiveSyncInScope($(document));
    }

    window.stoRepairRepeaterEditorRn = repairStoRepeaterEditorRn;
    window.stoNormalizeRepeaterEditorHtml = normalizeStoRepeaterEditorHtml;
    window.stoReadClassicEditorHtmlFromLeaf = readClassicEditorHtmlFromLeaf;
    window.stoFlushRepeaterVisualEditorToTextarea = stoFlushRepeaterVisualEditorToTextarea;
    window.stoSaveRepeaterTinyMceEditors = stoSaveRepeaterTinyMceEditors;
    window.stoSaveVisibleTinyMceEditors = stoSaveVisibleTinyMceEditors;

    window.stoStripRepeaterClassicEditorLeaves = stripRepeaterClassicEditorLeaves;
    window.stoRefreshClassicEditorsForScope = refreshClassicEditorsForScope;

    window.stoInitAdvancedRepeaterEditors = function ($scope) {
        bindRepeaterEditorTabs();
        preferCodeModeForTableMarkup($scope);
        seedRepeaterEditorHtmlBackupInScope($scope);
        bindRepeaterTinyMceLiveSyncInScope($scope);
    };

    $(function () {
        bindRepeaterEditorTabs();
        preferCodeModeForTableMarkup($(document));
    });
})(jQuery);
