<?php
/** Safe post-payment synchronization against the current non-idempotent API. */
namespace ARC\HotelCore\Booking;

use ARC\HotelCore\Api\Api_Client;
use ARC\HotelCore\Payment\Payment_Mapper;
use ARC\HotelCore\Settings\Settings;
use ARC\HotelCore\Support\Database_Lock;
use ARC\HotelCore\Support\Guest_Identity;
defined( 'ABSPATH' ) || exit;

class Reservation_Sync {
	public function init(): void {
		foreach ( [ 'woocommerce_payment_complete', 'woocommerce_order_status_processing', 'woocommerce_order_status_completed', 'woocommerce_order_status_on-hold', 'arc_retry_reservation_sync' ] as $hook ) {
			add_action( $hook, [ __CLASS__, 'maybe_sync' ], 10, 1 );
		}
		add_action( 'arc_observe_reservation_sync', [ __CLASS__, 'observe_inflight' ], 10, 1 );
		add_action( 'woocommerce_thankyou', [ $this, 'render_thankyou_card' ], 5, 1 );
		add_action( 'wp_ajax_arc_poll_booking_status', [ $this, 'ajax_poll_status' ] );
		add_action( 'wp_ajax_nopriv_arc_poll_booking_status', [ $this, 'ajax_poll_status' ] );
	}

	private static function eligible( $order ): bool {
		if ( 'room' !== $order->get_meta( '_arc_order_type' )
			|| in_array( $order->get_status(), [ 'cancelled', 'refunded', 'failed', 'trash' ], true )
			|| $order->get_total_refunded() > 0 ) {
			return false;
		}
		if ( in_array( $order->get_payment_method(), [ 'arc_pay_at_hotel', 'cod' ], true ) ) {
			return in_array( $order->get_status(), [ 'on-hold', 'processing', 'completed' ], true );
		}
		return $order->is_paid() || ( 'on-hold' === $order->get_status() && $order->get_date_paid() );
	}

	private static function review( $order, string $state, string $message ): void {
		$order->update_meta_data( '_arc_sync_status', $state );
		$order->update_meta_data( '_arc_sync_last_error', $message );
		$order->save();
		wp_clear_scheduled_hook( 'arc_retry_reservation_sync', [ $order->get_id() ] );
		$order->add_order_note( $message );
		if ( ! in_array( $order->get_status(), [ 'cancelled', 'refunded', 'failed' ], true ) ) {
			$order->update_status( 'on-hold' );
		}
		\ARC\HotelCore\Emails\Email_Manager::send_admin_sync_failed_alert( $order->get_id(), $message, strtoupper( $state ) );
	}

	/** Only failed reads are safe to retry automatically. No write is retried. */
	private static function retry_read( $order, string $message ): void {
		$attempt = (int) $order->get_meta( '_arc_sync_attempts' ) + 1;
		$order->update_meta_data( '_arc_sync_attempts', $attempt );
		$delays = [ 60, 300, 900, 3600, 10800 ];
		if ( $attempt > count( $delays ) ) {
			self::review( $order, 'failed', __( 'Availability verification failed repeatedly. No reservation was sent. Please review this order.', 'arc-hotel-core' ) );
			return;
		}
		$order->update_meta_data( '_arc_sync_status', 'pending' );
		$order->update_meta_data( '_arc_sync_last_error', $message );
		$order->save();
		if ( ! wp_next_scheduled( 'arc_retry_reservation_sync', [ $order->get_id() ] ) ) {
			wp_schedule_single_event( time() + $delays[ $attempt - 1 ], 'arc_retry_reservation_sync', [ $order->get_id() ] );
		}
	}

