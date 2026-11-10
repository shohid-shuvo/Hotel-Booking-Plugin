<?php
/**
 * Reservation Synchronization Engine.
 *
 * @package ARC\HotelCore\Booking
 */

namespace ARC\HotelCore\Booking;

use ARC\HotelCore\Api\Api_Client;
use ARC\HotelCore\Payment\Payment_Mapper;
use ARC\HotelCore\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles post-payment reservation synchronization with the ASP.NET API.
 */
class Reservation_Sync {

	/**
	 * Hook order status and payment events.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'woocommerce_payment_complete', [ __CLASS__, 'maybe_sync' ], 10, 1 );
		add_action( 'woocommerce_order_status_processing', [ __CLASS__, 'maybe_sync' ], 10, 1 );
		add_action( 'woocommerce_order_status_completed', [ __CLASS__, 'maybe_sync' ], 10, 1 );
		add_action( 'arc_retry_reservation_sync', [ __CLASS__, 'maybe_sync' ], 10, 1 );

		// Thank you page confirmation display & polling
		add_action( 'woocommerce_thankyou', [ $this, 'render_thankyou_card' ], 5, 1 );
		add_action( 'wp_ajax_arc_poll_booking_status', [ $this, 'ajax_poll_status' ] );
		add_action( 'wp_ajax_nopriv_arc_poll_booking_status', [ $this, 'ajax_poll_status' ] );
	}

	/**
	 * Attempt to sync reservation to ASP.NET API idempotently.
	 *
	 * @param int $order_id Order ID.
	 * @return bool
	 */
	public static function maybe_sync( int $order_id ): bool {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return false;
		}

		// 1. Guard checks
		$order_type = $order->get_meta( '_arc_order_type' );
		if ( 'room' !== $order_type ) {
			return false;
		}

		$sync_status = $order->get_meta( '_arc_sync_status' );
		if ( 'synced' === $sync_status ) {
			return true; // Already synced successfully
		}

		// 2. Atomic lock acquisition to prevent concurrent synchronization
		$lock_key   = 'arc_lock_' . $order_id;
		$lock_time  = (int) get_option( $lock_key, 0 );
		$now        = time();

		if ( $lock_time > 0 && ( $now - $lock_time ) < 120 ) {
			return false; // Another process is currently executing sync
		}

		update_option( $lock_key, $now, 'no' );

