/**
 * Date + time fields: jQuery UI Datepicker + native time input → hidden Y-m-d H:i.
 */
(function($) {
    'use strict';

    function pad2(n) {
        return n < 10 ? '0' + n : String(n);
    }

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

    function formatYmd(dt) {
        if (!dt || !(dt instanceof Date) || isNaN(dt.getTime())) {
            return '';
        }
        return dt.getFullYear() + '-' + pad2(dt.getMonth() + 1) + '-' + pad2(dt.getDate());
    }

    function parseTimeVal(s) {
        s = (s || '').trim();
        if (!s) {
            return { h: 0, m: 0 };
        }
        var p = s.split(':');
        var h = parseInt(p[0], 10);
        var m = parseInt(p[1] !== undefined && p[1] !== '' ? p[1] : '0', 10);
        if (isNaN(h) || isNaN(m)) {
            return { h: 0, m: 0 };
        }
        h = Math.max(0, Math.min(23, h));
        m = Math.max(0, Math.min(59, m));
        return { h: h, m: m };
    }

    function parseBound($wrap, attr) {
        var raw = $wrap.attr(attr);
        if (!raw) {
            return null;
        }
        return parseYmd(String(raw));
    }

    function syncHidden($wrap) {
        var $hidden = $wrap.find('.sto-datetime-field__value');
        var $date = $wrap.find('.sto-datetime-field__date');
        var $time = $wrap.find('.sto-datetime-field__time');
        var dt = null;
        if ($date.data('datepicker')) {
            try {
                dt = $date.datepicker('getDate');
            } catch (e) {
                dt = null;
            }
        }
        var tm = parseTimeVal($time.val());
        if (!dt) {
            $hidden.val('');
            return;
        }
        $hidden.val(formatYmd(dt) + ' ' + pad2(tm.h) + ':' + pad2(tm.m));
    }

    function applyFromHidden($wrap) {
        var $hidden = $wrap.find('.sto-datetime-field__value');
        var $date = $wrap.find('.sto-datetime-field__date');
        var $time = $wrap.find('.sto-datetime-field__time');
        var v = ($hidden.val() || '').trim();
        var m = v.match(/^(\d{4}-\d{2}-\d{2}) (\d{2}):(\d{2})$/);
        if (!m) {
            if ($date.data('datepicker')) {
                try {
                    $date.datepicker('setDate', null);
                } catch (e2) {
                    $date.val('');
                }
            } else {
                $date.val('');
            }
            $time.val('00:00');
            return;
        }
        var d = parseYmd(m[1]);
        if ($date.data('datepicker')) {
            try {
                $date.datepicker('setDate', d);
            } catch (e3) {
                $date.val('');
            }
        }
        $time.val(m[2] + ':' + m[3]);
    }

    function bindOne($wrap) {
        var $hidden = $wrap.find('.sto-datetime-field__value');
        var $date = $wrap.find('.sto-datetime-field__date');
        var $time = $wrap.find('.sto-datetime-field__time');
        var $clear = $wrap.find('.sto-datetime-field__clear');

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

        $date.datepicker({
            changeMonth: true,
            changeYear: true,
            showAnim: '',
            yearRange: yearRange,
            minDate: minD,
            maxDate: maxD,
            beforeShow: function() {
                $('#ui-datepicker-div').addClass('sto-datepicker--sto');
            },
            onClose: function() {
                $('#ui-datepicker-div').removeClass('sto-datepicker--sto');
            },
            onSelect: function() {
                syncHidden($wrap);
                $hidden.trigger('change');
            }
        });

        applyFromHidden($wrap);

        $time.on('change input', function() {
            syncHidden($wrap);
            $hidden.trigger('change');
        });

        $date.on('keydown', function(e) {
            if (e.key === 'Enter' || e.keyCode === 13) {
                e.preventDefault();
                $date.datepicker('show');
            }
        });

        $clear.on('click', function(ev) {
            ev.preventDefault();
            $hidden.val('');
            if ($date.data('datepicker')) {
                try {
                    $date.datepicker('setDate', null);
                } catch (e4) {
                    $date.val('');
                }
            } else {
                $date.val('');
            }
            $time.val('00:00');
            $hidden.trigger('change');
        });
    }

    window.stoInitDateTimeFields = function($scope) {
        var $root = $scope && $scope.length ? $scope : $(document);
        $root.find('.sto-datetime-field[data-sto-datetime]').each(function() {
            var $wrap = $(this);
            if ($wrap.data('stoDateTimeBound')) {
                applyFromHidden($wrap);
                return;
            }
            if (typeof $.fn.datepicker !== 'function') {
                return;
            }
            $wrap.data('stoDateTimeBound', 1);
            bindOne($wrap);
        });
    };

    $(function() {
        if (typeof window.stoInitDateTimeFields === 'function') {
            window.stoInitDateTimeFields($('.sto-options-form'));
        }
    });
})(jQuery);
