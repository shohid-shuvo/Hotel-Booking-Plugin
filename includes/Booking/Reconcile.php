<?php
/** Paginated ERP reconciliation. Financial values and stay dates are never overwritten. */
namespace ARC\HotelCore\Booking;
use ARC\HotelCore\Api\Api_Client;
use ARC\HotelCore\Settings\Settings;
use ARC\HotelCore\Support\Database_Lock;
use ARC\HotelCore\Support\Guest_Identity;
use ARC\HotelCore\Support\Order_Query;
defined( 'ABSPATH' ) || exit;

class Reconcile {
	public function init(): void {
		add_action( 'arc_daily_reconcile', [ $this, 'run_reconciliation' ] );
		add_action( 'wp_ajax_arc_manual_reconcile', [ $this, 'ajax_manual_reconcile' ] );
	}
	public function run_reconciliation(): array {
		$summary = [ 'success'=>true, 'checked'=>0, 'updated'=>0, 'errors'=>0 ];
		if ( ! Database_Lock::acquire( 'reconcile' ) ) { return [ 'success'=>false, 'checked'=>0, 'updated'=>0, 'errors'=>1 ]; }
		try {
			// Snapshot IDs before changing states, so pagination never skips modified rows.
			$ids = [];
			$page = 1;
			do {
				$batch = Order_Query::get_orders( [ 'limit'=>50, 'page'=>$page++, 'orderby'=>'date', 'order'=>'ASC', 'meta_query'=>[ [ 'key'=>'_arc_order_type','value'=>'room' ], [ 'key'=>'_arc_sync_status','value'=>[ 'synced','sending' ],'compare'=>'IN' ] ] ] );
				$added = 0;
				foreach ( $batch as $order ) {
					if ( ! isset( $ids[ $order->get_id() ] ) ) { $ids[ $order->get_id() ] = $order->get_id(); $added++; }
				}
				if ( $batch && ! $added ) { $summary['errors']++; break; }
			} while ( count( $batch ) === 50 );
			foreach ( $ids as $id ) {
				$order = wc_get_order( $id );
				if ( ! $order ) { continue; }
				$order->read_meta_data( true );
				if ( 'sending' === $order->get_meta( '_arc_sync_status' ) ) {
					if ( (int) $order->get_meta( '_arc_sync_started_at' ) < time() - 120 ) { Reservation_Sync::observe_inflight( $id ); }
					continue;
				}
				$booking = (array) $order->get_meta( '_arc_booking' );
				if ( 'synced' !== $order->get_meta( '_arc_sync_status' ) || ( $booking['check_out'] ?? '' ) < current_time( 'Y-m-d' ) || ! Database_Lock::acquire( 'order_' . $id ) ) { continue; }
				try {
					$summary['checked']++;
					$reference = (string) $order->get_meta( '_arc_aspnet_reservation_no' );
					$result = ( new Api_Client() )->get( 'api/v1/public-booking/track/' . rawurlencode( $reference ), [], [ 'use_cache'=>false ] );
					if ( ! $result->ok() ) { $summary['errors']++; continue; }
					$row = is_array( $result->data() ) ? Guest_Identity::find_booking( $result->data(), $reference, $order->get_billing_phone(), Settings::get( 'company_code' ) ) : null;
					if ( ! $row || ! is_numeric( $row['totalAmount'] ?? null )
						|| substr( (string) ( $row['checkInDate'] ?? '' ),0,10 ) !== ( $booking['check_in'] ?? '' )
						|| substr( (string) ( $row['checkOutDate'] ?? '' ),0,10 ) !== ( $booking['check_out'] ?? '' )
						|| abs( (float) $row['totalAmount'] - (float) ( $booking['total_price'] ?? 0 ) ) > 0.01 ) {
						$message = __( 'ERP reconciliation found missing or changed booking details. Review dates and payment; no automatic refund was made.', 'arc-hotel-core' );
						$order->update_meta_data( '_arc_sync_status','manual' );
						$order->update_meta_data( '_arc_sync_last_error',$message );
						$order->save(); $order->add_order_note( $message );
						\ARC\HotelCore\Emails\Email_Manager::send_admin_sync_failed_alert( $id,$message,'RECONCILE' );
						$summary['updated']++; continue;
					}
					$changed = false;
					foreach ( [ 'roomNumber'=>'_arc_aspnet_room_number','paymentStatus'=>'_arc_api_payment_status','paidAmount'=>'_arc_api_paid_amount','dueAmount'=>'_arc_api_due_amount' ] as $field=>$meta ) {
						if ( isset( $row[ $field ] ) && (string) $row[ $field ] !== (string) $order->get_meta( $meta ) ) {
							$order->update_meta_data( $meta, sanitize_text_field( (string) $row[ $field ] ) ); $changed = true;
						}
					}
					if ( strtolower( trim( (string) ( $row['status'] ?? '' ) ) ) === 'cancelled' && ! in_array( $order->get_status(),[ 'cancelled','refunded' ],true ) ) {
						$order->update_status( 'cancelled', __( 'ERP cancellation confirmed. No automatic WooCommerce refund was made.', 'arc-hotel-core' ) ); $changed = true;
					}
					if ( $changed ) { $order->save(); $order->add_order_note( __( 'ERP room/payment information was refreshed.', 'arc-hotel-core' ) ); $summary['updated']++; }
				} catch ( \Throwable $error ) { $summary['errors']++; }
				finally { Database_Lock::release( 'order_' . $id ); }
			}
			update_option( 'arc_cron_last_run',time(),false );
			$summary['success'] = 0 === $summary['errors'];
		} finally { Database_Lock::release( 'reconcile' ); }
		return $summary;
	}
	public function ajax_manual_reconcile(): void {
		check_ajax_referer( 'arc_reconcile_nonce','nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_send_json_error( [ 'message'=>__( 'Permission denied.','arc-hotel-core' ) ],403 ); }
		wp_send_json_success( $this->run_reconciliation() );
	}
}
