/**
 * Icon select: preview + modal library (Font Awesome from JSON manifest + WordPress Dashicons from page config).
 */
(function($) {
    'use strict';

    var manifestPromise = null;

    function getManifestUrl() {
        if (window.stoIconSelectField && window.stoIconSelectField.manifestUrl) {
            return String(window.stoIconSelectField.manifestUrl);
        }
        return '';
    }

    function getDashiconsFromPage() {
        if (window.stoIconSelectField && Array.isArray(window.stoIconSelectField.dashicons)) {
            return window.stoIconSelectField.dashicons;
        }
        return [];
    }

    function loadIcons() {
        if (manifestPromise) {
            return manifestPromise;
        }
        var url = getManifestUrl();
        if (!url) {
            manifestPromise = $.Deferred().resolve(getDashiconsFromPage()).promise();
            return manifestPromise;
        }
        manifestPromise = $.ajax({ url: url, dataType: 'json' }).then(function(data) {
            var icons = data && data.icons && Array.isArray(data.icons) ? data.icons : [];
            return icons.concat(getDashiconsFromPage());
        }, function() {
            return getDashiconsFromPage();
        });
        return manifestPromise;
    }

    function escAttr(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    function escHtml(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    function isDashiconsClass(classStr) {
        var s = String(classStr || '').trim();
        return s !== '' && /^dashicons(\s+dashicons-[a-z0-9-]+)+$/i.test(s);
    }

    function buildTiles($grid, icons) {
        var html = '';
        var i;
        for (i = 0; i < icons.length; i++) {
            var row = icons[i];
            if (!row || !row.c || !row.n) {
                continue;
            }
            var g = row.g ? String(row.g) : 'solid';
            var gLower = g.toLowerCase();
            html += '<button type="button" class="sto-icon-select__tile" data-sto-icon-class="' + escAttr(row.c) + '" data-sto-icon-name="' + escAttr(row.n) + '" data-sto-icon-group="' + escAttr(gLower) + '" title="' + escAttr(row.n) + '">';
            html += '<span class="sto-icon-select__tile-icon">';
            if (gLower === 'wordpress') {
                html += '<span class="' + escAttr(row.c) + '" aria-hidden="true"></span>';
            } else {
                html += '<i class="' + escAttr(row.c) + '" aria-hidden="true"></i>';
            }
            html += '</span>';
            html += '<span class="sto-icon-select__tile-label">' + escHtml(row.n) + '</span>';
            html += '</button>';
        }
        $grid.html(html);
    }

    function applyFilter($root) {
        var $grid = $root.find('[data-sto-icon-select-grid]');
        var filter = ($root.data('stoFilterGroup') || 'all').toString().toLowerCase();
        var q = ($root.data('stoFilterQ') || '').toString().toLowerCase().trim();
        $grid.find('.sto-icon-select__tile').each(function() {
            var $t = $(this);
            var g = ($t.attr('data-sto-icon-group') || '').toLowerCase();
            var n = ($t.attr('data-sto-icon-name') || '').toLowerCase();
            var okG = filter === 'all' || g === filter;
            var okQ = !q || n.indexOf(q) !== -1;
            $t.toggle(okG && okQ);
        });
    }

    function syncPreview($root, classStr) {
        var $prev = $root.find('[data-sto-icon-select-preview]');
        var $hidden = $root.find('.sto-icon-select-value');
        $hidden.val(classStr);
        if (classStr) {
            if (isDashiconsClass(classStr)) {
                $prev.html('<span class="' + escAttr(classStr) + '" aria-hidden="true"></span>');
            } else {
                $prev.html('<i class="' + escAttr(classStr) + '" aria-hidden="true"></i>');
            }
        } else {
            var ph = (window.stoIconSelectField && window.stoIconSelectField.i18n && window.stoIconSelectField.i18n.noIcon) || 'No icon';
            $prev.html('<span class="sto-icon-select__placeholder">' + escHtml(ph) + '</span>');
        }
    }

    function setInsertEnabled($root, on) {
        $root.find('[data-sto-icon-select-insert]').prop('disabled', !on);
    }

    function setActiveTile($root, classStr) {
        var $tiles = $root.find('.sto-icon-select__tile');
        $tiles.removeClass('sto-is-active');
        if (!classStr) {
            return;
        }
        $tiles.each(function() {
            var $t = $(this);
            if (($t.attr('data-sto-icon-class') || '') === classStr) {
                $t.addClass('sto-is-active');
            }
        });
    }

    function openModal($root) {
        var $modal = $root.find('[data-sto-icon-select-modal]');
        var $btn = $root.find('[data-sto-icon-select-open]');
        $modal.prop('hidden', false);
        $btn.attr('aria-expanded', 'true');
        var cur = $root.find('.sto-icon-select-value').val() || '';
        $root.data('stoPendingClass', cur);
        setInsertEnabled($root, !!cur);
        setActiveTile($root, cur);
        $root.find('[data-sto-icon-select-q]').val('');
        $root.data('stoFilterQ', '');
        $root.find('[data-sto-icon-filter]').removeClass('sto-is-active');
        $root.find('[data-sto-icon-filter][data-sto-icon-filter="all"]').addClass('sto-is-active');
        $root.data('stoFilterGroup', 'all');
        loadIcons().then(function(icons) {
            var $grid = $root.find('[data-sto-icon-select-grid]');
            if ($grid.children().length === 0 && icons.length) {
                buildTiles($grid, icons);
            }
            applyFilter($root);
            setActiveTile($root, $root.data('stoPendingClass') || '');
            var $q = $root.find('[data-sto-icon-select-q]');
            window.setTimeout(function() {
                $q.trigger('focus');
            }, 50);
        });
    }

    function closeModal($root) {
        var $modal = $root.find('[data-sto-icon-select-modal]');
        var $btn = $root.find('[data-sto-icon-select-open]');
        $modal.prop('hidden', true);
        $btn.attr('aria-expanded', 'false');
        $root.data('stoPendingClass', null);
    }

    function bindOne($root) {
        if ($root.data('stoIconSelectBound')) {
            return;
        }
        $root.data('stoIconSelectBound', 1);
        $root.data('stoFilterGroup', 'all');
        $root.data('stoFilterQ', '');

        $root.on('click', '[data-sto-icon-select-open]', function(ev) {
            ev.preventDefault();
            openModal($root);
        });

        $root.on('click', '[data-sto-icon-select-close]', function(ev) {
            ev.preventDefault();
            closeModal($root);
        });

        $root.on('click', '[data-sto-icon-select-clear]', function(ev) {
            ev.preventDefault();
            syncPreview($root, '');
            closeModal($root);
        });

        $root.on('click', '[data-sto-icon-filter]', function(ev) {
            ev.preventDefault();
            var $b = $(this);
            $root.find('[data-sto-icon-filter]').removeClass('sto-is-active');
            $b.addClass('sto-is-active');
            $root.data('stoFilterGroup', ($b.attr('data-sto-icon-filter') || 'all').toLowerCase());
            applyFilter($root);
        });

        $root.on('input', '[data-sto-icon-select-q]', function() {
            $root.data('stoFilterQ', $(this).val() || '');
            applyFilter($root);
        });

        $root.on('click', '.sto-icon-select__tile', function(ev) {
            ev.preventDefault();
            var cl = $(this).attr('data-sto-icon-class') || '';
            $root.find('.sto-icon-select__tile').removeClass('sto-is-active');
            $(this).addClass('sto-is-active');
            $root.data('stoPendingClass', cl);
            setInsertEnabled($root, !!cl);
        });

        $root.on('click', '[data-sto-icon-select-insert]', function(ev) {
            ev.preventDefault();
            var cl = $root.data('stoPendingClass');
            if (!cl) {
                return;
            }
            syncPreview($root, cl);
            closeModal($root);
        });

        $root.on('keydown', function(ev) {
            if (ev.key !== 'Escape') {
                return;
            }
            var $modal = $root.find('[data-sto-icon-select-modal]');
            if (!$modal.length || $modal.prop('hidden')) {
                return;
            }
            ev.stopPropagation();
            closeModal($root);
            $root.find('[data-sto-icon-select-open]').trigger('focus');
        });
    }

    window.stoInitIconSelectFields = function($scope) {
        var $root = $scope && $scope.length ? $scope : $(document);
        $root.find('.sto-icon-select[data-sto-icon-select]').each(function() {
            bindOne($(this));
        });
    };

    $(function() {
        if (typeof window.stoInitIconSelectFields === 'function') {
            window.stoInitIconSelectFields($('.sto-options-form'));
        }
    });
})(jQuery);
