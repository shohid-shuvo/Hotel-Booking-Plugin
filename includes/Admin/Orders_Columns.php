<?php
/**
 * WooCommerce Orders List Columns & Filters.
 *
 * @package ARC\HotelCore\Admin
 */

namespace ARC\HotelCore\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds Booking Type & Sync Status columns to Orders list table.
 */
class Orders_Columns {

	/**
	 * Hook order table filters.
	 *
	 * @return void
	 */
	public function init(): void {
		// Classic shop_order screen
		add_filter( 'manage_edit-shop_order_columns', [ $this, 'add_columns' ], 20 );
		add_action( 'manage_shop_order_posts_custom_column', [ $this, 'render_column' ], 20, 2 );

		// HPOS screen
		add_filter( 'manage_woocommerce_page_wc-orders_columns', [ $this, 'add_columns' ], 20 );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', [ $this, 'render_hpos_column' ], 20, 2 );
	}

	/**
	 * Add custom columns.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function add_columns( array $columns ): array {
		$new_columns = [];
		foreach ( $columns as $key => $column ) {
			$new_columns[ $key ] = $column;
			if ( 'order_status' === $key ) {
				$new_columns['arc_booking_type'] = __( 'Type / Sync Status', 'arc-hotel-core' );
			}
		}
		if ( ! isset( $new_columns['arc_booking_type'] ) ) {
			$new_columns['arc_booking_type'] = __( 'Type / Sync Status', 'arc-hotel-core' );
		}
		return $new_columns;
	}

	/**
	 * Render classic post column.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 * @return void
	 */
	public function render_column( string $column, int $post_id ): void {
		if ( 'arc_booking_type' === $column ) {
			$order = wc_get_order( $post_id );
			if ( $order ) {
				$this->display_badge( $order );
			}
		}
	}

	/**
	 * Render HPOS column.
	 *
	 * @param string    $column Column key.
	 * @param \WC_Order $order  Order object.
	 * @return void
	 */
	public function render_hpos_column( string $column, \WC_Order $order ): void {
		if ( 'arc_booking_type' === $column ) {
			$this->display_badge( $order );
		}
	}

	/**
	 * Output formatted status badge.
	 *
	 * @param \WC_Order $order Order object.
	 * @return void
	 */
	protected function display_badge( \WC_Order $order ): void {
		$order_type = $order->get_meta( '_arc_order_type' );

		if ( 'room' === $order_type ) {
			$sync_status = $order->get_meta( '_arc_sync_status' ) ?: 'pending';
			$booking_no  = $order->get_meta( '_arc_aspnet_reservation_no' );

			$bg    = 'synced' === $sync_status ? '#e8f5e9' : ( 'failed' === $sync_status ? '#ffebee' : '#fff8e1' );
			$color = 'synced' === $sync_status ? '#2e7d32' : ( 'failed' === $sync_status ? '#c62828' : '#f57f17' );

			echo '<div style="display: flex; flex-direction: column; gap: 4px;">';
			echo '<span style="font-weight: 600; color: #0284c7;">&#127976; ' . esc_html__( 'Hotel Room', 'arc-hotel-core' ) . '</span>';
			echo '<span style="display: inline-block; padding: 2px 6px; border-radius: 3px; font-size: 11px; font-weight: bold; text-transform: uppercase; background: ' . esc_attr( $bg ) . '; color: ' . esc_attr( $color ) . ';">' . esc_html( $sync_status ) . '</span>';
			if ( ! empty( $booking_no ) ) {
				echo '<small style="color: #64748b;"><code>' . esc_html( $booking_no ) . '</code></small>';
			}
			echo '</div>';
		} else {
			echo '<span style="color: #64748b;">&#127828; ' . esc_html__( 'Restaurant Food', 'arc-hotel-core' ) . '</span>';
		}
	}
}
