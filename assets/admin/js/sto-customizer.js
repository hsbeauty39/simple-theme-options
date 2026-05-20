/**
 * Theme Settings embedded in the WordPress Customizer.
 */
(function ($, wp) {
    'use strict';

    if (typeof wp === 'undefined' || !wp.customize) {
        return;
    }

    var hasCustomizerSaveBridge = typeof stoCustomizer !== 'undefined';

    function $embedRoot() {
        var $root = $('#customize-theme-controls .sto-customizer-embed-root').first();
        if ($root.length) {
            return $root;
        }

        return $('#customize-theme-controls .sto-option-panel-wrapper--customizer-embed').first();
    }

    function getCustomizerForm() {
        var $root = $embedRoot();
        if (!$root.length) {
            return $();
        }
        return $root.find('#sto-theme-settings-options-form').first();
    }

    function flushEditors($form) {
        if (typeof window.stoSaveRepeaterTinyMceEditors === 'function') {
            window.stoSaveRepeaterTinyMceEditors($form);
        }
        if (typeof window.stoSyncAdvancedRepeaterFields === 'function') {
            window.stoSyncAdvancedRepeaterFields($form);
        }
    }

    function saveCustomizerSection() {
        if (!hasCustomizerSaveBridge) {
            return $.Deferred().reject(new Error('Customizer save bridge unavailable.')).promise();
        }

        var $form = getCustomizerForm();
        if (!$form.length) {
            return $.Deferred().resolve().promise();
        }

        flushEditors($form);

        return $.ajax({
            url: stoCustomizer.ajax_url,
            method: 'POST',
            data: $form.serialize()
                + '&action=' + encodeURIComponent(stoCustomizer.action)
                + '&nonce=' + encodeURIComponent(stoCustomizer.nonce)
        }).then(function (response) {
            if (!response || !response.success) {
                var message = (response && response.data && response.data.message)
                    ? response.data.message
                    : stoCustomizer.i18n.save_failed;
                throw new Error(message);
            }
        });
    }

    /**
     * Flatten wp-admin flex shells, enforce search → sidebar → fields order, and collapse
     * any tall empty band above the section nav (wp-admin flex row + sticky rail at full viewport).
     */
    function fixStoCustomizerEmbedLayout() {
        var $root = $embedRoot();
        if (!$root.length) {
            return;
        }

        var $wrapper = $root.find('.sto-option-panel-wrapper').first();
        if (!$wrapper.length) {
            $wrapper = $root.filter('.sto-option-panel-wrapper').first();
        }
        if (!$wrapper.length) {
            return;
        }

        $wrapper.find('.sto-option-panel-nav-layout').each(function () {
            var $layout = $(this);
            $layout.children().appendTo($layout.parent());
            $layout.remove();
        });

        var $head = $wrapper.children('.sto-option-panel-head').first();
        var $sidebar = $wrapper.find('.sto-option-panel-sidebar-wrap').first();
        var $main = $wrapper.find('.sto-option-panel-main').first();

        if (!$sidebar.length || !$main.length) {
            return;
        }

        $sidebar.detach();
        $main.detach();
        $wrapper.find('.sto-option-panel-body').remove();

        if ($head.length) {
            $wrapper.append($head);
        }
        $wrapper.append($sidebar);
        $wrapper.append($main);

        $wrapper.css({
            display: 'flex',
            flexDirection: 'column',
            flexWrap: 'nowrap',
            alignItems: 'stretch',
            gap: '12px'
        });
        $head.css({ order: '1', flex: '0 0 auto', marginTop: '' });
        $sidebar.css({
            order: '2',
            flex: '0 0 auto',
            position: 'static',
            top: 'auto',
            width: '100%',
            maxWidth: 'none',
            marginTop: ''
        });
        $main.css({
            order: '3',
            flex: '0 0 auto',
            minHeight: 0,
            height: 'auto',
            width: '100%'
        });

        function collapseEmbedGapAboveSidebar() {
            if (!$head.length || !$sidebar.length) {
                return;
            }

            var rowGap = 12;
            var headBottom = $head.offset().top + $head.outerHeight(true);
            var sidebarTop = $sidebar.offset().top;
            var extraGap = Math.round(sidebarTop - headBottom - rowGap);

            if (extraGap > 20) {
                $sidebar.css('margin-top', (-extraGap) + 'px');
            } else {
                $sidebar.css('margin-top', '');
            }
        }

        collapseEmbedGapAboveSidebar();
        window.requestAnimationFrame(collapseEmbedGapAboveSidebar);
        window.setTimeout(collapseEmbedGapAboveSidebar, 100);
    }

    window.stoFixCustomizerEmbedLayout = fixStoCustomizerEmbedLayout;

    function initEmbedUi() {
        var $root = $embedRoot();
        if (!$root.length) {
            return;
        }

        fixStoCustomizerEmbedLayout();

        var $form = getCustomizerForm();
        if (typeof window.stoInitAdvancedRepeaterFields === 'function') {
            window.stoInitAdvancedRepeaterFields($root);
        }
        if (typeof window.stoRefreshTypographySelect2 === 'function') {
            window.stoRefreshTypographySelect2($root);
        }
        if (typeof window.stoInitColorPickers === 'function') {
            window.stoInitColorPickers($root);
        }
        if (typeof window.stoInitGradientControls === 'function') {
            window.stoInitGradientControls($root);
        }
        if (typeof window.stoInitBackgroundControls === 'function') {
            window.stoInitBackgroundControls($root);
        }

        if (typeof window.stoSyncThemeSettings === 'function') {
            var defaultLeaf = $root.attr('data-sto-default-leaf') || '';
            var menuPage = $root.attr('data-sto-menu-page') || '';
            if (defaultLeaf && menuPage && window.battery_simple_theme_options && window.battery_simple_theme_options.sto_nav) {
                var adminBase = window.battery_simple_theme_options.sto_nav.admin_url || (window.location.origin + '/wp-admin/');
                var url = adminBase + 'admin.php?page=' + encodeURIComponent(menuPage) + '&section=' + encodeURIComponent(defaultLeaf);
                window.stoSyncThemeSettings(url);
            }
        }

        if ($form.length) {
            $form.on('submit', function (submitEvent) {
                submitEvent.preventDefault();
            });
        }
    }

    wp.customize.bind('ready', function () {
        var saving = false;

        function notify(type, message) {
            if (!wp.customize || !wp.customize.notifications) {
                return;
            }
            wp.customize.notifications.add(
                new wp.customize.Notification('sto_theme_settings_' + type + '_' + Date.now(), {
                    message: message,
                    type: type,
                    dismissible: true
                })
            );
        }

        function runSave() {
            if (!hasCustomizerSaveBridge || saving || !$embedRoot().length) {
                return;
            }
            saving = true;
            saveCustomizerSection()
                .done(function () {
                    notify('success', stoCustomizer.i18n.saved);
                })
                .fail(function (err) {
                    notify('error', (err && err.message) ? err.message : stoCustomizer.i18n.save_failed);
                })
                .always(function () {
                    saving = false;
                });
        }

        $('#customize-header-actions').on('click', '#save, #publish-settings', function () {
            runSave();
        });

        wp.customize.section('sto_theme_settings_embed', function (section) {
            if (!section) {
                return;
            }
            section.expanded.bind(function (expanded) {
                if (!expanded) {
                    return;
                }
                initEmbedUi();
                window.setTimeout(initEmbedUi, 50);
                window.setTimeout(initEmbedUi, 300);
                window.setTimeout(initEmbedUi, 800);
                window.setTimeout(initEmbedUi, 1500);
            });
            if (section.expanded()) {
                initEmbedUi();
            }
        });

        window.setTimeout(initEmbedUi, 100);
        window.setTimeout(initEmbedUi, 500);
        window.setTimeout(initEmbedUi, 1200);

        if (wp.customize.state('expandedSection') && wp.customize.state('expandedSection').get() === 'sto_theme_settings_embed') {
            initEmbedUi();
        }
    });
}(jQuery, window.wp));
