<?php
/**
 * Single room "Book Now" form: [arc_room_booking]
 *
 * Flow: form POST -> admin-post handler -> server-side availability + price
 * check -> empty cart -> add hotel container product -> redirect to checkout.
 *
 * @package ARC\HotelCore\Elementor
 */

namespace ARC\HotelCore\Elementor;

use ARC\HotelCore\Api\Api_Client;
use ARC\HotelCore\Settings\Settings;
use ARC\HotelCore\Rooms\Room_Post_Type;
use ARC\HotelCore\Plugin;

defined( 'ABSPATH' ) || exit;

class Room_Booking_Form {

	const ACTION = 'arc_book_room';

	/**
	 * Register shortcode and admin-post hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_shortcode( 'arc_room_booking', [ __CLASS__, 'render' ] );
		add_action( 'admin_post_nopriv_' . self::ACTION, [ __CLASS__, 'handle' ] );
		add_action( 'admin_post_' . self::ACTION, [ __CLASS__, 'handle' ] );
	}

	/**
	 * Resolve the room post ID: shortcode attribute first, then current post.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return int Post ID.
	 */
	private static function resolve_post_id( array $atts ): int {
		$id = isset( $atts['room_id'] ) ? absint( $atts['room_id'] ) : 0;
		if ( ! $id ) {
			$id = (int) get_the_ID();
		}
		return ( $id && Room_Post_Type::POST_TYPE === get_post_type( $id ) ) ? $id : 0;
	}