	public static function maybe_sync( int $order_id ): bool {
		$key = 'order_' . $order_id;
		if ( ! Database_Lock::acquire( $key ) ) {
			return false;
		}
		$order = null;
		$sent = false;
		$room_lock = null;
		try {
			$order = wc_get_order( $order_id );
			if ( ! $order ) { return false; }
			$order->read_meta_data( true );
			if ( $order->get_meta( '_arc_aspnet_booking_id' ) || $order->get_meta( '_arc_aspnet_reservation_no' ) ) {
				return 'synced' === $order->get_meta( '_arc_sync_status' );
			}
			$state = (string) $order->get_meta( '_arc_sync_status' );
			if ( 'sending' === $state ) {
				self::review( $order, 'unknown', __( 'The previous reservation result is unknown. Check the hotel ERP before any retry.', 'arc-hotel-core' ) );
				return false;
			}
			if ( in_array( $state, [ 'unknown', 'manual', 'failed', 'synced' ], true ) || ! self::eligible( $order ) ) {
				return false;
			}
			if ( '1.1.0' !== $order->get_meta( '_arc_sync_protocol' )
				&& ( (int) $order->get_meta( '_arc_sync_attempts' ) > 0 || get_option( 'arc_lock_' . $order_id ) ) ) {
				self::review( $order, 'unknown', __( 'A previous plugin version attempted this reservation. Check the hotel ERP before retrying.', 'arc-hotel-core' ) );
				return false;
			}
			$order->update_meta_data( '_arc_sync_protocol', '1.1.0' );
			if ( 'BDT' !== $order->get_currency() ) {
				self::review( $order, 'manual', __( 'The order currency differs from the API currency BDT. Review payment before reservation.', 'arc-hotel-core' ) );
				return false;
			}
			$booking = Room_Quote::normalize_booking( (array) $order->get_meta( '_arc_booking' ) );
			if ( ( $booking['company_code'] ?? '' ) !== Settings::get( 'company_code' ) || empty( $booking['room_type_id'] ) || empty( $booking['check_in'] ) || empty( $booking['check_out'] ) || empty( $booking['total_price'] ) ) {
				self::review( $order, 'manual', __( 'Room booking metadata is incomplete. Review the selected room and dates before retrying.', 'arc-hotel-core' ) );
				return false;
			}
			if ( $booking['api_room_id'] > 0 ) {
				$room_lock = 'room_' . Settings::get( 'company_code' ) . '_' . $booking['api_room_id'];
				if ( ! Database_Lock::acquire( $room_lock ) ) {
					$room_lock = null;
					self::retry_read( $order, __( 'Another booking is being verified for this room. No reservation was sent.', 'arc-hotel-core' ) );
					return false;
				}
			}
			$result = Room_Quote::search( $booking['check_in'], $booking['check_out'], (int) ( $booking['adults'] ?? 0 ), (int) ( $booking['children'] ?? 0 ), (int) $booking['room_type_id'] );
			if ( ! $result->ok() ) {
				if ( in_array( $result->error_code(), [ 'CONNECTION_ERROR', 'SERVER_ERROR', 'INVALID_JSON', 'INVALID_RESPONSE', 'RATE_LIMITED' ], true ) ) {
					self::retry_read( $order, __( 'Hotel availability could not be verified. No reservation has been sent.', 'arc-hotel-core' ) );
				} else {
					self::review( $order, 'manual', __( 'The hotel could not verify this room booking. Review configuration, dates and guest counts.', 'arc-hotel-core' ) );
				}
				return false;
			}
			$room = Room_Quote::select( $result->data(), (int) $booking['room_type_id'], (int) $booking['api_room_id'] );
			if ( $room && null === $room_lock ) {
				$room_lock = 'room_' . Settings::get( 'company_code' ) . '_' . $room['roomId'];
				if ( ! Database_Lock::acquire( $room_lock ) ) {
					$room_lock = null;
					self::retry_read( $order, __( 'The selected room is being verified by another booking. No reservation was sent.', 'arc-hotel-core' ) );
					return false;
				}
				$result = Room_Quote::search( $booking['check_in'], $booking['check_out'], (int) $booking['adults'], (int) $booking['children'], (int) $booking['room_type_id'] );
				$room = $result->ok() ? Room_Quote::select( $result->data(), (int) $booking['room_type_id'], (int) $room['roomId'] ) : null;
			}
			$total = (float) $booking['total_price'];
			if ( ! $room || abs( (float) $room['totalPrice'] - $total ) > 0.01 ) {
				self::review( $order, 'manual', __( 'The selected room or its price changed. Review room reassignment or refund before retrying.', 'arc-hotel-core' ) );
				return false;
			}
			$room_total = 0.0;
			foreach ( $order->get_items( 'line_item' ) as $item ) { $room_total += (float) $item->get_total(); }
			// Gateway fees remain in WooCommerce; only the room amount is posted to ERP.
			if ( abs( $room_total - $total ) > 0.01 || (float) $order->get_total() + 0.01 < $total ) {
				self::review( $order, 'manual', __( 'The room line total differs from the API quote. Review discounts and payment before reservation.', 'arc-hotel-core' ) );
				return false;
			}
			$gateway = $order->get_payment_method();
			$paid = in_array( $gateway, [ 'arc_pay_at_hotel', 'cod' ], true ) ? 0.0 : $total;
			$parts = [ '[WEBSITE WC-' . $order_id . ']' ];
			foreach ( [ '_arc_bed_type' => 'Bed', '_arc_arrival' => 'Arrival' ] as $meta => $label ) {
				if ( $order->get_meta( $meta ) ) { $parts[] = $label . ': ' . $order->get_meta( $meta ); }
			}
			foreach ( [ '_arc_guests' => 'Extra Guests', '_arc_requests' => 'Requests' ] as $meta => $label ) {
				$values = array_filter( (array) $order->get_meta( $meta ), 'is_scalar' );
				if ( $values ) { $parts[] = $label . ': ' . implode( '; ', $values ); }
			}
			$payload = [
				'companyCode' => Settings::get( 'company_code' ),
				'checkInDate' => Settings::stay_datetime( $booking['check_in'], true ),
				'checkOutDate' => Settings::stay_datetime( $booking['check_out'], false ),
				'roomTypeId' => (int) $room['roomTypeId'], 'roomId' => (int) $room['roomId'],
				'adults' => (int) $booking['adults'], 'children' => (int) $booking['children'],
				'fullName' => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
				'phone' => $order->get_billing_phone(), 'email' => $order->get_billing_email(),
				'nationalIdOrPassport' => (string) $order->get_meta( '_arc_id_number' ),
				'specialRequests' => implode( ' | ', $parts ), 'discountAmount' => 0,
				'paymentOption' => Payment_Mapper::get_api_payment_option( $paid, $total ),
				'paymentMethod' => Payment_Mapper::get_api_payment_method( $gateway ), 'amountPaid' => $paid,
				'transactionReference' => $order->get_transaction_id() ?: 'WC-' . $order_id,
				'paymentRemarks' => '[WEBSITE WC-' . $order_id . '] ' . $order->get_payment_method_title(),
			];
			// Re-read payment/cancellation after the network preflight, before the write.
			$order = wc_get_order( $order_id );
			$order->read_meta_data( true );
			if ( ! self::eligible( $order ) ) { return false; }
			$order->update_meta_data( '_arc_booking', array_replace( $booking, [ 'room_id' => (int) $room['roomId'], 'api_room_id' => (int) $room['roomId'] ] ) );
			$order->update_meta_data( '_arc_sync_status', 'sending' );
			$order->update_meta_data( '_arc_sync_started_at', time() );
			if ( ! $order->save() ) { return false; }
			$order->read_meta_data( true );
			if ( 'sending' !== $order->get_meta( '_arc_sync_status' ) ) { return false; }
			wp_clear_scheduled_hook( 'arc_retry_reservation_sync', [ $order_id ] );
			if ( ! wp_next_scheduled( 'arc_observe_reservation_sync', [ $order_id ] )
				&& ! wp_schedule_single_event( time() + 120, 'arc_observe_reservation_sync', [ $order_id ] ) ) {
				self::review( $order, 'manual', __( 'Reservation recovery monitoring could not be scheduled. No reservation was sent.', 'arc-hotel-core' ) );
				return false;
			}
			$sent = true;
			$response = ( new Api_Client() )->post( 'api/v1/public-booking/create-reservation-json', $payload );
			$data = $response->ok() && is_array( $response->data() ) ? $response->data() : [];
			$order = wc_get_order( $order_id );
			$order->read_meta_data( true );
			if ( ! empty( $data['bookingNumber'] ) && (int) ( $data['bookingId'] ?? 0 ) > 0 ) {
				self::store_confirmation( $order, $data );
				$matches = self::amounts_match( $data, $total, $paid )
					&& ( empty( $room['roomNumber'] ) || (string) ( $data['roomNumber'] ?? '' ) === (string) $room['roomNumber'] )
					&& substr( (string) ( $data['checkInDate'] ?? '' ), 0, 10 ) === $booking['check_in']
					&& substr( (string) ( $data['checkOutDate'] ?? '' ), 0, 10 ) === $booking['check_out']
					&& ( (string) ( $data['companyCode'] ?? '' ) === Settings::get( 'company_code' ) )
					&& Guest_Identity::matching_phone( (string) ( $data['guestPhone'] ?? '' ), $payload['phone'] );
				if ( ! $matches || ! self::eligible( $order ) ) {
					self::review( $order, 'manual', __( 'A reservation reference was received, but booking/payment details require review. Do not create another reservation.', 'arc-hotel-core' ) );
					return false;
				}
				$order->update_meta_data( '_arc_sync_status', 'synced' );
				$order->update_meta_data( '_arc_sync_last_error', '' );
				$order->save();
				$order->add_order_note( sprintf( __( 'Hotel reservation confirmed: %s.', 'arc-hotel-core' ), $data['bookingNumber'] ) );
				wp_clear_scheduled_hook( 'arc_observe_reservation_sync', [ $order_id ] );
				try { do_action( 'arc_reservation_confirmed', $order_id ); } catch ( \Throwable $email_error ) {
					$order->add_order_note( __( 'The reservation is confirmed, but its confirmation email needs review.', 'arc-hotel-core' ) );
				}
				return true;
			}
			$ambiguous = $response->ok() || $response->http_status() === 0 || $response->http_status() >= 500
				|| in_array( $response->error_code(), [ 'INVALID_JSON', 'INVALID_RESPONSE' ], true );
			self::review( $order, $ambiguous ? 'unknown' : 'manual', $ambiguous
				? __( 'Reservation result is unknown. Check the ERP and link the existing reservation before retrying.', 'arc-hotel-core' )
				: __( 'The hotel rejected this reservation. Review the order before a manual retry.', 'arc-hotel-core' ) );
			return false;
		} catch ( \Throwable $error ) {
			if ( $order ) {
				self::review( $order, $sent ? 'unknown' : 'manual', $sent
					? __( 'Reservation processing was interrupted after sending. Check the ERP before retrying.', 'arc-hotel-core' )
					: __( 'Reservation processing was interrupted before sending. Review the order.', 'arc-hotel-core' ) );
			}
			return false;
		} finally {
			if ( null !== $room_lock ) { Database_Lock::release( $room_lock ); }
			Database_Lock::release( $key );
		}
	}

