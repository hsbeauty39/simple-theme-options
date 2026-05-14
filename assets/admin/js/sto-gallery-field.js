/**
 * Gallery: wp.media multi-select, thumbnails, reorder (sortable), hidden JSON (sto_options).
 */
(function($) {
    'use strict';

    function parseI18n($wrap) {
        var raw = $wrap.attr('data-sto-gallery-i18n') || '{}';
        try {
            var o = JSON.parse(raw);
            return o && typeof o === 'object' ? o : {};
        } catch (e) {
            return {};
        }
    }

    function parseIds($hidden) {
        var raw = String($hidden.val() || '').trim();
        if (!raw) {
            return [];
        }
        try {
            var arr = JSON.parse(raw);
            if (!Array.isArray(arr)) {
                return [];
            }
            return arr.map(function(x) {
                return String(x != null ? x : '').trim();
            }).filter(Boolean);
        } catch (e2) {
            return [];
        }
    }

    function writeIds($wrap, ids) {
        var seen = {};
        var out = [];
        for (var i = 0; i < ids.length; i++) {
            var k = String(ids[i]).trim();
            if (!k || seen[k]) {
                continue;
            }
            seen[k] = 1;
            out.push(k);
        }
        var max = parseInt($wrap.attr('data-sto-gallery-max') || '0', 10);
        if (max > 0 && out.length > max) {
            out = out.slice(0, max);
        }
        var $hidden = $wrap.find('.sto-gallery-value').first();
        $hidden.val(JSON.stringify(out)).trigger('change');
        syncDomFromIds($wrap, out);
    }

    function updateCount($wrap, n) {
        var i18n = parseI18n($wrap);
        var $c = $wrap.find('[data-sto-gallery-count]').first();
        if (!$c.length) {
            return;
        }
        var empty = i18n.empty != null ? String(i18n.empty) : 'No images selected';
        var one = i18n.count != null ? String(i18n.count) : '%d image selected';
        var many = i18n.countPlural != null ? String(i18n.countPlural) : '%d images selected';
        if (n < 1) {
            $c.text(empty);
        } else if (n === 1) {
            $c.text(one.replace('%d', '1'));
        } else {
            $c.text(many.replace('%d', String(n)));
        }
        var $clr = $wrap.find('[data-sto-gallery-clear]').first();
        if (n > 0) {
            $clr.removeAttr('hidden');
        } else {
            $clr.attr('hidden', 'hidden');
        }
    }

    function syncDomFromIds($wrap, ids) {
        var $list = $wrap.find('[data-sto-gallery-list]').first();
        var $addSlot = $list.find('.sto-gallery__slot--add').first();
        $list.find('.sto-gallery__slot--thumb').remove();
        for (var i = 0; i < ids.length; i++) {
            var id = ids[i];
            var $li = $(
                '<li class="sto-gallery__slot sto-gallery__slot--thumb" data-sto-gallery-item="' +
                    String(id).replace(/"/g, '') +
                    '">' +
                    '<span class="sto-gallery__thumb-wrap"><img src="" alt="" class="sto-gallery__thumb" width="80" height="80" loading="lazy" decoding="async" /></span>' +
                    '<button type="button" class="sto-gallery__remove" data-sto-gallery-remove aria-label=""><i class="fa-light fa-xmark" aria-hidden="true"></i></button>' +
                    '</li>'
            );
            var i18n = parseI18n($wrap);
            $li.find('.sto-gallery__remove').attr('aria-label', i18n.removeOne != null ? String(i18n.removeOne) : 'Remove');
            $addSlot.after($li);
            fetchThumb($li, id);
        }
        updateCount($wrap, ids.length);
    }

    function fetchThumb($li, id) {
        if (typeof wp === 'undefined' || !wp.media || !wp.media.attachment) {
            return;
        }
        var att = wp.media.attachment(parseInt(id, 10));
        if (!att || !att.fetch) {
            return;
        }
        att.fetch().done(function() {
            var json = att.toJSON();
            var url =
                (json.sizes && json.sizes.thumbnail && json.sizes.thumbnail.url) ||
                json.url ||
                '';
            if (url) {
                $li.find('.sto-gallery__thumb').attr('src', url);
            }
        });
    }

    function bindSortable($wrap) {
        var $list = $wrap.find('[data-sto-gallery-list]').first();
        if ($list.data('stoGallerySortable')) {
            return;
        }
        if (!$.fn.sortable) {
            return;
        }
        $list.data('stoGallerySortable', 1);
        $list.sortable({
            items: '> .sto-gallery__slot--thumb',
            tolerance: 'pointer',
            cursor: 'grabbing',
            placeholder: 'sto-gallery__slot sto-gallery__slot--placeholder',
            forcePlaceholderSize: true,
            update: function() {
                var ids = [];
                $list.find('.sto-gallery__slot--thumb').each(function() {
                    var id = $(this).attr('data-sto-gallery-item') || '';
                    if (id) {
                        ids.push(id);
                    }
                });
                var $hidden = $wrap.find('.sto-gallery-value').first();
                $hidden.val(JSON.stringify(ids)).trigger('change');
                updateCount($wrap, ids.length);
            }
        });
    }

    function openFrame($wrap) {
        if (typeof wp === 'undefined' || !wp.media) {
            return;
        }
        var i18n = parseI18n($wrap);
        var $hidden = $wrap.find('.sto-gallery-value').first();
        var cur = parseIds($hidden);
        var max = parseInt($wrap.attr('data-sto-gallery-max') || '0', 10);

        var frame = wp.media({
            title: i18n.frameTitle != null ? String(i18n.frameTitle) : '',
            library: { type: 'image' },
            button: { text: i18n.frameButton != null ? String(i18n.frameButton) : 'Add' },
            multiple: true
        });

        frame.on('select', function() {
            var sel = frame.state().get('selection');
            var next = cur.slice();
            sel.each(function(att) {
                var json = att.toJSON();
                var id = json.id ? String(parseInt(json.id, 10)) : '';
                if (!id) {
                    return;
                }
                if (next.indexOf(id) !== -1) {
                    return;
                }
                if (max > 0 && next.length >= max) {
                    return;
                }
                next.push(id);
            });
            writeIds($wrap, next);
            if (typeof window.stoApplyDependentFieldVisibility === 'function') {
                window.stoApplyDependentFieldVisibility();
            }
        });

        frame.open();
    }

    function bindOne($wrap) {
        if ($wrap.data('stoGalleryBound')) {
            return;
        }
        $wrap.data('stoGalleryBound', 1);

        $wrap.on('click', '[data-sto-gallery-add]', function(ev) {
            ev.preventDefault();
            openFrame($wrap);
        });

        $wrap.on('click', '[data-sto-gallery-clear]', function(ev) {
            ev.preventDefault();
            writeIds($wrap, []);
            if (typeof window.stoApplyDependentFieldVisibility === 'function') {
                window.stoApplyDependentFieldVisibility();
            }
        });

        $wrap.on('click', '[data-sto-gallery-remove]', function(ev) {
            ev.preventDefault();
            var $li = $(this).closest('.sto-gallery__slot--thumb');
            var rid = $li.attr('data-sto-gallery-item') || '';
            var $hidden = $wrap.find('.sto-gallery-value').first();
            var ids = parseIds($hidden).filter(function(x) {
                return String(x) !== String(rid);
            });
            writeIds($wrap, ids);
            if (typeof window.stoApplyDependentFieldVisibility === 'function') {
                window.stoApplyDependentFieldVisibility();
            }
        });

        bindSortable($wrap);
    }

    window.stoInitGalleryFields = function($scope) {
        var $ctx = $scope && $scope.length ? $scope : $(document);
        $ctx.find('.sto-gallery[data-sto-gallery="1"]').each(function() {
            bindOne($(this));
        });
    };
})(jQuery);
