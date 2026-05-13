/**
 * Group tab panels: switch panes, re-run Theme Settings inits for controls in the visible pane.
 * Responsive Tabs: a single "master" tab strip in the title row syncs Slot A/B; each inner field in the grid has its own breakpoint toolbar.
 */
(function($) {
    'use strict';

    function activateTab($row, $btn) {
        var targetId = $btn.attr('data-sto-tabs-target');
        if (!targetId) {
            return;
        }
        var el = document.getElementById(targetId);
        var $pane = el ? $(el) : $();
        if (!$pane.length) {
            return;
        }

        $row.find('.sto-tabs__tab').removeClass('sto-is-active').attr('aria-selected', 'false').attr('tabindex', '-1');
        $btn.addClass('sto-is-active').attr('aria-selected', 'true').attr('tabindex', '0');

        $row.find('.sto-tabs__pane').each(function() {
            $(this).prop('hidden', true);
        });
        $pane.prop('hidden', false);

        if (typeof window.refreshStoSelect2 === 'function') {
            window.refreshStoSelect2();
        }
    }

    /**
     * @param {JQuery} $outer .sto-field-row-tabs[data-sto-tabs-master]
     * @param {JQuery} $btn    Master tab button (.sto-tabs__tab--master-sync)
     */
    function activateMasterTab($outer, $btn) {
        var paneKey = $btn.attr('data-sto-sync-tab') || '';
        if (!paneKey) {
            return;
        }

        $outer.find('.sto-tabs__toolbar--sto-master .sto-tabs__tab').removeClass('sto-is-active').attr('aria-selected', 'false').attr('tabindex', '-1');
        $btn.addClass('sto-is-active').attr('aria-selected', 'true').attr('tabindex', '0');

        $outer.children('.sto-tabs').each(function() {
            var $tabsRoot = $(this);
            var $target = $tabsRoot.find('.sto-tabs__pane[data-sto-tabs-pane="' + paneKey + '"]');
            if (!$target.length) {
                return;
            }
            $tabsRoot.find('.sto-tabs__pane').each(function() {
                $(this).prop('hidden', true);
            });
            $target.prop('hidden', false);
        });

        if (typeof window.refreshStoSelect2 === 'function') {
            window.refreshStoSelect2();
        }
    }

    function bindOne($row) {
        if ($row.data('stoTabsBound')) {
            return;
        }
        $row.data('stoTabsBound', 1);

        $row.on('click', '.sto-tabs__tab', function(ev) {
            if ($(this).closest('.sto-tabs__toolbar--sto-master').length) {
                return;
            }
            ev.preventDefault();
            activateTab($row, $(this));
        });

        $row.on('keydown', '.sto-tabs__tab', function(ev) {
            if ($(this).closest('.sto-tabs__toolbar--sto-master').length) {
                return;
            }
            var key = ev.key || ev.keyCode;
            if (key !== 'ArrowRight' && key !== 39 && key !== 'ArrowLeft' && key !== 37 && key !== 'Home' && key !== 36 && key !== 'End' && key !== 35) {
                return;
            }
            var $tabs = $row.find('.sto-tabs__tab').not('.sto-tabs__tab--master-sync');
            var idx = $tabs.index(this);
            if (idx < 0) {
                return;
            }
            ev.preventDefault();
            var next = idx;
            if (key === 'ArrowRight' || key === 39) {
                next = (idx + 1) % $tabs.length;
            } else if (key === 'ArrowLeft' || key === 37) {
                next = (idx - 1 + $tabs.length) % $tabs.length;
            } else if (key === 'Home' || key === 36) {
                next = 0;
            } else if (key === 'End' || key === 35) {
                next = $tabs.length - 1;
            }
            $tabs.eq(next).trigger('focus').trigger('click');
        });
    }

    function bindMaster($outer) {
        if ($outer.data('stoTabsMasterBound')) {
            return;
        }
        $outer.data('stoTabsMasterBound', 1);

        $outer.on('click', '.sto-tabs__toolbar--sto-master .sto-tabs__tab', function(ev) {
            ev.preventDefault();
            activateMasterTab($outer, $(this));
        });

        $outer.on('keydown', '.sto-tabs__toolbar--sto-master .sto-tabs__tab', function(ev) {
            var key = ev.key || ev.keyCode;
            if (key !== 'ArrowRight' && key !== 39 && key !== 'ArrowLeft' && key !== 37 && key !== 'Home' && key !== 36 && key !== 'End' && key !== 35) {
                return;
            }
            var $tabs = $outer.find('.sto-tabs__toolbar--sto-master .sto-tabs__tab');
            var idx = $tabs.index(this);
            if (idx < 0) {
                return;
            }
            ev.preventDefault();
            var next = idx;
            if (key === 'ArrowRight' || key === 39) {
                next = (idx + 1) % $tabs.length;
            } else if (key === 'ArrowLeft' || key === 37) {
                next = (idx - 1 + $tabs.length) % $tabs.length;
            } else if (key === 'Home' || key === 36) {
                next = 0;
            } else if (key === 'End' || key === 35) {
                next = $tabs.length - 1;
            }
            $tabs.eq(next).trigger('focus').trigger('click');
        });
    }

    window.stoInitTabsControls = function($scope) {
        var $root = $scope && $scope.length ? $scope : $(document);
        $root.find('.sto-field-row-tabs[data-sto-tabs]').each(function() {
            bindOne($(this));
        });
        $root.find('.sto-field-row-tabs[data-sto-tabs-master="1"]').each(function() {
            bindMaster($(this));
        });
    };

    $(function() {
        if (typeof window.stoInitTabsControls === 'function') {
            window.stoInitTabsControls($('.sto-options-form'));
        }
    });
})(jQuery);
