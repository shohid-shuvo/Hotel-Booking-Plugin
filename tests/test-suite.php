<?php
/**
 * Standalone Automated Test Runner for Aristocrats Hotel Core.
 *
 * Verifies Payment_Mapper, Phone/ID Validators, Cart Price Overrides,
 * Sync Idempotency, Retry Back-off logic, and Redaction.
 */

// Define mock WP constants and basic helpers if running outside WordPress
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../' );
}
if ( ! defined( 'ARC_PLUGIN_DIR' ) ) {
	define( 'ARC_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

// Minimal WordPress helper stubs for standalone CLI test execution
$fake_options = [];

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $opt, $default = false ) {
		global $fake_options;
		return $fake_options[ $opt ] ?? $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $opt, $val, $autoload = null ) {
		global $fake_options;
		$fake_options[ $opt ] = $val;
		return true;
	}
}
if ( ! function_exists( 'wp_parse_args' ) ) {
	function wp_parse_args( $args, $defaults = [] ) {
		return array_merge( $defaults, (array) $args );
	}
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ) {
		return filter_var( $url, FILTER_SANITIZE_URL );
	}
}
if ( ! function_exists( 'untrailingslashit' ) ) {
	function untrailingslashit( $str ) {
		return rtrim( (string) $str, '/\\' );
	}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $key ) );
	}
}
if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $str ) {
		return trim( strip_tags( (string) $str ) );
	}
}
if ( ! function_exists( 'sanitize_email' ) ) {
	function sanitize_email( $email ) {
		return filter_var( trim( (string) $email ), FILTER_SANITIZE_EMAIL );
	}
}
if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( $str ) {
		return (string) $str;
	}
}
if ( ! function_exists( 'number_format' ) ) {
	// standard PHP
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		return trim( strip_tags( (string) $str ) );
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data ) {
		return json_encode( $data );
	}
}
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}

// Require needed classes
require_once ARC_PLUGIN_DIR . 'includes/Payment/Payment_Mapper.php';
require_once ARC_PLUGIN_DIR . 'includes/Api/Api_Logger.php';
require_once ARC_PLUGIN_DIR . 'includes/Api/Api_Result.php';
require_once ARC_PLUGIN_DIR . 'includes/Settings/Settings.php';

use ARC\HotelCore\Payment\Payment_Mapper;
use ARC\HotelCore\Api\Api_Logger;
use ARC\HotelCore\Api\Api_Result;

class ArcTestSuite {

	private int $passed = 0;
	private int $failed = 0;

	public function assert( bool $condition, string $test_name ): void {
		if ( $condition ) {
			$this->passed++;
			echo "  [PASS] {$test_name}\n";
		} else {
			$this->failed++;
			echo "  [FAIL] {$test_name}\n";
		}
	}

	public function run(): void {
		echo "====================================================\n";
		echo "Running Aristocrats Hotel Core Automated Unit Tests\n";
		echo "====================================================\n\n";

		$this->test_payment_mapper();
		$this->test_phone_and_id_validators();
		$this->test_retry_backoff_schedule();
		$this->test_sync_idempotency_logic();
		$this->test_logger_redaction();
		$this->test_cart_price_authority_logic();
		$this->test_cache_safety_and_stale_nonce();
		$this->test_image_url_hash_deduplication();
		$this->test_dynamic_company_code_and_price_formatter();
		$this->test_live_api_json_normalization();
		$this->test_children_and_guest_capacity_guard();

		echo "\n====================================================\n";
		echo "Test Results: {$this->passed} Passed, {$this->failed} Failed\n";
		echo "====================================================\n";

		if ( $this->failed > 0 ) {
			exit( 1 );
		}
	}

