<?php
/**
 * Plugin Settings Manager.
 *
 * @package ARC\HotelCore\Settings
 */

namespace ARC\HotelCore\Settings;

use ARC\HotelCore\Api\Api_Client;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings model and option accessor.
 */
class Settings {

	/**
	 * WordPress option key.
	 */
	const OPTION_KEY = 'arc_settings';

	/**
	 * Standard Production API Base URL (Internal SaaS Endpoint).
	 */
	const DEFAULT_API_BASE_URL = 'https://hotelapi.dusrasoftltd.com';

	/**
	 * In-memory settings cache.
	 *
	 * @var array|null
	 */
	protected static ?array $settings = null;

	/**
	 * Get default settings values.
	 *
	 * @return array
	 */
	public static function get_defaults(): array {
		return [
			'company_code'             => 'GPH01',
			'currency_symbol'          => 'BDT',
			'currency_position'        => 'right', // 'left' or 'right'
			'cache_ttl_info'           => 1800, // 30 mins
			'cache_ttl_rooms'          => 600,  // 10 mins
			'max_stay_nights'          => 30,
			'max_advance_days'         => 365,
			'default_adults'           => 2,
			'default_children'         => 0,
			'room_gateways'            => [ 'bacs', 'cod', 'arc_pay_at_hotel', 'sslcommerz', 'bkash', 'nagad' ],
			'pay_at_hotel_enabled'     => true,
			'pay_at_hotel_title'       => 'Pay at Hotel / Reception Desk',
			'pay_at_hotel_desc'        => 'Reserve your room now and pay directly at the hotel front desk during check-in.',
			'cancellation_policy_text' => 'Free cancellation up to 48 hours before check-in. Cancellations within 48 hours are subject to a 100% first night charge.',
			'auto_sync_interval'       => 'daily',
		];
	}

	/**
	 * Get all settings.
	 *
	 * @return array
	 */
	public static function get_all(): array {
		if ( null === self::$settings ) {
			$saved = get_option( self::OPTION_KEY, [] );
			if ( ! is_array( $saved ) ) {
				$saved = [];
			}
			self::$settings = wp_parse_args( $saved, self::get_defaults() );
		}
		return self::$settings;
	}

	/**
	 * Get a specific setting value.
	 *
	 * Base API URL is protected and read from wp-config.php constant ARC_API_BASE_URL
	 * or falls back to the production API constant. Company code can be overridden
	 * in wp-config.php or retrieved from saved settings.
	 *
	 * @param string $key Setting key.
	 * @param mixed  $default Fallback value.
	 * @return mixed
	 */
	public static function get( string $key, mixed $default = null ): mixed {
		if ( 'api_base_url' === $key ) {
			if ( defined( 'ARC_API_BASE_URL' ) && ! empty( ARC_API_BASE_URL ) ) {
				return esc_url_raw( untrailingslashit( ARC_API_BASE_URL ) );
			}
			return self::DEFAULT_API_BASE_URL;
		}

		if ( 'company_code' === $key && defined( 'ARC_COMPANY_CODE' ) && ! empty( ARC_COMPANY_CODE ) ) {
			return sanitize_text_field( ARC_COMPANY_CODE );
		}

		$all = self::get_all();
		return $all[ $key ] ?? $default;
	}

	/**
	 * Update settings.
	 *
	 * @param array $new_settings Settings to save.
	 * @return bool
	 */
	public static function update( array $new_settings ): bool {
		$sanitized      = self::sanitize( $new_settings );
		self::$settings = $sanitized;
		return update_option( self::OPTION_KEY, $sanitized, 'no' );
	}

