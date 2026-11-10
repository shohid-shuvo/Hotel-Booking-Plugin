<?php
/**
 * Reservation Reconcile Engine.
 *
 * @package ARC\HotelCore\Booking
 */

namespace ARC\HotelCore\Booking;

use ARC\HotelCore\Api\Api_Client;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reconciles upcoming active bookings against the ASP.NET API.
 */
class Reconcile {

	/**
	 * Hook cron and manual reconcile actions.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'arc_daily_reconcile', [ $this, 'run_reconciliation' ] );
		add_action( 'wp_ajax_arc_manual_reconcile', [ $this, 'ajax_manual_reconcile' ] );
	}

	/**
	 * Run reconciliation for upcoming synced bookings.
	 *
	 * @return array Reconcile summary.
	 */
	public function run_reconciliation(): array {
		$today = current_time( 'Y-m-d' );

		// Query synced room orders with upcoming or active stays
		$orders = wc_get_orders( [
			'limit'        => 50,
			'status'       => [ 'processing', 'completed', 'on-hold' ],
			'meta_key'     => '_arc_sync_status',
			'meta_value'   => 'synced',
		] );

		$checked = 0;
		$updated = 0;
		$client  = new Api_Client();

		foreach ( $orders as $order ) {
			$booking_no = $order->get_meta( '_arc_aspnet_reservation_no' );
			$booking    = (array) $order->get_meta( '_arc_booking' );
			$check_out  = $booking['check_out'] ?? '';

			if ( empty( $booking_no ) || ( ! empty( $check_out ) && $check_out < $today ) ) {
				continue; // Skip past checkouts
			}

			$checked++;
			$api_res = $client->get( 'api/v1/public-booking/track/' . rawurlencode( $booking_no ), [], [ 'use_cache' => false ] );

			if ( ! $api_res->ok() ) {
				continue;
			}

			$data = $api_res->data();
			if ( empty( $data ) ) {
				continue;
			}

			$remote_info = is_array( $data ) && isset( $data[0] ) ? $data[0] : $data;
			$remote_status = sanitize_text_field( $remote_info['status'] ?? '' );
			$remote_room   = sanitize_text_field( $remote_info['roomNumber'] ?? '' );

			$local_room = (string) $order->get_meta( '_arc_aspnet_room_number' );

			// Check for room reassignment in hotel software
			if ( ! empty( $remote_room ) && $remote_room !== $local_room ) {
				$order->update_meta_data( '_arc_aspnet_room_number', $remote_room );
				$order->add_order_note(
					sprintf(
						/* translators: 1: New room number, 2: Old room number */
						__( 'Reconcile: Receptionist reassigned room from %1$s to %2$s in hotel software.', 'arc-hotel-core' ),
						$local_room ?: 'Unassigned',
						$remote_room
					)
				);
				$order->save();
				$updated++;
			}

			// Check for cancellation in receptionist software
			if ( 'Cancelled' === $remote_status && 'cancelled' !== $order->get_status() ) {
				$order->add_order_note( __( 'Reconcile notice: This reservation was marked CANCELLED in the hotel software. Note: WooCommerce order was NOT auto-refunded.', 'arc-hotel-core' ) );
				$order->update_status( 'cancelled', __( 'Cancelled in receptionist software.', 'arc-hotel-core' ) );
				$updated++;
			}
		}

		return [
			'success' => true,
			'checked' => $checked,
			'updated' => $updated,
		];
	}

	/**
	 * AJAX handler for manual admin trigger.
	 *
	 * @return void
	 */
	public function ajax_manual_reconcile(): void {
		check_ajax_referer( 'arc_reconcile_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'arc-hotel-core' ) ] );
		}

		$stats = $this->run_reconciliation();
		wp_send_json_success( $stats );
	}
}
