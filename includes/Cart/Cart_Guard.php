<?php
/**
 * Cart Guard & Dynamic Price Override.
 *
 * @package ARC\HotelCore\Cart
 */

namespace ARC\HotelCore\Cart;

use ARC\HotelCore\Booking\Room_Quote;
use ARC\HotelCore\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles room addition to cart, strict separation between food and room, and server-side price override.
 */
class Cart_Guard {

	/**
	 * Hook WooCommerce cart filters and actions.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'template_redirect', [ $this, 'handle_book_room_action' ] );
		add_action( 'template_redirect', [ $this, 'handle_clear_cart_action' ] );

		add_filter( 'woocommerce_add_to_cart_validation', [ $this, 'validate_add_to_cart' ], 10, 6 );
		add_filter( 'woocommerce_add_cart_item_data', [ $this, 'attach_room_data' ], 10, 3 );
		add_filter( 'woocommerce_get_cart_item_from_session', [ $this, 'restore_room_data' ], 10, 2 );
		add_action( 'woocommerce_before_calculate_totals', [ $this, 'override_cart_price' ], 20, 1 );
		add_action( 'woocommerce_check_cart_items', [ $this, 'validate_cart_items' ] );

		// Display custom booking dates in cart item table
		add_filter( 'woocommerce_get_item_data', [ $this, 'render_cart_item_meta' ], 10, 2 );
	}

	/**
	 * Check if current WooCommerce cart contains a hotel room.
	 *
	 * @return bool
	 */
	public static function cart_has_room(): bool {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return false;
		}

