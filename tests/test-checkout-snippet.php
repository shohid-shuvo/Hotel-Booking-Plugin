<?php
/**
 * Checkout snippet integration regressions using the real core checkout classes.
 * Run both loading orders: --snippet-first and --plugin-first.
 * WordPress transport is forbidden; the real API client uses local fixtures.
 */

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'ARC_PLUGIN_DIR', ABSPATH );
define( 'ARC_PLUGIN_FILE', ABSPATH . 'arc-hotel-core.php' );
define( 'ARC_PLUGIN_URL', 'https://example.test/plugin/' );
define( 'ARC_VERSION', '1.0.0' );
if ( ! defined( 'ARC_API_MOCK' ) ) { define( 'ARC_API_MOCK', true ); }
define( 'MINUTE_IN_SECONDS', 60 );

set_error_handler( static function ( $severity, $message, $file, $line ) {
	throw new ErrorException( $message, 0, $severity, $file, $line );
} );

$hooks = array();
$options = array();
$transients = array();
$styles = array();
$checkout_page = true;
$received_page = false;
$passed = 0;
$transport_calls = 0;

function add_filter( $name, $callback, $priority = 10, $accepted_args = 1 ) {
	global $hooks;
	$hooks[ $name ][ $priority ][] = array( $callback, $accepted_args );
}
function add_action( $name, $callback, $priority = 10, $accepted_args = 1 ) { add_filter( $name, $callback, $priority, $accepted_args ); }
function apply_filters( $name, $value, ...$args ) {
	global $hooks;
	$callbacks = $hooks[ $name ] ?? array();
	ksort( $callbacks );
	foreach ( $callbacks as $group ) {
		foreach ( $group as $callback ) {
			$value = $callback[0]( ...array_slice( array_merge( array( $value ), $args ), 0, $callback[1] ) );
		}
	}
	return $value;
}
function do_action( $name, ...$args ) {
	global $hooks;
	$callbacks = $hooks[ $name ] ?? array();
	ksort( $callbacks );
	foreach ( $callbacks as $group ) {
		foreach ( $group as $callback ) { $callback[0]( ...array_slice( $args, 0, $callback[1] ) ); }
	}
}
function get_option( $key, $default = false ) { global $options; return $options[ $key ] ?? $default; }
function get_transient( $key ) { global $transients; return $transients[ $key ] ?? false; }
function set_transient( $key, $value, $ttl ) { global $transients; $transients[ $key ] = $value; return true; }
function wp_parse_args( $args, $defaults = array() ) { return array_replace( $defaults, $args ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_textarea_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_email( $value ) { return filter_var( $value, FILTER_SANITIZE_EMAIL ); }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_-]/i', '', (string) $value ) ); }
function wp_unslash( $value ) { return $value; }
function absint( $value ) { return abs( (int) $value ); }
function wp_json_encode( $value ) { return json_encode( $value ); }
function get_post_meta( $id, $key, $single ) { return 101 === (int) $id && '_is_hotel_room' === $key ? 'yes' : ''; }
function has_term( $term, $taxonomy, $id ) { return false; }
function get_bloginfo( $key ) { return 'Aristocrats Inn'; }
function wp_timezone() { return new DateTimeZone( 'Asia/Dhaka' ); }
function current_time( $format ) { return 'timestamp' === $format ? time() : ( new DateTimeImmutable( 'now', wp_timezone() ) )->format( $format ); }
function is_checkout() { global $checkout_page; return $checkout_page; }
function is_order_received_page() { global $received_page; return $received_page; }
function is_admin() { return false; }
function __( $text, $domain = '' ) { return $text; }
function esc_html__( $text, $domain = '' ) { return esc_html( $text ); }
function esc_html_e( $text, $domain = '' ) { echo esc_html( $text ); }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $text ) { return esc_html( $text ); }
function esc_url( $url ) { return htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' ); }
function esc_url_raw( $url ) { return $url; }
function untrailingslashit( $value ) { return rtrim( $value, '/' ); }
function wc_price( $price ) { return number_format( $price, 2 ) . ' BDT'; }
function wp_register_style( $handle, $source, $dependencies = array(), $version = null ) { global $styles; $styles[ $handle ] = ''; }
function wp_enqueue_style( $handle, ...$args ) { global $styles; $styles[ $handle ] = $styles[ $handle ] ?? ''; }
function wp_add_inline_style( $handle, $css ) { global $styles; $styles[ $handle ] .= $css; }
function wp_remote_get( ...$args ) { global $transport_calls, $http_results, $http_requests; $transport_calls++; if ( isset( $http_results ) ) { $http_requests[] = $args; return array_shift( $http_results ); } throw new RuntimeException( 'Network access is forbidden in this test.' ); }
function wp_remote_post( ...$args ) { global $transport_calls, $http_results, $http_requests; $transport_calls++; if ( isset( $http_results ) ) { $http_requests[] = $args; return array_shift( $http_results ); } throw new RuntimeException( 'Reservation writes are forbidden in this test.' ); }

class TestCheckoutDatabase {
	public string $prefix = 'wp_';
	public function prepare( $sql, ...$args ) { return $sql; }
	public function get_var( $sql ) { return '1'; }
}
$wpdb = new TestCheckoutDatabase();

class WP_Error {
	public array $errors = array();
	public function add( $code, $message ) { $this->errors[ $code ][] = $message; }
}
class TestProduct {
	public function __construct( private bool $room = true ) {}
	public function get_meta( $key ) { return $this->room ? 'yes' : ''; }
}
class WC_Cart {
	public array $items = array();
	public function get_cart() { return $this->items; }
}
class WC_Order {
	public array $meta = array();
	public float $total = 4275.0;
	public float $discount = 500.0;
	public float $tax = 225.0;
	public float $fee = 50.0;
	public function get_meta( $key ) { return $this->meta[ $key ] ?? ''; }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
	public function get_total() { return $this->total; }
	public function get_currency() { return 'BDT'; }
}
$wc = (object) array( 'cart' => new WC_Cart() );
function WC() { global $wc; return $wc; }

function verify( $condition, $name ) {
	global $passed;
	if ( ! $condition ) { throw new RuntimeException( $name ); }
	$passed++;
	echo "[PASS] {$name}\n";
}
function room_item( $metadata_key = 'arc_room' ) {
	$in = new DateTimeImmutable( 'tomorrow', wp_timezone() );
	return array(
		'product_id' => 101, 'quantity' => 1, 'data' => new TestProduct(),
		$metadata_key => array(
			'company_code' => '', 'room_type_id' => 2, 'room_type_name' => 'Deluxe Couple', 'room_post_id' => 42,
			'room_id' => 1, 'room_number' => '101', 'check_in' => $in->format( 'Y-m-d' ),
			'check_out' => $in->modify( '+1 day' )->format( 'Y-m-d' ),
			'nights' => 1, 'adults' => 2, 'children' => 1,
			'rate_per_night' => 4500.0, 'total_price' => 4500.0,
			'thumbnail_url' => 'https://example.test/room.jpg', 'custom_quote_key' => 'preserved',
		),
	);
}
function checkout_fields() {
	$billing = array();
	foreach ( array( 'billing_first_name', 'billing_last_name', 'billing_company', 'billing_address_1', 'billing_address_2', 'billing_postcode', 'billing_city', 'billing_state', 'billing_country', 'billing_phone', 'billing_email' ) as $key ) { $billing[ $key ] = array( 'required' => true ); }
	return array( 'billing' => $billing, 'shipping' => array( 'shipping_address_1' => array() ), 'order' => array( 'order_comments' => array() ) );
}

require_once ARC_PLUGIN_DIR . 'includes/Autoloader.php';
\ARC\HotelCore\Autoloader::register();
if ( defined( 'ARC_TEST_BOOTSTRAP_ONLY' ) ) { return; }
$snippet_path = ARC_PLUGIN_DIR . 'documentsForAllMDfile/snippets/aristocrats-conditional-checkout.php';
$snippet_first = in_array( '--snippet-first', $argv, true );
if ( $snippet_first ) { require $snippet_path; }
$core_checkout = new \ARC\HotelCore\Checkout\Checkout_Fields();
$core_checkout->init();
( new \ARC\HotelCore\Checkout\Checkout_Validation() )->init();
if ( ! $snippet_first ) { require $snippet_path; }

verify( function_exists( 'arc_cfg' ) && isset( $hooks['woocommerce_checkout_create_order'][20] ), 'Replacement registers helpers and the priority-20 save hook' );
verify( ! isset( $hooks['woocommerce_thankyou'] ) && ! isset( $hooks['woocommerce_email_after_order_table'] ) && ! isset( $hooks['woocommerce_admin_order_data_after_billing_address'] ), 'Snippet adds no duplicate confirmation, email or admin detail handlers' );

foreach ( array( 'arc_room', '_arc_booking' ) as $format ) {
	$item = apply_filters( 'woocommerce_get_cart_item_from_session', room_item( $format ), room_item( $format ) );
	$wc->cart->items = array( $item );
	verify( isset( $item['arc_room'], $item['_arc_booking'] ), "{$format}: session adapter supplies both core cart shapes" );
	verify( $item['arc_room']['api_room_id'] === 1 && $item['arc_room']['room_id'] === 1, "{$format}: physical room aliases match" );
	$data = array(
		'arc_id_number' => '1234567890', 'billing_phone' => '+8801711223344',
		'arc_guest_2' => 'অতিথি দুই', 'arc_terms' => '1',
		'arc_req_quiet' => '1', 'arc_req_high_floor' => '1', 'arc_req_airport' => '1',
		'arc_req_early_checkin' => '1', 'arc_req_extra_bed' => '1', 'arc_req_decoration' => '1',
		'arc_bed_type' => 'Twin Beds', 'arc_arrival_time' => '6 PM - 10 PM',
		'arc_special_note' => 'Please call before arrival.',
		// Browser values must never replace the server-side quote.
		'room_type_id' => 99, 'room_id' => 99, 'total_price' => 1,
	);
	$_POST = $data;
	$errors = new WP_Error();
	do_action( 'woocommerce_after_checkout_validation', $data, $errors );
	verify( array() === $errors->errors, "{$format}: real core validation and snippet validation accept a valid checkout" );
	$order = new WC_Order();
	$order->meta['_arc_booking'] = array( 'order_extension' => 'keep-me' );
	$money_before = array( $order->total, $order->discount, $order->tax, $order->fee );
	do_action( 'woocommerce_checkout_create_order', $order, $data );
	$saved = $order->get_meta( '_arc_booking' );
	verify( $saved['room_type_id'] === 2 && $saved['api_room_id'] === 1 && $saved['room_id'] === 1, "{$format}: real core save plus snippet save preserve selected room IDs" );
	verify( $saved['total_price'] === 4500.0 && $saved['rate_per_night'] === 4500.0 && $saved['nights'] === 1, "{$format}: API quote survives unchanged and ignores browser money" );
	verify( $saved['custom_quote_key'] === 'preserved' && $saved['order_extension'] === 'keep-me', "{$format}: extra cart and order metadata survive" );
	verify( $order->get_meta( '_arc_guests' ) === array( 2 => 'অতিথি দুই' ), "{$format}: additional guest names are saved once" );
	$requests = $order->get_meta( '_arc_requests' );
	verify( count( $requests ) === 7 && count( $requests ) === count( array_unique( $requests ) ) && in_array( 'Extra Bed', $requests, true ) && in_array( $data['arc_special_note'], $requests, true ), "{$format}: every selected request and note survives core saving without duplicates" );
	verify( $order->get_meta( '_arc_bed_type' ) === 'Twin Beds' && $order->get_meta( '_arc_arrival' ) === '6 PM - 10 PM', "{$format}: preferences use the existing core meta keys" );
	verify( $money_before === array( $order->total, $order->discount, $order->tax, $order->fee ) && $order->get_meta( '_arc_sync_status' ) === 'pending', "{$format}: coupons, taxes, fees and core sync state are untouched" );
	$changed = $data;
	$changed['arc_guest_2'] = 'Replacement Guest';
	foreach ( array_keys( arc_request_options() ) as $key ) { $changed[ $key ] = ''; }
	$changed['arc_special_note'] = '';
	$changed['arc_bed_type'] = '';
	$changed['arc_arrival_time'] = '';
	$_POST = $changed;
	do_action( 'woocommerce_checkout_create_order', $order, $changed );
	verify( $order->get_meta( '_arc_guests' ) === array( 2 => 'Replacement Guest' ) && $order->get_meta( '_arc_requests' ) === array() && '' === $order->get_meta( '_arc_bed_type' ), "{$format}: changed guests and cleared preferences do not retain stale data" );
}

$item = room_item();
$item['arc_room']['api_room_id'] = 7;
$wc->cart->items = array( $item );
$_POST = $data;
$errors = new WP_Error();
do_action( 'woocommerce_after_checkout_validation', $data, $errors );
verify( isset( $errors->errors['arc_booking_data'] ), 'Conflicting physical room IDs block checkout' );

$wc->cart->items = array( room_item() );
$missing = $data;
$missing['arc_guest_2'] = '';
$missing['arc_terms'] = '';
$_POST = $missing;
$errors = new WP_Error();
do_action( 'woocommerce_after_checkout_validation', $missing, $errors );
verify( isset( $errors->errors['arc_missing_guest_2'], $errors->errors['arc_terms'] ), 'Real core guest validation and explicit terms validation block missing required data' );

$fields = apply_filters( 'woocommerce_checkout_fields', checkout_fields() );
verify( $fields['billing']['arc_guest_2']['required'] && isset( $fields['order']['arc_special_note'] ) && ! isset( $fields['order']['order_comments'] ), 'Room form matches the core validator and special-note field' );
verify( ! isset( $fields['billing']['billing_address_1'] ) && array() === $fields['shipping'], 'Room checkout has no delivery address fields' );
verify( isset( $fields['order']['arc_req_quiet'], $fields['order']['arc_req_extra_bed'], $fields['order']['arc_req_decoration'] ), 'Existing and core request options remain available' );

ob_start(); do_action( 'woocommerce_checkout_before_customer_details' ); $room_html = ob_get_clean();
verify( str_contains( $room_html, 'Deluxe Couple' ) && str_contains( $room_html, 'https://example.test/room.jpg' ) && ! str_contains( $room_html, 'Hotel Room Reservation' ), 'Review card shows selected category and photo rather than the container product' );
verify( str_contains( $room_html, 'Hotel Aristocrats &amp; Suites' ) && str_contains( $room_html, '12:00' ), 'Review card gets hotel profile and checkout time through the real mocked API client' );
verify( '' === arc_cfg()['cancellation'] && 0 === arc_cfg()['stars'], 'Unconfigured policy and star rating remain hidden' );

$styles = array(); do_action( 'wp_enqueue_scripts' );
verify( isset( $styles['arc-checkout'] ) && str_contains( $styles['arc-checkout'], 'form.checkout:has(.arc-booking-card)' ), 'Room CSS is loaded and scoped to the review-card checkout form' );

$wc->cart->items = array( array( 'product_id' => 202, 'data' => new TestProduct( false ), 'quantity' => 1 ) );
$styles = array(); do_action( 'wp_enqueue_scripts' );
verify( ! isset( $styles['arc-checkout'] ) && ! isset( $styles['arc-room-checkout-css'] ), 'Food checkout loads no hotel checkout CSS' );
$fields = apply_filters( 'woocommerce_checkout_fields', checkout_fields() );
verify( isset( $fields['billing']['billing_state'], $fields['billing']['billing_address_1'] ) && ! isset( $fields['billing']['arc_id_number'], $fields['order']['arc_terms'] ), 'Food checkout retains district/address and receives no hotel fields' );
ob_start(); do_action( 'woocommerce_checkout_before_customer_details' ); do_action( 'woocommerce_checkout_after_customer_details' ); $food_html = ob_get_clean();
verify( '' === $food_html && 'Place order' === apply_filters( 'woocommerce_order_button_text', 'Place order' ), 'Food checkout receives no room cards or booking button label' );
$food_order = new WC_Order(); do_action( 'woocommerce_checkout_create_order', $food_order, array() );
verify( 'food' === $food_order->get_meta( '_arc_order_type' ) && '' === $food_order->get_meta( '_arc_booking' ), 'Food order saving creates no booking metadata' );

$wc->cart->items = array( room_item() );
$received_page = true;
$styles = array(); do_action( 'wp_enqueue_scripts' );
verify( ! isset( $styles['arc-checkout'] ), 'Order-received page is left to the core confirmation renderer' );
verify( 0 === $transport_calls, 'Snippet and fixture tests perform no HTTP writes or live network requests' );

$save_count = count( $hooks['woocommerce_checkout_create_order'][20] );
require $snippet_path;
verify( $save_count === count( $hooks['woocommerce_checkout_create_order'][20] ), 'A second copy does not register duplicate save handlers' );

echo "\n{$passed} checks passed (" . ( $snippet_first ? 'snippet-first' : 'plugin-first' ) . ").\n";