	private static function amounts_match( array $data, float $total, float $paid ): bool {
		return is_numeric( $data['totalAmount'] ?? null ) && is_numeric( $data['paidAmount'] ?? null )
			&& abs( (float) $data['totalAmount'] - $total ) <= 0.01 && abs( (float) $data['paidAmount'] - $paid ) <= 0.01;
	}
	private static function store_confirmation( $order, array $data ): void {
		foreach ( [ 'bookingId' => '_arc_aspnet_booking_id', 'bookingNumber' => '_arc_aspnet_reservation_no', 'roomNumber' => '_arc_aspnet_room_number', 'registrationToken' => '_arc_aspnet_reg_token', 'paymentStatus' => '_arc_api_payment_status', 'totalAmount' => '_arc_api_total_amount', 'paidAmount' => '_arc_api_paid_amount', 'dueAmount' => '_arc_api_due_amount' ] as $field => $meta ) {
			if ( isset( $data[ $field ] ) ) { $order->update_meta_data( $meta, $data[ $field ] ); }
		}
		$order->save();
	}
	public static function observe_inflight( int $order_id ): void {
		$key = 'order_' . $order_id;
		if ( ! Database_Lock::acquire( $key ) ) {
			if ( ! wp_next_scheduled( 'arc_observe_reservation_sync', [ $order_id ] ) ) {
				wp_schedule_single_event( time() + 120, 'arc_observe_reservation_sync', [ $order_id ] );
			}
			return;
		}
		try {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				$order->read_meta_data( true );
				if ( 'sending' === $order->get_meta( '_arc_sync_status' ) ) {
					self::review( $order, 'unknown', __( 'Reservation confirmation was interrupted. Check the ERP before any retry.', 'arc-hotel-core' ) );
				}
			}
		} finally { Database_Lock::release( $key ); }
	}

	/** Admin recovery is explicit: attach an ERP reference or confirm no booking exists. */
	public static function recover_order( int $order_id, string $reference, bool $checked ): bool {
		if ( ! $checked || ! Database_Lock::acquire( 'order_' . $order_id ) ) { return false; }
		$retry = false;
		try {
			$order = wc_get_order( $order_id );
			if ( ! $order ) { return false; }
			$order->read_meta_data( true );
			if ( 'room' !== $order->get_meta( '_arc_order_type' ) || $order->get_meta( '_arc_aspnet_reservation_no' ) || $order->get_meta( '_arc_aspnet_booking_id' ) ) { return false; }
			if ( '' !== $reference ) {
				$result = ( new Api_Client() )->get( 'api/v1/public-booking/track/' . rawurlencode( $reference ), [], [ 'use_cache' => false ] );
				$row = $result->ok() && is_array( $result->data() ) ? Guest_Identity::find_booking( $result->data(), $reference, $order->get_billing_phone(), Settings::get( 'company_code' ) ) : null;
				$booking = (array) $order->get_meta( '_arc_booking' );
				if ( ! $row || empty( $row['bookingId'] ) || substr( (string) ( $row['checkInDate'] ?? '' ), 0, 10 ) !== ( $booking['check_in'] ?? '' ) || substr( (string) ( $row['checkOutDate'] ?? '' ), 0, 10 ) !== ( $booking['check_out'] ?? '' ) ) { return false; }
				foreach ( \ARC\HotelCore\Support\Order_Query::get_orders( [ 'limit'=>2, 'meta_query'=>[ [ 'key'=>'_arc_aspnet_reservation_no', 'value'=>$reference ] ] ] ) as $linked ) {
					if ( $linked->get_id() !== $order_id ) { return false; }
				}
				if ( ! empty( $row['roomType'] ) && ! empty( $booking['room_type_name'] ) && strcasecmp( trim( $row['roomType'] ), trim( $booking['room_type_name'] ) ) !== 0 ) { return false; }
				$paid = in_array( $order->get_payment_method(), [ 'arc_pay_at_hotel', 'cod' ], true ) ? 0.0 : (float) ( $booking['total_price'] ?? 0 );
				self::store_confirmation( $order, $row );
				$order->update_meta_data( '_arc_sync_status', self::amounts_match( $row, (float) ( $booking['total_price'] ?? 0 ), $paid ) && self::eligible( $order ) && strtolower( (string) ( $row['status'] ?? '' ) ) === 'reserved' ? 'synced' : 'manual' );
				$order->save();
				$order->add_order_note( __( 'Administrator checked the hotel ERP and linked its existing reservation. No reservation POST was made.', 'arc-hotel-core' ) );
				wp_clear_scheduled_hook( 'arc_retry_reservation_sync', [ $order_id ] );
				wp_clear_scheduled_hook( 'arc_observe_reservation_sync', [ $order_id ] );
				if ( 'synced' === $order->get_meta( '_arc_sync_status' ) ) { do_action( 'arc_reservation_confirmed', $order_id ); }
				return true;
			}
			$booking = (array) $order->get_meta( '_arc_booking' );
			$booking['company_code'] = Settings::get( 'company_code' );
			$order->update_meta_data( '_arc_booking', $booking );
			$order->update_meta_data( '_arc_sync_status', 'pending' );
			$order->update_meta_data( '_arc_sync_attempts', 0 );
			$order->update_meta_data( '_arc_sync_protocol', '1.1.0' );
			$order->save();
			$order->add_order_note( __( 'Administrator confirmed that no ERP reservation exists and requested a new reservation attempt.', 'arc-hotel-core' ) );
			$retry = true;
		} finally { Database_Lock::release( 'order_' . $order_id ); }
		return $retry && self::maybe_sync( $order_id );
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
			<?php elseif ( in_array( $status, [ 'unknown', 'manual', 'failed' ], true ) ) : ?>
				<p><?php esc_html_e( 'Your order was received and the hotel is reviewing its reservation confirmation. Please contact the hotel with your WooCommerce order number.', 'arc-hotel-core' ); ?></p>
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
						'orderKey' => $order->get_order_key(),
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
		if ( ! $order || 'room' !== $order->get_meta( '_arc_order_type' )
			|| ! isset( $_POST['order_key'] ) || ! is_string( $_POST['order_key'] )
			|| ! hash_equals( $order->get_order_key(), wp_unslash( $_POST['order_key'] ) ) ) {
			wp_send_json_error( [ 'message' => __( 'Unable to verify this order.', 'arc-hotel-core' ) ], 403 );
		}

		$status     = $order->get_meta( '_arc_sync_status' );
		$booking_no = $order->get_meta( '_arc_aspnet_reservation_no' );

		wp_send_json_success( [
			'synced'        => ( 'synced' === $status && ! empty( $booking_no ) ),
			'bookingNumber' => $booking_no,
			'terminal' => in_array( $status, [ 'unknown', 'manual', 'failed' ], true ),
			'message' => __( 'The hotel is reviewing this reservation. Please contact the hotel with your order number.', 'arc-hotel-core' ),
		] );
	}
}