		foreach ( WC()->cart->get_cart() as $item ) {
			if ( ( ! empty( $item['arc_room'] ) || ! empty( $item['_arc_booking'] ) ) ) {
				return true;
			}
			if ( ! empty( $item['data'] ) && 'yes' === $item['data']->get_meta( '_is_hotel_room' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if current cart contains food or non-hotel items.
	 *
	 * @return bool
	 */
	public static function cart_has_food(): bool {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return false;
		}

		foreach ( WC()->cart->get_cart() as $item ) {
			if ( empty( $item['arc_room'] ) && empty( $item['_arc_booking'] ) && ( empty( $item['data'] ) || 'yes' !== $item['data']->get_meta( '_is_hotel_room' ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Handle Book Room POST submission.
	 *
	 * @return void
	 */
	public function handle_book_room_action(): void {
		if ( ! isset( $_POST['arc_action'] ) || 'book_room' !== $_POST['arc_action'] ) {
			return;
		}

		$nonce_raw   = isset( $_POST['arc_book_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['arc_book_nonce'] ) ) : ( isset( $_POST['arc_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['arc_nonce'] ) ) : '' );
		$nonce_valid = self::verify_booking_nonce( $nonce_raw );

		/*
		 * No session-based bypass is allowed here.
		 * Full-page cached pages ship stale nonces, so the supported recovery path is the
		 * no-store REST endpoint `arc/v1/fresh-nonces`, which the frontend calls to refresh
		 * the token before submitting. Accepting "a WooCommerce session exists" as proof of
		 * intent would disable CSRF protection entirely.
		 */
		if ( ! $nonce_valid ) {
			wc_add_notice( __( 'Security token expired. Please try searching again.', 'arc-hotel-core' ), 'error' );
			return;
		}

		$room_type_id = isset( $_POST['room_type_id'] ) ? absint( $_POST['room_type_id'] ) : 0;
		$room_id      = isset( $_POST['room_id'] ) ? absint( $_POST['room_id'] ) : 0;
		$check_in     = isset( $_POST['check_in'] ) ? sanitize_text_field( wp_unslash( $_POST['check_in'] ) ) : '';
		$check_out    = isset( $_POST['check_out'] ) ? sanitize_text_field( wp_unslash( $_POST['check_out'] ) ) : '';
		$adults       = isset( $_POST['adults'] ) ? max( 1, absint( $_POST['adults'] ) ) : 2;
		$children     = isset( $_POST['children'] ) ? absint( $_POST['children'] ) : 0;

		if ( empty( $check_in ) || empty( $check_out ) || $room_type_id <= 0 ) {
			wc_add_notice( __( 'Please provide valid reservation dates and room selection.', 'arc-hotel-core' ), 'error' );
			return;
		}

		$result = Room_Quote::search( $check_in, $check_out, $adults, $children, $room_type_id );
		if ( ! $result->ok() ) {
			wc_add_notice( $result->message(), 'error' );
			return;
		}
		$matched_room = Room_Quote::select( $result->data(), $room_type_id, $room_id );
		if ( ! $matched_room ) {
			wc_add_notice( __( 'The selected room is unavailable for these dates and guest counts.', 'arc-hotel-core' ), 'error' );
			return;
		}

		// Get container product
		$product_id = Plugin::ensure_room_container_product();
		if ( ! $product_id ) {
			wc_add_notice( __( 'Unable to process hotel booking. Booking container product is missing.', 'arc-hotel-core' ), 'error' );
			return;
		}

		// Strict Separation: Clear existing cart before adding hotel room
		WC()->cart->empty_cart();

		$booking = Room_Quote::booking_data( $matched_room, $check_in, $check_out, $adults, $children );
		$cart_item_data = [ 'arc_room' => $booking, '_arc_booking' => $booking ];
		if ( ! WC()->cart->add_to_cart( $product_id, 1, 0, [], $cart_item_data ) ) {
			wc_add_notice( __( 'Unable to start your booking session. Please try again.', 'arc-hotel-core' ), 'error' );
			return;
		}

		// Redirect directly to checkout for fast OTA experience
		wp_safe_redirect( wc_get_checkout_url() );
		exit;
	}

	/**
	 * Handle one-click clear cart action.
	 *
	 * @return void
	 */
	public function handle_clear_cart_action(): void {
		if ( ! isset( $_GET['arc_clear_cart'] ) || '1' !== $_GET['arc_clear_cart'] ) {
			return;
		}

		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'arc_clear_cart_nonce' ) ) {
			wc_add_notice( __( 'Invalid session link.', 'arc-hotel-core' ), 'error' );
			return;
		}

		if ( function_exists( 'WC' ) && WC()->cart ) {
			WC()->cart->empty_cart();
			wc_add_notice( __( 'Cart cleared successfully.', 'arc-hotel-core' ), 'success' );
		}

		wp_safe_redirect( wc_get_cart_url() );
		exit;
	}

	/**
	 * Block mixing hotel rooms with restaurant food items.
	 *
	 * @param bool $passed     Validation status.
	 * @param int  $product_id Product ID.
	 * @param int  $quantity   Quantity.
	 * @return bool
	 */
	public function validate_add_to_cart( bool $passed, int $product_id, int $quantity, int $variation_id = 0, array $variations = [], array $cart_item_data = [] ): bool {
		$is_hotel = 'yes' === get_post_meta( $product_id, '_is_hotel_room', true ) || has_term( 'hotel-rooms', 'product_cat', $product_id );

		if ( $is_hotel ) {
			$booking = (array) ( $cart_item_data['arc_room'] ?? [] );
			if ( empty( $booking['room_type_id'] ) || empty( $booking['total_price'] ) || $quantity !== 1 ) {
				wc_add_notice( __( 'Please select your room and dates before starting checkout.', 'arc-hotel-core' ), 'error' );
				return false;
			}
			if ( self::cart_has_food() ) {
				$clear_url = wp_nonce_url( add_query_arg( 'arc_clear_cart', '1', wc_get_cart_url() ), 'arc_clear_cart_nonce' );
				wc_add_notice(
					sprintf(
						/* translators: %s: Clear cart URL */
						__( 'You cannot book a hotel room while restaurant food items are in your cart. <a href="%s" class="button wc-forward" style="margin-left: 10px;">Clear Cart & Continue</a>', 'arc-hotel-core' ),
						esc_url( $clear_url )
					),
					'error'
				);
				return false;
			}
		} else {
			if ( self::cart_has_room() ) {
				$clear_url = wp_nonce_url( add_query_arg( 'arc_clear_cart', '1', wc_get_cart_url() ), 'arc_clear_cart_nonce' );
				wc_add_notice(
					sprintf(
						/* translators: %s: Clear cart URL */
						__( 'You cannot order food items while a hotel room reservation is in your cart. <a href="%s" class="button wc-forward" style="margin-left: 10px;">Clear Cart & Continue</a>', 'arc-hotel-core' ),
						esc_url( $clear_url )
					),
					'error'
				);
				return false;
			}
		}

		return $passed;
	}

	/**
	 * Attach room metadata into cart item array.
	 *
	 * @param array $cart_item_data Cart item data.
	 * @param int   $product_id     Product ID.
	 * @param int   $variation_id   Variation ID.
	 * @return array
	 */
	public function attach_room_data( array $cart_item_data, int $product_id, int $variation_id ): array {
		if ( isset( $cart_item_data['arc_room'] ) ) {
			$cart_item_data['unique_key'] = $cart_item_data['arc_room']['unique_key'] ?? md5( microtime() );
		}
		return $cart_item_data;
	}

	/**
	 * Restore room data from session.
	 *
	 * @param array $cart_item Cart item array.
	 * @param array $values    Session values.
	 * @return array
	 */
	public function restore_room_data( array $cart_item, array $values ): array {
		if ( ! empty( $values['arc_room'] ) || ! empty( $values['_arc_booking'] ) ) {
			$cart_item['arc_room'] = Room_Quote::normalize_booking( (array) ( $values['arc_room'] ?? $values['_arc_booking'] ) );
			$cart_item['_arc_booking'] = $cart_item['arc_room'];
		}
		return $cart_item;
	}

	/**
	 * Override cart line item price server-side using API verified total.
	 *
	 * @param \WC_Cart $cart Cart object.
	 * @return void
	 */
	public function override_cart_price( \WC_Cart $cart ): void {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
			if ( ! empty( $cart_item['arc_room']['total_price'] ) ) {
				$total_price = (float) $cart_item['arc_room']['total_price'];
				$cart_item['data']->set_price( $total_price );
				$cart->set_quantity( $cart_item_key, 1, false );
			}
		}
	}

	/**
	 * Check cart items on every load to ensure no mixed cart and valid dates.
	 *
	 * @return void
	 */
	public function validate_cart_items(): void {
		if ( function_exists( 'WC' ) && WC()->cart ) {
			foreach ( WC()->cart->get_cart() as $item ) {
				if ( ! empty( $item['arc_room'] ) ) {
					$booking = Room_Quote::normalize_booking( $item['arc_room'] );
					if ( ! Room_Quote::validate_dates( (string) ( $booking['check_in'] ?? '' ), (string) ( $booking['check_out'] ?? '' ) ) || empty( $booking['total_price'] ) ) {
						wc_add_notice( __( 'Your room dates or booking quote have expired. Please select your room again.', 'arc-hotel-core' ), 'error' );
					}
				}
			}
		}

		if ( self::cart_has_room() && self::cart_has_food() ) {
			$clear_url = wp_nonce_url( add_query_arg( 'arc_clear_cart', '1', wc_get_cart_url() ), 'arc_clear_cart_nonce' );
			wc_add_notice(
				sprintf(
					/* translators: %s: Clear cart URL */
					__( 'Mixed cart detected (Hotel room + Restaurant items). Please <a href="%s">clear your cart</a> to proceed.', 'arc-hotel-core' ),
					esc_url( $clear_url )
				),
				'error'
			);
		}
	}

	/**
	 * Display booking dates & guest count in WooCommerce cart and checkout tables.
	 *
	 * @param array $item_data Existing item data.
	 * @param array $cart_item Current cart item.
	 * @return array
	 */
	public function render_cart_item_meta( array $item_data, array $cart_item ): array {
		if ( empty( $cart_item['arc_room'] ) ) {
			return $item_data;
		}

		$room = $cart_item['arc_room'];

		$item_data[] = [
			'key'   => __( 'Room Type', 'arc-hotel-core' ),
			'value' => esc_html( $room['room_type_name'] ?? 'Hotel Room' ),
		];
		$item_data[] = [
			'key'   => __( 'Dates', 'arc-hotel-core' ),
			'value' => esc_html( $room['check_in'] . ' to ' . $room['check_out'] . ' (' . $room['nights'] . ' nights)' ),
		];
		$item_data[] = [
			'key'   => __( 'Guests', 'arc-hotel-core' ),
			'value' => sprintf(
				/* translators: 1: Adults count, 2: Children count */
				esc_html__( '%1$d Adults, %2$d Children', 'arc-hotel-core' ),
				absint( $room['adults'] ),
				absint( $room['children'] )
			),
		];
		$item_data[] = [
			'key'   => __( 'Rate', 'arc-hotel-core' ),
			'value' => esc_html( number_format( $room['rate_per_night'] ) . ' BDT / night' ),
		];

		return $item_data;
	}

	/** Verify the submitting user's nonce; cached guest pages refresh through REST. */
	public static function verify_booking_nonce( string $nonce ): bool {
		if ( empty( $nonce ) ) {
			return false;
		}

		if ( wp_verify_nonce( $nonce, 'arc_add_room_to_cart' ) || wp_verify_nonce( $nonce, 'arc_book_room' ) ) {
			return true;
		}

		return false;
	}
}
