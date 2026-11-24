<?php
/**
 * Checkout Validation & Pre-Payment Anti-Double-Booking Guard.
 *
 * @package ARC\HotelCore\Checkout
 */

namespace ARC\HotelCore\Checkout;

use ARC\HotelCore\Cart\Cart_Guard;
use ARC\HotelCore\Booking\Room_Quote;
use ARC\HotelCore\Support\Guest_Identity;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates guest credentials, ID numbers, and verifies real-time room availability before charging.
 */
class Checkout_Validation {

	/**
	 * Hook checkout validation.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'woocommerce_after_checkout_validation', [ $this, 'validate_room_checkout' ], 10, 2 );
	}

	/**
	 * Validate room checkout fields and pre-payment availability.
	 *
	 * @param array     $data   Posted checkout field values.
	 * @param \WP_Error $errors Validation errors object.
	 * @return void
	 */
	public function validate_room_checkout( array $data, \WP_Error $errors ): void {
		if ( ! Cart_Guard::cart_has_room() ) {
			return; // Food mode checkout does not require hotel validations
		}

		if ( Cart_Guard::cart_has_food() ) {
			$errors->add( 'arc_mixed_cart', __( 'Room reservations and restaurant items require separate orders.', 'arc-hotel-core' ) );
			return;
		}
		if ( function_exists( 'get_woocommerce_currency' ) && 'BDT' !== get_woocommerce_currency() ) {
			$errors->add( 'arc_currency', __( 'Room booking requires the API currency BDT. Please contact the hotel to correct currency configuration.', 'arc-hotel-core' ) );
			return;
		}
		$room_count = 0;
		foreach ( WC()->cart->get_cart() as $item ) { if ( ! empty( $item['arc_room'] ) ) { $room_count++; } }
		if ( $room_count !== 1 ) {
			$errors->add( 'arc_room_count', __( 'Only one room reservation is allowed per checkout.', 'arc-hotel-core' ) );
			return;
		}

		// 1. Validate Mobile Phone (8-15 digits after removing formatting characters)
		$raw_phone   = $data['billing_phone'] ?? '';
		$clean_phone = Guest_Identity::normalize_phone( (string) $raw_phone );
		if ( ! preg_match( '/^\d{8,15}$/', $clean_phone ) ) {
			$errors->add( 'arc_invalid_phone', __( 'Please provide a valid mobile number (8 to 15 digits).', 'arc-hotel-core' ) );
		}

		// 2. Validate NID or Passport
		$id_number = isset( $_POST['arc_id_number'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['arc_id_number'] ) ) ) : '';
		if ( empty( $id_number ) ) {
			$errors->add( 'arc_missing_id', __( 'National ID (NID) or Passport Number is required for hotel guest check-in.', 'arc-hotel-core' ) );
		} else {
			$nid_pattern      = '/^(\d{10}|\d{13}|\d{17})$/';
			$passport_pattern = '/^[A-Za-z0-9]{6,9}$/';

			if ( ! preg_match( $nid_pattern, $id_number ) && ! preg_match( $passport_pattern, $id_number ) ) {
				$errors->add( 'arc_invalid_id', __( 'Invalid ID Number. Please enter a valid 10, 13, or 17-digit Bangladeshi NID or a 6 to 9-character Passport number.', 'arc-hotel-core' ) );
			}
		}

		// 3. Validate Additional Adult Guest Names
		$room_item = null;
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( ! empty( $item['arc_room'] ) ) {
				$room_item = Room_Quote::normalize_booking( $item['arc_room'] );
				break;
			}
		}

		if ( $room_item ) {
			$adults = (int) ( $room_item['adults'] ?? 1 );
			for ( $i = 2; $i <= min( 8, $adults ); $i++ ) {
				$guest_name = isset( $_POST[ 'arc_guest_' . $i ] ) ? trim( sanitize_text_field( wp_unslash( $_POST[ 'arc_guest_' . $i ] ) ) ) : '';
				if ( empty( $guest_name ) ) {
					$errors->add(
						'arc_missing_guest_' . $i,
						sprintf(
							/* translators: %d: Guest number */
							__( 'Please provide the full name for Adult Guest %d.', 'arc-hotel-core' ),
							$i
						)
					);
				}
			}

			$lock = 'room_' . \ARC\HotelCore\Settings\Settings::get( 'company_code' ) . '_' . (int) ( $room_item['api_room_id'] ?? 0 );
			if ( ( $room_item['company_code'] ?? '' ) !== \ARC\HotelCore\Settings\Settings::get( 'company_code' ) ) {
				$errors->add( 'arc_session_tenant', __( 'Your hotel booking session expired. Please select your room and dates again.', 'arc-hotel-core' ) );
				return;
			}
			if ( empty( $room_item['api_room_id'] ) || ! \ARC\HotelCore\Support\Database_Lock::acquire( $lock ) ) {
				$errors->add( 'arc_verification_busy', __( 'Room verification is temporarily unavailable. Please try again before payment.', 'arc-hotel-core' ) );
				return;
			}
			try {
			$result = Room_Quote::search( (string) ( $room_item['check_in'] ?? '' ), (string) ( $room_item['check_out'] ?? '' ), (int) ( $room_item['adults'] ?? 0 ), (int) ( $room_item['children'] ?? 0 ), (int) ( $room_item['room_type_id'] ?? 0 ) );
			if ( ! $result->ok() ) {
				$errors->add( 'arc_api_check_failed', $result->message() );
				return;
			}
			$room = Room_Quote::select( $result->data(), (int) ( $room_item['room_type_id'] ?? 0 ), (int) ( $room_item['api_room_id'] ?? 0 ) );
			if ( ! $room ) {
				$errors->add( 'arc_room_unavailable', __( 'The selected room is unavailable for these dates and guest counts. Please select another room.', 'arc-hotel-core' ) );
				return;
			}
			$total = (float) ( $room_item['total_price'] ?? 0 );
			if ( abs( (float) $room['totalPrice'] - $total ) > 0.01 ) {
				$errors->add( 'arc_price_changed', __( 'The room price changed. Please select your room again to accept the current rate.', 'arc-hotel-core' ) );
			}
			foreach ( WC()->cart->get_cart() as $item ) {
				if ( ! empty( $item['arc_room'] ) && isset( $item['line_total'] ) && abs( (float) $item['line_total'] - $total ) > 0.01 ) {
					$errors->add( 'arc_room_discount', __( 'This coupon changes the API room fare. Remove the coupon before completing your room reservation.', 'arc-hotel-core' ) );
				}
			}
			if ( ! function_exists( 'arc_cfg' ) && empty( $_POST['arc_terms'] ) ) {
				$errors->add( 'arc_terms', __( 'Please accept the hotel terms before booking.', 'arc-hotel-core' ) );
			}
			} finally { \ARC\HotelCore\Support\Database_Lock::release( $lock ); }
		} else {
			$errors->add( 'arc_missing_booking', __( 'Your booking session is incomplete. Please select your room again.', 'arc-hotel-core' ) );
		}
	}
}