	/**
	 * Sanitize settings array.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public static function sanitize( array $input ): array {
		$defaults = self::get_defaults();
		$clean    = [];

		$clean['company_code'] = ! empty( $input['company_code'] )
			? sanitize_text_field( trim( $input['company_code'] ) )
			: $defaults['company_code'];

		$clean['currency_symbol'] = ! empty( $input['currency_symbol'] )
			? sanitize_text_field( trim( $input['currency_symbol'] ) )
			: $defaults['currency_symbol'];

		$clean['currency_position'] = isset( $input['currency_position'] ) && in_array( $input['currency_position'], [ 'left', 'right' ], true )
			? $input['currency_position']
			: $defaults['currency_position'];

		$clean['cache_ttl_info'] = isset( $input['cache_ttl_info'] )
			? max( 60, absint( $input['cache_ttl_info'] ) )
			: $defaults['cache_ttl_info'];

		$clean['cache_ttl_rooms'] = isset( $input['cache_ttl_rooms'] )
			? max( 60, absint( $input['cache_ttl_rooms'] ) )
			: $defaults['cache_ttl_rooms'];

		$clean['max_stay_nights'] = isset( $input['max_stay_nights'] )
			? max( 1, absint( $input['max_stay_nights'] ) )
			: $defaults['max_stay_nights'];

		$clean['max_advance_days'] = isset( $input['max_advance_days'] )
			? max( 1, absint( $input['max_advance_days'] ) )
			: $defaults['max_advance_days'];

		$clean['default_adults'] = isset( $input['default_adults'] )
			? max( 1, absint( $input['default_adults'] ) )
			: $defaults['default_adults'];

		$clean['default_children'] = isset( $input['default_children'] )
			? absint( $input['default_children'] )
			: $defaults['default_children'];

		$clean['room_gateways'] = ! empty( $input['room_gateways'] ) && is_array( $input['room_gateways'] )
			? array_map( 'sanitize_key', $input['room_gateways'] )
			: $defaults['room_gateways'];

		$clean['pay_at_hotel_enabled'] = ! empty( $input['pay_at_hotel_enabled'] );

		$clean['pay_at_hotel_title'] = ! empty( $input['pay_at_hotel_title'] )
			? sanitize_text_field( trim( $input['pay_at_hotel_title'] ) )
			: $defaults['pay_at_hotel_title'];

		$clean['pay_at_hotel_desc'] = ! empty( $input['pay_at_hotel_desc'] )
			? sanitize_textarea_field( trim( $input['pay_at_hotel_desc'] ) )
			: $defaults['pay_at_hotel_desc'];

		$clean['cancellation_policy_text'] = ! empty( $input['cancellation_policy_text'] )
			? sanitize_textarea_field( $input['cancellation_policy_text'] )
			: $defaults['cancellation_policy_text'];

		$clean['auto_sync_interval'] = isset( $input['auto_sync_interval'] ) && in_array( $input['auto_sync_interval'], [ 'hourly', 'twicedaily', 'daily', 'manual' ], true )
			? $input['auto_sync_interval']
			: $defaults['auto_sync_interval'];

		return $clean;
	}

	/**
	 * Format price with configured currency symbol and position.
	 *
	 * @param float $amount Amount to format.
	 * @return string Formatted price string.
	 */
	public static function format_price( float $amount ): string {
		$symbol   = self::get( 'currency_symbol', 'BDT' );
		$position = self::get( 'currency_position', 'right' );
		$num_str  = number_format( $amount );

		if ( 'left' === $position ) {
			return $symbol . ' ' . $num_str;
		}
		return $num_str . ' ' . $symbol;
	}

	/**
	 * Fetch or retrieve cached hotel profile information directly from API.
	 *
	 * @param bool $refresh Force API refresh.
	 * @return array
	 */
	public static function get_hotel_info( bool $refresh = false ): array {
		$company_code = self::get( 'company_code' );
		$transient    = 'arc_hotel_info_' . md5( $company_code );

		if ( ! $refresh ) {
			$cached = get_transient( $transient );
			if ( is_array( $cached ) && ! empty( $cached ) ) {
				return $cached;
			}
		}

		$client = new Api_Client();
		$res    = $client->get(
			'api/v1/public-booking/hotel-info',
			[ 'companyCode' => $company_code ],
			[ 'use_cache' => false ]
		);

		$info = [
			'hotelName'            => 'Hotel & Resort',
			'companyCode'          => $company_code,
			'phone'                => '',
			'email'                => '',
			'address'              => '',
			'contactPerson'        => '',
			'standardCheckInTime'  => '14:00',
			'standardCheckOutTime' => '12:00',
		];

		if ( $res->ok() && is_array( $res->data() ) ) {
			$data = $res->data();
			$info = [
				'hotelName'            => sanitize_text_field( $data['hotelName'] ?? 'Hotel & Resort' ),
				'companyCode'          => sanitize_text_field( $data['companyCode'] ?? $company_code ),
				'phone'                => sanitize_text_field( $data['phone'] ?? '' ),
				'email'                => sanitize_email( $data['email'] ?? '' ),
				'address'              => sanitize_text_field( $data['address'] ?? '' ),
				'contactPerson'        => sanitize_text_field( $data['contactPerson'] ?? '' ),
				'standardCheckInTime'  => sanitize_text_field( $data['standardCheckInTime'] ?? '14:00' ),
				'standardCheckOutTime' => sanitize_text_field( $data['standardCheckOutTime'] ?? '12:00' ),
			];
			set_transient( $transient, $info, self::get( 'cache_ttl_info', 1800 ) );
		}

		return $info;
	}

	/**
	 * Check if secret API key constant is defined.
	 *
	 * @return bool
	 */
	public static function has_api_key(): bool {
		return defined( 'ARC_API_KEY' ) && ! empty( ARC_API_KEY );
	}

	/**
	 * Retrieve API Key constant value securely.
	 *
	 * @return string
	 */
	public static function get_api_key(): string {
		return defined( 'ARC_API_KEY' ) ? trim( (string) ARC_API_KEY ) : '';
	}

	/**
	 * Check if system is in mock mode.
	 *
	 * @return bool
	 */
	public static function is_mock_mode(): bool {
		return defined( 'ARC_API_MOCK' ) && (bool) ARC_API_MOCK;
	}
}
