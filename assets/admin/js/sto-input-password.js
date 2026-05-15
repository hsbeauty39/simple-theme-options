/**
 * Password input: show / hide value (Input field type `password`).
 */
(function ($) {
    'use strict';

    var cfg = window.stoInputPassword || {};
    var showLabel = cfg.show != null ? String(cfg.show) : 'Show password';
    var hideLabel = cfg.hide != null ? String(cfg.hide) : 'Hide password';

    $(document).on('click', '[data-sto-password-toggle]', function (e) {
        e.preventDefault();
        var $btn = $(this);
        var $wrap = $btn.closest('.sto-input-wrap--password');
        var $input = $wrap.find('input.sto-input-control--password').first();
        if (!$input.length) {
            return;
        }
        var revealed = $btn.attr('aria-pressed') === 'true';
        var $icon = $btn.find('.dashicons').first();
        if (revealed) {
            $input.attr('type', 'password');
            $btn.attr('aria-pressed', 'false').attr('aria-label', showLabel);
            $icon.removeClass('dashicons-hidden').addClass('dashicons-visibility');
        } else {
            $input.attr('type', 'text');
            $btn.attr('aria-pressed', 'true').attr('aria-label', hideLabel);
            $icon.removeClass('dashicons-visibility').addClass('dashicons-hidden');
        }
    });
})(jQuery);
