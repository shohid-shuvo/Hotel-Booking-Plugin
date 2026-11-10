<?php
/**
 * Gateway Filter for Room vs Food Carts.
 *
 * @package ARC\HotelCore\Payment
 */

namespace ARC\HotelCore\Payment;

use ARC\HotelCore\Cart\Cart_Guard;
use ARC\HotelCore\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers Pay at Hotel gateway and scopes available gateways conditionally.
 */
class Gateway_Filter {

	/**
	 * Hook WooCommerce payment gateway filters.
	 *
	 * @return void
	 */
	public function init(): void {
		add_filter( 'woocommerce_payment_gateways', [ $this, 'register_gateway' ] );
		add_filter( 'woocommerce_available_payment_gateways', [ $this, 'filter_gateways' ] );
	}

	/**
	 * Register the custom Pay at Hotel gateway class.
	 *
	 * @param array $gateways Existing gateways.
	 * @return array
	 */
	public function register_gateway( array $gateways ): array {
		$gateways[] = Gateway_Pay_At_Hotel::class;
		return $gateways;
	}

	/**
	 * Filter gateways depending on whether cart contains hotel room or food items.
	 *
	 * @param array $available_gateways Active gateways.
	 * @return array
	 */
	public function filter_gateways( array $available_gateways ): array {
		if ( is_admin() ) {
			return $available_gateways;
		}

		$has_room = Cart_Guard::cart_has_room();
		$has_food = Cart_Guard::cart_has_food();

		// Room Cart Mode
		if ( $has_room ) {
			$allowed_gateways     = (array) Settings::get( 'room_gateways', [] );
			$pay_at_hotel_enabled = Settings::get( 'pay_at_hotel_enabled', true );

			foreach ( $available_gateways as $id => $gateway ) {
				if ( 'arc_pay_at_hotel' === $id ) {
					if ( ! $pay_at_hotel_enabled ) {
						unset( $available_gateways[ $id ] );
					}
					continue;
				}

				if ( ! in_array( $id, $allowed_gateways, true ) ) {
					unset( $available_gateways[ $id ] );
				}
			}
		}

		// Food Cart Mode (Never allow Pay at Hotel for restaurant food delivery/takeaway)
		if ( $has_food || ! $has_room ) {
			if ( isset( $available_gateways['arc_pay_at_hotel'] ) ) {
				unset( $available_gateways['arc_pay_at_hotel'] );
			}
		}

		return $available_gateways;
	}
}