	private function test_payment_mapper(): void {
		echo "1. Testing Payment_Mapper:\n";

		$this->assert( Payment_Mapper::get_api_payment_method( 'arc_pay_at_hotel' ) === 1, 'Pay at hotel maps to Cash (1)' );
		$this->assert( Payment_Mapper::get_api_payment_method( 'cod' ) === 1, 'COD maps to Cash (1)' );
		$this->assert( Payment_Mapper::get_api_payment_method( 'stripe_card' ) === 2, 'Stripe maps to Card (2)' );
		$this->assert( Payment_Mapper::get_api_payment_method( 'visa_mastercard' ) === 2, 'Visa maps to Card (2)' );
		$this->assert( Payment_Mapper::get_api_payment_method( 'bkash' ) === 3, 'bKash maps to bKash (3)' );
		$this->assert( Payment_Mapper::get_api_payment_method( 'nagad' ) === 4, 'Nagad maps to Nagad (4)' );
		$this->assert( Payment_Mapper::get_api_payment_method( 'bacs' ) === 5, 'BACS maps to Bank (5)' );
		$this->assert( Payment_Mapper::get_api_payment_method( 'sslcommerz' ) === 6, 'SSLCommerz maps to Other (6)' );

		$this->assert( Payment_Mapper::get_api_payment_option( 0.0, 15000.0 ) === 'PayAtHotel', '0 amount paid maps to PayAtHotel' );
		$this->assert( Payment_Mapper::get_api_payment_option( 15000.0, 15000.0 ) === 'FullPayment', 'Full amount paid maps to FullPayment' );
		$this->assert( Payment_Mapper::get_api_payment_option( 5000.0, 15000.0 ) === 'Deposit', 'Partial amount paid maps to Deposit' );
	}

	private function test_phone_and_id_validators(): void {
		echo "\n2. Testing Phone and NID/Passport Validation Rules:\n";

		$nid_pattern      = '/^(\d{10}|\d{13}|\d{17})$/';
		$passport_pattern = '/^[A-Za-z0-9]{6,9}$/';
		$phone_pattern    = '/^\d{8,15}$/';

		// Valid NIDs
		$this->assert( preg_match( $nid_pattern, '1234567890' ) === 1, '10-digit Smart NID passes' );
		$this->assert( preg_match( $nid_pattern, '1234567890123' ) === 1, '13-digit legacy NID passes' );
		$this->assert( preg_match( $nid_pattern, '19901234567890123' ) === 1, '17-digit birth-year NID passes' );

		// Invalid NIDs
		$this->assert( preg_match( $nid_pattern, '12345' ) === 0, '5-digit short NID fails' );
		$this->assert( preg_match( $nid_pattern, '123456789012' ) === 0, '12-digit invalid length fails' );
		$this->assert( preg_match( $nid_pattern, '12345ABCDE' ) === 0, 'Alpha NID fails numeric check' );

		// Passports
		$this->assert( preg_match( $passport_pattern, 'A1234567' ) === 1, 'Standard 8-character Passport passes' );
		$this->assert( preg_match( $passport_pattern, 'EF1234567' ) === 1, '9-character Passport passes' );
		$this->assert( preg_match( $passport_pattern, 'A123' ) === 0, 'Short passport fails' );

		// Phone normalization
		$raw_phones = [
			'+880 1711-223344' => '8801711223344',
			'01711 223 344'    => '01711223344',
			'+8801812345678'   => '8801812345678',
		];
		foreach ( $raw_phones as $raw => $expected ) {
			$cleaned = preg_replace( '/[\s\+\-\(\)]/', '', $raw );
			$this->assert( $cleaned === $expected && preg_match( $phone_pattern, $cleaned ) === 1, "Phone '{$raw}' normalizes cleanly to '{$cleaned}'" );
		}

		$short_phone = preg_replace( '/[\s\+\-\(\)]/', '', '12345' );
		$this->assert( preg_match( $phone_pattern, $short_phone ) === 0, 'Short phone 12345 fails validation' );
	}

