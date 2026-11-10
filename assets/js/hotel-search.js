/**
 * Datepicker and form validation for Hotel Availability Search.
 */

(function($) {
	'use strict';

	$(document).ready(function() {
		var $checkIn = $('#arc_check_in');
		var $checkOut = $('#arc_check_out');

		if (!$checkIn.length || !$checkOut.length) {
			return;
		}

		// Automatically refresh nonces on cached pages via no-store REST endpoint
		if ($('input[name="arc_book_nonce"], input[name="arc_track_nonce"]').length) {
			$.ajax({
				url: '/wp-json/arc/v1/fresh-nonces',
				method: 'GET',
				cache: false,
				success: function(res) {
					if (res && res.book_nonce) {
						$('input[name="arc_book_nonce"]').val(res.book_nonce);
					}
					if (res && res.track_nonce) {
						$('input[name="arc_track_nonce"]').val(res.track_nonce);
					}
				}
			});
		}
		$checkIn.on('change', function() {
			var inVal = $(this).val();
			if (!inVal) {
				return;
			}

			var inDate = new Date(inVal);
			if (isNaN(inDate.getTime())) {
				return;
			}

			// Add 1 day
			var minOutDate = new Date(inDate.getTime() + (24 * 60 * 60 * 1000));
			var minOutStr = minOutDate.toISOString().split('T')[0];

			$checkOut.attr('min', minOutStr);

			var outVal = $checkOut.val();
			if (outVal) {
				var outDate = new Date(outVal);
				if (outDate <= inDate) {
					$checkOut.val(minOutStr);
				}
			} else {
				$checkOut.val(minOutStr);
			}
		});

		// Validate on form submit
		$('.arc-search-form').on('submit', function(e) {
			var inDate = new Date($checkIn.val());
			var outDate = new Date($checkOut.val());

			if (outDate <= inDate) {
				e.preventDefault();
				alert('Check-out date must be after check-in date.');
				$checkOut.focus();
				return false;
			}

			var diffDays = Math.round((outDate - inDate) / (1000 * 60 * 60 * 24));
			var maxStay = parseInt(window.arcSearchSettings?.maxStay || 30, 10);

			if (diffDays > maxStay) {
				e.preventDefault();
				alert('Maximum allowable stay is ' + maxStay + ' nights.');
				return false;
			}
		});
	});
})(jQuery);
