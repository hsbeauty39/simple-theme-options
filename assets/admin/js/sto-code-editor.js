/**
 * sto-code-editor.js — upgrade `.sto-code-editor[data-sto-code-editor]` textareas to
 * `wp.codeEditor` (WordPress core CodeMirror 5 wrapper) with IDE-style ergonomics:
 *
 *   - **Auto language detection** (`mode => 'auto'`): heuristic match runs once on mount and
 *     again on every content change (debounced 250ms) — flips `cm.setOption('mode', mime)` so
 *     CSS / HTML / JS / PHP / JSON / Markdown / XML / YAML each get their native parser without
 *     the admin telling the field which language it holds.
 *   - **Chrome-bar language switcher** (`<select>` next to the mode badge): manual override.
 *     Setting "Auto-detect" re-arms the heuristic.
 *   - **Autocomplete** (`Ctrl/Cmd + Space` explicit + smart auto-trigger on alpha / `<` / `.` / `@`):
 *     calls `cm.showHint()` with the mode-specific provider from `CodeMirror.hint.*` and falls
 *     back to `CodeMirror.hint.anyword` so plain-text / unsupported modes still get word
 *     completion against the buffer.
 *   - **Word wrap** — **Alt+Z** (Option+Z on macOS) toggles **`lineWrapping`** (`cm.addKeyMap`)
 *     without overwriting autocomplete **`extraKeys`**. Plain‑textarea fallback toggles **`wrap`**
 *     soft/on versus **`wrap`** off.
 *   - **Submit safety net** — flushes every CodeMirror buffer back into its `<textarea>` on
 *     `submit` of the options form.
 *
 * Lifecycle hooks (called from `main.js`):
 *   - DOMContentLoaded — initial scan.
 *   - `stoInitCodeEditors($scope)` — re-run after a section / responsive-tab switch so editors
 *     hidden in inactive panels call `cm.refresh()` once visible (CodeMirror's scroll math sits
 *     at 0 until first paint).
 *
 * Fallback: when `wp.codeEditor` is unavailable the raw `<textarea>` stays in place with
 * monospaced font; the chrome bar still works (fullscreen toggle + **Alt+Z** wrap toggle + submit flush).
 */
