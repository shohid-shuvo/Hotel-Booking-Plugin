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
 * Diagnostic logger for API requests.
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
	 * Create database table for API logs.
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
	}

	/**
	 * Record an API transaction.
	 *
	 * @param string      $endpoint       Called API endpoint.
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

		$redacted_request  = self::redact( $request );
		$redacted_response = self::redact( $response );

		$wpdb->insert(
			$table_name,
			[
				'created_at'       => current_time( 'mysql', true ),
				'endpoint'         => sanitize_text_field( $endpoint ),
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