		try {
			$booking   = (array) $order->get_meta( '_arc_booking' );
			$id_number = (string) $order->get_meta( '_arc_id_number' );
			$guests    = (array) $order->get_meta( '_arc_guests' );
			$requests  = (array) $order->get_meta( '_arc_requests' );
			$bed_type  = (string) $order->get_meta( '_arc_bed_type' );
			$arrival   = (string) $order->get_meta( '_arc_arrival' );

			// Merge special requests cleanly
			$request_parts = [];
			if ( ! empty( $bed_type ) ) {
				$request_parts[] = 'Bed: ' . $bed_type;
			}
			if ( ! empty( $arrival ) ) {
				$request_parts[] = 'Arrival: ' . $arrival;
			}
			if ( ! empty( $guests ) ) {
				$request_parts[] = 'Extra Guests: ' . implode( ', ', $guests );
			}
			if ( ! empty( $requests ) ) {
				$request_parts[] = 'Requests: ' . implode( '; ', $requests );
			}
			$special_requests_str = implode( ' | ', $request_parts );

			// Payment calculations
			$total_amount = (float) ( $booking['total_price'] ?? $order->get_total() );
			$gateway_id   = $order->get_payment_method();
			$is_offline   = ( 'arc_pay_at_hotel' === $gateway_id || 'cod' === $gateway_id );
			$amount_paid  = $is_offline ? 0.0 : (float) $order->get_total();

			$payment_method = Payment_Mapper::get_api_payment_method( $gateway_id );
			$payment_option = Payment_Mapper::get_api_payment_option( $amount_paid, $total_amount );
			$trx_id         = $order->get_transaction_id() ?: ( 'WC-' . $order_id );

			// Assemble API payload
			$check_in  = $booking['check_in'] ?? current_time( 'Y-m-d' );
			$check_out = $booking['check_out'] ?? gmdate( 'Y-m-d', strtotime( '+1 day' ) );

			$payload = [
				'companyCode'          => Settings::get( 'company_code' ),
				'checkInDate'          => $check_in . 'T14:00:00',
				'checkOutDate'         => $check_out . 'T12:00:00',
				'roomTypeId'           => (int) ( $booking['room_type_id'] ?? 0 ),
				'roomId'               => (int) ( $booking['api_room_id'] ?? 0 ),
				'adults'               => (int) ( $booking['adults'] ?? 2 ),
				'children'             => (int) ( $booking['children'] ?? 0 ),
				'fullName'             => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
				'phone'                => $order->get_billing_phone(),
				'email'                => $order->get_billing_email(),
				'nationalIdOrPassport' => $id_number,
				'specialRequests'      => $special_requests_str,
				'paymentOption'        => $payment_option,
				'paymentMethod'        => $payment_method,
				'amountPaid'           => $amount_paid,
				'transactionReference' => $trx_id,
				'paymentRemarks'       => sprintf( 'WooCommerce Order #%d via %s', $order_id, $order->get_payment_method_title() ),
				'externalReference'    => 'WC-' . $order_id, // Unique idempotency reference
				'expectedTotalAmount'  => $total_amount,
			];

			$client  = new Api_Client();
			$api_res = $client->post( 'api/v1/public-booking/create-reservation-json', $payload );

			if ( $api_res->ok() ) {
				$data = (array) $api_res->data();

				$order->update_meta_data( '_arc_sync_status', 'synced' );
				$order->update_meta_data( '_arc_aspnet_booking_id', $data['bookingId'] ?? 0 );
				$order->update_meta_data( '_arc_aspnet_reservation_no', $data['bookingNumber'] ?? '' );
				$order->update_meta_data( '_arc_aspnet_room_number', $data['roomNumber'] ?? '' );
				$order->update_meta_data( '_arc_aspnet_reg_token', $data['registrationToken'] ?? '' );
				$order->update_meta_data( '_arc_api_payment_status', $data['paymentStatus'] ?? '' );
				$order->save();

				$order->add_order_note(
					sprintf(
						/* translators: 1: Booking number, 2: Room number */
						__( 'ASP.NET Hotel Reservation created successfully. Official Booking No: %1$s, Room: %2$s', 'arc-hotel-core' ),
						$data['bookingNumber'] ?? 'N/A',
						$data['roomNumber'] ?? 'Auto-Assigned'
					)
				);

				delete_option( $lock_key );
				return true;
			}

			// Handle Sync Failure
			$error_code = $api_res->error_code();
			$error_msg  = $api_res->message();
			$attempts   = (int) $order->get_meta( '_arc_sync_attempts' ) + 1;

			$order->update_meta_data( '_arc_sync_attempts', $attempts );
			$order->update_meta_data( '_arc_sync_last_error', $error_msg );

			// Business errors (room taken, price mismatch) require immediate admin review
			if ( in_array( $error_code, [ 'ROOM_NOT_AVAILABLE', 'PRICE_CHANGED', 'HOLD_EXPIRED' ], true ) ) {
				$order->update_meta_data( '_arc_sync_status', 'manual' );
				$order->update_status( 'on-hold', sprintf( __( 'API Sync Conflict: %s. Requires manual room assignment or customer contact.', 'arc-hotel-core' ), $error_msg ) );
				\ARC\HotelCore\Emails\Email_Manager::send_admin_sync_failed_alert( $order_id, $error_msg, $error_code );
				delete_option( $lock_key );
				return false;
			}

			// Transient / Connection errors: schedule Action Scheduler retry with back-off
			$order->update_meta_data( '_arc_sync_status', 'pending' );
			$order->save();

			$retry_intervals = [ 1 => 60, 2 => 300, 3 => 900, 4 => 3600, 5 => 10800 ];
			if ( isset( $retry_intervals[ $attempts ] ) ) {
				wp_schedule_single_event( time() + $retry_intervals[ $attempts ], 'arc_retry_reservation_sync', [ $order_id ] );
				$order->add_order_note(
					sprintf(
						/* translators: 1: Attempt number, 2: Error message, 3: Minutes to next retry */
						__( 'API Sync Attempt #%1$d failed (%2$s). Next retry scheduled in %3$d minutes.', 'arc-hotel-core' ),
						$attempts,
						$error_msg,
						round( $retry_intervals[ $attempts ] / 60 )
					)
				);
			} else {
				$order->update_meta_data( '_arc_sync_status', 'failed' );
				$order->update_status( 'on-hold', __( 'Final API sync retry failed. Admin manual intervention required.', 'arc-hotel-core' ) );
				\ARC\HotelCore\Emails\Email_Manager::send_admin_sync_failed_alert( $order_id, $error_msg, 'FINAL_RETRY_FAILED' );
			}

			delete_option( $lock_key );
			return false;

		} catch ( \Throwable $e ) {
			delete_option( $lock_key );
			return false;
		}
	}

	/**
	 * Render official booking number card on Thank You page.
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public function render_thankyou_card( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order || 'room' !== $order->get_meta( '_arc_order_type' ) ) {
			return;
		}

		$status       = $order->get_meta( '_arc_sync_status' );
		$booking_no   = $order->get_meta( '_arc_aspnet_reservation_no' );
		$room_no      = $order->get_meta( '_arc_aspnet_room_number' );
		$booking_info = (array) $order->get_meta( '_arc_booking' );
		?>
		<div class="arc-confirmation-card" id="arc-thankyou-box" style="margin: 25px 0; padding: 25px; border-radius: 12px; border: 2px solid #0284c7; background: #f0f9ff;">
			<?php if ( 'synced' === $status && ! empty( $booking_no ) ) : ?>
				<div style="display: flex; align-items: center; gap: 15px;">
					<div style="font-size: 36px; color: #0284c7;">&#10004;</div>
					<div>
						<h2 style="margin: 0; color: #0369a1;"><?php esc_html_e( 'Official Hotel Reservation Confirmed!', 'arc-hotel-core' ); ?></h2>
						<p style="margin: 5px 0 0 0; color: #334155;">
							<?php esc_html_e( 'Official Booking Reference Number:', 'arc-hotel-core' ); ?>
							<strong style="font-size: 18px; color: #0f172a;"><?php echo esc_html( $booking_no ); ?></strong>
							<?php if ( ! empty( $room_no ) ) : ?>
								&bull; <?php esc_html_e( 'Assigned Room:', 'arc-hotel-core' ); ?> <strong><?php echo esc_html( $room_no ); ?></strong>
							<?php endif; ?>
						</p>
					</div>
				</div>
			<?php else : ?>
				<div id="arc-sync-pending-wrap" style="text-align: center; padding: 15px;">
					<h3 style="color: #0369a1; margin-top: 0;"><?php esc_html_e( 'Confirming Your Reservation with Hotel Front Desk...', 'arc-hotel-core' ); ?></h3>
					<p style="color: #475569;"><?php esc_html_e( 'Please wait a moment while your official booking code is generated by our hotel system.', 'arc-hotel-core' ); ?></p>
					<div class="arc-spinner" style="font-size: 24px;">&#8987;</div>
				</div>
				<?php
				wp_enqueue_script(
					'arc-thank-you-poll',
					ARC_PLUGIN_URL . 'assets/js/thank-you-poll.js',
					[ 'jquery' ],
					ARC_VERSION,
					true
				);
				wp_localize_script(
					'arc-thank-you-poll',
					'arcThankYouData',
					[
						'orderId' => absint( $order_id ),
						'nonce'   => wp_create_nonce( 'arc_poll_nonce_' . $order_id ),
						'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					]
				);
				?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * AJAX endpoint for Thank-You page polling.
	 *
	 * @return void
	 */
	public function ajax_poll_status(): void {
		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		check_ajax_referer( 'arc_poll_nonce_' . $order_id, 'nonce' );

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_send_json_error();
		}

		$status     = $order->get_meta( '_arc_sync_status' );
		$booking_no = $order->get_meta( '_arc_aspnet_reservation_no' );

		wp_send_json_success( [
			'synced'        => ( 'synced' === $status && ! empty( $booking_no ) ),
			'bookingNumber' => $booking_no,
		] );
	}
}