(function ($) {
    'use strict';

    /** Track initialized wrappers so re-init calls don't double-wrap. */
    var INIT_KEY = 'stoCodeEditorInitialized';

    /** Debounce window for re-running detection on content change. */
    var DETECT_DEBOUNCE_MS = 250;

    /**
     * Initialize every Code Editor inside the given scope ($scope falls back to document).
     *
     * @param {jQuery|HTMLElement} [$scope]
     */
    function initCodeEditors($scope) {
        var $context = $scope && $scope.length ? $($scope) : $(document);
        if (!$context.length) {
            return;
        }

        $context.find('.sto-code-editor[data-sto-code-editor]').each(function () {
            initOne(this);
        });
    }

    function initOne(wrapperEl) {
        var $wrap = $(wrapperEl);
        if ($wrap.data(INIT_KEY)) {
            // Already wrapped — just refresh layout (handles section-switch / responsive-tab show).
            refreshEditor($wrap);
            return;
        }

        var $ta = $wrap.find('textarea.sto-code-editor__textarea').first();
        if (!$ta.length) {
            return;
        }

        var settings = parseSettings($wrap);
        var rawHeight = parseInt($wrap.attr('data-sto-code-height'), 10);
        var height = isFinite(rawHeight) && rawHeight > 0 ? rawHeight : 240;
        var initialMode = String($wrap.attr('data-sto-code-mode') || 'text');
        var autocompleteEnabled = String($wrap.attr('data-sto-code-autocomplete') || '0') === '1';

        // Bail to plain `<textarea>` fallback when `wp.codeEditor` isn't available — still
        // attach the chrome controls so the rest of the UI keeps working.
        if (!window.wp || !window.wp.codeEditor || typeof window.wp.codeEditor.initialize !== 'function') {
            attachLanguageSwitcher($wrap, null);
            attachFullscreenToggle($wrap);
            attachPlainTextareaWrapToggle($wrap, $ta);
            $wrap.data(INIT_KEY, true);
            return;
        }

        var initResult;
        try {
            initResult = window.wp.codeEditor.initialize($ta[0], { codemirror: settings });
        } catch (err) {
            attachLanguageSwitcher($wrap, null);
            attachFullscreenToggle($wrap);
            attachPlainTextareaWrapToggle($wrap, $ta);
            $wrap.data(INIT_KEY, true);
            return;
        }

        if (!initResult || !initResult.codemirror) {
            attachLanguageSwitcher($wrap, null);
            attachFullscreenToggle($wrap);
            attachPlainTextareaWrapToggle($wrap, $ta);
            $wrap.data(INIT_KEY, true);
            return;
        }

        var cm = initResult.codemirror;
        $wrap.addClass('sto-code-editor--initialized');
        $wrap.data('stoCm', cm);
        $wrap.data(INIT_KEY, true);

        attachCmWrapToggleKeymap($wrap, cm);

        try {
            cm.setSize('100%', height);
        } catch (errSize) { /* size errors are non-fatal */ }

        // Mirror CodeMirror buffer to the backing `<textarea>` on every change so form
        // serialization captures the latest content even before the user clicks Save.
        cm.on('change', function (instance) {
            $ta.val(instance.getValue());
        });

        // Refresh once after mount — CodeMirror needs an explicit kick when the editor is
        // initially inside a hidden / off-screen container.
        window.setTimeout(function () {
            try {
                cm.refresh();
            } catch (errRefresh) { /* non-fatal */ }
        }, 60);

        attachLanguageSwitcher($wrap, cm);
        attachFullscreenToggle($wrap, cm);
        if (autocompleteEnabled) {
            attachAutocomplete($wrap, cm);
        }
        if (initialMode === 'auto') {
            armAutoDetect($wrap, cm);
        }
    }

    function parseSettings($wrap) {
        var raw = $wrap.attr('data-sto-code-settings');
        if (!raw) {
            return {};
        }
        try {
            return JSON.parse(raw) || {};
        } catch (err) {
            return {};
        }
    }

    /**
     * Refresh layout for an already-initialized editor. Used after the parent section /
     * responsive tab becomes visible — CodeMirror needs an explicit kick or the scroll
     * height stays at 0 until the user clicks inside.
     */
    function refreshEditor($wrap) {
        var cm = $wrap.data('stoCm');
        if (!cm || typeof cm.refresh !== 'function') {
            return;
        }
        window.setTimeout(function () {
            try {
                cm.refresh();
            } catch (err) { /* non-fatal */ }
        }, 50);
    }

    /**
     * Toggle CodeMirror line wrapping with Alt-Z — layered alongside autocomplete Ctrl/Cmd+Space
     * bindings (`extraKeys`) via **`cm.addKeyMap()`**.
     */
    function attachCmWrapToggleKeymap($wrap, cm) {
        if (!cm || typeof cm.addKeyMap !== 'function') {
            return;
        }
        if ($wrap.data('stoCmWrapKeymap')) {
            return;
        }
        $wrap.data('stoCmWrapKeymap', true);

        cm.addKeyMap({
            'Alt-Z': function toggleCmWrap(inst) {
                var next = !inst.getOption('lineWrapping');
                try {
                    inst.setOption('lineWrapping', next);
                } catch (errLw) {
                    /* non-fatal */
                }
                $wrap.toggleClass('sto-code-editor--line-wrap-on', next);
                updateWrapHintChip($wrap, next);
                window.setTimeout(function () {
                    try {
                        inst.refresh();
                    } catch (errRf) {
                        /* non-fatal */
                    }
                }, 10);
            },
        });

        var lwInitial = !!cm.getOption('lineWrapping');
        $wrap.toggleClass('sto-code-editor--line-wrap-on', lwInitial);
        updateWrapHintChip($wrap, lwInitial);
    }

    /**
     * Fallback **textarea**: Toggle **`wrap="soft"` ↔ **`wrap="off"`**.
     */
    function attachPlainTextareaWrapToggle($wrap, $ta) {
        if (!$wrap.length || !$ta.length || !$ta[0] || $wrap.data('stoTaWrapBound')) {
            return;
        }
        $wrap.data('stoTaWrapBound', true);

        var wrapAttr = String($ta.attr('wrap') || 'off').toLowerCase();
        var isSoft = wrapAttr === 'soft' || wrapAttr === 'virtual';
        $wrap.toggleClass('sto-code-editor--textarea-soft-wrap', isSoft);
        $wrap.toggleClass('sto-code-editor--line-wrap-on', isSoft);
        updateWrapHintChip($wrap, isSoft);

        $ta.on('keydown.stoTaWrap', function (event) {
            if (event.repeat || !event.altKey || event.ctrlKey || event.metaKey || event.shiftKey) {
                return;
            }
            var key = event.key;
            if (key !== 'z' && key !== 'Z') {
                return;
            }
            event.preventDefault();

            var cur = String($ta.attr('wrap') || 'off').toLowerCase();
            var nextOn = !(cur === 'soft' || cur === 'virtual');
            $ta.attr('wrap', nextOn ? 'soft' : 'off');
            $wrap.toggleClass('sto-code-editor--textarea-soft-wrap', nextOn);
            $wrap.toggleClass('sto-code-editor--line-wrap-on', nextOn);
            updateWrapHintChip($wrap, nextOn);
        });
    }

    function updateWrapHintChip($wrap, isOn) {
        var $hint = $wrap.find('[data-sto-code-wrap-hint]').first();
        if (!$hint.length) {
            return;
        }
        $hint.attr('data-sto-wrap-active', isOn ? '1' : '0');
        $hint.attr(
            'title',
            isOn
                ? 'Word wrap On — Alt + Z (Option + Z on macOS) to unwrap.'
                : 'Word wrap Off — Alt + Z (Option + Z on macOS) to wrap.'
        );
    }

    /* ----------------------------------------------------------------------------------- */
    /* Language switcher                                                                   */
    /* ----------------------------------------------------------------------------------- */

    function attachLanguageSwitcher($wrap, cm) {
        var $select = $wrap.find('[data-sto-code-lang-select]').first();
        if (!$select.length || $select.data('stoLangBound')) {
            return;
        }
        $select.data('stoLangBound', true);

        // Upgrade the native `<select>` to a compact Select2 pill so the chrome matches the
        // plugin's other Select2 widgets (Select / Typography / DynamicObject). We don't
        // stamp `sto-input-select` upstream so the global `refreshStoSelect2` doesn't grab it
        // with full-row chrome — instead we init here with tight sizing + per-option icon
        // templates pulled from each `<option data-sto-code-icon="…">`.
        initLangSelect2($wrap, $select);

        $select.on('change', function () {
            var key = $select.val();
            var $opt = $select.find('option:selected');
            var mime = $opt.attr('data-sto-code-mime') || 'text/plain';

            $wrap.attr('data-sto-code-mode', key);
            $wrap.toggleClass('sto-code-editor--mode-auto', key === 'auto');

            if (!cm) {
                return;
            }

            if (key === 'auto') {
                armAutoDetect($wrap, cm);
                detectAndApply($wrap, cm);
            } else {
                disarmAutoDetect($wrap);
                try {
                    cm.setOption('mode', mime);
                } catch (err) { /* non-fatal */ }
                updateAutoBadge($wrap, key, /*detected*/ false);
            }
        });
    }

    /**
     * Initialize Select2 on the chrome-bar language `<select>` with compact pill chrome and
     * per-option language icons. Falls back silently to the native `<select>` if Select2
     * isn't loaded (e.g. user disabled syntax highlighting + Select2 dep didn't load yet).
     */
    function initLangSelect2($wrap, $select) {
        if (!$.fn || typeof $.fn.select2 !== 'function') {
            return;
        }
        if ($select.data('select2')) {
            return;
        }

        var $dropdownParent =
            typeof window.stoGetSelect2DropdownParent === 'function'
                ? window.stoGetSelect2DropdownParent($select)
                : $('#wpbody-content');
        if (!$dropdownParent.length) {
            $dropdownParent = $(document.body);
        }

        try {
            $select.select2({
                width: '160px',
                minimumResultsForSearch: -1,
                dropdownCssClass: 'sto-code-editor__lang-dropdown',
                selectionCssClass: 'sto-code-editor__lang-s2',
                dropdownParent: $dropdownParent,
                templateSelection: renderLangChip,
                templateResult: renderLangOption
            });
        } catch (err) { /* non-fatal — native select keeps working */ }
    }

    /** Build the visible "pill" content shown inside the closed selection box. */
    function renderLangChip(option) {
        if (!option || !option.id || !option.element) {
            return option ? option.text : '';
        }
        var icon = option.element.getAttribute('data-sto-code-icon') || 'fa-light fa-code';
        var $chip = $(
            '<span class="sto-code-editor__lang-chip">' +
                '<i aria-hidden="true"></i>' +
                '<span class="sto-code-editor__lang-chip-text"></span>' +
            '</span>'
        );
        $chip.find('i').attr('class', icon + ' sto-code-editor__lang-chip-icon');
        $chip.find('.sto-code-editor__lang-chip-text').text(option.text || '');
        return $chip;
    }

    /** Build the dropdown row markup — icon + label, with extra padding for active highlight. */
    function renderLangOption(option) {
        if (!option || !option.id || !option.element) {
            return option ? option.text : '';
        }
        var icon = option.element.getAttribute('data-sto-code-icon') || 'fa-light fa-code';
        var $row = $(
            '<span class="sto-code-editor__lang-opt">' +
                '<i aria-hidden="true"></i>' +
                '<span class="sto-code-editor__lang-opt-text"></span>' +
            '</span>'
        );
        $row.find('i').attr('class', icon + ' sto-code-editor__lang-opt-icon');
        $row.find('.sto-code-editor__lang-opt-text').text(option.text || '');
        return $row;
    }

    /**
     * Update the small "Auto" / "Auto · CSS" badge so the user can see which language the
     * heuristic decided on. Visibility is owned by CSS (the wrapper class
     * `sto-code-editor--mode-auto` toggles `display`), so this function only rewrites the
     * label + tooltip — never `display` / `hidden`.
     */
    function updateAutoBadge($wrap, modeKey, detected) {
        var $badge = $wrap.find('[data-sto-code-auto-badge]').first();
        if (!$badge.length) {
            return;
        }
        var $label = $badge.find('span').first();
        if (!$label.length) {
            $label = $('<span/>').appendTo($badge);
        }
        var label = labelForMode(modeKey);
        var prefix = 'Auto';
        if (detected && modeKey && modeKey !== 'auto' && modeKey !== 'text') {
            $badge.attr(
                'title',
                'Language auto-detected from content (' + label + ')'
            );
            $label.text(prefix + ' · ' + label);
        } else {
            $badge.attr('title', 'Language auto-detected from content');
            $label.text(prefix);
        }
    }

    function labelForMode(key) {
        switch (key) {
            case 'css': return 'CSS';
            case 'html': return 'HTML';
            case 'javascript': return 'JavaScript';
            case 'php': return 'PHP';
            case 'json': return 'JSON';
            case 'markdown': return 'Markdown';
            case 'xml': return 'XML';
            case 'yaml': return 'YAML';
            case 'text': return 'Plain text';
            case 'auto': return 'Auto';
            default: return key ? key.toUpperCase() : '';
        }
    }

    /* ----------------------------------------------------------------------------------- */
    /* Auto-detection                                                                       */
    /* ----------------------------------------------------------------------------------- */

    function armAutoDetect($wrap, cm) {
        if ($wrap.data('stoAutoBound')) {
            // Already armed — just run a detect pass.
            detectAndApply($wrap, cm);
            return;
        }
        $wrap.data('stoAutoBound', true);

        var debounceTimer = null;
        var handler = function () {
            if ($wrap.attr('data-sto-code-mode') !== 'auto') {
                return;
            }
            window.clearTimeout(debounceTimer);
            debounceTimer = window.setTimeout(function () {
                detectAndApply($wrap, cm);
            }, DETECT_DEBOUNCE_MS);
        };

        cm.on('change', handler);
        $wrap.data('stoAutoHandler', handler);

        // Initial detect pass — runs even on empty content (yields `text`).
        detectAndApply($wrap, cm);
    }

    function disarmAutoDetect($wrap) {
        var cm = $wrap.data('stoCm');
        var handler = $wrap.data('stoAutoHandler');
        if (cm && handler) {
            try {
                cm.off('change', handler);
            } catch (err) { /* non-fatal */ }
        }
        $wrap.removeData('stoAutoBound');
        $wrap.removeData('stoAutoHandler');
    }

    function detectAndApply($wrap, cm) {
        if (!cm || typeof cm.getValue !== 'function') {
            return;
        }
        var content = cm.getValue();
        var detected = detectLanguage(content);
        var mime = mimeForMode(detected);
        try {
            cm.setOption('mode', mime);
        } catch (err) { /* non-fatal */ }
        updateAutoBadge($wrap, detected, /*detected*/ true);
        // Sync the chrome-bar select label without firing its `change` listener (would
        // disarm auto-detection). We keep the dropdown showing "Auto-detect" while the
        // badge shows the resolved language.
    }

    function mimeForMode(mode) {
        switch (mode) {
            case 'css': return 'text/css';
            case 'html': return 'text/html';
            case 'javascript': return 'application/javascript';
            case 'php': return 'application/x-httpd-php';
            case 'json': return 'application/json';
            case 'markdown': return 'text/x-markdown';
            case 'xml': return 'text/xml';
            case 'yaml': return 'text/x-yaml';
            case 'text':
            default:
                return 'text/plain';
        }
    }

    /**
     * Heuristic language detector — runs entirely client-side against the buffer string.
     * Ordered from most-specific to least-specific so a JSON document doesn't accidentally
     * trigger the JS branch (it would: const + curly + colon), and an XML doc doesn't trigger
     * the HTML branch. Returns one of: `php` | `html` | `xml` | `json` | `css` | `javascript`
     * | `markdown` | `yaml` | `text`.
     */
    function detectLanguage(content) {
        var c = (content || '').replace(/^\s+/, '');
        if (!c) {
            return 'text';
        }
        var sample = c.length > 4000 ? c.substring(0, 4000) : c;
        var lowered = sample.toLowerCase();

        // PHP — must contain `<?php` or short open tag.
        if (/<\?php\b/.test(sample) || /^<\?=?/.test(sample)) {
            return 'php';
        }

        // HTML — DOCTYPE, <html>, or any well-formed tag near the top + closing tag somewhere.
        if (/^<!doctype html/i.test(sample) || /^<html\b/i.test(lowered)) {
            return 'html';
        }
        if (/^<[a-z][\w-]*(\s[^<>]*)?>/i.test(sample) && /<\/[a-z][\w-]*>/i.test(sample)) {
            // Distinguish HTML from generic XML — common HTML tags should appear.
            if (/<(div|span|p|a|img|h[1-6]|ul|ol|li|button|form|input|html|body|head|meta|link|script|style|table|tr|td|nav|header|footer|main|section|article|aside)\b/i.test(lowered)) {
                return 'html';
            }
            return 'xml';
        }
        if (/^<\?xml\b/i.test(sample)) {
            return 'xml';
        }

        // JSON — strictly delimited, parse-checked.
        if (/^[\s]*[\[\{]/.test(c) && /[\]\}][\s]*$/.test(c)) {
            try {
                JSON.parse(c);
                return 'json';
            } catch (e) {
                // Fall through — looks like JSON but isn't valid; might be JS.
            }
        }

        // CSS — selector + brace + property:value pairs. Steer away from JS object literals
        // by requiring at least one CSS-only property name or at-rule.
        if (/[#.\w][\w\s.,>+~:()\-\[\]]*\s*\{[\s\S]*?\}/.test(sample)) {
            if (/\b(color|background|background-color|margin|padding|display|font|font-size|width|height|border|flex|grid|position|top|bottom|left|right|opacity|transform|transition|box-shadow|line-height|text-align)\s*:/i.test(sample)) {
                return 'css';
            }
            if (/@(media|keyframes|import|font-face|supports|charset|namespace|page)\b/i.test(sample)) {
                return 'css';
            }
        }

        // JavaScript — function / const / let / =>, plus common globals or module syntax.
        if (
            /\b(function|class|export|import|require)\b/.test(sample) ||
            /\b(const|let|var)\s+[A-Za-z_$][\w$]*\s*[=;]/.test(sample) ||
            /=>\s*[\{(]/.test(sample) ||
            /\b(console\.(log|error|warn|info)|document\.|window\.|jQuery\(|\$\()/.test(sample) ||
            /\?\.\s*[A-Za-z_$]/.test(sample)
        ) {
            return 'javascript';
        }

        // Markdown — heading or list markers at line start, fenced code blocks, or [text](url).
        if (/^#{1,6}\s+\S/m.test(sample) || /^```/m.test(sample) || /^\s*[*+\-]\s+\S/m.test(sample) || /\[[^\]]+\]\([^)]+\)/.test(sample)) {
            return 'markdown';
        }

        // YAML — key: value with dash list, no curly braces. Restrictive to avoid false hits.
        if (/^[A-Za-z_][\w-]*\s*:\s*[\S]/m.test(sample) && !/\{|;/.test(sample.substring(0, 200)) && /^---|^[A-Za-z_].*:\s*$|^\s+-\s+/m.test(sample)) {
            return 'yaml';
        }

        return 'text';
    }

    /* ----------------------------------------------------------------------------------- */
    /* Autocomplete (CodeMirror `show-hint` addon)                                          */
    /* ----------------------------------------------------------------------------------- */

    /**
     * Wire up Ctrl/Cmd+Space + smart auto-trigger. The hint engine is provided by the
     * `show-hint` addon bundled with `wp-codemirror`; if the addon isn't present we no-op.
     */
    function attachAutocomplete($wrap, cm) {
        if (!cm || typeof cm.showHint !== 'function') {
            // `show-hint` isn't loaded — graceful no-op, keep typing fluid.
            return;
        }

        var triggerKeyHandler = function (instance) {
            instance.showHint({
                completeSingle: false,
                hint: pickHintFn(instance),
            });
        };

        var extra = cm.getOption('extraKeys') || {};
        extra['Ctrl-Space'] = triggerKeyHandler;
        extra['Cmd-Space'] = triggerKeyHandler;
        cm.setOption('extraKeys', extra);

        // Smart auto-trigger — open the hint popup as soon as the user starts typing a word /
        // tag / selector / decorator character, but only when the popup isn't already open
        // (CodeMirror sets `state.completionActive` while a hint widget is alive).
        cm.on('inputRead', function (instance, change) {
            if (change.origin !== '+input') {
                return;
            }
            if (instance.state && instance.state.completionActive) {
                return;
            }
            var last = change.text && change.text.length ? change.text[change.text.length - 1] : '';
            if (!last) {
                return;
            }
            // Trigger characters per mode — alpha word chars universally, plus context openers:
            //   `<` for HTML / XML, `.` and `@` and `:` for CSS selectors / at-rules / pseudo,
            //   `$` for JS variables.
            if (!/[a-zA-Z_<.@:$]/.test(last)) {
                return;
            }
            window.setTimeout(function () {
                if (instance.state && instance.state.completionActive) {
                    return;
                }
                try {
                    instance.showHint({
                        completeSingle: false,
                        hint: pickHintFn(instance),
                    });
                } catch (err) { /* non-fatal */ }
            }, 80);
        });
    }

    /**
     * Pick the best hint provider for the editor's current mode. Falls back to the universal
     * `anyword` provider (word-completion against the buffer) for any mode without a dedicated
     * hint addon — that still beats a blank popup.
     */
    function pickHintFn(cm) {
        var CM = window.CodeMirror;
        var hintBag = CM && CM.hint ? CM.hint : null;
        if (!hintBag) {
            return null;
        }
        var mode = cm.getOption('mode');
        var modeName = '';
        if (typeof mode === 'string') {
            modeName = mode;
        } else if (mode && mode.name) {
            modeName = mode.name;
        }

        if (modeName === 'text/css' || modeName === 'css') {
            return hintBag.css || hintBag.anyword || null;
        }
        if (modeName === 'text/html' || modeName === 'htmlmixed' || modeName === 'html') {
            return hintBag.html || hintBag.xml || hintBag.anyword || null;
        }
        if (modeName === 'text/xml' || modeName === 'xml') {
            return hintBag.xml || hintBag.anyword || null;
        }
        if (modeName === 'application/javascript' || modeName === 'javascript' || modeName === 'text/javascript') {
            return hintBag.javascript || hintBag.anyword || null;
        }
        if (modeName === 'application/x-httpd-php' || modeName === 'php') {
            // PHP mode is multiplex — html + javascript + css + php; default to anyword to
            // cover variable / function names across the buffer.
            return hintBag.anyword || null;
        }
        if (modeName === 'application/json' || modeName === 'json') {
            return hintBag.anyword || null;
        }
        if (modeName === 'application/x-sql' || modeName === 'sql') {
            return hintBag.sql || hintBag.anyword || null;
        }

        return hintBag.anyword || null;
    }

    /* ----------------------------------------------------------------------------------- */
    /* Fullscreen                                                                           */
    /* ----------------------------------------------------------------------------------- */

    function attachFullscreenToggle($wrap, cm) {
        var $btn = $wrap.find('[data-sto-code-fullscreen]').first();
        if (!$btn.length || $btn.data('stoFsBound')) {
            return;
        }
        $btn.data('stoFsBound', true);

        $btn.on('click', function (event) {
            event.preventDefault();
            toggleFullscreen($wrap, cm);
        });

        $wrap.on('keydown', function (event) {
            if (event.key !== 'Escape') {
                return;
            }
            if (!$wrap.hasClass('sto-code-editor--is-fullscreen')) {
                return;
            }
            toggleFullscreen($wrap, cm);
            event.stopPropagation();
        });
    }

    function toggleFullscreen($wrap, cm) {
        var nowFs = !$wrap.hasClass('sto-code-editor--is-fullscreen');
        $wrap.toggleClass('sto-code-editor--is-fullscreen', nowFs);
        $('body').toggleClass('sto-code-editor-fullscreen-lock', nowFs);

        var $langSel = $wrap.find('[data-sto-code-lang-select]').first();
        if ($langSel.length && $langSel.data('select2')) {
            try {
                $langSel.select2('destroy');
            } catch (errFs) { /* non-fatal */ }
            initLangSelect2($wrap, $langSel);
        }

        if (cm && typeof cm.refresh === 'function') {
            window.setTimeout(function () {
                try {
                    cm.refresh();
                } catch (err) { /* non-fatal */ }
            }, 60);
        }
    }

    /* ----------------------------------------------------------------------------------- */
    /* Submit-time flush                                                                    */
    /* ----------------------------------------------------------------------------------- */

    function syncAllToTextareas() {
        $('.sto-code-editor.sto-code-editor--initialized').each(function () {
            var $wrap = $(this);
            var cm = $wrap.data('stoCm');
            if (!cm || typeof cm.getValue !== 'function') {
                return;
            }
            var $ta = $wrap.find('textarea.sto-code-editor__textarea').first();
            if ($ta.length) {
                $ta.val(cm.getValue());
            }
        });
    }

    window.stoInitCodeEditors = initCodeEditors;

    $(function () {
        initCodeEditors($(document));
        $(document).on('submit', '#sto-theme-settings-options-form, form.sto-options-form', syncAllToTextareas);
    });
})(jQuery);
