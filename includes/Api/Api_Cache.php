<?php
/**
 * API Cache Manager.
 *
 * @package ARC\HotelCore\Api
 */

namespace ARC\HotelCore\Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles transient caching for allowable hotel API data.
 */
class Api_Cache {

	/**
	 * Cache key prefix.
	 */
	const PREFIX = 'arc_cache_';

	/**
	 * Generate a consistent cache key.
	 *
	 * @param string $endpoint API endpoint path.
	 * @param array  $params   Query parameters.
	 * @return string
	 */
	public static function key( string $endpoint, array $params = [] ): string {
		$clean_path = str_replace( [ '/', '-' ], '_', trim( $endpoint, '/' ) );
		if ( ! empty( $params ) ) {
			ksort( $params );
			$hash = substr( md5( wp_json_encode( $params ) ), 0, 10 );
			return self::PREFIX . $clean_path . '_' . $hash;
		}
		return self::PREFIX . $clean_path;
	}

	/**
	 * Retrieve cached response if available.
	 *
	 * @param string $endpoint API endpoint path.
	 * @param array  $params   Query parameters.
	 * @return mixed|null Null if not cached or expired.
	 */
	public static function get( string $endpoint, array $params = [] ): mixed {
		$key  = self::key( $endpoint, $params );
		$data = get_transient( $key );
		return false !== $data ? $data : null;
	}

	/**
	 * Store API response in transient cache.
	 *
	 * @param string $endpoint   API endpoint path.
	 * @param array  $params     Query parameters.
	 * @param mixed  $data       Data to cache.
	 * @param int    $expiration TTL in seconds.
	 * @return bool
	 */
	public static function set( string $endpoint, array $params, mixed $data, int $expiration ): bool {
		$key = self::key( $endpoint, $params );
		return set_transient( $key, $data, $expiration );
	}

	/**
	 * Delete specific cached item.
	 *
	 * @param string $endpoint API endpoint path.
	 * @param array  $params   Query parameters.
	 * @return bool
	 */
	public static function delete( string $endpoint, array $params = [] ): bool {
		$key = self::key( $endpoint, $params );
		return delete_transient( $key );
	}

	/**
	 * Flush all plugin cache transients.
	 *
	 * @return void
	 */
	public static function flush(): void {
		global $wpdb;
		$like = '_transient_' . self::PREFIX . '%';
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );
		$timeout_like = '_transient_timeout_' . self::PREFIX . '%';
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $timeout_like ) );
	}
}
