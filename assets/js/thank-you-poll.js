/**
 * Asynchronous Booking Confirmation Polling for Thank-You Page.
 */

(function($) {
	'use strict';

	$(document).ready(function() {
		var $box = $('#arc-thankyou-box');
		if (!$box.length || !window.arcThankYouData) {
			return;
		}

		var orderId = window.arcThankYouData.orderId;
		var nonce = window.arcThankYouData.nonce;
		var ajaxUrl = window.arcThankYouData.ajaxUrl;
		var attempts = 0;
		var maxAttempts = 24; // 2 minutes (every 5 seconds)

		var timer = setInterval(function() {
			attempts++;
			if (attempts > maxAttempts) {
				clearInterval(timer);
				$('#arc-sync-pending-wrap').html(
					'<p style="color: #b45309; font-weight: 600;">' +
					'Your reservation is being processed by our front office. You will receive an official confirmation email shortly.' +
					'</p>'
				);
				return;
			}

			$.post(ajaxUrl, {
				action: 'arc_poll_booking_status',
				order_id: orderId,
				nonce: nonce
			}, function(res) {
				if (res.success && res.data && res.data.synced) {
					clearInterval(timer);
					window.location.reload();
				}
			});
		}, 5000);
	});
})(jQuery);