	/**
	 * Render the Single Room Booking Widget Form.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public static function render( array $atts = [] ): string {
		$atts    = shortcode_atts( [ 'room_id' => 0 ], $atts, 'arc_room_booking' );
		$post_id = self::resolve_post_id( $atts );

		// Elementor Theme Builder preview/editor: fall back to the first room so form is visible.
		if ( ! $post_id ) {
			$fallback = get_posts( [
				'post_type'   => Room_Post_Type::POST_TYPE,
				'numberposts' => 1,
				'fields'      => 'ids',
			] );
			$post_id  = $fallback ? (int) $fallback[0] : 0;
		}

		if ( ! $post_id ) {
			return '';
		}

		$today      = current_time( 'Y-m-d' );
		$tomorrow   = gmdate( 'Y-m-d', strtotime( '+1 day', current_time( 'timestamp' ) ) );
		$from_price = (float) get_post_meta( $post_id, '_arc_from_price', true );
		$error      = isset( $_GET['arc_err'] ) ? sanitize_text_field( wp_unslash( $_GET['arc_err'] ) ) : '';

		ob_start();
		?>
		<div class="arc-single-booking-box" style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:22px;box-shadow:0 4px 6px -1px rgba(0,0,0,0.05);">
			<?php if ( $from_price > 0 ) : ?>
				<div class="arc-box-header" style="border-bottom:1px solid #f1f5f9;padding-bottom:14px;margin-bottom:18px;">
					<span style="font-size:12px;text-transform:uppercase;color:#64748b;letter-spacing:0.5px;display:block;"><?php esc_html_e( 'Rates from', 'arc-hotel-core' ); ?></span>
					<h3 style="margin:4px 0 0;font-size:24px;font-weight:700;color:#0f172a;">
						<?php echo esc_html( Settings::format_price( $from_price ) ); ?>
						<span style="font-size:13px;font-weight:400;color:#64748b;">/ <?php esc_html_e( 'night', 'arc-hotel-core' ); ?></span>
					</h3>
				</div>
			<?php endif; ?>

			<form class="arc-room-booking" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<input type="hidden" name="room_post_id" value="<?php echo esc_attr( $post_id ); ?>">
				<input type="hidden" name="arc_book_nonce" value="<?php echo esc_attr( wp_create_nonce( self::ACTION ) ); ?>">

				<?php if ( ! empty( $error ) ) : ?>
					<div class="arc-booking-error" role="alert" style="background:#fef2f2;color:#991b1b;padding:12px;border-radius:6px;margin-bottom:16px;font-size:13.5px;border-left:4px solid #ef4444;">
						&#9888; <?php echo esc_html( $error ); ?>
					</div>
				<?php endif; ?>

				<div style="display:flex;gap:12px;margin-bottom:14px;flex-wrap:wrap;">
					<div style="flex:1;min-width:130px;">
						<label for="arc_check_in" style="display:block;font-size:12px;font-weight:600;margin-bottom:6px;color:#334155;text-transform:uppercase;"><?php esc_html_e( 'Check-in', 'arc-hotel-core' ); ?></label>
						<input type="date" id="arc_check_in" name="check_in" min="<?php echo esc_attr( $today ); ?>" value="<?php echo esc_attr( $today ); ?>" required style="width:100%;padding:10px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:14px;background:#f8fafc;">
					</div>
					<div style="flex:1;min-width:130px;">
						<label for="arc_check_out" style="display:block;font-size:12px;font-weight:600;margin-bottom:6px;color:#334155;text-transform:uppercase;"><?php esc_html_e( 'Check-out', 'arc-hotel-core' ); ?></label>
						<input type="date" id="arc_check_out" name="check_out" min="<?php echo esc_attr( $tomorrow ); ?>" value="<?php echo esc_attr( $tomorrow ); ?>" required style="width:100%;padding:10px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:14px;background:#f8fafc;">
					</div>
				</div>

				<div style="display:flex;gap:12px;margin-bottom:20px;flex-wrap:wrap;">
					<div style="flex:1;min-width:130px;">
						<label for="arc_adults" style="display:block;font-size:12px;font-weight:600;margin-bottom:6px;color:#334155;text-transform:uppercase;"><?php esc_html_e( 'Adults', 'arc-hotel-core' ); ?></label>
						<select id="arc_adults" name="adults" style="width:100%;padding:10px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:14px;background:#f8fafc;">
							<?php for ( $i = 1; $i <= 8; $i++ ) : ?>
								<option value="<?php echo (int) $i; ?>" <?php selected( $i, 2 ); ?>><?php printf( esc_html__( '%d Adult(s)', 'arc-hotel-core' ), (int) $i ); ?></option>
							<?php endfor; ?>
						</select>
					</div>
					<div style="flex:1;min-width:130px;">
						<label for="arc_children" style="display:block;font-size:12px;font-weight:600;margin-bottom:6px;color:#334155;text-transform:uppercase;"><?php esc_html_e( 'Children', 'arc-hotel-core' ); ?></label>
						<select id="arc_children" name="children" style="width:100%;padding:10px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:14px;background:#f8fafc;">
							<?php for ( $i = 0; $i <= 6; $i++ ) : ?>
								<option value="<?php echo (int) $i; ?>" <?php selected( $i, 0 ); ?>><?php printf( esc_html__( '%d Child(ren)', 'arc-hotel-core' ), (int) $i ); ?></option>
							<?php endfor; ?>
						</select>
					</div>
				</div>

				<button type="submit" class="arc-book-now-btn" style="width:100%;background:#1e3a8a;color:#fff;border:0;padding:14px 18px;border-radius:6px;font-weight:700;font-size:15px;letter-spacing:0.5px;cursor:pointer;transition:background 0.2s ease;">
					<?php esc_html_e( 'Book Now &bull; Proceed to Checkout', 'arc-hotel-core' ); ?> &rarr;
				</button>
			</form>
		</div>

		<script>
		(function () {
			var inEl = document.getElementById('arc_check_in');
			var outEl = document.getElementById('arc_check_out');
			if (!inEl || !outEl) { return; }
			inEl.addEventListener('change', function () {
				var d = new Date(inEl.value + 'T00:00:00');
				if (isNaN(d.getTime())) { return; }
				d.setDate(d.getDate() + 1);
				var min = d.toISOString().slice(0, 10);
				outEl.min = min;
				if (!outEl.value || outEl.value <= inEl.value) { outEl.value = min; }
			});
		})();
		</script>
		<?php
		return ob_get_clean();
	}

	/**
	 * admin-post handler: validate, verify availability + price server-side, add to cart, go to checkout.
	 *
	 * @return void
	 */
	public static function handle(): void {
		$post_id = isset( $_POST['room_post_id'] ) ? absint( $_POST['room_post_id'] ) : 0;
		$back    = $post_id ? get_permalink( $post_id ) : home_url( '/' );

		// Nonce validation (supports both self::ACTION and arc_add_room_to_cart for cache resilience)
		$nonce = isset( $_POST['arc_book_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['arc_book_nonce'] ) ) : ( isset( $_POST['arc_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['arc_nonce'] ) ) : '' );
		if ( ! self::verify_form_nonce( $nonce ) ) {
			self::fail( $back, __( 'Session expired or invalid security token. Please refresh the page and try again.', 'arc-hotel-core' ) );
		}

