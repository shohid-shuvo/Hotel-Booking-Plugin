<?php
/**
 * API Logger.
 *
 * @package ARC\HotelCore\Api
 */

namespace ARC\HotelCore\Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Diagnostic logger for API requests with strict data masking and endpoint obfuscation.
 */
class Api_Logger {

	/**
	 * Table name without prefix.
	 */
	const TABLE_NAME = 'arc_api_log';

	/**
	 * Get full table name with prefix.
	 *
	 * @return string
	 */
	public static function get_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_NAME;
	}

	/**
	 * Create database table for API logs and migrate legacy records.
	 *
	 * @return void
	 */
	public static function install_table(): void {
		global $wpdb;

		$table_name      = self::get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			endpoint varchar(255) NOT NULL,
			method varchar(10) NOT NULL,
			http_status int(5) NOT NULL,
			duration_ms int(11) NOT NULL DEFAULT 0,
			correlation_id varchar(64) NOT NULL DEFAULT '',
			succeeded tinyint(1) NOT NULL DEFAULT 0,
			request_payload longtext DEFAULT NULL,
			response_payload longtext DEFAULT NULL,
			error_message text DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY endpoint (endpoint),
			KEY correlation_id (correlation_id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		self::migrate_legacy_logs();
	}

	/**
	 * Migrate existing legacy raw endpoints in DB to safe obfuscated slugs.
	 *
	 * @return void
	 */
	public static function migrate_legacy_logs(): void {
		global $wpdb;
		$table_name = self::get_table_name();

		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
		if ( ! $table_exists ) {
			return;
		}

		// Obfuscate raw API routes in existing rows to safe slugs
		$wpdb->query( "UPDATE {$table_name} SET endpoint = 'hotel_profile_sync' WHERE endpoint LIKE '%hotel-info%'" );
		$wpdb->query( "UPDATE {$table_name} SET endpoint = 'room_catalog_sync' WHERE endpoint LIKE '%room-categories%'" );
		$wpdb->query( "UPDATE {$table_name} SET endpoint = 'availability_check' WHERE endpoint LIKE '%available-rooms%'" );
		$wpdb->query( "UPDATE {$table_name} SET endpoint = 'reservation_create' WHERE endpoint LIKE '%create-reservation%'" );
		$wpdb->query( "UPDATE {$table_name} SET endpoint = 'reservation_cancel' WHERE endpoint LIKE '%cancel-reservation%'" );
		$wpdb->query( "UPDATE {$table_name} SET endpoint = 'reservation_track' WHERE endpoint LIKE '%track%'" );

		// Clear payloads for succeeded rows to protect internal schema
		$is_debug = ( defined( 'WP_DEBUG' ) && WP_DEBUG ) || ( defined( 'ARC_DEV_MODE' ) && ARC_DEV_MODE );
		if ( ! $is_debug ) {
			$wpdb->query( "UPDATE {$table_name} SET request_payload = NULL, response_payload = NULL WHERE succeeded = 1" );
		}
	}

	/**
	 * Map endpoint or operation slug to client-safe friendly operation title.
	 *
	 * @param string $endpoint Raw endpoint or slug.
	 * @return string Friendly operation name.
	 */
	public static function get_operation_name( string $endpoint ): string {
		$clean = trim( strtolower( $endpoint ), '/' );

		if ( str_contains( $clean, 'hotel-info' ) || 'hotel_profile_sync' === $clean ) {
			return __( 'Hotel Profile Sync', 'arc-hotel-core' );
		}
		if ( str_contains( $clean, 'room-categories' ) || 'room_catalog_sync' === $clean ) {
			return __( 'Room Catalog Sync', 'arc-hotel-core' );
		}
		if ( str_contains( $clean, 'available-rooms' ) || 'availability_check' === $clean ) {
			return __( 'Availability Check', 'arc-hotel-core' );
		}
		if ( str_contains( $clean, 'create-reservation' ) || 'reservation_create' === $clean ) {
			return __( 'Reservation Booking', 'arc-hotel-core' );
		}
		if ( str_contains( $clean, 'cancel-reservation' ) || 'reservation_cancel' === $clean ) {
			return __( 'Reservation Cancellation', 'arc-hotel-core' );
		}
		if ( str_contains( $clean, 'track' ) || 'reservation_track' === $clean ) {
			return __( 'Reservation Tracking', 'arc-hotel-core' );
		}

		return __( 'System Cloud Sync', 'arc-hotel-core' );
	}

	/**
	 * Obfuscate raw API route into a sanitized internal slug.
	 *
	 * Never persists proprietary REST URL routes to the database.
	 *
	 * @param string $endpoint Raw endpoint path.
	 * @return string Safe slug.
	 */
	public static function get_operation_slug( string $endpoint ): string {
		$clean = trim( strtolower( $endpoint ), '/' );

		if ( str_contains( $clean, 'hotel-info' ) ) {
			return 'hotel_profile_sync';
		}
		if ( str_contains( $clean, 'room-categories' ) ) {
			return 'room_catalog_sync';
		}
		if ( str_contains( $clean, 'available-rooms' ) ) {
			return 'availability_check';
		}
		if ( str_contains( $clean, 'create-reservation' ) ) {
			return 'reservation_create';
		}
		if ( str_contains( $clean, 'cancel-reservation' ) ) {
			return 'reservation_cancel';
		}
		if ( str_contains( $clean, 'track' ) ) {
			return 'reservation_track';
		}

		return 'system_cloud_sync';
	}

	/**
	 * Record a synchronization transaction.
	 *
	 * @param string      $endpoint       Called API endpoint or slug.
	 * @param string      $method         HTTP method (GET, POST, etc.).
	 * @param int         $http_status    Returned HTTP status code.
	 * @param int         $duration_ms    Call duration in milliseconds.
	 * @param string      $correlation_id Tracking UUID.
	 * @param bool        $succeeded      Whether call was successful.
	 * @param mixed       $request        Request parameters or body.
	 * @param mixed       $response       Response data.
	 * @param string|null $error_message  Error description if any.
	 * @return void
	 */
	public static function log(
		string $endpoint,
		string $method,
		int $http_status,
		int $duration_ms,
		string $correlation_id,
		bool $succeeded,
		mixed $request = null,
		mixed $response = null,
		?string $error_message = null
	): void {
		global $wpdb;

		$table_name = self::get_table_name();

		// Check if table exists to avoid fatal error during early calls before activation.
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
		if ( ! $table_exists ) {
			self::install_table();
		}

		$is_debug       = ( defined( 'WP_DEBUG' ) && WP_DEBUG ) || ( defined( 'ARC_DEV_MODE' ) && ARC_DEV_MODE );
		$store_payloads = $is_debug || ! $succeeded;

		$redacted_request  = $store_payloads ? self::redact( $request ) : null;
		$redacted_response = $store_payloads ? self::redact( $response ) : null;

		$wpdb->insert(
			$table_name,
			[
				'created_at'       => current_time( 'mysql', true ),
				'endpoint'         => self::get_operation_slug( $endpoint ),
				'method'           => strtoupper( sanitize_text_field( $method ) ),
				'http_status'      => (int) $http_status,
				'duration_ms'      => (int) $duration_ms,
				'correlation_id'   => sanitize_text_field( $correlation_id ),
				'succeeded'        => $succeeded ? 1 : 0,
				'request_payload'  => ! empty( $redacted_request ) ? wp_json_encode( $redacted_request ) : null,
				'response_payload' => ! empty( $redacted_response ) ? wp_json_encode( $redacted_response ) : null,
				'error_message'    => $error_message ? sanitize_text_field( $error_message ) : null,
			],
			[ '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%s', '%s', '%s' ]
		);
	}

	/**
	 * Purge logs older than given days.
	 *
	 * @param int $days Number of days to keep.
	 * @return int Number of deleted rows.
	 */
	public static function purge_old_logs( int $days = 30 ): int {
		global $wpdb;
		$table_name = self::get_table_name();
		$cutoff     = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table_name} WHERE created_at < %s",
				$cutoff
			)
		);

		return false !== $deleted ? (int) $deleted : 0;
	}

	/**
	 * Redact personally identifiable information (PII) and credentials.
	 *
	 * @param mixed $data Data to sanitize.
	 * @return mixed
	 */
	public static function redact( mixed $data ): mixed {
		if ( empty( $data ) ) {
			return $data;
		}

		if ( is_string( $data ) ) {
			$decoded = json_decode( $data, true );
			if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
				return self::redact( $decoded );
			}
			return $data;
		}

		if ( ! is_array( $data ) ) {
			return $data;
		}

		$sensitive_keys = [
			'nationalIdOrPassport',
			'national_id',
			'nid',
			'passport',
			'phone',
			'guestPhone',
			'email',
			'guestEmail',
			'password',
			'token',
			'secret',
			'api_key',
			'apiKey',
			'authorization',
			'auth',
			'x-api-key',
			'cookie',
			'credit_card',
			'cardNumber',
			'cvv',
		];

		$clean = [];
		foreach ( $data as $key => $value ) {
			if ( is_string( $key ) && in_array( strtolower( $key ), array_map( 'strtolower', $sensitive_keys ), true ) ) {
				$clean[ $key ] = '***REDACTED***';
			} elseif ( is_array( $value ) ) {
				$clean[ $key ] = self::redact( $value );
			} else {
				$clean[ $key ] = $value;
			}
		}

		return $clean;
	}
}
