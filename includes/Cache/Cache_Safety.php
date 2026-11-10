<?php
/**
 * Cache Safety Manager for Full-Page Cache Plugins.
 *
 * Compatible with LiteSpeed Cache, WP Rocket, WP-Optimize, W3 Total Cache.
 *
 * @package ARC\HotelCore\Cache
 */

namespace ARC\HotelCore\Cache;

use ARC\HotelCore\Cart\Cart_Guard;
use ARC\HotelCore\Api\Api_Client;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles no-cache headers, REST endpoints for live cached blocks, and stale nonce protection.
 */
class Cache_Safety {

	/**
	 * Hook cache safety filters and REST API.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'template_redirect', [ $this, 'enforce_nocache_for_live_pages' ], 1 );
		add_action( 'send_headers', [ $this, 'send_cache_control_headers' ], 1 );
		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
	}

	/**
	 * Determine if current request displays real-time live data that must not be cached.
	 *
	 * @return bool
	 */
	public static function is_live_page(): bool {
		// WooCommerce transactional pages
		if ( function_exists( 'is_cart' ) && is_cart() ) {
			return true;
		}
		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			return true;
		}
		if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
			return true;
		}

		// Hotel state-changing requests or live search query parameters
		if ( isset( $_GET['check_in'] ) || isset( $_GET['booking_number'] ) || isset( $_GET['arc_clear_cart'] ) || isset( $_POST['arc_action'] ) ) {
			return true;
		}

		// Check post content for live shortcodes if inside WordPress loop
		global $post;
		if ( $post && is_a( $post, '\WP_Post' ) ) {
			$content = $post->post_content;
			if (
				has_shortcode( $content, 'arc_results' ) ||
				has_shortcode( $content, 'arc_track' )
			) {
				return true;
			}
		}

		// Match common live URIs
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		if ( str_contains( $uri, 'room-search-results' ) || str_contains( $uri, 'track-booking' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Define anti-caching constants for full-page cache plugins.
	 *
	 * @return void
	 */
	public function enforce_nocache_for_live_pages(): void {
		if ( ! self::is_live_page() ) {
			return;
		}

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		if ( ! defined( 'DONOTCACHEDB' ) ) {
			define( 'DONOTCACHEDB', true );
		}
		if ( ! defined( 'DONOTMINIFY' ) ) {
			define( 'DONOTMINIFY', true );
		}

		// WooCommerce standard nocache
		if ( function_exists( 'nocache_headers' ) ) {
			nocache_headers();
		}
	}

	/**
	 * Send explicit HTTP anti-cache headers.
	 *
	 * @return void
	 */
	public function send_cache_control_headers(): void {
		if ( ! self::is_live_page() ) {
			return;
		}

		if ( ! headers_sent() ) {
			// Universal full-page cache disablers
			header( 'Cache-Control: no-cache, no-store, must-revalidate, max-age=0' );
			header( 'Pragma: no-cache' );
			header( 'Expires: 0' );

			// LiteSpeed Cache engine header
			header( 'X-LiteSpeed-Cache-Control: no-cache' );
		}
	}

	/**
	 * Register REST API routes for cached room pages and dynamic nonce refresh.
	 *
	 * @return void
	 */
	public function register_rest_routes(): void {
		// 1. Live room availability & price (read-only, no nonce, rate-limited)
		register_rest_route(
			'arc/v1',
			'/room-availability',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'rest_get_room_availability' ],
				'permission_callback' => '__return_true', // Public read-only
			]
		);

		// 2. Fresh nonce retrieval for cacheable HTML pages
		register_rest_route(
			'arc/v1',
			'/fresh-nonces',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'rest_get_fresh_nonces' ],
				'permission_callback' => '__return_true',
			]
		);
	}

	/**
	 * REST handler for live room availability without requiring nonce.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function rest_get_room_availability( \WP_REST_Request $request ): \WP_REST_Response {
		// Rate limiting: 30 requests per minute per IP
		$ip       = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1' ) );
		$rate_key = 'arc_rate_rest_avail_' . md5( $ip );
		$count    = (int) get_transient( $rate_key );

		if ( $count >= 30 ) {
			return new \WP_REST_Response( [ 'error' => 'Rate limit exceeded. Please wait.' ], 429 );
		}
		set_transient( $rate_key, $count + 1, MINUTE_IN_SECONDS );

		$room_type_id = absint( $request->get_param( 'room_type_id' ) );
		$check_in     = sanitize_text_field( $request->get_param( 'check_in' ) );
		$check_out    = sanitize_text_field( $request->get_param( 'check_out' ) );
		$adults       = max( 1, absint( $request->get_param( 'adults' ) ?: 2 ) );

		if ( empty( $check_in ) || empty( $check_out ) ) {
			$today     = current_time( 'Y-m-d' );
			$tomorrow  = gmdate( 'Y-m-d', strtotime( '+1 day', current_time( 'timestamp' ) ) );
			$check_in  = $today;
			$check_out = $tomorrow;
		}

		$client = new Api_Client();
		$api_res = $client->get(
			'api/v1/public-booking/available-rooms',
			[
				'checkIn'    => $check_in . 'T14:00:00',
				'checkOut'   => $check_out . 'T12:00:00',
				'roomTypeId' => $room_type_id ?: null,
				'adults'     => $adults,
			],
			[ 'use_cache' => false ]
		);

		$response_data = [
			'available'     => false,
			'roomsCount'    => 0,
			'pricePerNight' => 0.0,
			'totalPrice'    => 0.0,
			'nights'        => 1,
			'currency'      => 'BDT',
		];

		if ( $api_res->ok() ) {
			$rooms = (array) $api_res->data();
			$matched_count = 0;
			$matched_room  = null;

			foreach ( $rooms as $r ) {
				if ( ! $room_type_id || (int) ( $r['roomTypeId'] ?? 0 ) === $room_type_id ) {
					$matched_count++;
					if ( null === $matched_room ) {
						$matched_room = $r;
					}
				}
			}

			if ( $matched_count > 0 && $matched_room ) {
				$response_data['available']     = true;
				$response_data['roomsCount']    = $matched_count;
				$response_data['pricePerNight'] = (float) ( $matched_room['pricePerNight'] ?? 0 );
				$response_data['totalPrice']    = (float) ( $matched_room['totalPrice'] ?? 0 );
				$response_data['nights']        = (int) ( $matched_room['nights'] ?? 1 );
			}
		}

		$response = new \WP_REST_Response( $response_data, 200 );
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		$response->header( 'Pragma', 'no-cache' );
		return $response;
	}

	/**
	 * REST handler for fetching fresh nonces for cached HTML pages.
	 *
	 * @return \WP_REST_Response
	 */
	public function rest_get_fresh_nonces(): \WP_REST_Response {
		$data = [
			'book_nonce'  => wp_create_nonce( 'arc_book_room' ),
			'track_nonce' => wp_create_nonce( 'arc_track_booking_action' ),
		];

		$response = new \WP_REST_Response( $data, 200 );
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		$response->header( 'Pragma', 'no-cache' );
		return $response;
	}
}
