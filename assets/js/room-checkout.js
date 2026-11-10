/**
 * Scoped Room Checkout Script (Field Validation & Interactions)
 */

(function($) {
	'use strict';

	$(document).ready(function() {
		// Only run if hotel checkout section is present
		if (!$('.arc-hotel-checkout-section').length) {
			return;
		}

		var $idInput = $('#arc_id_number');
		var $phoneInput = $('#billing_phone');

		// Instant client-side validation for NID or Passport
		$idInput.on('blur', function() {
			var val = $(this).val().trim();
			if (!val) {
				return;
			}

			var nidRegex = /^(\d{10}|\d{13}|\d{17})$/;
			var passportRegex = /^[A-Za-z0-9]{6,9}$/;

			if (!nidRegex.test(val) && !passportRegex.test(val)) {
				$(this).css('border-color', '#dc2626');
				if (!$('#arc-id-error').length) {
					$(this).after('<div id="arc-id-error" style="color: #dc2626; font-size: 12px; margin-top: 4px;">Please enter a valid 10, 13, or 17-digit Bangladeshi NID, or a 6-9 character Passport number.</div>');
				}
			} else {
				$(this).css('border-color', '#16a34a');
				$('#arc-id-error').remove();
			}
		});

		// Instant client-side validation for Phone number
		$phoneInput.on('blur', function() {
			var val = $(this).val().replace(/[\s\+\-\(\)]/g, '');
			if (!val) {
				return;
			}

			if (!/^\d{8,15}$/.test(val)) {
				$(this).css('border-color', '#dc2626');
				if (!$('#arc-phone-error').length) {
					$(this).after('<div id="arc-phone-error" style="color: #dc2626; font-size: 12px; margin-top: 4px;">Phone number must be between 8 and 15 digits.</div>');
				}
			} else {
				$(this).css('border-color', '#16a34a');
				$('#arc-phone-error').remove();
			}
		});
	});
})(jQuery);
