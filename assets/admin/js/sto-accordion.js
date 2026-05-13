/**
 * Accordion: one section open at a time (optional); responsive rows use a master strip (same idea as Tabs master).
 * Clicking the active header again collapses all panels. Default PHP markup may start one panel open via expanded/show/open.
 */
(function($) {
    'use strict';

    function setItemExpanded($item, $header, $region, expanded) {
        $header.toggleClass('sto-is-active', expanded);
        $header.attr('aria-expanded', expanded ? 'true' : 'false');
        if (expanded) {
            $region.removeAttr('hidden');
        } else {
            $region.attr('hidden', 'hidden');
        }
    }

    function activatePanelInRoot($root, paneKey) {
        if (!$root.length) {
            return;
        }
        var want = paneKey || '';
        $root.find('.sto-accordion__item').each(function() {
            var $item = $(this);
            var key = $item.attr('data-sto-accordion-item') || '';
            var $h = $item.find('.sto-accordion__header').first();
            var rid = $h.attr('aria-controls') || '';
            var $region = rid ? $(document.getElementById(rid)) : $();
            if (!$region.length) {
                return;
            }
            setItemExpanded($item, $h, $region, want !== '' && key === want);
        });
    }

    function clearMasterStrip($row) {
        $row.find('.sto-accordion__toolbar--sto-master .sto-accordion__master-tab').removeClass('sto-is-active').attr('aria-selected', 'false').attr('tabindex', '-1');
        var $first = $row.find('.sto-accordion__toolbar--sto-master .sto-accordion__master-tab').first();
        if ($first.length) {
            $first.attr('tabindex', '0');
        }
    }

    function refreshInits($ctx) {
        if (typeof window.refreshStoSelect2 === 'function') {
            window.refreshStoSelect2();
        }
    }

    function activateByHeader($row, $btn) {
        var $host = $btn.closest('.sto-field-row-accordion[data-sto-accordion], .sto-field-row-accordion[data-sto-accordion-master="1"]');
        if (!$host.length || !$host.is($row)) {
            return;
        }

        var targetId = $btn.attr('data-sto-accordion-target') || '';
        if (!targetId) {
            return;
        }
        var id = targetId.charAt(0) === '#' ? targetId.slice(1) : targetId;
        var el = document.getElementById(id);
        var $region = el ? $(el) : $();
        if (!$region.length) {
            return;
        }
        var $item = $btn.closest('.sto-accordion__item');
        var $root = $btn.closest('.sto-accordion');
        var paneKey = $item.attr('data-sto-accordion-item') || '';

        if ($btn.hasClass('sto-is-active')) {
            $root.find('.sto-accordion__item').each(function() {
                var $it = $(this);
                var $h = $it.find('.sto-accordion__header').first();
                var rc = $h.attr('aria-controls') || '';
                var $reg = rc ? $(document.getElementById(rc)) : $();
                if (!$reg.length) {
                    return;
                }
                setItemExpanded($it, $h, $reg, false);
            });
            if ($row.attr('data-sto-accordion-master') === '1') {
                clearMasterStrip($row);
            }
            refreshInits($row);
            return;
        }

        $root.find('.sto-accordion__item').each(function() {
            var $it = $(this);
            var $h = $it.find('.sto-accordion__header').first();
            var rc = $h.attr('aria-controls') || '';
            var $reg = rc ? $(document.getElementById(rc)) : $();
            if (!$reg.length) {
                return;
            }
            var isThis = $it.is($item);
            setItemExpanded($it, $h, $reg, isThis);
        });

        if ($row.attr('data-sto-accordion-master') === '1' && paneKey) {
            $row.find('.sto-accordion__toolbar--sto-master .sto-accordion__master-tab').removeClass('sto-is-active').attr('aria-selected', 'false').attr('tabindex', '-1');
            var $sync = $row.find('.sto-accordion__toolbar--sto-master .sto-accordion__master-tab[data-sto-sync-accordion-pane="' + paneKey + '"]');
            if ($sync.length) {
                $sync.addClass('sto-is-active').attr('aria-selected', 'true').attr('tabindex', '0');
            }
        }

        refreshInits($row);
    }

    function activateMaster($outer, $btn) {
        var paneKey = $btn.attr('data-sto-sync-accordion-pane') || '';
        if (!paneKey) {
            return;
        }

        if ($btn.hasClass('sto-is-active')) {
            $outer.find('.sto-accordion__toolbar--sto-master .sto-accordion__master-tab').removeClass('sto-is-active').attr('aria-selected', 'false').attr('tabindex', '-1');
            var $mf = $outer.find('.sto-accordion__toolbar--sto-master .sto-accordion__master-tab').first();
            if ($mf.length) {
                $mf.attr('tabindex', '0');
            }
            $outer.children('.sto-accordion').each(function() {
                activatePanelInRoot($(this), '');
            });
            refreshInits($outer);
            return;
        }

        $outer.find('.sto-accordion__toolbar--sto-master .sto-accordion__master-tab').removeClass('sto-is-active').attr('aria-selected', 'false').attr('tabindex', '-1');
        $btn.addClass('sto-is-active').attr('aria-selected', 'true').attr('tabindex', '0');

        $outer.children('.sto-accordion').each(function() {
            activatePanelInRoot($(this), paneKey);
        });

        refreshInits($outer);
    }

    function bindOne($row) {
        if ($row.data('stoAccordionBound')) {
            return;
        }
        $row.data('stoAccordionBound', 1);

        $row.on('click', '.sto-accordion__header', function(ev) {
            if ($(this).closest('.sto-accordion__toolbar--sto-master').length) {
                return;
            }
            ev.preventDefault();
            activateByHeader($row, $(this));
        });
    }

    function bindMaster($outer) {
        if ($outer.data('stoAccordionMasterBound')) {
            return;
        }
        $outer.data('stoAccordionMasterBound', 1);

        $outer.on('click', '.sto-accordion__toolbar--sto-master .sto-accordion__master-tab', function(ev) {
            ev.preventDefault();
            activateMaster($outer, $(this));
        });

        $outer.on('keydown', '.sto-accordion__toolbar--sto-master .sto-accordion__master-tab', function(ev) {
            var key = ev.key || ev.keyCode;
            if (key !== 'ArrowRight' && key !== 39 && key !== 'ArrowLeft' && key !== 37 && key !== 'Home' && key !== 36 && key !== 'End' && key !== 35) {
                return;
            }
            var $tabs = $outer.find('.sto-accordion__toolbar--sto-master .sto-accordion__master-tab');
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

    window.stoInitAccordionControls = function($scope) {
        var $root = $scope && $scope.length ? $scope : $(document);
        $root.find('.sto-field-row-accordion[data-sto-accordion]').each(function() {
            bindOne($(this));
        });
        $root.find('.sto-field-row-accordion[data-sto-accordion-master="1"]').each(function() {
            bindMaster($(this));
            bindOne($(this));
        });
    };

    $(function() {
        if (typeof window.stoInitAccordionControls === 'function') {
            window.stoInitAccordionControls($('.sto-options-form'));
        }
    });
})(jQuery);
