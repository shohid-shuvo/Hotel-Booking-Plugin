<?php
/** Private reference-and-phone tracking against the current GET API. */
namespace ARC\HotelCore\Track;
use ARC\HotelCore\Api\Api_Client;
use ARC\HotelCore\Settings\Settings;
use ARC\HotelCore\Support\Guest_Identity;
use ARC\HotelCore\Support\Order_Query;
use ARC\HotelCore\Support\Database_Lock;
defined( 'ABSPATH' ) || exit;

class Track_Controller {
	public function init(): void {
		foreach ( [ 'wp_ajax_arc_track_booking', 'wp_ajax_nopriv_arc_track_booking' ] as $hook ) { add_action( $hook, [ $this, 'ajax_track' ] ); }
		foreach ( [ 'wp_ajax_arc_cancel_booking', 'wp_ajax_nopriv_arc_cancel_booking' ] as $hook ) { add_action( $hook, [ $this, 'ajax_cancel' ] ); }
	}
	private static function input( string $key ): string {
		return isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? trim( sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) ) : '';
	}
	private function lookup( string $reference, string $phone ): ?array {
		try { $result = ( new Api_Client() )->get( 'api/v1/public-booking/track/' . rawurlencode( $reference ), [], [ 'use_cache' => false ] ); } catch ( \Throwable $error ) { return null; }
		return $result->ok() && is_array( $result->data() ) ? Guest_Identity::find_booking( $result->data(), $reference, $phone, Settings::get( 'company_code' ) ) : null;
	}
	private static function identity( string $operation ): array {
		$reference = self::input( 'booking_number' );
		$phone = self::input( 'guest_phone' );
		if ( ! Guest_Identity::rate_limit( $operation, $reference ) ) {
			wp_send_json_error( [ 'message' => __( 'Too many attempts. Please wait 15 minutes or contact the hotel.', 'arc-hotel-core' ) ], 429 );
		}
		if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9_-]{5,119}$/', $reference ) || '' === Guest_Identity::normalize_phone( $phone ) || '' === Settings::get( 'company_code' ) ) {
			wp_send_json_error( [ 'message' => __( 'Enter a valid booking number and its full phone number.', 'arc-hotel-core' ) ], 400 );
		}
		return [ $reference, $phone ];
	}
	private static function public_data( array $row ): array {
		$data = [];
		foreach ( [ 'bookingNumber', 'status', 'roomType', 'roomNumber', 'paymentStatus' ] as $key ) { $data[ $key ] = sanitize_text_field( $row[ $key ] ?? '' ); }
		foreach ( [ 'checkInDate', 'checkOutDate' ] as $key ) { $data[ $key ] = substr( sanitize_text_field( $row[ $key ] ?? '' ), 0, 10 ); }
		foreach ( [ 'totalAmount', 'paidAmount', 'dueAmount', 'totalNights' ] as $key ) { $data[ $key ] = (float) ( $row[ $key ] ?? 0 ); }
		$data['canCancel'] = strtolower( trim( (string) ( $row['status'] ?? '' ) ) ) === 'reserved';
		return $data;
	}
	public function ajax_track(): void {
		check_ajax_referer( 'arc_track_booking_action', 'nonce' );
		nocache_headers();
		[ $reference, $phone ] = self::identity( 'track' );
		$row = $this->lookup( $reference, $phone );
		if ( ! $row ) { wp_send_json_error( [ 'message' => __( 'No reservation found matching these details.', 'arc-hotel-core' ) ], 404 ); }
		wp_send_json_success( self::public_data( $row ) );
	}
	public function ajax_cancel(): void {
		check_ajax_referer( 'arc_cancel_booking_action', 'nonce' );
		nocache_headers();
		[ $reference, $phone ] = self::identity( 'cancel' );
		$key = 'cancel_' . Settings::get( 'company_code' ) . '_' . $reference;
		if ( ! Database_Lock::acquire( $key ) ) { wp_send_json_error( [ 'message' => __( 'This cancellation is already being checked. Track the booking again.', 'arc-hotel-core' ) ], 409 ); }
		try {
			$row = $this->lookup( $reference, $phone );
			if ( ! $row || ! self::public_data( $row )['canCancel'] ) {
				Database_Lock::release( $key );
				wp_send_json_error( [ 'message' => __( 'This reservation cannot be cancelled online. Please contact the hotel.', 'arc-hotel-core' ) ], 409 );
			}
			$reason = isset( $_POST['reason'] ) && is_string( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) : '';
			if ( '' === trim( $reason ) || strlen( $reason ) > 2000 ) { Database_Lock::release( $key ); wp_send_json_error( [ 'message' => __( 'Please provide a short cancellation reason.', 'arc-hotel-core' ) ], 400 ); }
			try { $result = ( new Api_Client() )->post( 'api/v1/public-booking/cancel-reservation-json', [ 'bookingNumber' => $reference, 'phone' => $row['phone'], 'reason' => $reason ] ); } catch ( \Throwable $error ) { $result = \ARC\HotelCore\Api\Api_Result::error( 'CONNECTION_ERROR', 'Cancellation outcome unknown.', 0 ); }
			if ( ! $result->ok() || true !== $result->data() ) {
				Database_Lock::release( $key );
				wp_send_json_error( [ 'message' => __( 'Cancellation could not be confirmed. Track the booking again or contact the hotel before retrying.', 'arc-hotel-core' ) ], 409 );
			}
			try {
			$orders = Order_Query::get_orders( [ 'limit' => 10, 'meta_query' => [ [ 'key' => '_arc_aspnet_reservation_no', 'value' => $reference ], [ 'key' => '_arc_order_type', 'value' => 'room' ] ] ] );
			foreach ( $orders as $order ) {
				if ( Guest_Identity::matching_phone( $order->get_billing_phone(), $phone ) && ! in_array( $order->get_status(), [ 'cancelled', 'refunded' ], true ) ) {
					$order->update_status( 'cancelled', __( 'Guest cancellation confirmed by the hotel API. Any refund must be processed manually.', 'arc-hotel-core' ) );
				}
			}
			} catch ( \Throwable $local_error ) { error_log( 'ARC: confirmed cancellation needs local order reconciliation.' ); }
			Database_Lock::release( $key );
			wp_send_json_success( [ 'message' => __( 'Your reservation is cancelled. Payment refunds are handled separately by the hotel.', 'arc-hotel-core' ) ] );
		} finally { Database_Lock::release( $key ); }
	}
}
