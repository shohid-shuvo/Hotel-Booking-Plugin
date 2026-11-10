<?php
/**
 * Custom WooCommerce Payment Gateway: Pay at Hotel.
 *
 * @package ARC\HotelCore\Payment
 */

namespace ARC\HotelCore\Payment;

use ARC\HotelCore\Booking\Reservation_Sync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pay at Hotel Gateway.
 */
class Gateway_Pay_At_Hotel extends \WC_Payment_Gateway {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id                 = 'arc_pay_at_hotel';
		$this->icon               = '';
		$this->has_fields         = false;
		$this->method_title       = __( 'Pay at Hotel', 'arc-hotel-core' );
		$this->method_description = __( 'Allows hotel guests to reserve their room online and pay in cash or card at the hotel reception desk upon check-in.', 'arc-hotel-core' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = \ARC\HotelCore\Settings\Settings::get( 'pay_at_hotel_title' ) ?: $this->get_option( 'title', __( 'Pay at Hotel (Upon Arrival)', 'arc-hotel-core' ) );
		$this->description = \ARC\HotelCore\Settings\Settings::get( 'pay_at_hotel_desc' ) ?: $this->get_option( 'description', __( 'Reserve your room now without paying online. Payment will be collected at the hotel front desk during check-in.', 'arc-hotel-core' ) );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
	}

	/**
	 * Initialise Gateway Settings Form Fields.
	 *
	 * @return void
	 */
	public function init_form_fields(): void {
		$this->form_fields = [
			'enabled' => [
				'title'   => __( 'Enable/Disable', 'arc-hotel-core' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Pay at Hotel Gateway', 'arc-hotel-core' ),
				'default' => 'yes',
			],
			'title' => [
				'title'       => __( 'Title', 'arc-hotel-core' ),
				'type'        => 'text',
				'description' => __( 'This controls the payment method title which the user sees during checkout.', 'arc-hotel-core' ),
				'default'     => __( 'Pay at Hotel (Upon Arrival)', 'arc-hotel-core' ),
				'desc_tip'    => true,
			],
			'description' => [
				'title'       => __( 'Description', 'arc-hotel-core' ),
				'type'        => 'textarea',
				'description' => __( 'Payment method description that the customer will see on checkout.', 'arc-hotel-core' ),
				'default'     => __( 'Reserve your room now without paying online. Payment will be collected at the hotel front desk during check-in.', 'arc-hotel-core' ),
			],
		];
	}

	/**
	 * Process the payment and return the result.
	 *
	 * @param int $order_id Order ID.
	 * @return array
	 */
	public function process_payment( $order_id ): array {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return [
				'result'   => 'failure',
				'messages' => __( 'Invalid order.', 'arc-hotel-core' ),
			];
		}

		// Mark order on-hold
		$order->update_status( 'on-hold', __( 'Guest selected Pay at Hotel. Reservation queued for hotel system synchronization.', 'arc-hotel-core' ) );

		// Empty cart
		if ( function_exists( 'WC' ) && WC()->cart ) {
			WC()->cart->empty_cart();
		}

		// Trigger immediate reservation sync
		if ( class_exists( '\ARC\HotelCore\Booking\Reservation_Sync' ) ) {
			Reservation_Sync::maybe_sync( $order_id );
		}

		return [
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		];
	}
}
