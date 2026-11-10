<?php
/**
 * Payment Gateway Mapper to ASP.NET API Flags.
 *
 * @package ARC\HotelCore\Payment
 */

namespace ARC\HotelCore\Payment;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps WooCommerce gateways and totals to API PaymentMethod enum and PaymentOption string.
 */
class Payment_Mapper {

	/**
	 * Map WooCommerce payment gateway ID to API PaymentMethod enum (1..6).
	 *
	 * 1 = Cash
	 * 2 = Card
	 * 3 = bKash
	 * 4 = Nagad
	 * 5 = Bank
	 * 6 = Other
	 *
	 * @param string $gateway_id Gateway ID.
	 * @return int
	 */
	public static function get_api_payment_method( string $gateway_id ): int {
		$clean_id = strtolower( trim( $gateway_id ) );

		if ( 'arc_pay_at_hotel' === $clean_id || 'cod' === $clean_id ) {
			return 1; // Cash
		}

		if ( str_contains( $clean_id, 'card' ) || str_contains( $clean_id, 'stripe' ) || str_contains( $clean_id, 'visa' ) || str_contains( $clean_id, 'mastercard' ) ) {
			return 2; // Card
		}

		if ( str_contains( $clean_id, 'bkash' ) ) {
			return 3; // bKash
		}

		if ( str_contains( $clean_id, 'nagad' ) ) {
			return 4; // Nagad
		}

		if ( 'bacs' === $clean_id || str_contains( $clean_id, 'bank' ) || str_contains( $clean_id, 'cheque' ) ) {
			return 5; // Bank
		}

		return 6; // Other / SSLCommerz / generic
	}

	/**
	 * Determine API PaymentOption string: "PayAtHotel" | "Deposit" | "FullPayment".
	 *
	 * @param float $amount_paid Paid amount.
	 * @param float $total       Total expected amount.
	 * @return string
	 */
	public static function get_api_payment_option( float $amount_paid, float $total ): string {
		if ( $amount_paid <= 0.0 ) {
			return 'PayAtHotel';
		}

		if ( $amount_paid >= ( $total - 0.01 ) ) {
			return 'FullPayment';
		}

		return 'Deposit';
	}
}
