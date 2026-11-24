/** Per-form date validation and cache-safe nonce refresh through WordPress REST. */
(function ($) {
    'use strict';

    // Delegate picker activation so AJAX and Elementor popup fields also work.
    function openHotelDatePicker(event) {
        var el = event.target;
        if (!el || !el.matches || !el.matches('input[type="date"]') || el.matches(':disabled') || el.readOnly) { return; }

        var isHotelInput = el.classList.contains('arc-date-input') || el.name.indexOf('arc_') === 0;
        for (var container = el.parentElement; !isHotelInput && container; container = container.parentElement) {
            isHotelInput = Array.prototype.some.call(container.classList, function (className) {
                return className.indexOf('arc-') === 0;
            });
        }
        if (!isHotelInput) { return; }

        if (typeof el.showPicker === 'function') {
            try { el.showPicker(); } catch (e) {}
        }
    }

    // Capture focus because it does not bubble, and clicks before popup handlers.
    document.addEventListener('click', openHotelDatePicker, true);
    document.addEventListener('focus', openHotelDatePicker, true);

    $(function () {
        var nonceRequest = null;
        var config = window.arcSearchSettings || {};
        if (!config.isLoggedIn && $('input[name="arc_book_nonce"]').length) {
            nonceRequest = $.ajax({ url: config.restUrl || '/wp-json/arc/v1/fresh-nonces', method: 'GET', cache: false })
                .done(function (data) { if (data.book_nonce) { $('input[name="arc_book_nonce"]').val(data.book_nonce); } });
        }
        $('.arc-search-form, .arc-room-booking').each(function () {
            var form = this, input = $(form).find('[name="check_in"]'), output = $(form).find('[name="check_out"]');
            if (!input.length || !output.length) { return; }
            input.on('change', function () {
                var date = new Date(input.val() + 'T00:00:00Z');
                if (isNaN(date.getTime())) { return; }
                date.setUTCDate(date.getUTCDate() + 1);
                var minimum = date.toISOString().slice(0, 10);
                output.attr('min', minimum);
                if (!output.val() || output.val() <= input.val()) { output.val(minimum); }
            });
            $(form).on('submit', function (event) {
                var start = new Date(input.val() + 'T00:00:00Z'), end = new Date(output.val() + 'T00:00:00Z');
                var nights = (end - start) / 86400000;
                if (!isFinite(nights) || nights < 1 || nights > Number(config.maxStay || 30)) {
                    event.preventDefault();
                    window.alert('Select a valid stay of 1 to ' + (config.maxStay || 30) + ' nights.');
                    return;
                }
                if (nonceRequest && nonceRequest.state() === 'pending' && $(form).find('[name="arc_book_nonce"]').length) {
                    event.preventDefault();
                    if ($(form).data('arcNonceWaiting')) { return; }
                    $(form).data('arcNonceWaiting', true);
                    nonceRequest.always(function () { $(form).removeData('arcNonceWaiting'); if (form.requestSubmit) { form.requestSubmit(); } else { form.submit(); } });
                }
            });
        });
    });
})(jQuery);