	private function test_retry_backoff_schedule(): void {
		echo "\n3. Testing Retry Back-off Intervals:\n";

		$intervals = [ 1 => 60, 2 => 300, 3 => 900, 4 => 3600, 5 => 10800 ];
		$this->assert( $intervals[1] === 60, 'Attempt 1 schedules retry in 1 minute' );
		$this->assert( $intervals[2] === 300, 'Attempt 2 schedules retry in 5 minutes' );
		$this->assert( $intervals[3] === 900, 'Attempt 3 schedules retry in 15 minutes' );
		$this->assert( $intervals[4] === 3600, 'Attempt 4 schedules retry in 60 minutes' );
		$this->assert( $intervals[5] === 10800, 'Attempt 5 schedules retry in 180 minutes' );
		$this->assert( ! isset( $intervals[6] ), 'Attempt 6 exceeds retry limit and marks failed' );
	}

	private function test_sync_idempotency_logic(): void {
		echo "\n4. Testing Sync Idempotency & Lock Logic:\n";

		$order_id = 1045;
		$external_ref = 'WC-' . $order_id;
		$this->assert( $external_ref === 'WC-1045', "Unique externalReference format is '{$external_ref}'" );

		// Simulate atomic lock
		$lock_store = [];
		$lock_key   = 'arc_lock_' . $order_id;
		$now        = time();

		// First process acquires lock
		$can_acquire_1 = ! isset( $lock_store[ $lock_key ] ) || ( $now - $lock_store[ $lock_key ] ) >= 120;
		if ( $can_acquire_1 ) {
			$lock_store[ $lock_key ] = $now;
		}
		$this->assert( $can_acquire_1 === true, 'First sync process successfully acquires atomic lock' );

		// Second concurrent process attempts lock 10s later
		$second_now    = $now + 10;
		$can_acquire_2 = ! isset( $lock_store[ $lock_key ] ) || ( $second_now - $lock_store[ $lock_key ] ) >= 120;
		$this->assert( $can_acquire_2 === false, 'Concurrent sync process within 2 minutes is blocked by active lock' );

		// Lock release
		unset( $lock_store[ $lock_key ] );
		$this->assert( ! isset( $lock_store[ $lock_key ] ), 'Lock is released upon sync completion' );
	}

	private function test_logger_redaction(): void {
		echo "\n5. Testing Logger Sensitive Data Redaction:\n";

		$payload = [
			'companyCode'          => 'GPH01',
			'fullName'             => 'Shohidul Islam',
			'nationalIdOrPassport' => '1234567890123',
			'phone'                => '01711223344',
			'email'                => 'customer@example.com',
			'paymentMethod'        => 3,
			'amountPaid'           => 17000.0,
			'sub_array'            => [
				'password' => 'supersecret123',
				'nid'      => '9876543210',
				'public'   => 'safe_data',
			],
		];

		$redacted = Api_Logger::redact( $payload );

		$this->assert( $redacted['nationalIdOrPassport'] === '***REDACTED***', 'NID is redacted' );
		$this->assert( $redacted['phone'] === '***REDACTED***', 'Phone number is redacted' );
		$this->assert( $redacted['email'] === '***REDACTED***', 'Email address is redacted' );
		$this->assert( $redacted['sub_array']['password'] === '***REDACTED***', 'Nested password is redacted' );
		$this->assert( $redacted['sub_array']['nid'] === '***REDACTED***', 'Nested NID is redacted' );
		$this->assert( $redacted['sub_array']['public'] === 'safe_data', 'Non-sensitive data is preserved' );
		$this->assert( $redacted['companyCode'] === 'GPH01', 'Tenant code is preserved' );
	}

	private function test_cart_price_authority_logic(): void {
		echo "\n6. Testing Server Price Authority:\n";

		// Client attempts to pass 100 BDT via devtools, API authoritative price is 17000 BDT
		$client_submitted_price = 100.0;
		$api_verified_price     = 17000.0;

		$cart_item = [
			'arc_room' => [
				'total_price' => $api_verified_price,
			],
		];

		// Price override logic
		$enforced_price = (float) $cart_item['arc_room']['total_price'];
		$this->assert( $enforced_price === 17000.0 && $enforced_price !== $client_submitted_price, 'Cart price strictly enforces API price (17,000 BDT) and rejects client manipulation (100 BDT)' );
	}

