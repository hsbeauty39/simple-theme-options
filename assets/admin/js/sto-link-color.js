(function($) {
    var DEFAULT_KEYS = ['regular', 'hover'];

    function parseHidden($hidden) {
        var raw = String($hidden.val() || '').trim();
        var out = { regular: '', hover: '' };
        if (!raw) {
            return out;
        }
        try {
            var o = JSON.parse(raw);
            if (!o || typeof o !== 'object') {
                return out;
            }
            DEFAULT_KEYS.forEach(function(k) {
                out[k] = o[k] != null ? String(o[k]) : '';
            });
            return out;
        } catch (e) {
            return out;
        }
    }

    function writeHidden($hidden, data) {
        var payload = {};
        DEFAULT_KEYS.forEach(function(k) {
            payload[k] = data[k] != null ? String(data[k]) : '';
        });
        $hidden.val(JSON.stringify(payload)).trigger('change');
    }

    function syncFromInputs($wrap) {
        var $hidden = $wrap.find('.sto-link-color-value');
        var cur = parseHidden($hidden);
        $wrap.find('.sto-link-color__input').each(function() {
            var $input = $(this);
            var key = String($input.attr('data-sto-link-color-key') || '').trim();
            if (!key) {
                return;
            }
            cur[key] = String($input.val() || '').trim();
        });
        writeHidden($hidden, cur);
    }

    function bindOnce($wrap) {
        if ($wrap.data('stoLinkColorInit')) {
            return;
        }
        $wrap.data('stoLinkColorInit', 1);

        var $hidden = $wrap.find('.sto-link-color-value');
        var initial = parseHidden($hidden);
        $wrap.find('.sto-link-color__input').each(function() {
            var $input = $(this);
            var key = String($input.attr('data-sto-link-color-key') || '').trim();
            if (!key) {
                return;
            }
            if (initial[key]) {
                $input.val(initial[key]);
            }
        });

        // Iris fires change on each color input (sto-color.js triggers deferred change).
        // Re-sync the hidden JSON whenever either picker emits change / input.
        $wrap.on('change.stoLinkColor input.stoLinkColor', '.sto-link-color__input', function() {
            syncFromInputs($wrap);
            if (typeof window.stoApplyDependentFieldVisibility === 'function') {
                window.stoApplyDependentFieldVisibility();
            }
        });

        $wrap.on('click.stoLinkColor', '.sto-color-reset', function() {
            // Reset path fires change after wpColorPicker('color', def); defer to flush.
            window.setTimeout(function() {
                syncFromInputs($wrap);
            }, 0);
        });
    }

    function initOne($wrap) {
        bindOnce($wrap);

        if (typeof window.stoInitColorPickers === 'function') {
            window.stoInitColorPickers($wrap);
        }
    }

    function initStoLinkColors($scope) {
        var $root = $scope && $scope.length ? $scope : $(document);
        $root.find('[data-sto-link-color]').each(function() {
            initOne($(this));
        });
    }

    /**
     * Ensure hidden JSON is flushed from the visible pickers before POST. wpColorPicker
     * sometimes updates the text input without a bubbling change in edge cases.
     */
    function syncAllInForm($form) {
        if (!$form || !$form.length) {
            return;
        }
        $form.find('[data-sto-link-color]').each(function() {
            syncFromInputs($(this));
        });
    }

    $(document).on('submit', '#sto-theme-settings-options-form', function() {
        syncAllInForm($(this));
    });

    window.stoInitLinkColors = initStoLinkColors;
})(jQuery);
