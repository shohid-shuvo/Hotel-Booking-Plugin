<?php
/**
 * Track & Cancel Controller.
 *
 * @package ARC\HotelCore\Track
 */

namespace ARC\HotelCore\Track;

use ARC\HotelCore\Api\Api_Client;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles Track Booking and Cancel Booking AJAX requests.
 */
class Track_Controller {

	/**
	 * Hook AJAX actions.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'wp_ajax_arc_track_booking', [ $this, 'ajax_track' ] );
		add_action( 'wp_ajax_nopriv_arc_track_booking', [ $this, 'ajax_track' ] );
		add_action( 'wp_ajax_arc_cancel_booking', [ $this, 'ajax_cancel' ] );
		add_action( 'wp_ajax_nopriv_arc_cancel_booking', [ $this, 'ajax_cancel' ] );
	}

	/**
	 * AJAX handler for tracking reservation.
	 *
	 * @return void
	 */
	public function ajax_track(): void {
		check_ajax_referer( 'arc_track_booking_action', 'nonce' );

		// 1. Rate Limiting: 5 attempts per 15 mins per IP
		$ip       = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1' ) );
		$rate_key = 'arc_rate_track_' . md5( $ip );
		$attempts = (int) get_transient( $rate_key );

		if ( $attempts >= 5 ) {
			wp_send_json_error( [ 'message' => __( 'Too many lookup attempts. Please wait 15 minutes before trying again.', 'arc-hotel-core' ) ] );
		}
		set_transient( $rate_key, $attempts + 1, 15 * MINUTE_IN_SECONDS );

		$booking_number = isset( $_POST['booking_number'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['booking_number'] ) ) ) : '';
		$guest_phone    = isset( $_POST['guest_phone'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['guest_phone'] ) ) ) : '';

		if ( empty( $booking_number ) || empty( $guest_phone ) ) {
			wp_send_json_error( [ 'message' => __( 'Please provide both Booking Number and Guest Phone number.', 'arc-hotel-core' ) ] );
		}

		$clean_phone = preg_replace( '/[\s\+\-\(\)]/', '', $guest_phone );
		// Strip leading 88 if present
		if ( str_starts_with( $clean_phone, '88' ) ) {
			$clean_phone = substr( $clean_phone, 2 );
		}

		$client  = new Api_Client();
		$api_res = $client->get( 'api/v1/public-booking/track/' . rawurlencode( $booking_number ), [], [ 'use_cache' => false ] );

		if ( ! $api_res->ok() ) {
			wp_send_json_error( [ 'message' => __( 'No reservation found matching these details.', 'arc-hotel-core' ) ] );
		}

		$data = $api_res->data();
		if ( empty( $data ) || ! is_array( $data ) ) {
			wp_send_json_error( [ 'message' => __( 'No reservation found matching these details.', 'arc-hotel-core' ) ] );
		}

		// Items can be an array of bookings or a single booking
		$booking = isset( $data[0] ) ? $data[0] : $data;

		// Verify phone match
		$stored_phone = preg_replace( '/[\s\+\-\(\)]/', '', (string) ( $booking['phone'] ?? '' ) );
		if ( str_starts_with( $stored_phone, '88' ) ) {
			$stored_phone = substr( $stored_phone, 2 );
		}

		if ( ! str_ends_with( $stored_phone, $clean_phone ) && ! str_ends_with( $clean_phone, $stored_phone ) ) {
			// Generic message to prevent enumeration
			wp_send_json_error( [ 'message' => __( 'No reservation found matching these details.', 'arc-hotel-core' ) ] );
		}

		// Format output safely (No NID returned)
		$output = [
			'bookingNumber' => sanitize_text_field( $booking['bookingNumber'] ?? $booking_number ),
			'status'        => sanitize_text_field( $booking['status'] ?? 'Reserved' ),
			'guestName'     => sanitize_text_field( $booking['guestName'] ?? '' ),
			'roomType'      => sanitize_text_field( $booking['roomType'] ?? '' ),
			'roomNumber'    => sanitize_text_field( $booking['roomNumber'] ?? 'Auto-Assigned' ),
			'checkInDate'   => sanitize_text_field( substr( (string) ( $booking['checkInDate'] ?? '' ), 0, 10 ) ),
			'checkOutDate'  => sanitize_text_field( substr( (string) ( $booking['checkOutDate'] ?? '' ), 0, 10 ) ),
			'totalNights'   => absint( $booking['totalNights'] ?? 1 ),
			'totalAmount'   => (float) ( $booking['totalAmount'] ?? 0 ),
			'paidAmount'    => (float) ( $booking['paidAmount'] ?? 0 ),
			'dueAmount'     => (float) ( $booking['dueAmount'] ?? 0 ),
			'paymentStatus' => sanitize_text_field( $booking['paymentStatus'] ?? 'Unpaid' ),
		];

		wp_send_json_success( $output );
	}

	/**
	 * AJAX handler for guest cancellation.
	 *
	 * @return void
	 */
	public function ajax_cancel(): void {
		check_ajax_referer( 'arc_track_booking_action', 'nonce' );

		$booking_number = isset( $_POST['booking_number'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['booking_number'] ) ) ) : '';
		$guest_phone    = isset( $_POST['guest_phone'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['guest_phone'] ) ) ) : '';
		$reason         = isset( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) : 'Customer requested online cancellation';

		if ( empty( $booking_number ) || empty( $guest_phone ) ) {
			wp_send_json_error( [ 'message' => __( 'Missing required booking number or phone.', 'arc-hotel-core' ) ] );
		}

		$client  = new Api_Client();
		$api_res = $client->post( 'api/v1/public-booking/cancel-reservation-json', [
			'bookingNumber' => $booking_number,
			'phone'         => $guest_phone,
			'reason'        => $reason,
		] );

		if ( ! $api_res->ok() ) {
			wp_send_json_error( [ 'message' => $api_res->message() ] );
		}

		// Update WooCommerce order note if found
		$orders = wc_get_orders( [
			'limit'      => 1,
			'meta_key'   => '_arc_aspnet_reservation_no',
			'meta_value' => $booking_number,
		] );

		if ( ! empty( $orders ) ) {
			$order = $orders[0];
			$order->update_status( 'cancelled', sprintf( __( 'Reservation cancelled from website by guest. Reason: %s', 'arc-hotel-core' ), $reason ) );
		}

		wp_send_json_success( [ 'message' => __( 'Your reservation has been cancelled successfully.', 'arc-hotel-core' ) ] );
	}
}
