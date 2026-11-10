<?php
/**
 * Scoped Checkout Fields for Room vs Food.
 *
 * @package ARC\HotelCore\Checkout
 */

namespace ARC\HotelCore\Checkout;

use ARC\HotelCore\Cart\Cart_Guard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles conditional checkout fields and Review Booking card.
 */
class Checkout_Fields {

	/**
	 * Hook checkout fields and templates.
	 *
	 * @return void
	 */
	public function init(): void {
		add_filter( 'woocommerce_checkout_fields', [ $this, 'filter_checkout_fields' ], 999 );
		add_action( 'woocommerce_checkout_before_customer_details', [ $this, 'render_review_booking_card' ] );
		add_action( 'woocommerce_checkout_after_customer_details', [ $this, 'render_hotel_specific_fields' ] );
		add_action( 'woocommerce_checkout_create_order', [ $this, 'save_checkout_fields_to_order' ], 10, 2 );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_checkout_assets' ] );
	}

	/**
	 * Conditionally adjust checkout fields based on cart contents.
	 *
	 * @param array $fields Existing checkout fields.
	 * @return array
	 */
	public function filter_checkout_fields( array $fields ): array {
		if ( Cart_Guard::cart_has_room() ) {
			// Room Checkout Mode: Remove physical shipping and unnecessary billing address fields
			$remove_keys = [
				'billing_company',
				'billing_address_1',
				'billing_address_2',
				'billing_city',
				'billing_state',
				'billing_postcode',
				'billing_country',
			];

			foreach ( $remove_keys as $key ) {
				unset( $fields['billing'][ $key ] );
			}

			// Rename labels for hotel guest context
			if ( isset( $fields['billing']['billing_first_name'] ) ) {
				$fields['billing']['billing_first_name']['label'] = __( 'Primary Guest First Name', 'arc-hotel-core' );
			}
			if ( isset( $fields['billing']['billing_last_name'] ) ) {
				$fields['billing']['billing_last_name']['label'] = __( 'Primary Guest Last Name', 'arc-hotel-core' );
			}
			if ( isset( $fields['billing']['billing_phone'] ) ) {
				$fields['billing']['billing_phone']['label'] = __( 'Mobile Phone Number', 'arc-hotel-core' );
				$fields['billing']['billing_phone']['description'] = __( 'Will be used for booking lookup and SMS confirmation.', 'arc-hotel-core' );
			}
		}

		return $fields;
	}

	/**
	 * Enqueue scoped CSS/JS only on Room Checkout page.
	 *
	 * @return void
	 */
	public function enqueue_checkout_assets(): void {
		if ( ! is_checkout() || is_order_received_page() ) {
			return;
		}

		if ( Cart_Guard::cart_has_room() ) {
			wp_enqueue_style(
				'arc-room-checkout-css',
				ARC_PLUGIN_URL . 'assets/css/room-checkout.css',
				[],
				ARC_VERSION
			);

			wp_enqueue_script(
				'arc-room-checkout-js',
				ARC_PLUGIN_URL . 'assets/js/room-checkout.js',
				[ 'jquery' ],
				ARC_VERSION,
				true
			);
		}
	}

	/**
	 * Render "Review Your Booking" card on Room Checkout.
	 *
	 * @return void
	 */
	public function render_review_booking_card(): void {
		if ( ! Cart_Guard::cart_has_room() ) {
			return;
		}

		$room_item = null;
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( ! empty( $item['arc_room'] ) ) {
				$room_item = $item['arc_room'];
				break;
			}
		}

		if ( ! $room_item ) {
			return;
		}

