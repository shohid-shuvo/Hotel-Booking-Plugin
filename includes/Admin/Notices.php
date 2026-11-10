<?php
/**
 * Admin Notices & Health Warnings.
 *
 * @package ARC\HotelCore\Admin
 */

namespace ARC\HotelCore\Admin;

use ARC\HotelCore\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles persistent and dismissible admin notices.
 */
class Notices {

	/**
	 * Hook notices.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_notices', [ $this, 'render_notices' ] );
	}

	/**
	 * Render administrative alerts.
	 *
	 * @return void
	 */
	public function render_notices(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// 1. Missing Secret API Key Notice
		if ( ! Settings::has_api_key() && ! Settings::is_mock_mode() ) {
			?>
			<div class="notice notice-warning is-dismissible">
				<p>
					<strong><?php esc_html_e( 'Aristocrats Hotel Core Alert:', 'arc-hotel-core' ); ?></strong>
					<?php esc_html_e( 'The ARC_API_KEY constant is not defined in wp-config.php. Live reservations cannot be transmitted to the ASP.NET API without authorization.', 'arc-hotel-core' ); ?>
				</p>
			</div>
			<?php
		}

		// 2. Mock Mode Indicator
		if ( Settings::is_mock_mode() ) {
			?>
			<div class="notice notice-info">
				<p>
					<strong><?php esc_html_e( 'Hotel Core [Mock Mode Active]:', 'arc-hotel-core' ); ?></strong>
					<?php esc_html_e( 'API calls are currently returning static test fixtures. Set ARC_API_MOCK = false in wp-config.php to connect to live hotel servers.', 'arc-hotel-core' ); ?>
				</p>
			</div>
			<?php
		}

		// 3. Failed or Conflict Reservations Needing Action
		$failed_orders = wc_get_orders( [
			'limit'      => 5,
			'meta_key'   => '_arc_sync_status',
			'meta_value' => [ 'failed', 'manual' ],
		] );

		if ( ! empty( $failed_orders ) ) {
			$orders_url = admin_url( 'admin.php?page=wc-orders' );
			?>
			<div class="notice notice-error">
				<p>
					<strong><?php esc_html_e( 'Hotel Reservation Sync Attention Required:', 'arc-hotel-core' ); ?></strong>
					<?php
					$notice_html = sprintf(
						/* translators: 1: Count of failed orders, 2: Orders list URL */
						esc_html__( 'There are %1$d hotel room bookings that failed automatic synchronization with the hotel software. Please <a href="%2$s">review these orders</a> to assign rooms manually or retry sync.', 'arc-hotel-core' ),
						count( $failed_orders ),
						esc_url( $orders_url )
					);

					echo wp_kses(
						$notice_html,
						[
							'a' => [
								'href' => [],
							],
						]
					);
					?>
				</p>
			</div>
			<?php
		}

		// 4. Past-Due Action Scheduler Warning (Cron not running)
		$as_metrics = Action_Scheduler_Status::get_metrics();
		if ( ! empty( $as_metrics['past_due'] ) && $as_metrics['past_due'] > 0 ) {
			?>
			<div class="notice notice-error">
				<p>
					<strong><?php esc_html_e( 'Hotel Core Cron Alert:', 'arc-hotel-core' ); ?></strong>
					<?php
					printf(
						/* translators: %d: Number of overdue tasks */
						esc_html__( 'There are %d background hotel tasks past due in Action Scheduler. This indicates that WordPress Cron / Server Cron is not executing. Please configure system crontab to trigger wp-cron.php.', 'arc-hotel-core' ),
						absint( $as_metrics['past_due'] )
					);
					?>
				</p>
			</div>
			<?php
		}
	}
}
