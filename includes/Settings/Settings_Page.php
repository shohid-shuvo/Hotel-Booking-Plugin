<?php
/**
 * Settings Page View & Controller.
 *
 * @package ARC\HotelCore\Settings
 */

namespace ARC\HotelCore\Settings;

use ARC\HotelCore\Api\Api_Client;
use ARC\HotelCore\Api\Api_Cache;
use ARC\HotelCore\Rooms\Room_Sync;
use ARC\HotelCore\Rooms\Room_Post_Type;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin Settings Page.
 */
class Settings_Page {

	/**
	 * Hook admin menus and AJAX handlers.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_menu', [ $this, 'register_menu' ], 60 );
		add_action( 'admin_init', [ $this, 'handle_save' ] );
		add_action( 'wp_ajax_arc_test_api_connection', [ $this, 'ajax_test_connection' ] );
		add_action( 'wp_ajax_arc_flush_api_cache', [ $this, 'ajax_flush_cache' ] );
	}

	/**
	 * Register submenu under the unified Hotel Management menu (edit.php?post_type=arc_room).
	 *
	 * @return void
	 */
	public function register_menu(): void {
		add_submenu_page(
			'edit.php?post_type=' . Room_Post_Type::POST_TYPE,
			__( 'Hotel Settings', 'arc-hotel-core' ),
			__( 'Settings', 'arc-hotel-core' ),
			'manage_woocommerce',
			'arc-hotel-settings',
			[ $this, 'render' ]
		);
	}