		$template_path = ARC_PLUGIN_DIR . 'templates/checkout/review-booking.php';
		if ( file_exists( $template_path ) ) {
			include $template_path;
		}
	}

	/**
	 * Render additional hotel fields (NID, additional guests, bed preference, arrival time).
	 *
	 * @param \WC_Checkout $checkout Checkout object.
	 * @return void
	 */
	public function render_hotel_specific_fields( $checkout ): void {
		if ( ! Cart_Guard::cart_has_room() ) {
			return;
		}

		$room_item = null;
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( ! empty( $item['arc_room'] ) ) {
				$room_item = $item['arc_room'];
				break;
			}
		}

		$adults = $room_item['adults'] ?? 1;
		?>
		<div class="arc-hotel-checkout-section" style="margin-top: 25px; padding: 20px; background: #fff; border: 1px solid #e2e8f0; border-radius: 8px;">
			<h3 style="margin-top: 0; color: #1e293b;"><?php esc_html_e( 'Guest Details & Identification', 'arc-hotel-core' ); ?></h3>

			<p class="form-row form-row-wide validate-required" id="arc_id_number_field">
				<label for="arc_id_number" class="">
					<?php esc_html_e( 'National ID (NID) or Passport Number', 'arc-hotel-core' ); ?> <abbr class="required" title="required">*</abbr>
				</label>
				<span class="woocommerce-input-wrapper">
					<input type="text" class="input-text" name="arc_id_number" id="arc_id_number" placeholder="<?php esc_attr_e( 'e.g. 10/13/17-digit NID or Passport number', 'arc-hotel-core' ); ?>" value="<?php echo esc_attr( $checkout->get_value( 'arc_id_number' ) ); ?>" required />
				</span>
				<small style="color: #64748b;"><?php esc_html_e( 'Required by Bangladesh hotel regulations for check-in verification.', 'arc-hotel-core' ); ?></small>
			</p>

			<?php if ( $adults > 1 ) : ?>
				<div class="arc-additional-guests-wrap" style="margin-top: 15px;">
					<h4><?php esc_html_e( 'Additional Adult Guest Names', 'arc-hotel-core' ); ?></h4>
					<?php for ( $g = 2; $g <= min( 8, $adults ); $g++ ) : ?>
						<p class="form-row form-row-wide validate-required" id="arc_guest_<?php echo esc_attr( $g ); ?>_field">
							<label for="arc_guest_<?php echo esc_attr( $g ); ?>">
								<?php
								printf(
									/* translators: %d: Guest number */
									esc_html__( 'Adult Guest %d Full Name', 'arc-hotel-core' ),
									absint( $g )
								);
								?> <abbr class="required" title="required">*</abbr>
							</label>
							<input type="text" class="input-text" name="arc_guest_<?php echo esc_attr( $g ); ?>" id="arc_guest_<?php echo esc_attr( $g ); ?>" placeholder="<?php esc_attr_e( 'Full name as per ID', 'arc-hotel-core' ); ?>" required />
						</p>
					<?php endfor; ?>
				</div>
			<?php endif; ?>

			<div class="arc-preferences-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 20px;">
				<div class="arc-bed-type-group">
					<label><strong><?php esc_html_e( 'Preferred Bed Setup', 'arc-hotel-core' ); ?></strong></label>
					<div style="margin-top: 8px;">
						<label style="display: block; margin-bottom: 6px;">
							<input type="radio" name="arc_bed_type" value="Large Double Bed" checked /> <?php esc_html_e( '1 Large Double / King Bed', 'arc-hotel-core' ); ?>
						</label>
						<label style="display: block;">
							<input type="radio" name="arc_bed_type" value="Twin Beds" /> <?php esc_html_e( '2 Separate Twin Beds', 'arc-hotel-core' ); ?>
						</label>
					</div>
				</div>

				<div class="arc-arrival-time-group">
					<label for="arc_arrival_time"><strong><?php esc_html_e( 'Estimated Arrival Time', 'arc-hotel-core' ); ?></strong></label>
					<select name="arc_arrival_time" id="arc_arrival_time" class="select" style="margin-top: 8px; width: 100%;">
						<option value="Standard (14:00 - 16:00)"><?php esc_html_e( 'Standard Check-in (14:00 - 16:00)', 'arc-hotel-core' ); ?></option>
						<option value="16:00 - 18:00">16:00 - 18:00</option>
						<option value="18:00 - 20:00">18:00 - 20:00</option>
						<option value="Late Arrival (After 20:00)"><?php esc_html_e( 'Late Arrival (After 20:00)', 'arc-hotel-core' ); ?></option>
						<option value="Early Arrival (Subject to availability)"><?php esc_html_e( 'Early Check-in (Before 14:00)', 'arc-hotel-core' ); ?></option>
					</select>
				</div>
			</div>

			<div class="arc-special-requests-group" style="margin-top: 20px;">
				<label><strong><?php esc_html_e( 'Additional Requests', 'arc-hotel-core' ); ?></strong></label>
				<div style="margin-top: 8px; display: flex; flex-direction: column; gap: 6px;">
					<label><input type="checkbox" name="arc_req_quiet" value="1" /> <?php esc_html_e( 'Quiet room (away from elevator/street)', 'arc-hotel-core' ); ?></label>
					<label><input type="checkbox" name="arc_req_high_floor" value="1" /> <?php esc_html_e( 'High floor room', 'arc-hotel-core' ); ?></label>
					<label><input type="checkbox" name="arc_req_airport" value="1" /> <?php esc_html_e( 'Airport pickup request (concierge will contact you)', 'arc-hotel-core' ); ?></label>
				</div>
				<p class="form-row form-row-wide" style="margin-top: 15px;">
					<label for="arc_special_note"><?php esc_html_e( 'Special Requests / Notes (Optional)', 'arc-hotel-core' ); ?></label>
					<textarea name="arc_special_note" id="arc_special_note" rows="2" class="input-text" placeholder="<?php esc_attr_e( 'E.g., honeymoon setup, dietary considerations, etc.', 'arc-hotel-core' ); ?>"></textarea>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Save custom hotel fields to WooCommerce Order meta.
	 *
	 * @param \WC_Order $order   Created order.
	 * @param array     $data    Posted checkout data.
	 * @return void
	 */
	public function save_checkout_fields_to_order( \WC_Order $order, array $data ): void {
		if ( ! Cart_Guard::cart_has_room() ) {
			$order->update_meta_data( '_arc_order_type', 'food' );
			return;
		}

		$order->update_meta_data( '_arc_order_type', 'room' );

		// Save booking cart specifications
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( ! empty( $item['arc_room'] ) ) {
				$order->update_meta_data( '_arc_booking', $item['arc_room'] );
				break;
			}
		}

		// Save identification and guest data
		if ( isset( $_POST['arc_id_number'] ) ) {
			$order->update_meta_data( '_arc_id_number', sanitize_text_field( wp_unslash( $_POST['arc_id_number'] ) ) );
		}

		// Save additional guests
		$guests = [];
		for ( $i = 2; $i <= 8; $i++ ) {
			if ( ! empty( $_POST[ 'arc_guest_' . $i ] ) ) {
				$guests[] = sanitize_text_field( wp_unslash( $_POST[ 'arc_guest_' . $i ] ) );
			}
		}
		if ( ! empty( $guests ) ) {
			$order->update_meta_data( '_arc_guests', $guests );
		}

		// Save preferences
		if ( isset( $_POST['arc_bed_type'] ) ) {
			$order->update_meta_data( '_arc_bed_type', sanitize_text_field( wp_unslash( $_POST['arc_bed_type'] ) ) );
		}
		if ( isset( $_POST['arc_arrival_time'] ) ) {
			$order->update_meta_data( '_arc_arrival', sanitize_text_field( wp_unslash( $_POST['arc_arrival_time'] ) ) );
		}

		// Save special requests
		$requests = [];
		if ( ! empty( $_POST['arc_req_quiet'] ) ) {
			$requests[] = 'Quiet Room';
		}
		if ( ! empty( $_POST['arc_req_high_floor'] ) ) {
			$requests[] = 'High Floor';
		}
		if ( ! empty( $_POST['arc_req_airport'] ) ) {
			$requests[] = 'Airport Pickup Required';
		}
		if ( ! empty( $_POST['arc_special_note'] ) ) {
			$requests[] = sanitize_textarea_field( wp_unslash( $_POST['arc_special_note'] ) );
		}

		$order->update_meta_data( '_arc_requests', $requests );
		$order->update_meta_data( '_arc_sync_status', 'pending' );
	}
}
