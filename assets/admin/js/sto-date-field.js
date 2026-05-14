/**
 * Date fields: jQuery UI Datepicker + hidden ISO (Y-m-d) for Theme Settings.
 */
(function($) {
    'use strict';

    function parseYmd(s) {
        s = (s || '').trim();
        if (!/^\d{4}-\d{2}-\d{2}$/.test(s)) {
            return null;
        }
        var p = s.split('-');
        var y = parseInt(p[0], 10);
        var m = parseInt(p[1], 10) - 1;
        var d = parseInt(p[2], 10);
        var dt = new Date(y, m, d);
        if (dt.getFullYear() !== y || dt.getMonth() !== m || dt.getDate() !== d) {
            return null;
        }
        return dt;
    }

    function ymdFromDate(dt) {
        if (!dt || !(dt instanceof Date) || isNaN(dt.getTime())) {
            return '';
        }
        var y = dt.getFullYear();
        var m = dt.getMonth() + 1;
        var d = dt.getDate();
        return y + '-' + (m < 10 ? '0' : '') + m + '-' + (d < 10 ? '0' : '') + d;
    }

    function syncDisplay($wrap) {
        var $hidden = $wrap.find('.sto-date-field__value');
        var $disp = $wrap.find('.sto-date-field__display');
        var iso = ($hidden.val() || '').trim();
        var dt = parseYmd(iso);
        if ($disp.data('datepicker')) {
            try {
                if (dt) {
                    $disp.datepicker('setDate', dt);
                } else {
                    $disp.datepicker('setDate', null);
                }
            } catch (e) {
                $disp.val('');
            }
        } else {
            $disp.val('');
        }
    }

    function parseBound($wrap, attr) {
        var raw = $wrap.attr(attr);
        if (!raw) {
            return null;
        }
        return parseYmd(String(raw));
    }

    function bindOne($wrap) {
        var $hidden = $wrap.find('.sto-date-field__value');
        var $disp = $wrap.find('.sto-date-field__display');
        var $clear = $wrap.find('.sto-date-field__clear');

        if (typeof $.fn.datepicker !== 'function') {
            return;
        }

        var minD = parseBound($wrap, 'data-sto-date-min');
        var maxD = parseBound($wrap, 'data-sto-date-max');

        var yearRange = 'c-30:c+15';
        if (minD && maxD) {
            yearRange = minD.getFullYear() + ':' + maxD.getFullYear();
        } else if (minD) {
            yearRange = minD.getFullYear() + ':c+30';
        } else if (maxD) {
            yearRange = 'c-30:' + maxD.getFullYear();
        }

        $disp.datepicker({
            changeMonth: true,
            changeYear: true,
            showAnim: '',
            yearRange: yearRange,
            minDate: minD,
            maxDate: maxD,
            altField: $hidden,
            altFormat: 'yy-mm-dd',
            beforeShow: function() {
                $('#ui-datepicker-div').addClass('sto-datepicker--sto');
            },
            onClose: function() {
                $('#ui-datepicker-div').removeClass('sto-datepicker--sto');
            },
            onSelect: function() {
                $hidden.trigger('change');
            }
        });

        syncDisplay($wrap);

        $disp.on('keydown', function(e) {
            if (e.key === 'Enter' || e.keyCode === 13) {
                e.preventDefault();
                $disp.datepicker('show');
            }
        });

        $clear.on('click', function(ev) {
            ev.preventDefault();
            $hidden.val('');
            if ($disp.data('datepicker')) {
                $disp.datepicker('setDate', null);
            }
            $disp.val('');
            $hidden.trigger('change');
        });
    }

    window.stoInitDateFields = function($scope) {
        var $root = $scope && $scope.length ? $scope : $(document);
        $root.find('.sto-date-field[data-sto-date]').each(function() {
            var $wrap = $(this);
            if ($wrap.data('stoDateBound')) {
                syncDisplay($wrap);
                return;
            }
            if (typeof $.fn.datepicker !== 'function') {
                return;
            }
            $wrap.data('stoDateBound', 1);
            bindOne($wrap);
        });
    };

    $(function() {
        if (typeof window.stoInitDateFields === 'function') {
            window.stoInitDateFields($('.sto-options-form'));
        }
    });
})(jQuery);
