<?php
/**
 * API Client.
 *
 * @package ARC\HotelCore\Api
 */

namespace ARC\HotelCore\Api;

use ARC\HotelCore\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main HTTP Gateway for the Smart Hotel Management ASP.NET API.
 */
class Api_Client {

	/**
	 * Default read timeout in seconds.
	 */
	const READ_TIMEOUT = 8;

	/**
	 * Default write timeout in seconds.
	 */
	const WRITE_TIMEOUT = 10;

	/**
	 * Execute a GET request.
	 *
	 * @param string $path  API endpoint path.
	 * @param array  $query Query parameters.
	 * @param array  $opts  Additional options.
	 * @return Api_Result
	 */
	public function get( string $path, array $query = [], array $opts = [] ): Api_Result {
		$use_cache   = $opts['use_cache'] ?? true;
		$cache_ttl   = $opts['cache_ttl'] ?? 0;
		$retry_count = $opts['retries'] ?? 1;

		// 1. Check transient cache if requested and allowed
		if ( $use_cache && $cache_ttl > 0 ) {
			$cached = Api_Cache::get( $path, $query );
			if ( null !== $cached ) {
				return Api_Result::success( $cached, 'Loaded from local cache', 200, [ 'cached' => true ] );
			}
		}

		// 2. Check Mock mode
		if ( Api_Mock::is_enabled() ) {
			$mock_result = Api_Mock::get_mock_result( $path, $query );
			if ( $mock_result ) {
				return $mock_result;
			}
		}

		// Inject company code if not already present
		if ( ! isset( $query['companyCode'] ) ) {
			$query['companyCode'] = Settings::get( 'company_code' );
		}

		$base_url = Settings::get( 'api_base_url' );
		$url      = trailingslashit( $base_url ) . ltrim( $path, '/' );
		$full_url = add_query_arg( $query, $url );

		$correlation_id = wp_generate_uuid4();
		$api_key        = Settings::get_api_key();

		$headers = [
			'Accept'           => 'application/json',
			'X-Correlation-Id' => $correlation_id,
		];

		if ( ! empty( $api_key ) ) {
			$headers['X-Api-Key'] = $api_key;
		}

		$args = [
			'method'      => 'GET',
			'timeout'     => self::READ_TIMEOUT,
			'redirection' => 2,
			'httpversion' => '1.1',
			'user-agent'  => 'AristocratsHotelWordPress/' . ( defined( 'ARC_VERSION' ) ? ARC_VERSION : '1.0' ),
			'headers'     => $headers,
			'sslverify'   => true,
		];

		$start_time = microtime( true );
		$response   = wp_remote_get( $full_url, $args );

		// One retry on connection failure
		if ( is_wp_error( $response ) && $retry_count > 0 ) {
			$response = wp_remote_get( $full_url, $args );
		}

		$duration_ms = (int) round( ( microtime( true ) - $start_time ) * 1000 );

		return $this->process_response( $response, $path, 'GET', $query, $duration_ms, $correlation_id, $cache_ttl );
	}

	/**
	 * Execute a POST request.
	 *
	 * @param string $path API endpoint path.
	 * @param array  $body Request payload body.
	 * @param array  $opts Additional options.
	 * @return Api_Result
	 */
	public function post( string $path, array $body = [], array $opts = [] ): Api_Result {
		// 1. Check Mock mode
		if ( Api_Mock::is_enabled() ) {
			$mock_result = Api_Mock::get_mock_result( $path, $body );
			if ( $mock_result ) {
				return $mock_result;
			}
		}

		// Inject company code if missing in body
		if ( ! isset( $body['companyCode'] ) ) {
			$body['companyCode'] = Settings::get( 'company_code' );
		}

		$base_url = Settings::get( 'api_base_url' );
		$full_url = trailingslashit( $base_url ) . ltrim( $path, '/' );

		$correlation_id = wp_generate_uuid4();
		$api_key        = Settings::get_api_key();

		$headers = [
			'Accept'           => 'application/json',
			'Content-Type'     => 'application/json; charset=utf-8',
			'X-Correlation-Id' => $correlation_id,
		];

		if ( ! empty( $api_key ) ) {
			$headers['X-Api-Key'] = $api_key;
		}

		$args = [
			'method'      => 'POST',
			'timeout'     => self::WRITE_TIMEOUT,
			'redirection' => 2,
			'httpversion' => '1.1',
			'user-agent'  => 'AristocratsHotelWordPress/' . ( defined( 'ARC_VERSION' ) ? ARC_VERSION : '1.0' ),
			'headers'     => $headers,
			'body'        => wp_json_encode( $body ),
			'sslverify'   => true,
		];

		$start_time  = microtime( true );
		$response    = wp_remote_post( $full_url, $args );
		$duration_ms = (int) round( ( microtime( true ) - $start_time ) * 1000 );

		return $this->process_response( $response, $path, 'POST', $body, $duration_ms, $correlation_id, 0 );
	}

