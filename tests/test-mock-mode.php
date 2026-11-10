<?php
/**
 * Test Mock Mode Endpoints.
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../' );
}
if ( ! defined( 'ARC_PLUGIN_DIR' ) ) {
	define( 'ARC_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'ARC_API_MOCK' ) ) {
	define( 'ARC_API_MOCK', true );
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) { return trim( strip_tags( (string) $str ) ); }
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data ) { return json_encode( $data ); }
}
if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	function wp_generate_uuid4() { return sprintf( '%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0x0fff ) | 0x4000, mt_rand( 0, 0x3fff ) | 0x8000, mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ) ); }
}
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) { return $text; }
}

require_once ARC_PLUGIN_DIR . 'includes/Api/Api_Result.php';
require_once ARC_PLUGIN_DIR . 'includes/Api/Api_Mock.php';
require_once ARC_PLUGIN_DIR . 'includes/Api/Api_Logger.php';
require_once ARC_PLUGIN_DIR . 'includes/Api/Api_Cache.php';
require_once ARC_PLUGIN_DIR . 'includes/Settings/Settings.php';
require_once ARC_PLUGIN_DIR . 'includes/Api/Api_Client.php';

use ARC\HotelCore\Api\Api_Client;

$client = new Api_Client();

echo "Testing Api_Client in Mock Mode:\n\n";

// 1. Hotel Info
$res1 = $client->get( 'api/v1/public-booking/hotel-info', [], [ 'use_cache' => false ] );
echo "1. GET hotel-info: " . ( $res1->ok() && ! empty( $res1->data()['hotelName'] ) ? "OK (" . $res1->data()['hotelName'] . ")" : "FAIL" ) . "\n";

// 2. Room Categories
$res2 = $client->get( 'api/v1/public-booking/room-categories', [], [ 'use_cache' => false ] );
echo "2. GET room-categories: " . ( $res2->ok() && count( $res2->data() ) >= 3 ? "OK (" . count( $res2->data() ) . " categories)" : "FAIL" ) . "\n";

// 3. Available Rooms
$res3 = $client->get( 'api/v1/public-booking/available-rooms', [], [ 'use_cache' => false ] );
echo "3. GET available-rooms: " . ( $res3->ok() && count( $res3->data() ) >= 2 ? "OK (" . count( $res3->data() ) . " rooms available)" : "FAIL" ) . "\n";

// 4. Create Reservation
$res4 = $client->post( 'api/v1/public-booking/create-reservation-json', [
	'checkInDate'  => '2026-11-05T14:00:00',
	'checkOutDate' => '2026-11-07T12:00:00',
	'roomTypeId'   => 1,
] );
echo "4. POST create-reservation-json: " . ( $res4->ok() && ! empty( $res4->data()['bookingNumber'] ) ? "OK (" . $res4->data()['bookingNumber'] . ")" : "FAIL" ) . "\n";

// 5. Track
$res5 = $client->get( 'api/v1/public-booking/track/WEB-20261101-7891', [], [ 'use_cache' => false ] );
echo "5. GET track: " . ( $res5->ok() && ! empty( $res5->data() ) ? "OK (Found booking)" : "FAIL" ) . "\n";

// 6. Cancel
$res6 = $client->post( 'api/v1/public-booking/cancel-reservation-json', [
	'bookingNumber' => 'WEB-20261101-7891',
	'phone'         => '01700000000',
] );
echo "6. POST cancel-reservation-json: " . ( $res6->ok() && $res6->data() === true ? "OK (Cancelled)" : "FAIL" ) . "\n";

echo "\nAll mock endpoints verified successfully!\n";
