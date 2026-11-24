<?php
/** Remove plugin diagnostics and schedules; preserve booking/financial records. */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }

global $wpdb;
foreach ( [ 'arc_sync_rooms_cron', 'arc_daily_log_purge', 'arc_daily_reconcile', 'arc_retry_reservation_sync', 'arc_observe_reservation_sync' ] as $hook ) {
	wp_unschedule_hook( $hook );
}
foreach ( [ 'arc_settings', 'arc_room_product_id', 'arc_plugin_version', 'arc_log_privacy_version', 'arc_cron_last_run', 'arc_cache_generation', 'arc_permalinks_flushed_v3' ] as $option ) {
	delete_option( $option );
}
foreach ( [ '_transient_arc_', '_transient_timeout_arc_', 'arc_lock_' ] as $prefix ) {
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) );
}
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}arc_api_log" );
// Room posts, the container product, WooCommerce orders and guest metadata are retained.