	/**
	 * Parse response and return standard Api_Result.
	 *
	 * @param mixed  $response       Response from wp_remote_*.
	 * @param string $endpoint       Endpoint.
	 * @param string $method         HTTP Method.
	 * @param mixed  $request_data   Request payload or params.
	 * @param int    $duration_ms    Duration in ms.
	 * @param string $correlation_id Request correlation UUID.
	 * @param int    $cache_ttl      Cache TTL if applicable.
	 * @return Api_Result
	 */
	protected function process_response(
		mixed $response,
		string $endpoint,
		string $method,
		mixed $request_data,
		int $duration_ms,
		string $correlation_id,
		int $cache_ttl
	): Api_Result {
		// Connection error
		if ( is_wp_error( $response ) ) {
			$error_message = $response->get_error_message();
			Api_Logger::log( $endpoint, $method, 0, $duration_ms, $correlation_id, false, $request_data, null, $error_message );
			return Api_Result::error( 'CONNECTION_ERROR', 'Unable to connect to the hotel booking system. Please try again shortly.', 0 );
		}

		$http_code = (int) wp_remote_retrieve_response_code( $response );
		$raw_body  = wp_remote_retrieve_body( $response );
		$parsed    = json_decode( $raw_body, true );

		if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $parsed ) ) {
			$error_msg = 'Invalid JSON response from hotel server. Status: ' . $http_code;
			Api_Logger::log( $endpoint, $method, $http_code, $duration_ms, $correlation_id, false, $request_data, $raw_body, $error_msg );
			return Api_Result::error( 'INVALID_JSON', 'Invalid response from booking server.', $http_code );
		}

		$succeeded  = ! empty( $parsed['succeeded'] );
		$data       = $parsed['data'] ?? null;
		$message    = $parsed['message'] ?? '';
		$errors     = $parsed['errors'] ?? [];
		$error_code = $this->map_error_code( $http_code, $parsed );

		// If success
		if ( $succeeded && ( $http_code >= 200 && $http_code < 300 ) ) {
			Api_Logger::log( $endpoint, $method, $http_code, $duration_ms, $correlation_id, true, $request_data, $data, null );

			if ( 'GET' === $method && $cache_ttl > 0 && null !== $data ) {
				Api_Cache::set( $endpoint, is_array( $request_data ) ? $request_data : [], $data, $cache_ttl );
			}

			return Api_Result::success( $data, $message, $http_code, $parsed );
		}

		// If error response
		$error_detail = ! empty( $errors ) ? implode( '; ', (array) $errors ) : $message;
		Api_Logger::log( $endpoint, $method, $http_code, $duration_ms, $correlation_id, false, $request_data, $parsed, $error_detail );

		$safe_message = $this->get_safe_error_message( $error_code, $message );
		return Api_Result::error( $error_code, $safe_message, $http_code, $parsed, $data );
	}

	/**
	 * Map HTTP code and backend errors to canonical error code.
	 *
	 * @param int   $http_code HTTP status.
	 * @param array $parsed    JSON response array.
	 * @return string
	 */
	protected function map_error_code( int $http_code, array $parsed ): string {
		if ( ! empty( $parsed['errorCode'] ) ) {
			return sanitize_text_field( $parsed['errorCode'] );
		}

		$message = strtolower( $parsed['message'] ?? '' );
		if ( str_contains( $message, 'not available' ) || str_contains( $message, 'already booked' ) ) {
			return 'ROOM_NOT_AVAILABLE';
		}
		if ( str_contains( $message, 'price changed' ) || str_contains( $message, 'rate changed' ) ) {
			return 'PRICE_CHANGED';
		}
		if ( str_contains( $message, 'expired' ) ) {
			return 'HOLD_EXPIRED';
		}

		return match ( $http_code ) {
			400 => 'INVALID_DATES',
			401 => 'UNAUTHORIZED',
			409 => 'ROOM_NOT_AVAILABLE',
			410 => 'HOLD_EXPIRED',
			422 => 'VALIDATION',
			429 => 'RATE_LIMITED',
			500, 502, 503 => 'SERVER_ERROR',
			default => 'UNKNOWN_ERROR',
		};
	}

	/**
	 * Translate error code to guest-safe presentation message.
	 *
	 * @param string $error_code Canonical error code.
	 * @param string $fallback   API message.
	 * @return string
	 */
	protected function get_safe_error_message( string $error_code, string $fallback = '' ): string {
		return match ( $error_code ) {
			'ROOM_NOT_AVAILABLE' => __( 'The selected room is no longer available for the chosen dates. Please choose another room or different dates.', 'arc-hotel-core' ),
			'PRICE_CHANGED'      => __( 'The room rate has been updated. Please review the updated fare before confirming.', 'arc-hotel-core' ),
			'HOLD_EXPIRED'       => __( 'Your booking session has expired. Please select your room again.', 'arc-hotel-core' ),
			'INVALID_DATES'      => __( 'Invalid dates selected. Please check your check-in and check-out dates.', 'arc-hotel-core' ),
			'RATE_LIMITED'      => __( 'Too many requests. Please wait a moment and try again.', 'arc-hotel-core' ),
			'UNAUTHORIZED'       => __( 'Booking service authorization failed. Please contact hotel support.', 'arc-hotel-core' ),
			default              => ! empty( $fallback ) ? esc_html( $fallback ) : __( 'An unexpected error occurred while processing your reservation. Please try again.', 'arc-hotel-core' ),
		};
	}
}
