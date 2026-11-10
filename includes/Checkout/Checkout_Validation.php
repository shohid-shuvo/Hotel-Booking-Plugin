<?php
/**
 * Checkout Validation & Pre-Payment Anti-Double-Booking Guard.
 *
 * @package ARC\HotelCore\Checkout
 */

namespace ARC\HotelCore\Checkout;

use ARC\HotelCore\Cart\Cart_Guard;
use ARC\HotelCore\Api\Api_Client;

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

		// 1. Validate Mobile Phone (8-15 digits after removing formatting characters)
		$raw_phone   = $data['billing_phone'] ?? '';
		$clean_phone = preg_replace( '/[\s\+\-\(\)]/', '', $raw_phone );
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
				$room_item = $item['arc_room'];
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

			// 4. Pre-Payment Real-Time Overlap Verification (Anti-Double-Booking Guard)
			$client = new Api_Client();
			$api_res = $client->get(
				'api/v1/public-booking/available-rooms',
				[
					'checkIn'    => $room_item['check_in'] . 'T14:00:00',
					'checkOut'   => $room_item['check_out'] . 'T12:00:00',
					'roomTypeId' => $room_item['room_type_id'],
					'adults'     => $room_item['adults'],
				],
				[ 'use_cache' => false ]
			);

			if ( ! $api_res->ok() ) {
				$errors->add( 'arc_api_check_failed', $api_res->message() );
				return;
			}

			$available_rooms = $api_res->data();
			if ( empty( $available_rooms ) || ! is_array( $available_rooms ) ) {
				$errors->add(
					'arc_room_taken',
					__( 'We apologize, but this room was just reserved by another guest or receptionist. Your payment has not been processed. Please select another room.', 'arc-hotel-core' )
				);
				return;
			}

			// Verify that the requested room type is still free
			$is_still_available = false;
			$latest_total_price = 0.0;
			foreach ( $available_rooms as $ar ) {
				if ( (int) $ar['roomTypeId'] === (int) $room_item['room_type_id'] ) {
					$is_still_available = true;
					$latest_total_price = (float) ( $ar['totalPrice'] ?? 0 );
					break;
				}
			}

			if ( ! $is_still_available ) {
				$errors->add(
					'arc_room_unavailable',
					__( 'This room category has just become fully booked. Please select different dates or a different suite.', 'arc-hotel-core' )
				);
				return;
			}

			// Price authority verification
			$cart_price = (float) ( $room_item['total_price'] ?? 0 );
			if ( abs( $latest_total_price - $cart_price ) > 0.01 ) {
				$errors->add(
					'arc_price_changed',
					sprintf(
						/* translators: 1: New price, 2: Old price */
						__( 'The room rate has changed to %1$s BDT (was %2$s BDT). Please refresh the page to accept the updated fare.', 'arc-hotel-core' ),
						number_format( $latest_total_price ),
						number_format( $cart_price )
					)
				);
			}
		}
	}
}
