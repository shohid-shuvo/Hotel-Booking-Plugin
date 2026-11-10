<?php
/**
 * WooCommerce HPOS-Compatible Order Meta Box.
 *
 * @package ARC\HotelCore\Admin
 */

namespace ARC\HotelCore\Admin;

use ARC\HotelCore\Booking\Reservation_Sync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders Hotel Booking information and API Sync controls on Order edit screen.
 */
class Order_Meta_Box {

	/**
	 * Hook order meta boxes and AJAX actions.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'add_meta_boxes', [ $this, 'register_meta_box' ] );
		add_action( 'wp_ajax_arc_manual_retry_sync', [ $this, 'ajax_retry_sync' ] );
	}

	/**
	 * Register meta box supporting both HPOS and legacy posts.
	 *
	 * @return void
	 */
	public function register_meta_box(): void {
		$screen = class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' ) &&
			wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled()
			? wc_get_page_screen_id( 'shop-order' )
			: 'shop_order';

		add_meta_box(
			'arc_hotel_booking_meta',
			__( 'ASP.NET Hotel Reservation Details', 'arc-hotel-core' ),
			[ $this, 'render' ],
			$screen,
			'side',
			'high'
		);
	}

	/**
	 * Render the meta box content.
	 *
	 * @param \WP_Post|\WC_Order $post_or_order Post or Order object.
	 * @return void
	 */
	public function render( $post_or_order ): void {
		$order = ( $post_or_order instanceof \WC_Order ) ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order ) {
			return;
		}

		$order_type = $order->get_meta( '_arc_order_type' );
		if ( 'room' !== $order_type ) {
			echo '<p style="color: #64748b; margin: 0;">' . esc_html__( 'This is a standard restaurant food or merchandise order (No hotel room attached).', 'arc-hotel-core' ) . '</p>';
			return;
		}

		$booking_no   = $order->get_meta( '_arc_aspnet_reservation_no' );
		$booking_id   = $order->get_meta( '_arc_aspnet_booking_id' );
		$room_no      = $order->get_meta( '_arc_aspnet_room_number' );
		$sync_status  = $order->get_meta( '_arc_sync_status' ) ?: 'pending';
		$raw_id       = (string) $order->get_meta( '_arc_id_number' );
		$masked_id    = strlen( $raw_id ) > 4 ? str_repeat( '•', strlen( $raw_id ) - 4 ) . substr( $raw_id, -4 ) : $raw_id;
		$booking_info = (array) $order->get_meta( '_arc_booking' );
		$bed_type     = $order->get_meta( '_arc_bed_type' );
		$arrival      = $order->get_meta( '_arc_arrival' );
		$last_error   = $order->get_meta( '_arc_sync_last_error' );
		$order_id     = $order->get_id();
		?>
		<div class="arc-admin-order-meta">
			<div style="margin-bottom: 15px;">
				<span class="arc-status-badge arc-badge-<?php echo esc_attr( $sync_status ); ?>" style="display: inline-block; padding: 4px 10px; border-radius: 4px; font-weight: bold; font-size: 12px; text-transform: uppercase; background: <?php echo 'synced' === $sync_status ? '#e8f5e9; color: #2e7d32;' : ( 'failed' === $sync_status ? '#ffebee; color: #c62828;' : '#fff8e1; color: #f57f17;' ); ?>">
					<?php echo esc_html( ucfirst( $sync_status ) ); ?>
				</span>
			</div>