		if ( ! $post_id || Room_Post_Type::POST_TYPE !== get_post_type( $post_id ) ) {
			self::fail( $back, __( 'Invalid hotel room selected.', 'arc-hotel-core' ) );
		}

		$check_in  = isset( $_POST['check_in'] ) ? sanitize_text_field( wp_unslash( $_POST['check_in'] ) ) : '';
		$check_out = isset( $_POST['check_out'] ) ? sanitize_text_field( wp_unslash( $_POST['check_out'] ) ) : '';
		$adults    = isset( $_POST['adults'] ) ? max( 1, min( 8, absint( $_POST['adults'] ) ) ) : 2;
		$children  = isset( $_POST['children'] ) ? max( 0, min( 6, absint( $_POST['children'] ) ) ) : 0;

		$tz  = wp_timezone();
		$in  = \DateTimeImmutable::createFromFormat( '!Y-m-d', $check_in, $tz );
		$out = \DateTimeImmutable::createFromFormat( '!Y-m-d', $check_out, $tz );
		if ( ! $in || ! $out || $in->format( 'Y-m-d' ) !== $check_in || $out->format( 'Y-m-d' ) !== $check_out ) {
			self::fail( $back, __( 'Please select valid check-in and check-out dates.', 'arc-hotel-core' ) );
		}

		$today = new \DateTimeImmutable( 'today', $tz );
		if ( $in < $today ) {
			self::fail( $back, __( 'Check-in date cannot be in the past.', 'arc-hotel-core' ) );
		}

		$max_nights = (int) Settings::get( 'max_stay_nights', 30 );
		$nights     = (int) $in->diff( $out )->format( '%r%a' );
		if ( $nights < 1 || $nights > $max_nights ) {
			/* translators: %d: max nights */
			self::fail( $back, sprintf( __( 'Stay must be between 1 and %d nights.', 'arc-hotel-core' ), $max_nights ) );
		}

		$room_type_id = (int) get_post_meta( $post_id, '_arc_room_type_id', true );
		if ( ! $room_type_id ) {
			self::fail( $back, __( 'This room is not currently connected for online booking.', 'arc-hotel-core' ) );
		}

		// Server-side availability and price authority. Real-time API query.
		$rooms = self::fetch_available( $check_in, $check_out, $room_type_id, $adults );
		if ( is_wp_error( $rooms ) ) {
			self::fail( $back, __( 'Could not verify room availability right now. Please try again shortly.', 'arc-hotel-core' ) );
		}

		$picked = null;
		foreach ( $rooms as $room ) {
			if ( (int) ( $room['roomTypeId'] ?? 0 ) === $room_type_id
				&& (int) ( $room['maxAdults'] ?? 2 ) >= $adults
				&& (int) ( $room['maxChildren'] ?? 0 ) >= $children ) {
				$picked = $room;
				break;
			}
		}

		if ( ! $picked ) {
			self::fail( $back, __( 'Sorry, no rooms are available for the selected dates and guest count.', 'arc-hotel-core' ) );
		}

		$rate  = (float) ( $picked['pricePerNight'] ?? 0 );
		$total = (float) ( $picked['totalPrice'] ?? ( $rate * $nights ) );
		if ( $rate <= 0 || $total <= 0 ) {
			self::fail( $back, __( 'Pricing information is currently unavailable for this room.', 'arc-hotel-core' ) );
		}

