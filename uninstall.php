<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package ARC\HotelCore
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// 1. Remove plugin settings and options
delete_option( 'arc_settings' );
delete_option( 'arc_room_product_id' );

// 2. Remove all cached transients
$like_cache = '_transient_arc_cache_%';
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like_cache ) );
$like_timeout = '_transient_timeout_arc_cache_%';
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like_timeout ) );

// 3. Drop API diagnostics log table
$log_table = $wpdb->prefix . 'arc_api_log';
$wpdb->query( "DROP TABLE IF EXISTS {$log_table}" );

// 4. Clear scheduled crons
$timestamp = wp_next_scheduled( 'arc_daily_log_purge' );
if ( $timestamp ) {
	wp_unschedule_event( $timestamp, 'arc_daily_log_purge' );
}

// NOTE: Orders, order metadata, and customer records are deliberately preserved
// to maintain financial and historical booking integrity.