			<table class="widefat striped" style="border: none;">
				<tbody>
					<tr>
						<td><strong><?php esc_html_e( 'Booking No:', 'arc-hotel-core' ); ?></strong></td>
						<td><strong><?php echo esc_html( $booking_no ?: 'Pending Sync' ); ?></strong></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'API Booking ID:', 'arc-hotel-core' ); ?></strong></td>
						<td><code><?php echo esc_html( $booking_id ?: 'N/A' ); ?></code></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Room Number:', 'arc-hotel-core' ); ?></strong></td>
						<td><?php echo esc_html( $room_no ?: 'Auto-Assigned' ); ?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Stay Dates:', 'arc-hotel-core' ); ?></strong></td>
						<td><?php echo esc_html( ( $booking_info['check_in'] ?? '' ) . ' to ' . ( $booking_info['check_out'] ?? '' ) ); ?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Guests:', 'arc-hotel-core' ); ?></strong></td>
						<td><?php echo esc_html( ( $booking_info['adults'] ?? 1 ) . ' Adults, ' . ( $booking_info['children'] ?? 0 ) . ' Children' ); ?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Guest ID:', 'arc-hotel-core' ); ?></strong></td>
						<td>
							<span id="arc-masked-id-<?php echo esc_attr( $order_id ); ?>"><?php echo esc_html( $masked_id ); ?></span>
							<span id="arc-full-id-<?php echo esc_attr( $order_id ); ?>" style="display: none;"><?php echo esc_html( $raw_id ); ?></span>
							<?php if ( ! empty( $raw_id ) ) : ?>
								<button type="button" class="button button-small" onclick="arcToggleId(<?php echo esc_attr( $order_id ); ?>);"><?php esc_html_e( 'Reveal', 'arc-hotel-core' ); ?></button>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Preferences:', 'arc-hotel-core' ); ?></strong></td>
						<td><?php echo esc_html( $bed_type . ' | ' . $arrival ); ?></td>
					</tr>
				</tbody>
			</table>

			<?php if ( ! empty( $last_error ) && 'synced' !== $sync_status ) : ?>
				<div style="margin-top: 12px; padding: 8px; background: #ffebee; border-left: 3px solid #d32f2f; font-size: 12px; color: #b71c1c;">
					<strong><?php esc_html_e( 'Last Sync Error:', 'arc-hotel-core' ); ?></strong><br>
					<?php echo esc_html( $last_error ); ?>
				</div>
			<?php endif; ?>

			<?php if ( 'synced' !== $sync_status ) : ?>
				<div style="margin-top: 15px;">
					<button type="button" id="arc-retry-sync-btn" class="button button-primary" style="width: 100%;" onclick="arcRetrySync(<?php echo esc_attr( $order_id ); ?>);">
						<?php esc_html_e( 'Retry API Sync Now', 'arc-hotel-core' ); ?>
					</button>
				</div>
			<?php endif; ?>
		</div>

		<script>
		function arcToggleId(orderId) {
			var masked = document.getElementById('arc-masked-id-' + orderId);
			var full = document.getElementById('arc-full-id-' + orderId);
			if (masked.style.display === 'none') {
				masked.style.display = 'inline';
				full.style.display = 'none';
			} else {
				masked.style.display = 'none';
				full.style.display = 'inline';
			}
		}

		function arcRetrySync(orderId) {
			var btn = jQuery('#arc-retry-sync-btn');
			btn.prop('disabled', true).text('<?php echo esc_js( __( 'Syncing with API...', 'arc-hotel-core' ) ); ?>');

			jQuery.post(ajaxurl, {
				action: 'arc_manual_retry_sync',
				order_id: orderId,
				nonce: '<?php echo esc_js( wp_create_nonce( 'arc_retry_sync_nonce' ) ); ?>'
			}, function(response) {
				if (response.success) {
					alert('<?php echo esc_js( __( 'Reservation synchronized successfully!', 'arc-hotel-core' ) ); ?>');
					location.reload();
				} else {
					alert(response.data ? response.data.message : '<?php echo esc_js( __( 'Sync failed.', 'arc-hotel-core' ) ); ?>');
					btn.prop('disabled', false).text('<?php echo esc_js( __( 'Retry API Sync Now', 'arc-hotel-core' ) ); ?>');
				}
			});
		}
		</script>
		<?php
	}

	/**
	 * AJAX handler for manual admin retry.
	 *
	 * @return void
	 */
	public function ajax_retry_sync(): void {
		check_ajax_referer( 'arc_retry_sync_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'arc-hotel-core' ) ] );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$success  = Reservation_Sync::maybe_sync( $order_id );

		if ( $success ) {
			wp_send_json_success();
		} else {
			$order = wc_get_order( $order_id );
			$err   = $order ? $order->get_meta( '_arc_sync_last_error' ) : 'Sync failed';
			wp_send_json_error( [ 'message' => $err ] );
		}
	}
}