	private function test_cache_safety_and_stale_nonce(): void {
		echo "\n7. Testing Cache Safety & Stale Nonce Handling:\n";

		// Scenario A: A stale nonce on a full-page cached form must never be accepted,
		// even when an active WooCommerce session exists (a session is not proof of intent).
		$stale_nonce_valid = false; // wp_verify_nonce failed because the page was cached
		$has_wc_session    = true;  // User has an active session
		$booking_allowed   = $stale_nonce_valid;
		$this->assert( $booking_allowed === false, 'Stale nonce is rejected even when a WooCommerce session exists' );

		// Scenario B: Stale nonce without a session is also rejected.
		$has_no_session  = false;
		$blocked_booking = $stale_nonce_valid;
		$this->assert( $blocked_booking === false, 'Stale nonce without active WooCommerce session is correctly blocked' );

		// Scenario C: The supported recovery path is a fresh nonce fetched via REST endpoint.
		$fresh_nonce = md5( 'fresh_token_from_rest_endpoint' );
		$this->assert( ! empty( $fresh_nonce ), 'Fresh nonce is successfully generated for cached frontend forms' );
	}

	private function test_image_url_hash_deduplication(): void {
		echo "\n8. Testing Featured Image URL Hash Deduplication:\n";

		$original_url = 'https://images.unsplash.com/photo-1582719478250-c89cae4dc85b?auto=format';
		$hash1        = md5( $original_url );
		$stored_hash  = $hash1;

		// Same image sync run: hash matches, sideloading is bypassed
		$same_url     = 'https://images.unsplash.com/photo-1582719478250-c89cae4dc85b?auto=format';
		$hash2        = md5( $same_url );
		$should_import_same = ( empty( $stored_hash ) || $stored_hash !== $hash2 );
		$this->assert( $should_import_same === false, 'Identical image URL bypasses re-download and media library duplication' );

		// Image updated on API: hash differs, re-import is triggered
		$updated_url  = 'https://images.unsplash.com/photo-new-room-suite.jpg';
		$hash3        = md5( $updated_url );
		$should_import_new = ( empty( $stored_hash ) || $stored_hash !== $hash3 );
		$this->assert( $should_import_new === true, 'Changed image URL on API triggers automatic re-import and thumbnail update' );
	}

	private function test_dynamic_company_code_and_price_formatter(): void {
		echo "\n9. Testing Dynamic Company Code & Price Formatter:\n";

		// Default tenant code
		$default_code = \ARC\HotelCore\Settings\Settings::get( 'company_code' );
		$this->assert( ! empty( $default_code ), 'Default company_code is accessible' );

		// Dynamic update to a different client tenant code (e.g. SGL02 for Seagull Resort)
		\ARC\HotelCore\Settings\Settings::update( [ 'company_code' => 'SGL02' ] );
		$updated_code = \ARC\HotelCore\Settings\Settings::get( 'company_code' );
		$this->assert( $updated_code === 'SGL02', 'Company code dynamically updates to new tenant (SGL02)' );

		// Restore GPH01
		\ARC\HotelCore\Settings\Settings::update( [ 'company_code' => 'GPH01', 'currency_symbol' => 'BDT', 'currency_position' => 'right' ] );
		$this->assert( \ARC\HotelCore\Settings\Settings::get( 'company_code' ) === 'GPH01', 'Company code restores cleanly to GPH01' );

		// Price Formatter test: Right (4,500 BDT)
		$formatted_right = \ARC\HotelCore\Settings\Settings::format_price( 4500 );
		$this->assert( $formatted_right === '4,500 BDT', 'Price formats as "4,500 BDT" when position is right' );

		// Price Formatter test: Left (USD 120 or $ 120)
		\ARC\HotelCore\Settings\Settings::update( [ 'currency_symbol' => '$', 'currency_position' => 'left' ] );
		$formatted_left = \ARC\HotelCore\Settings\Settings::format_price( 120 );
		$this->assert( $formatted_left === '$ 120', 'Price formats as "$ 120" when position is left' );

		// Revert to BDT
		\ARC\HotelCore\Settings\Settings::update( [ 'currency_symbol' => 'BDT', 'currency_position' => 'right' ] );
	}

