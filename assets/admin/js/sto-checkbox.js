/**
 * Checkbox control: single (hidden + tile) and multi (sr-only native + tiles).
 */
(function ($) {
    'use strict';

    function syncSingleTile($btn) {
        var targetId = $btn.attr('data-sto-checkbox-for') || '';
        if (!targetId) {
            return;
        }
        var $hidden = $('#' + targetId);
        if (!$hidden.length) {
            return;
        }
        var on = String($hidden.val() || '') === '1';
        var onLab = $btn.attr('data-sto-label-on') || '';
        var offLab = $btn.attr('data-sto-label-off') || '';
        $btn.toggleClass('sto-checkbox-tile--checked', on);
        $btn.attr('aria-checked', on ? 'true' : 'false');
        $btn.find('.sto-checkbox-tile__caption').text(on ? onLab : offLab);
    }

    function syncMultiTile($btn) {
        var nid = $btn.attr('data-sto-checkbox-native') || '';
        if (!nid) {
            return;
        }
        var $n = $('#' + nid);
        if (!$n.length) {
            return;
        }
        var on = !!$n.prop('checked');
        $btn.toggleClass('sto-checkbox-tile--checked', on);
        $btn.attr('aria-checked', on ? 'true' : 'false');
    }

    function maxForStack($stack) {
        var $row = $stack.closest('[data-sto-checkbox-control]');
        var m = parseInt($row.attr('data-sto-checkbox-max'), 10);
        if (!m || m < 1) {
            return 0;
        }
        return m;
    }

    function bindSingle($root) {
        $root.find('[data-sto-checkbox-single]').each(function () {
            var $btn = $(this);
            if ($btn.data('stoCbSingle')) {
                return;
            }
            $btn.data('stoCbSingle', 1);
            syncSingleTile($btn);
            $btn.on('click', function (e) {
                e.preventDefault();
                var id = $btn.attr('data-sto-checkbox-for') || '';
                var $h = id ? $('#' + id) : $();
                if (!$h.length) {
                    return;
                }
                $h.val(String($h.val() || '') === '1' ? '0' : '1');
                syncSingleTile($btn);
                $h.trigger('change');
            });
            $btn.on('keydown', function (e) {
                if (e.key === ' ' || e.key === 'Enter') {
                    e.preventDefault();
                    $btn.trigger('click');
                }
            });
        });
    }

    function bindMulti($root) {
        $root.find('[data-sto-checkbox-multi]').each(function () {
            var $btn = $(this);
            if ($btn.data('stoCbMulti')) {
                return;
            }
            $btn.data('stoCbMulti', 1);
            syncMultiTile($btn);
            var nid = $btn.attr('data-sto-checkbox-native') || '';
            var $n = nid ? $('#' + nid) : $();
            if ($n.length) {
                $n.on('change', function () {
                    syncMultiTile($btn);
                });
            }
            $btn.on('click', function (e) {
                e.preventDefault();
                if (!$n.length) {
                    return;
                }
                var want = !$n.prop('checked');
                if (want) {
                    var $stack = $btn.closest('.sto-checkbox-stack');
                    var max = maxForStack($stack);
                    if (max > 0) {
                        var checkedCount = $stack.find('.sto-checkbox-native:checked').length;
                        if (!$n.prop('checked') && checkedCount >= max) {
                            return;
                        }
                    }
                }
                $n.prop('checked', want).trigger('change');
                syncMultiTile($btn);
            });
            $btn.on('keydown', function (e) {
                if (e.key === ' ' || e.key === 'Enter') {
                    e.preventDefault();
                    $btn.trigger('click');
                }
            });
        });
    }

    window.stoInitCheckboxControls = function ($panel) {
        var $scope = $panel && $panel.length ? $panel : $('.sto-option-panel-section.sto-is-active');
        if (!$scope.length) {
            $scope = $(document);
        }
        bindSingle($scope);
        bindMulti($scope);
    };
})(jQuery);
