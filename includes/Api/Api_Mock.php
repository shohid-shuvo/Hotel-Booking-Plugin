<?php
/**
 * API Mock Handler.
 *
 * @package ARC\HotelCore\Api
 */

namespace ARC\HotelCore\Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides mock JSON responses for local development and testing.
 */
class Api_Mock {

	/**
	 * Path to mock fixtures directory.
	 *
	 * @return string
	 */
	public static function get_fixtures_dir(): string {
		return dirname( __DIR__, 2 ) . '/tests/fixtures/';
	}

	/**
	 * Check if mock mode is enabled.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		if ( defined( 'ARC_API_MOCK' ) && ARC_API_MOCK ) {
			return true;
		}
		return false;
	}

	/**
	 * Get fixture data for a given endpoint.
	 *
	 * @param string $endpoint API endpoint path.
	 * @param array  $body     Optional request body.
	 * @return Api_Result|null Null if no fixture found.
	 */
	public static function get_mock_result( string $endpoint, array $body = [] ): ?Api_Result {
		$clean_endpoint = trim( strtolower( $endpoint ), '/' );
		$fixtures_dir   = self::get_fixtures_dir();

		$fixture_map = [
			'api/v1/public-booking/hotel-info'             => 'hotel-info.json',
			'api/v1/public-booking/room-categories'        => 'room-categories.json',
			'api/v1/public-booking/available-rooms'        => 'available-rooms.json',
			'api/v1/public-booking/create-reservation-json' => 'create-reservation.json',
			'api/v1/public-booking/create-reservation'      => 'create-reservation.json',
			'api/v1/public-booking/cancel-reservation-json' => 'cancel.json',
			'api/v1/public-booking/cancel-reservation'      => 'cancel.json',
		];

		// Check for track endpoint wildcard (e.g., api/v1/public-booking/track/...)
		if ( str_starts_with( $clean_endpoint, 'api/v1/public-booking/track' ) ) {
			$file = $fixtures_dir . 'track.json';
		} elseif ( isset( $fixture_map[ $clean_endpoint ] ) ) {
			$file = $fixtures_dir . $fixture_map[ $clean_endpoint ];
		} else {
			return null;
		}

		if ( ! file_exists( $file ) ) {
			return Api_Result::error( 'FIXTURE_NOT_FOUND', "Mock fixture file not found for endpoint: {$clean_endpoint}", 404 );
		}

		$json_content = file_get_contents( $file );
		$data         = json_decode( $json_content, true );

		if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $data ) ) {
			return Api_Result::error( 'INVALID_FIXTURE_JSON', 'Malformed mock JSON fixture', 500 );
		}

		$succeeded = ! empty( $data['succeeded'] );
		$payload   = $data['data'] ?? null;
		$message   = $data['message'] ?? 'Mock response';

		return new Api_Result( $succeeded, $payload, '', $message, 200, $data );
	}
}