		// Ensure WooCommerce Cart and Session are initialized (critical in admin-post.php context)
		if ( function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}
		if ( function_exists( 'WC' ) ) {
			if ( null === WC()->session && method_exists( WC(), 'initialize_session' ) ) {
				WC()->initialize_session();
			}
			if ( null === WC()->cart && method_exists( WC(), 'initialize_cart' ) ) {
				WC()->initialize_cart();
			}
			if ( null === WC()->customer && class_exists( 'WC_Customer' ) ) {
				WC()->customer = new \WC_Customer( get_current_user_id(), true );
			}
		}

		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			self::fail( $back, __( 'Booking checkout system is temporarily unavailable.', 'arc-hotel-core' ) );
		}

		$product_id = (int) get_option( 'arc_room_product_id', 0 );
		if ( ! $product_id ) {
			$product_id = (int) Plugin::ensure_room_container_product();
		}

		// Strict single-room cart isolation: clear existing cart items
		WC()->cart->empty_cart();

		// Fallback thumbnail if API doesn't provide one
		$thumb = ! empty( $picked['thumbnailUrl'] ) ? $picked['thumbnailUrl'] : '';
		if ( empty( $thumb ) && ! empty( $picked['imageUrls'][0] ) ) {
			$thumb = $picked['imageUrls'][0];
		}
		if ( empty( $thumb ) ) {
			$thumb = get_the_post_thumbnail_url( $post_id, 'full' );
		}

		$booking_data = [
			'room_type_id'   => $room_type_id,
			'room_type_name' => (string) ( $picked['roomTypeName'] ?? get_the_title( $post_id ) ),
			'room_post_id'   => $post_id,
			'room_id'        => (int) ( $picked['roomId'] ?? 0 ),
			'room_number'    => (string) ( $picked['roomNumber'] ?? '' ),
			'check_in'       => $check_in,
			'check_out'      => $check_out,
			'nights'         => (int) ( $picked['nights'] ?? $nights ),
			'adults'         => $adults,
			'children'       => $children,
			'rate_per_night' => $rate,
			'total_price'    => $total,
			'thumbnail_url'  => esc_url_raw( (string) $thumb ),
		];

		// Provide both 'arc_room' (for Cart_Guard/Checkout_Fields) and '_arc_booking' (for Order meta)
		$cart_item_data = [
			'_is_hotel_room' => 'yes',
			'arc_room'       => $booking_data,
			'_arc_booking'   => $booking_data,
		];

		$key = WC()->cart->add_to_cart( $product_id, 1, 0, [], $cart_item_data );
		if ( ! $key ) {
			self::fail( $back, __( 'Could not start your booking session. Please try again.', 'arc-hotel-core' ) );
		}

		// Redirect directly to WooCommerce Checkout page
		wp_safe_redirect( wc_get_checkout_url() );
		exit;
	}

	/**
	 * Single adapter to the API layer.
	 *
	 * @param string $check_in     Check-in date.
	 * @param string $check_out    Check-out date.
	 * @param int    $room_type_id Room Type ID.
	 * @param int    $adults       Adults count.
	 * @return array|\WP_Error List of available room arrays.
	 */
	private static function fetch_available( string $check_in, string $check_out, int $room_type_id, int $adults ) {
		$company = Settings::get( 'company_code' );
		$client  = new Api_Client();

		$result = $client->get(
			'api/v1/public-booking/available-rooms',
			[
				'checkIn'     => $check_in . 'T14:00:00',
				'checkOut'    => $check_out . 'T12:00:00',
				'roomTypeId'  => $room_type_id,
				'adults'      => $adults,
				'companyCode' => $company,
			],
			[ 'use_cache' => false ]
		);

		if ( ! $result || ! $result->ok() || ! is_array( $result->data() ) ) {
			return new \WP_Error( 'arc_api', $result ? $result->message() : 'availability_failed' );
		}

		return $result->data();
	}

	/**
	 * Verify booking form nonce across logged-in and guest (cached/REST) contexts.
	 *
	 * When pages are cached or nonces are refreshed via the REST fresh-nonces endpoint,
	 * the nonce is generated in an unauthenticated (guest, UID 0) context. If a logged-in
	 * administrator or customer submits the form, WordPress's standard wp_verify_nonce
	 * hashes against their logged-in UID and would reject the valid guest nonce.
	 * This method checks both contexts cleanly.
	 *
	 * @param string $nonce Submitted nonce string.
	 * @return bool True if valid for either the current user or guest session.
	 */
	public static function verify_form_nonce( string $nonce ): bool {
		if ( empty( $nonce ) ) {
			return false;
		}

		// 1. Direct verify against current user (guest if guest, logged-in user if logged-in)
		if ( wp_verify_nonce( $nonce, self::ACTION ) || wp_verify_nonce( $nonce, 'arc_add_room_to_cart' ) ) {
			return true;
		}

		// 2. If current user is logged in, but nonce was fetched from REST fresh-nonces (which generated for UID 0)
		if ( is_user_logged_in() ) {
			$current_uid = get_current_user_id();
			wp_set_current_user( 0 );
			$guest_valid = wp_verify_nonce( $nonce, self::ACTION ) || wp_verify_nonce( $nonce, 'arc_add_room_to_cart' );
			wp_set_current_user( $current_uid );
			if ( $guest_valid ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Redirect back with error notice.
	 *
	 * @param string $url     Return URL.
	 * @param string $message Error message.
	 * @return void
	 */
	private static function fail( string $url, string $message ): void {
		wp_safe_redirect( add_query_arg( 'arc_err', rawurlencode( $message ), $url ) );
		exit;
	}
}
