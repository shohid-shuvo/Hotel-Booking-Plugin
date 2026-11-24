/** Private booking lookup and single-submit cancellation through WordPress AJAX. */
(function ($) {
    'use strict';
    $(function () {
        if (window.arcSearchSettings && window.arcSearchSettings.isLoggedIn) { return; }
        $('.arc-track-wrap').each(function () {
            var wrap = $(this), url = wrap.attr('data-nonces-url');
            if (url) { wrap.data('arcNonceRequest', $.ajax({ url: url, method: 'GET', cache: false }).done(function (data) {
                if (data.track_nonce) { wrap.attr('data-track-nonce', data.track_nonce); }
                if (data.cancel_nonce) { wrap.attr('data-cancel-nonce', data.cancel_nonce); }
            })); }
        });
    });
    $(document).on('submit', '.arc-track-form', function (event) {
        event.preventDefault();
        var form = this, wrap = $(form).closest('.arc-track-wrap');
        var button = $(form).find('button[type="submit"]'), result = wrap.find('.arc-track-result');
        var nonces = wrap.data('arcNonceRequest');
        if (nonces && nonces.state() === 'pending') { nonces.always(function () { $(form).trigger('submit'); }); return; }
        if (button.prop('disabled')) { return; }
        button.prop('disabled', true);
        wrap.removeData('arcVerifiedBooking');
        var sequence = (wrap.data('arcLookupSequence') || 0) + 1;
        wrap.data('arcLookupSequence', sequence);
        wrap.find('.arc-cancel-panel').prop('hidden', true);
        result.empty().append($('<p>').text('Checking your reservation...'));
        var reference = $(form).find('[name="booking_number"]').val();
        var phone = $(form).find('[name="guest_phone"]').val();
        $.ajax({ url: wrap.attr('data-ajax-url'), method: 'POST', dataType: 'json', timeout: 30000,
            data: { action: 'arc_track_booking', nonce: wrap.attr('data-track-nonce'), booking_number: reference, guest_phone: phone }
        }).done(function (response) {
            if (sequence !== wrap.data('arcLookupSequence')) { return; }
            result.empty();
            if (!response.success) { result.append($('<p>').text(response.data.message || 'No matching reservation found.')); return; }
            var details = response.data, table = $('<dl>');
            var labels = { bookingNumber: 'Booking number', status: 'Status', roomType: 'Room type', roomNumber: 'Assigned room', checkInDate: 'Check-in', checkOutDate: 'Check-out', totalNights: 'Nights', totalAmount: 'Total amount', paidAmount: 'Paid', dueAmount: 'Due', paymentStatus: 'Payment status' };
            $.each(labels, function (key, label) { if (details[key] !== undefined) { table.append($('<dt>').text(label), $('<dd>').text(String(details[key]))); } });
            result.append(table);
            wrap.data('arcVerifiedBooking', { reference: details.bookingNumber, phone: phone });
            if (details.canCancel) { wrap.find('.arc-cancel-panel').prop('hidden', false); }
        }).fail(function (xhr) {
            result.empty().append($('<p>').text(xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data.message : 'Unable to verify the reservation. Please try again later.'));
        }).always(function () { button.prop('disabled', false); });
    });
    $(document).on('input', '.arc-track-form input', function () {
        var wrap = $(this).closest('.arc-track-wrap');
        wrap.data('arcLookupSequence', (wrap.data('arcLookupSequence') || 0) + 1);
        wrap.removeData('arcVerifiedBooking').find('.arc-cancel-panel').prop('hidden', true);
    });
    $(document).on('submit', '.arc-cancel-form', function (event) {
        event.preventDefault();
        var form = this, wrap = $(form).closest('.arc-track-wrap'), booking = wrap.data('arcVerifiedBooking');
        if (!booking || !window.confirm('Cancel this reservation? Payment refunds are handled separately by the hotel.')) { return; }
        wrap.removeData('arcVerifiedBooking');
        var result = wrap.find('.arc-track-result');
        $(form).find(':input').prop('disabled', true);
        wrap.find('.arc-track-form :input').prop('disabled', true);
        $.ajax({ url: wrap.attr('data-ajax-url'), method: 'POST', dataType: 'json', timeout: 30000,
            data: { action: 'arc_cancel_booking', nonce: wrap.attr('data-cancel-nonce'), booking_number: booking.reference, guest_phone: booking.phone, reason: $(form).find('[name="reason"]').val() }
        }).done(function (response) { result.empty().append($('<p>').text(response.data.message)); })
        .fail(function (xhr) { result.empty().append($('<p>').text(xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data.message : 'Cancellation could not be confirmed. Track again or contact the hotel.')); })
        .always(function () { $(form).find(':input').prop('disabled', false); wrap.find('.arc-track-form :input').prop('disabled', false); wrap.find('.arc-cancel-panel').prop('hidden', true); });
    });
})(jQuery);