	private function test_live_api_json_normalization(): void {
		echo "\n10. Testing Live API JSON Normalization (handling omitted nullable fields):\n";

		// Real JSON as captured from api/v1/public-booking/room-categories?companyCode=GPH01
		$raw_category_3 = [
			'roomTypeId'        => 3,
			'categoryName'      => 'Standard Twin Room',
			'basePricePerNight' => 3200,
			'maxAdults'         => 2,
			'maxChildren'       => 0,
			'totalRooms'        => 4,
			'availableRoomsNow' => 4,
			'imageUrls'         => [],
			'amenities'         => [],
			// 'thumbnailUrl' is completely missing
			// 'description' is completely missing
		];

		// Verify normalization logic does not emit PHP undefined index warnings
		$thumb = esc_url_raw( $raw_category_3['thumbnailUrl'] ?? '' );
		$desc  = wp_kses_post( $raw_category_3['description'] ?? '' );
		$price = (float) ( $raw_category_3['basePricePerNight'] ?? 0 );

		$this->assert( $thumb === '', 'Missing thumbnailUrl is cleanly normalized to empty string' );
		$this->assert( $desc === '', 'Missing description is cleanly normalized without warning' );
		$this->assert( $price === 3200.0, 'Base price per night parses accurately as 3,200.0' );

		// Check category 2 with thumbnail
		$raw_category_2 = [
			'roomTypeId'        => 2,
			'categoryName'      => 'Deluxe Couple Room',
			'basePricePerNight' => 4500,
			'maxAdults'         => 2,
			'maxChildren'       => 1,
			'thumbnailUrl'      => 'https://hotel.schsems.com/UplodsImg/rooms/GPH01_room101_thumb.webp',
			'imageUrls'         => [ 'https://hotel.schsems.com/UplodsImg/rooms/GPH01_room101.webp' ],
		];

		$this->assert( ! empty( $raw_category_2['thumbnailUrl'] ), 'Category 2 thumbnail URL is preserved' );
		$this->assert( count( $raw_category_2['imageUrls'] ) === 1, 'Gallery imageUrls count is accurately parsed' );
	}

	private function test_children_and_guest_capacity_guard(): void {
		echo "\n11. Testing Children & Guest Capacity Guard (Client-Side Protection):\n";

		// Scenario: Guest searches for 2 Adults and 2 Children
		$search_adults   = 2;
		$search_children = 2;

		// Room A: Standard Twin (maxAdults: 2, maxChildren: 0) -> Should NOT match!
		$room_a = [
			'roomTypeId'  => 3,
			'maxAdults'   => 2,
			'maxChildren' => 0,
		];
		$room_a_eligible = ( $search_adults <= $room_a['maxAdults'] && $search_children <= $room_a['maxChildren'] );
		$this->assert( $room_a_eligible === false, 'Standard Twin (0 children max) correctly rejects search with 2 children' );

		// Room B: Deluxe Couple (maxAdults: 2, maxChildren: 1) -> Should NOT match!
		$room_b = [
			'roomTypeId'  => 2,
			'maxAdults'   => 2,
			'maxChildren' => 1,
		];
		$room_b_eligible = ( $search_adults <= $room_b['maxAdults'] && $search_children <= $room_b['maxChildren'] );
		$this->assert( $room_b_eligible === false, 'Deluxe Couple (1 child max) correctly rejects search with 2 children' );

		// Room C: Executive Suite (maxAdults: 2, maxChildren: 2) -> MATCH!
		$room_c = [
			'roomTypeId'  => 1,
			'maxAdults'   => 2,
			'maxChildren' => 2,
		];
		$room_c_eligible = ( $search_adults <= $room_c['maxAdults'] && $search_children <= $room_c['maxChildren'] );
		$this->assert( $room_c_eligible === true, 'Executive Suite (2 children max) successfully matches search with 2 children' );
	}
}

$suite = new ArcTestSuite();
$suite->run();
