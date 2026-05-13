(function($) {
    function parseHidden($hidden) {
        var raw = String($hidden.val() || '').trim();
        if (!raw) {
            return { color: '', image_id: '' };
        }
        try {
            var o = JSON.parse(raw);
            if (!o || typeof o !== 'object') {
                return { color: '', image_id: '' };
            }
            return {
                color: o.color != null ? String(o.color) : '',
                image_id: o.image_id != null ? String(o.image_id) : ''
            };
        } catch (e) {
            return { color: '', image_id: '' };
        }
    }

    function writeHidden($hidden, data) {
        var payload = {
            color: data.color != null ? String(data.color) : '',
            image_id: data.image_id != null ? String(data.image_id) : ''
        };
        $hidden.val(JSON.stringify(payload)).trigger('change');
    }

    function syncFromColor($wrap) {
        var $hidden = $wrap.find('.sto-background-control-value');
        var $color = $wrap.find('.sto-background-control-color');
        var cur = parseHidden($hidden);
        cur.color = String($color.val() || '').trim();
        writeHidden($hidden, cur);
    }

    function setImage($wrap, id, thumbUrl) {
        var $hidden = $wrap.find('.sto-background-control-value');
        var cur = parseHidden($hidden);
        cur.image_id = id ? String(id) : '';
        writeHidden($hidden, cur);

        var $prev = $wrap.find('.sto-background-control__preview');
        var $img = $wrap.find('.sto-background-control__thumb');
        if (id && thumbUrl) {
            $img.attr('src', thumbUrl);
            $prev.removeAttr('hidden');
        } else {
            $img.attr('src', '');
            $prev.attr('hidden', 'hidden');
        }
    }

    function bindMediaOnce($wrap) {
        if ($wrap.data('stoBgMediaInit')) {
            return;
        }
        $wrap.data('stoBgMediaInit', 1);

        var $btn = $wrap.find('.sto-background-control-upload');
        $btn.on('click', function(e) {
            e.preventDefault();
            if (typeof wp === 'undefined' || !wp.media) {
                return;
            }

            var frame = wp.media({
                title: $btn.data('stoFrameTitle') || '',
                library: { type: 'image' },
                button: { text: $btn.data('stoFrameButton') || 'Use image' },
                multiple: false
            });

            frame.on('select', function() {
                var att = frame.state().get('selection').first().toJSON();
                var id = att.id ? parseInt(att.id, 10) : 0;
                var url =
                    (att.sizes && att.sizes.thumbnail && att.sizes.thumbnail.url) ||
                    att.url ||
                    '';
                setImage($wrap, id, url);
                if (typeof window.stoApplyDependentFieldVisibility === 'function') {
                    window.stoApplyDependentFieldVisibility();
                }
            });

            frame.open();
        });
    }

    function bindColorSyncOnce($wrap) {
        if ($wrap.data('stoBgColorSync')) {
            return;
        }
        $wrap.data('stoBgColorSync', 1);

        var $hidden = $wrap.find('.sto-background-control-value');
        var $color = $wrap.find('.sto-background-control-color');
        var initial = parseHidden($hidden);
        if (initial.color) {
            $color.val(initial.color);
        }

        $color.on('change.stoBg input.stoBg', function() {
            syncFromColor($wrap);
            if (typeof window.stoApplyDependentFieldVisibility === 'function') {
                window.stoApplyDependentFieldVisibility();
            }
        });

        $wrap.find('.sto-color-reset').on('click.stoBg', function() {
            setTimeout(function() {
                syncFromColor($wrap);
            }, 0);
        });
    }

    function initOne($wrap) {
        bindMediaOnce($wrap);
        bindColorSyncOnce($wrap);

        if (typeof window.stoInitColorPickers === 'function') {
            window.stoInitColorPickers($wrap);
        }
    }

    function initStoBackgroundControls($scope) {
        var $root = $scope && $scope.length ? $scope : $(document);
        $root.find('[data-sto-background-control]').each(function() {
            initOne($(this));
        });
    }

    /**
     * Ensure hidden JSON is flushed from the visible picker before POST. Iris / alpha
     * paths can update the text input without a bubbling `change` in some cases.
     */
    function syncAllInForm($form) {
        if (!$form || !$form.length) {
            return;
        }
        $form.find('[data-sto-background-control]').each(function() {
            syncFromColor($(this));
        });
    }

    $(document).on('submit', '#sto-theme-settings-options-form', function() {
        syncAllInForm($(this));
    });

    window.stoInitBackgroundControls = initStoBackgroundControls;
})(jQuery);
