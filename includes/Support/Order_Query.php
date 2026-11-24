<?php
/** WooCommerce metadata queries for both HPOS and legacy post storage. */
namespace ARC\HotelCore\Support;
defined( 'ABSPATH' ) || exit;

class Order_Query {
	public static function get_orders( array $args ): array {
		$hpos = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		if ( $hpos || empty( $args['meta_query'] ) ) {
			return wc_get_orders( $args );
		}
		// CPT does not support the HPOS meta_query argument without this adapter.
		$args['arc_meta_query'] = $args['meta_query'];
		unset( $args['meta_query'] );
		$adapter = static function ( $query, $query_vars ) {
			if ( ! empty( $query_vars['arc_meta_query'] ) ) {
				$query['meta_query'][] = $query_vars['arc_meta_query'];
			}
			return $query;
		};
		add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', $adapter, 10, 2 );
		try {
			return wc_get_orders( $args );
		} finally {
			remove_filter( 'woocommerce_order_data_store_cpt_get_orders_query', $adapter, 10 );
		}
	}
}
