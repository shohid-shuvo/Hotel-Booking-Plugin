<?php
/**
 * Email Notifications Manager.
 *
 * @package ARC\HotelCore\Emails
 */

namespace ARC\HotelCore\Emails;

use ARC\HotelCore\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles custom hotel confirmation emails and admin sync-failed alerts.
 */
class Email_Manager {

	/**
	 * Hook email actions.
	 *
	 * @return void
	 */
	public function init(): void {
		// Inject hotel voucher details into customer and admin WooCommerce emails
		add_action( 'woocommerce_email_order_details', [ $this, 'attach_hotel_details_to_email' ], 25, 4 );
	}

	/**
	 * Inject formatted hotel reservation card into WooCommerce emails for room orders.
	 *
	 * @param \WC_Order $order         Order object.
	 * @param bool      $sent_to_admin Whether sent to admin.
	 * @param bool      $plain_text    Plain text format flag.
	 * @param \WC_Email $email         Email object.
	 * @return void
	 */
	public function attach_hotel_details_to_email( \WC_Order $order, bool $sent_to_admin, bool $plain_text, $email ): void {
		if ( 'room' !== $order->get_meta( '_arc_order_type' ) ) {
			return;
		}

		$booking_no   = $order->get_meta( '_arc_aspnet_reservation_no' );
		$room_no      = $order->get_meta( '_arc_aspnet_room_number' );
		$booking_info = (array) $order->get_meta( '_arc_booking' );
		$raw_id       = (string) $order->get_meta( '_arc_id_number' );
		$masked_id    = strlen( $raw_id ) > 4 ? str_repeat( '•', strlen( $raw_id ) - 4 ) . substr( $raw_id, -4 ) : $raw_id;
		$bed_type     = $order->get_meta( '_arc_bed_type' );
		$arrival      = $order->get_meta( '_arc_arrival' );
		$policy       = Settings::get( 'cancellation_policy_text' );

		if ( $plain_text ) {
			echo "\n========================================\n";
			echo "OFFICIAL HOTEL RESERVATION DETAILS\n";
			echo "========================================\n";
			echo "Booking Reference: " . ( $booking_no ?: 'Pending Sync' ) . "\n";
			if ( ! empty( $room_no ) ) {
				echo "Room Number: " . $room_no . "\n";
			}
			echo "Dates: " . ( $booking_info['check_in'] ?? '' ) . " to " . ( $booking_info['check_out'] ?? '' ) . " (" . ( $booking_info['nights'] ?? 1 ) . " nights)\n";
			echo "Guests: " . ( $booking_info['adults'] ?? 1 ) . " Adults, " . ( $booking_info['children'] ?? 0 ) . " Children\n";
			echo "Guest ID: " . $masked_id . "\n";
			echo "Check-in Time: From 14:00 | Check-out: Until 12:00\n";
			if ( ! empty( $bed_type ) ) {
				echo "Bed Preference: " . $bed_type . "\n";
			}
			if ( ! empty( $arrival ) ) {
				echo "Estimated Arrival: " . $arrival . "\n";
			}
			if ( ! empty( $policy ) ) {
				echo "\nCancellation Policy: " . $policy . "\n";
			}
			echo "========================================\n\n";
			return;
		}
		?>
		<div style="margin: 25px 0; padding: 20px; border: 2px solid #0284c7; border-radius: 8px; background-color: #f8fafc; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
			<h3 style="color: #0369a1; margin-top: 0; margin-bottom: 15px; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px;">
				<?php esc_html_e( 'Official Hotel Reservation Details', 'arc-hotel-core' ); ?>
			</h3>

			<table style="width: 100%; border-collapse: collapse; font-size: 14px; color: #334155;">
				<tr>
					<td style="padding: 6px 0; width: 40%;"><strong><?php esc_html_e( 'Official Booking Reference:', 'arc-hotel-core' ); ?></strong></td>
					<td style="padding: 6px 0;"><strong style="color: #0f172a; font-size: 16px;"><?php echo esc_html( $booking_no ?: 'Processing with Hotel...' ); ?></strong></td>
				</tr>
				<?php if ( ! empty( $room_no ) ) : ?>
					<tr>
						<td style="padding: 6px 0;"><strong><?php esc_html_e( 'Assigned Room:', 'arc-hotel-core' ); ?></strong></td>
						<td style="padding: 6px 0;"><?php echo esc_html( $room_no ); ?></td>
					</tr>
				<?php endif; ?>
				<tr>
					<td style="padding: 6px 0;"><strong><?php esc_html_e( 'Stay Dates:', 'arc-hotel-core' ); ?></strong></td>
					<td style="padding: 6px 0;"><?php echo esc_html( ( $booking_info['check_in'] ?? '' ) . ' to ' . ( $booking_info['check_out'] ?? '' ) . ' (' . ( $booking_info['nights'] ?? 1 ) . ' nights)' ); ?></td>
				</tr>
				<tr>
					<td style="padding: 6px 0;"><strong><?php esc_html_e( 'Guests:', 'arc-hotel-core' ); ?></strong></td>
					<td style="padding: 6px 0;"><?php echo esc_html( ( $booking_info['adults'] ?? 1 ) . ' Adults, ' . ( $booking_info['children'] ?? 0 ) . ' Children' ); ?></td>
				</tr>
				<tr>
					<td style="padding: 6px 0;"><strong><?php esc_html_e( 'Guest ID (NID/Passport):', 'arc-hotel-core' ); ?></strong></td>
					<td style="padding: 6px 0;"><?php echo esc_html( $masked_id ); ?></td>
				</tr>
				<tr>
					<td style="padding: 6px 0;"><strong><?php esc_html_e( 'Standard Times:', 'arc-hotel-core' ); ?></strong></td>
					<td style="padding: 6px 0;"><?php esc_html_e( 'Check-in from 14:00 | Check-out until 12:00', 'arc-hotel-core' ); ?></td>
				</tr>
				<?php if ( ! empty( $bed_type ) ) : ?>
					<tr>
						<td style="padding: 6px 0;"><strong><?php esc_html_e( 'Bed Preference:', 'arc-hotel-core' ); ?></strong></td>
						<td style="padding: 6px 0;"><?php echo esc_html( $bed_type ); ?></td>
					</tr>
				<?php endif; ?>
				<?php if ( ! empty( $arrival ) ) : ?>
					<tr>
						<td style="padding: 6px 0;"><strong><?php esc_html_e( 'Estimated Arrival:', 'arc-hotel-core' ); ?></strong></td>
						<td style="padding: 6px 0;"><?php echo esc_html( $arrival ); ?></td>
					</tr>
				<?php endif; ?>
			</table>

			<?php if ( ! empty( $policy ) ) : ?>
				<div style="margin-top: 15px; padding-top: 10px; border-top: 1px dashed #cbd5e1; font-size: 12px; color: #64748b;">
					<strong><?php esc_html_e( 'Cancellation Policy:', 'arc-hotel-core' ); ?></strong> <?php echo esc_html( $policy ); ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Send alert email to hotel administrator when API sync fails or requires manual action.
	 *
	 * @param int    $order_id   Order ID.
	 * @param string $error_msg  Error message.
	 * @param string $error_code Canonical error code.
	 * @return bool
	 */
	public static function send_admin_sync_failed_alert( int $order_id, string $error_msg, string $error_code ): bool {
		$admin_email = get_option( 'admin_email' );
		if ( empty( $admin_email ) ) {
			return false;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return false;
		}

		$subject = sprintf(
			/* translators: 1: Order ID, 2: Error code */
			__( '[URGENT] Hotel Booking Sync Failed for Order #%1$d (%2$s)', 'arc-hotel-core' ),
			$order_id,
			$error_code
		);

		$edit_url = admin_url( 'post.php?post=' . $order_id . '&action=edit' );
		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$edit_url = admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $order_id );
		}

		$message = sprintf(
			"Attention Hotel Management,\n\n" .
			"An online hotel room booking in WooCommerce encountered a synchronization issue with the ASP.NET Smart Hotel API.\n\n" .
			"Order ID: #%d\n" .
			"Customer: %s\n" .
			"Phone: %s\n" .
			"Error Code: %s\n" .
			"Error Message: %s\n\n" .
			"Action Required: Please open the order in WooCommerce Admin to review details or trigger a manual sync:\n%s\n\n" .
			"Aristocrats Hotel Core System",
			$order_id,
			$order->get_formatted_billing_full_name(),
			$order->get_billing_phone(),
			$error_code,
			$error_msg,
			$edit_url
		);

		return wp_mail( $admin_email, $subject, $message, [ 'Content-Type: text/plain; charset=UTF-8' ] );
	}
}