	/**
	 * Handle settings submission with nonce check and automatic sync on company change.
	 *
	 * @return void
	 */
	public function handle_save(): void {
		if ( ! isset( $_POST['arc_save_settings_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['arc_save_settings_nonce'] ) ), 'arc_save_settings_action' ) ) {
			wp_die( esc_html__( 'Security check failed. Please refresh and try again.', 'arc-hotel-core' ) );
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'arc-hotel-core' ) );
		}

		$old_company_code = Settings::get( 'company_code' );

		$raw_input = [
			'company_code'             => isset( $_POST['company_code'] ) ? sanitize_text_field( wp_unslash( $_POST['company_code'] ) ) : '',
			'currency_symbol'          => isset( $_POST['currency_symbol'] ) ? sanitize_text_field( wp_unslash( $_POST['currency_symbol'] ) ) : 'BDT',
			'currency_position'        => isset( $_POST['currency_position'] ) ? sanitize_key( wp_unslash( $_POST['currency_position'] ) ) : 'right',
			'cache_ttl_info'           => isset( $_POST['cache_ttl_info'] ) ? absint( $_POST['cache_ttl_info'] ) : 1800,
			'cache_ttl_rooms'          => isset( $_POST['cache_ttl_rooms'] ) ? absint( $_POST['cache_ttl_rooms'] ) : 600,
			'max_stay_nights'          => isset( $_POST['max_stay_nights'] ) ? absint( $_POST['max_stay_nights'] ) : 30,
			'max_advance_days'         => isset( $_POST['max_advance_days'] ) ? absint( $_POST['max_advance_days'] ) : 365,
			'default_adults'           => isset( $_POST['default_adults'] ) ? max( 1, absint( $_POST['default_adults'] ) ) : 2,
			'default_children'         => isset( $_POST['default_children'] ) ? absint( $_POST['default_children'] ) : 0,
			'room_gateways'            => isset( $_POST['room_gateways'] ) && is_array( $_POST['room_gateways'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['room_gateways'] ) ) : [],
			'pay_at_hotel_enabled'     => ! empty( $_POST['pay_at_hotel_enabled'] ),
			'pay_at_hotel_title'       => isset( $_POST['pay_at_hotel_title'] ) ? sanitize_text_field( wp_unslash( $_POST['pay_at_hotel_title'] ) ) : 'Pay at Hotel / Reception Desk',
			'pay_at_hotel_desc'        => isset( $_POST['pay_at_hotel_desc'] ) ? sanitize_textarea_field( wp_unslash( $_POST['pay_at_hotel_desc'] ) ) : '',
			'cancellation_policy_text' => isset( $_POST['cancellation_policy_text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['cancellation_policy_text'] ) ) : '',
			'auto_sync_interval'       => isset( $_POST['auto_sync_interval'] ) ? sanitize_key( wp_unslash( $_POST['auto_sync_interval'] ) ) : 'daily',
		];

		Settings::update( $raw_input );
		Api_Cache::flush();
		flush_rewrite_rules( false );

		$new_company_code = Settings::get( 'company_code' );
		$redirect_args    = [ 'post_type' => Room_Post_Type::POST_TYPE, 'page' => 'arc-hotel-settings', 'updated' => '1' ];

		// If company code changed or auto-sync requested, run synchronization
		if ( ! empty( $new_company_code ) && ( $new_company_code !== $old_company_code || ! empty( $_POST['arc_trigger_sync_on_save'] ) ) ) {
			$stats = Room_Sync::sync_all( $new_company_code );
			if ( $stats['success'] ) {
				$redirect_args['synced'] = rawurlencode( sprintf( __( 'Company code updated to [%s]. Successfully synchronized %d room categories!', 'arc-hotel-core' ), $new_company_code, $stats['total_synced'] ?? 0 ) );
			} else {
				$redirect_args['sync_err'] = rawurlencode( $stats['message'] );
			}
		}

		wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'edit.php' ) ) );
		exit;
	}

	/**
	 * Render settings page HTML.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$settings        = Settings::get_all();
		$has_api_key     = Settings::has_api_key();
		$is_mock         = Settings::is_mock_mode();
		$hotel_info      = Settings::get_hotel_info();
		$available_gates = class_exists( 'WC_Payment_Gateways' ) ? WC()->payment_gateways->payment_gateways() : [];

		// Query synced categories and calculate total inventory of physical rooms
		$synced_posts = get_posts( [
			'post_type'      => Room_Post_Type::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'meta_query'     => [
				[
					'key'     => '_arc_company_code',
					'value'   => $settings['company_code'],
					'compare' => '=',
				],
			],
		] );

		$categories_count = count( $synced_posts );
		$total_inventory  = 0;
		$category_breakdown = [];

		foreach ( $synced_posts as $sp ) {
			$r_total = (int) get_post_meta( $sp->ID, '_arc_total_rooms', true );
			$total_inventory += $r_total;
			$category_breakdown[] = [
				'title' => $sp->post_title,
				'count' => $r_total,
				'price' => (float) get_post_meta( $sp->ID, '_arc_from_price', true ),
			];
		}
		?>
		<div class="wrap arc-settings-wrap" style="max-width: 1200px;">
			<h1 style="display: flex; align-items: center; gap: 10px; margin-bottom: 5px;">
				<span class="dashicons dashicons-building" style="font-size: 30px; width: 30px; height: 30px; color: #1e3a8a;"></span>
				<?php esc_html_e( 'Hotel Management — Core Settings', 'arc-hotel-core' ); ?>
			</h1>
			<p class="description" style="margin-bottom: 15px;">
				<?php esc_html_e( 'Manage your Hotel Company Code (Tenant), review live API synchronization stats, and customize website checkout rules.', 'arc-hotel-core' ); ?>
			</p>

			<?php if ( isset( $_GET['updated'] ) && ! isset( $_GET['synced'] ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Settings updated successfully and API cache flushed.', 'arc-hotel-core' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( isset( $_GET['synced'] ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><strong>&#10004; <?php echo esc_html( urldecode( sanitize_text_field( wp_unslash( $_GET['synced'] ) ) ) ); ?></strong></p>
				</div>
			<?php endif; ?>

			<?php if ( isset( $_GET['sync_err'] ) ) : ?>
				<div class="notice notice-error is-dismissible">
					<p><strong>&#10008; <?php esc_html_e( 'Sync Error:', 'arc-hotel-core' ); ?></strong> <?php echo esc_html( urldecode( sanitize_text_field( wp_unslash( $_GET['sync_err'] ) ) ) ); ?></p>
				</div>
			<?php endif; ?>

			<!-- Live Connected Hotel Status Card -->
			<div style="background: linear-gradient(135deg, #0f172a, #1e3a8a); color: #fff; padding: 22px 26px; border-radius: 8px; margin: 15px 0 25px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.15);">
				<div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 20px;">
					<div>
						<span style="background: #10b981; color: #fff; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;">
							● <?php esc_html_e( 'Cloud API Connected', 'arc-hotel-core' ); ?> &bull; Tenant: <?php echo esc_html( $settings['company_code'] ); ?>
						</span>
						<h2 style="color: #fff; margin: 10px 0 6px; font-size: 24px; font-weight: 700;">
							<?php echo esc_html( $hotel_info['hotelName'] ?? 'Hotel & Suites' ); ?>
						</h2>
						<p style="margin: 0; opacity: 0.9; font-size: 14px;">
							&#128205; <?php echo esc_html( $hotel_info['address'] ?: __( 'Location configured in ERP', 'arc-hotel-core' ) ); ?>
							<?php if ( ! empty( $hotel_info['phone'] ) ) : ?>
								&bull; &#128222; <?php echo esc_html( $hotel_info['phone'] ); ?>
							<?php endif; ?>
							&bull; &#128338; <?php printf( esc_html__( 'Check-in: %s | Check-out: %s', 'arc-hotel-core' ), esc_html( $hotel_info['standardCheckInTime'] ), esc_html( $hotel_info['standardCheckOutTime'] ) ); ?>
						</p>
					</div>

					<div style="display: flex; gap: 15px; align-items: center;">
						<!-- Categories Count -->
						<div style="background: rgba(255,255,255,0.12); padding: 10px 16px; border-radius: 6px; text-align: center; border: 1px solid rgba(255,255,255,0.1);">
							<span style="font-size: 24px; font-weight: 700; display: block; line-height: 1;"><?php echo absint( $categories_count ); ?></span>
							<span style="font-size: 11px; text-transform: uppercase; opacity: 0.85;"><?php esc_html_e( 'Categories', 'arc-hotel-core' ); ?></span>
						</div>
						<!-- Physical Rooms Count -->
						<div style="background: rgba(255,255,255,0.12); padding: 10px 16px; border-radius: 6px; text-align: center; border: 1px solid rgba(255,255,255,0.1);">
							<span style="font-size: 24px; font-weight: 700; display: block; line-height: 1; color: #34d399;"><?php echo absint( $total_inventory ); ?></span>
							<span style="font-size: 11px; text-transform: uppercase; opacity: 0.85;"><?php esc_html_e( 'Total Rooms', 'arc-hotel-core' ); ?></span>
						</div>

						<button type="button" id="arc-hero-sync-btn" class="button button-primary button-hero" style="background: #10b981; border-color: #059669; font-weight: 600; display: inline-flex; align-items: center; gap: 6px; height: 42px;">
							<span class="dashicons dashicons-update" style="margin-top: 4px;"></span>
							<?php esc_html_e( 'Sync Rooms Now', 'arc-hotel-core' ); ?>
						</button>
					</div>
				</div>

				<!-- Inventory Category Breakdown Bar -->
				<?php if ( ! empty( $category_breakdown ) ) : ?>
					<div style="margin-top: 15px; padding-top: 12px; border-top: 1px solid rgba(255,255,255,0.15); display: flex; gap: 12px; flex-wrap: wrap; font-size: 12px;">
						<span style="opacity: 0.8; font-weight: 600;"><?php esc_html_e( 'Inventory Breakdown:', 'arc-hotel-core' ); ?></span>
						<?php foreach ( $category_breakdown as $cb ) : ?>
							<span style="background: rgba(255,255,255,0.15); padding: 2px 10px; border-radius: 12px;">
								<strong><?php echo esc_html( $cb['title'] ); ?>:</strong> <?php printf( esc_html__( '%d physical rooms', 'arc-hotel-core' ), absint( $cb['count'] ) ); ?> (<?php echo esc_html( Settings::format_price( $cb['price'] ) ); ?>/night)
							</span>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>

			<div style="display: flex; gap: 24px; flex-wrap: wrap;">
				<!-- Left Form Panel -->
				<div style="flex: 2; min-width: 500px; background: #fff; padding: 25px; border: 1px solid #e2e8f0; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
					<form method="post" action="">
						<?php wp_nonce_field( 'arc_save_settings_action', 'arc_save_settings_nonce' ); ?>

						<!-- 1. Tenant Authentication -->
						<h2 style="font-size: 17px; border-bottom: 2px solid #f1f5f9; padding-bottom: 8px; margin-top: 0; color: #1e293b;">
							&#128273; <?php esc_html_e( '1. Hotel Company Code (Tenant ID)', 'arc-hotel-core' ); ?>
						</h2>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row">
									<label for="company_code"><strong><?php esc_html_e( 'Company Code', 'arc-hotel-core' ); ?></strong></label>
								</th>
								<td>
									<input type="text" name="company_code" id="company_code" value="<?php echo esc_attr( Settings::get( 'company_code' ) ); ?>" class="regular-text" style="font-size: 16px; font-weight: bold; letter-spacing: 1px; color: #1e3a8a;" required />
									<p class="description" style="margin-top: 6px;">
										<?php esc_html_e( 'The unique hotel tenant code registered in the ASP.NET Hotel ERP (e.g. GPH01). Whenever this code is changed and saved, the plugin automatically switches to that hotel and syncs all of its room categories, inventory, and rates.', 'arc-hotel-core' ); ?>
									</p>
									<?php if ( defined( 'ARC_COMPANY_CODE' ) && ! empty( ARC_COMPANY_CODE ) ) : ?>
										<p style="color: #059669; font-weight: 600; margin: 4px 0 0;">
											&#10004; <?php printf( esc_html__( 'Overridden in wp-config.php via ARC_COMPANY_CODE = "%s"', 'arc-hotel-core' ), esc_html( ARC_COMPANY_CODE ) ); ?>
										</p>
									<?php endif; ?>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'API Connection', 'arc-hotel-core' ); ?></th>
								<td>
									<span style="color: #059669; font-weight: 600;">● <?php esc_html_e( 'Hotel Cloud API Connected (Encrypted HTTPS)', 'arc-hotel-core' ); ?></span>
									<p class="description"><?php esc_html_e( 'Backend API endpoints are securely locked internally to prevent accidental client disruption.', 'arc-hotel-core' ); ?></p>
								</td>
							</tr>
							<?php if ( $is_mock ) : ?>
								<tr>
									<th scope="row"><?php esc_html_e( 'Mock Test Mode', 'arc-hotel-core' ); ?></th>
									<td>
										<span style="background: #fef3c7; color: #92400e; padding: 3px 8px; border-radius: 4px; font-weight: 700; font-size: 12px;"><?php esc_html_e( 'MOCK FIXTURES MODE ACTIVE', 'arc-hotel-core' ); ?></span>
									</td>
								</tr>
							<?php endif; ?>
						</table>

						<!-- 2. Website Booking Limits & Search -->
						<h2 style="font-size: 17px; border-bottom: 2px solid #f1f5f9; padding-bottom: 8px; margin-top: 30px; color: #1e293b;">
							&#128197; <?php esc_html_e( '2. Website Booking & Stay Rules', 'arc-hotel-core' ); ?>
						</h2>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><label for="currency_symbol"><?php esc_html_e( 'Currency Symbol & Position', 'arc-hotel-core' ); ?></label></th>
								<td>
									<div style="display: flex; gap: 15px; align-items: center;">
										<input type="text" name="currency_symbol" id="currency_symbol" value="<?php echo esc_attr( $settings['currency_symbol'] ); ?>" style="width: 100px;" />
										<select name="currency_position" id="currency_position">
											<option value="right" <?php selected( $settings['currency_position'], 'right' ); ?>><?php esc_html_e( 'Right (e.g. 4,500 BDT)', 'arc-hotel-core' ); ?></option>
											<option value="left" <?php selected( $settings['currency_position'], 'left' ); ?>><?php esc_html_e( 'Left (e.g. BDT 4,500 / $ 4,500)', 'arc-hotel-core' ); ?></option>
										</select>
									</div>
									<p class="description"><?php esc_html_e( 'Controls how room rates and totals are formatted across all website room cards and widgets.', 'arc-hotel-core' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="max_stay_nights"><?php esc_html_e( 'Max Stay Duration (Nights)', 'arc-hotel-core' ); ?></label></th>
								<td>
									<input type="number" name="max_stay_nights" id="max_stay_nights" value="<?php echo esc_attr( $settings['max_stay_nights'] ); ?>" min="1" max="90" class="small-text" />
									<p class="description"><?php esc_html_e( 'Maximum consecutive nights allowed per online reservation (default: 30).', 'arc-hotel-core' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="max_advance_days"><?php esc_html_e( 'Max Advance Booking (Days)', 'arc-hotel-core' ); ?></label></th>
								<td>
									<input type="number" name="max_advance_days" id="max_advance_days" value="<?php echo esc_attr( $settings['max_advance_days'] ); ?>" min="1" max="730" class="small-text" />
									<p class="description"><?php esc_html_e( 'How far in advance guests can reserve dates on the calendar (default: 365).', 'arc-hotel-core' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Default Search Guests', 'arc-hotel-core' ); ?></th>
								<td>
									<label for="default_adults"><?php esc_html_e( 'Adults:', 'arc-hotel-core' ); ?></label>
									<input type="number" name="default_adults" id="default_adults" value="<?php echo esc_attr( $settings['default_adults'] ); ?>" min="1" max="10" class="small-text" style="margin-right: 20px;" />

									<label for="default_children"><?php esc_html_e( 'Children:', 'arc-hotel-core' ); ?></label>
									<input type="number" name="default_children" id="default_children" value="<?php echo esc_attr( $settings['default_children'] ); ?>" min="0" max="6" class="small-text" />
								</td>
							</tr>
						</table>

						<!-- 3. Payment Gateways & Policies -->
						<h2 style="font-size: 17px; border-bottom: 2px solid #f1f5f9; padding-bottom: 8px; margin-top: 30px; color: #1e293b;">
							&#128179; <?php esc_html_e( '3. Checkout & Payment Options', 'arc-hotel-core' ); ?>
						</h2>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><?php esc_html_e( 'Pay at Hotel (Frontdesk)', 'arc-hotel-core' ); ?></th>
								<td>
									<fieldset>
										<label style="display: block; margin-bottom: 8px; font-weight: 600;">
											<input type="checkbox" name="pay_at_hotel_enabled" value="1" <?php checked( $settings['pay_at_hotel_enabled'] ); ?> />
											<?php esc_html_e( 'Enable "Pay at Hotel" Offline Reservation Gateway', 'arc-hotel-core' ); ?>
										</label>
										<div style="margin-top: 8px; margin-bottom: 12px;">
											<input type="text" name="pay_at_hotel_title" value="<?php echo esc_attr( $settings['pay_at_hotel_title'] ); ?>" class="regular-text" placeholder="Title on Checkout" />
										</div>
										<textarea name="pay_at_hotel_desc" rows="2" class="large-text" placeholder="Description shown to guest during checkout"><?php echo esc_textarea( $settings['pay_at_hotel_desc'] ); ?></textarea>
									</fieldset>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Allowed Room Gateways', 'arc-hotel-core' ); ?></th>
								<td>
									<?php if ( ! empty( $available_gates ) ) : ?>
										<fieldset>
											<?php foreach ( $available_gates as $gate_id => $gate ) : ?>
												<label style="display: block; margin-bottom: 6px;">
													<input type="checkbox" name="room_gateways[]" value="<?php echo esc_attr( $gate_id ); ?>" <?php checked( in_array( $gate_id, (array) $settings['room_gateways'], true ) ); ?> />
													<strong><?php echo esc_html( $gate->get_title() ); ?></strong> (<code><?php echo esc_html( $gate_id ); ?></code>)
												</label>
											<?php endforeach; ?>
										</fieldset>
									<?php endif; ?>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="cancellation_policy_text"><?php esc_html_e( 'Cancellation Policy Notice', 'arc-hotel-core' ); ?></label></th>
								<td>
									<textarea name="cancellation_policy_text" id="cancellation_policy_text" rows="3" class="large-text"><?php echo esc_textarea( $settings['cancellation_policy_text'] ); ?></textarea>
									<p class="description"><?php esc_html_e( 'Displayed in checkout room details card, confirmation emails, and booking lookup pages.', 'arc-hotel-core' ); ?></p>
								</td>
							</tr>
						</table>

						<!-- 4. Automation -->
						<h2 style="font-size: 17px; border-bottom: 2px solid #f1f5f9; padding-bottom: 8px; margin-top: 30px; color: #1e293b;">
							&#9881; <?php esc_html_e( '4. Synchronization Schedule & Cache', 'arc-hotel-core' ); ?>
						</h2>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><label for="auto_sync_interval"><?php esc_html_e( 'Auto-Sync Frequency', 'arc-hotel-core' ); ?></label></th>
								<td>
									<select name="auto_sync_interval" id="auto_sync_interval">
										<option value="daily" <?php selected( $settings['auto_sync_interval'], 'daily' ); ?>><?php esc_html_e( 'Once Daily (Recommended)', 'arc-hotel-core' ); ?></option>
										<option value="twicedaily" <?php selected( $settings['auto_sync_interval'], 'twicedaily' ); ?>><?php esc_html_e( 'Twice Daily', 'arc-hotel-core' ); ?></option>
										<option value="hourly" <?php selected( $settings['auto_sync_interval'], 'hourly' ); ?>><?php esc_html_e( 'Hourly', 'arc-hotel-core' ); ?></option>
										<option value="manual" <?php selected( $settings['auto_sync_interval'], 'manual' ); ?>><?php esc_html_e( 'Manual Sync Only', 'arc-hotel-core' ); ?></option>
									</select>
									<p class="description"><?php esc_html_e( 'Background WP-Cron schedule to keep room categories and base rates updated.', 'arc-hotel-core' ); ?></p>
								</td>
							</tr>
						</table>

						<div style="margin-top: 25px; padding-top: 15px; border-top: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between;">
							<label>
								<input type="checkbox" name="arc_trigger_sync_on_save" value="1" checked />
								<strong><?php esc_html_e( 'Immediately synchronize room categories upon saving', 'arc-hotel-core' ); ?></strong>
							</label>

							<input type="submit" name="submit" id="submit" class="button button-primary button-large" value="<?php esc_attr_e( 'Save Hotel Settings', 'arc-hotel-core' ); ?>" />
						</div>
					</form>
				</div>

				<!-- Right Actions & Diagnostics Sidebar -->
				<div style="flex: 1; min-width: 320px;">
					<!-- Quick Actions Card -->
					<div style="background: #fff; padding: 22px; border: 1px solid #e2e8f0; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); margin-bottom: 24px;">
						<h3 style="margin-top: 0; font-size: 16px; color: #1e293b; display: flex; align-items: center; gap: 8px;">
							<span class="dashicons dashicons-controls-play" style="color: #2563eb;"></span>
							<?php esc_html_e( 'Quick Cloud Actions', 'arc-hotel-core' ); ?>
						</h3>
						<p class="description" style="margin-bottom: 15px;">
							<?php esc_html_e( 'Test the live connection to your hotel API server or force an immediate room synchronization.', 'arc-hotel-core' ); ?>
						</p>

						<button type="button" id="arc-test-conn-btn" class="button button-secondary" style="width: 100%; margin-bottom: 10px; padding: 6px 12px; height: auto; font-weight: 600;">
							<span class="dashicons dashicons-admin-links" style="margin-top: 2px;"></span>
							<?php esc_html_e( 'Test API Connection', 'arc-hotel-core' ); ?>
						</button>

						<button type="button" id="arc-sync-now-btn" class="button button-secondary" style="width: 100%; margin-bottom: 10px; padding: 6px 12px; height: auto; font-weight: 600; color: #047857;">
							<span class="dashicons dashicons-update" style="margin-top: 2px;"></span>
							<?php esc_html_e( 'Sync Room Categories Now', 'arc-hotel-core' ); ?>
						</button>

						<button type="button" id="arc-flush-cache-btn" class="button button-secondary" style="width: 100%; padding: 6px 12px; height: auto;">
							<span class="dashicons dashicons-trash" style="margin-top: 2px;"></span>
							<?php esc_html_e( 'Flush API Transient Cache', 'arc-hotel-core' ); ?>
						</button>

						<div id="arc-action-results" style="margin-top: 15px; padding: 12px; border-radius: 6px; display: none; font-size: 13px; line-height: 1.5;"></div>
					</div>

					<!-- Diagnostics Info Card -->
					<div style="background: #fff; padding: 22px; border: 1px solid #e2e8f0; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); margin-bottom: 24px;">
						<h3 style="margin-top: 0; font-size: 16px; color: #1e293b; display: flex; align-items: center; gap: 8px;">
							<span class="dashicons dashicons-dashboard" style="color: #64748b;"></span>
							<?php esc_html_e( 'System & Shortcodes', 'arc-hotel-core' ); ?>
						</h3>
						<p style="font-size: 13px; color: #475569; margin-bottom: 12px;">
							<?php esc_html_e( 'Use these shortcodes anywhere in Elementor, Gutenberg, or pages:', 'arc-hotel-core' ); ?>
						</p>
						<ul style="background: #f8fafc; padding: 12px 15px; border: 1px solid #e2e8f0; border-radius: 6px; font-family: monospace; font-size: 12px; margin: 0; line-height: 1.8;">
							<li><code>[arc_search]</code> &mdash; Search bar</li>
							<li><code>[arc_rooms]</code> &mdash; Room grid catalog</li>
							<li><code>[arc_results]</code> &mdash; Live search results</li>
							<li><code>[arc_track]</code> &mdash; Track & Cancel form</li>
							<li><code>[arc_hotel_info field="hotelName"]</code></li>
						</ul>
					</div>
				</div>
			</div>
		</div>

		<script>
		jQuery(document).ready(function($) {
			function showResult(html, isSuccess) {
				var $res = $('#arc-action-results');
				$res.css({
					'display': 'block',
					'background': isSuccess ? '#ecfdf5' : '#fef2f2',
					'color': isSuccess ? '#065f46' : '#991b1b',
					'border': '1px solid ' + (isSuccess ? '#a7f3d0' : '#fecaca')
				}).html(html);
			}

			// Test Connection
			$('#arc-test-conn-btn').on('click', function(e) {
				e.preventDefault();
				var $btn = $(this);
				var company = $('#company_code').val();

				$btn.prop('disabled', true).text('<?php echo esc_js( __( 'Contacting API...', 'arc-hotel-core' ) ); ?>');

				$.post(ajaxurl, {
					action: 'arc_test_api_connection',
					company_code: company,
					nonce: '<?php echo esc_js( wp_create_nonce( 'arc_test_conn_nonce' ) ); ?>'
				}, function(res) {
					$btn.prop('disabled', false).html('<span class="dashicons dashicons-admin-links" style="margin-top: 2px;"></span> <?php echo esc_js( __( 'Test API Connection', 'arc-hotel-core' ) ); ?>');
					if (res.success) {
						showResult(
							'<strong>&#10004; ' + res.data.message + '</strong><br>' +
							'<strong><?php echo esc_js( __( 'Hotel:', 'arc-hotel-core' ) ); ?></strong> ' + res.data.hotelName + '<br>' +
							'<strong><?php echo esc_js( __( 'Company Code:', 'arc-hotel-core' ) ); ?></strong> ' + res.data.companyCode + '<br>' +
							'<strong><?php echo esc_js( __( 'Latency:', 'arc-hotel-core' ) ); ?></strong> ' + res.data.duration + ' ms',
							true
						);
					} else {
						showResult('<strong>&#10008; ' + (res.data ? res.data.message : 'Connection failed') + '</strong>', false);
					}
				}).fail(function() {
					$btn.prop('disabled', false).text('<?php echo esc_js( __( 'Test API Connection', 'arc-hotel-core' ) ); ?>');
					showResult('<strong>&#10008; AJAX network error.</strong>', false);
				});
			});

			// Sync Rooms Now
			$('#arc-sync-now-btn, #arc-hero-sync-btn').on('click', function(e) {
				e.preventDefault();
				var $btn = $(this);
				var company = $('#company_code').val();

				$btn.prop('disabled', true).text('<?php echo esc_js( __( 'Synchronizing...', 'arc-hotel-core' ) ); ?>');

				$.post(ajaxurl, {
					action: 'arc_sync_rooms_now',
					company_code: company,
					nonce: '<?php echo esc_js( wp_create_nonce( 'arc_sync_rooms_nonce' ) ); ?>'
				}, function(res) {
					$btn.prop('disabled', false).html('<span class="dashicons dashicons-update" style="margin-top: 2px;"></span> <?php echo esc_js( __( 'Sync Rooms Now', 'arc-hotel-core' ) ); ?>');
					if (res.success) {
						showResult(
							'<strong>&#10004; ' + res.data.message + '</strong><br>' +
							'<?php echo esc_js( __( 'Created:', 'arc-hotel-core' ) ); ?> ' + res.data.created + ' | ' +
							'<?php echo esc_js( __( 'Updated:', 'arc-hotel-core' ) ); ?> ' + res.data.updated + ' | ' +
							'<?php echo esc_js( __( 'Drafted:', 'arc-hotel-core' ) ); ?> ' + res.data.drafted,
							true
						);
						setTimeout(function() { location.reload(); }, 2000);
					} else {
						showResult('<strong>&#10008; ' + (res.data ? res.data.message : 'Sync failed') + '</strong>', false);
					}
				}).fail(function() {
					$btn.prop('disabled', false).text('<?php echo esc_js( __( 'Sync Room Categories Now', 'arc-hotel-core' ) ); ?>');
					showResult('<strong>&#10008; Sync request timed out or failed.</strong>', false);
				});
			});

			// Flush Cache
			$('#arc-flush-cache-btn').on('click', function(e) {
				e.preventDefault();
				var $btn = $(this);

				$btn.prop('disabled', true).text('<?php echo esc_js( __( 'Flushing...', 'arc-hotel-core' ) ); ?>');

				$.post(ajaxurl, {
					action: 'arc_flush_api_cache',
					nonce: '<?php echo esc_js( wp_create_nonce( 'arc_flush_cache_nonce' ) ); ?>'
				}, function(res) {
					$btn.prop('disabled', false).html('<span class="dashicons dashicons-trash" style="margin-top: 2px;"></span> <?php echo esc_js( __( 'Flush API Transient Cache', 'arc-hotel-core' ) ); ?>');
					if (res.success) {
						showResult('<strong>&#10004; ' + res.data.message + '</strong>', true);
					}
				});
			});
		});
		</script>
		<?php
	}

	/**
	 * AJAX handler for testing API connection.
	 *
	 * @return void
	 */
	public function ajax_test_connection(): void {
		check_ajax_referer( 'arc_test_conn_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'arc-hotel-core' ) ] );
		}

		$company_code = isset( $_POST['company_code'] ) ? sanitize_text_field( wp_unslash( $_POST['company_code'] ) ) : Settings::get( 'company_code' );

		$client     = new Api_Client();
		$start_time = microtime( true );
		$result     = $client->get( 'api/v1/public-booking/hotel-info', [ 'companyCode' => $company_code ], [ 'use_cache' => false ] );
		$duration   = (int) round( ( microtime( true ) - $start_time ) * 1000 );

		if ( $result->ok() ) {
			$data = $result->data();
			wp_send_json_success( [
				'message'     => __( 'API connection successful!', 'arc-hotel-core' ),
				'hotelName'   => sanitize_text_field( $data['hotelName'] ?? 'N/A' ),
				'companyCode' => sanitize_text_field( $data['companyCode'] ?? $company_code ),
				'duration'    => $duration,
			] );
		} else {
			wp_send_json_error( [
				'message'  => $result->message(),
				'code'     => $result->error_code(),
				'status'   => $result->http_status(),
				'duration' => $duration,
			] );
		}
	}

	/**
	 * AJAX handler for flushing cache.
	 *
	 * @return void
	 */
	public function ajax_flush_cache(): void {
		check_ajax_referer( 'arc_flush_cache_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'arc-hotel-core' ) ] );
		}

		Api_Cache::flush();
		wp_send_json_success( [ 'message' => __( 'API cache cleared successfully.', 'arc-hotel-core' ) ] );
	}
}
