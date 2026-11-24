<?php
/** Real synchronization/controller regressions; fixture transport, isolated WP storage. */
define( 'ARC_TEST_BOOTSTRAP_ONLY', true );
require __DIR__ . '/test-checkout-snippet.php';

use ARC\HotelCore\Api\Api_Result;
use ARC\HotelCore\Booking\Reservation_Sync;
use ARC\HotelCore\Booking\Room_Quote;
use ARC\HotelCore\Settings\Settings;
use ARC\HotelCore\Support\Guest_Identity;
use ARC\HotelCore\Support\Database_Lock;
use ARC\HotelCore\Support\Order_Query;
use ARC\HotelCore\Support\Scheduler;

$orders = [];
$notes = [];
$events = [];
$mode = 'success';
$current_order = 0;
$post_count = 0;
$payloads = [];
$query_args = [];
function update_option( $key, $value, $autoload = null ) { global $options; $options[ $key ] = $value; return true; }
function remove_filter( $hook, $callback, $priority = 10 ) {
	global $hooks;
	foreach ( $hooks[ $hook ][ $priority ] ?? [] as $i => $registered ) {
		if ( $registered[0] === $callback ) { unset( $hooks[ $hook ][ $priority ][ $i ] ); }
	}
}
function wp_next_scheduled( $hook, $args = [] ) { global $events; return $events[ $hook ][ json_encode( $args ) ]['timestamp'] ?? false; }
function wp_schedule_single_event( $time, $hook, $args = [] ) { global $events; $events[ $hook ][ json_encode( $args ) ] = [ 'timestamp' => $time, 'schedule' => false ]; return true; }
function wp_schedule_event( $time, $schedule, $hook, $args = [] ) { global $events; $events[ $hook ][ json_encode( $args ) ] = [ 'timestamp' => $time, 'schedule' => $schedule ]; return true; }
function wp_clear_scheduled_hook( $hook, $args = [] ) { global $events; unset( $events[ $hook ][ json_encode( $args ) ] ); return 1; }
function wp_unschedule_hook( $hook ) { global $events; unset( $events[ $hook ] ); return 1; }
function wp_get_scheduled_event( $hook, $args = [] ) { global $events; return isset( $events[ $hook ][ json_encode( $args ) ] ) ? (object) $events[ $hook ][ json_encode( $args ) ] : false; }
function wc_get_order( $id ) { global $orders; return isset( $orders[ $id ] ) ? clone $orders[ $id ] : false; }
function wc_get_orders( $args ) {
	global $orders, $query_args;
	$query_args = $args;
	apply_filters( 'woocommerce_order_data_store_cpt_get_orders_query', [ 'meta_query' => [] ], $args );
	$matches = [];
	foreach ( $orders as $order ) {
		$conditions = $args['meta_query'] ?? $args['arc_meta_query'] ?? [];
		$match = true;
		foreach ( $conditions as $condition ) {
			if ( is_array( $condition ) && isset( $condition['key'] ) ) {
				$value = $order->get_meta( $condition['key'] );
				$match = $match && ( 'IN' === ( $condition['compare'] ?? '=' ) ? in_array( $value, $condition['value'], true ) : $value === $condition['value'] );
			}
		}
		if ( $match ) { $matches[] = clone $order; }
	}
	return array_slice( $matches, ( ( $args['page'] ?? 1 ) - 1 ) * ( $args['limit'] ?? 50 ), $args['limit'] ?? 50 );
}
class TestDatabase {
	public string $prefix = 'wp_';
	public bool $deny = false;
	public array $locks = [];
	public function prepare( $sql, ...$values ) {
		foreach ( $values as $value ) { $sql = preg_replace( '/%s/', "'" . addslashes( (string) $value ) . "'", $sql, 1 ); }
		return $sql;
	}
	public function get_var( $sql ) {
		if ( preg_match( '/GET_LOCK\(\x27([^\x27]+)\x27/', $sql, $match ) ) {
			if ( $this->deny || isset( $this->locks[ $match[1] ] ) ) { return '0'; }
			$this->locks[ $match[1] ] = true;
			return '1';
		}
		if ( preg_match( '/RELEASE_LOCK\(\x27([^\x27]+)\x27/', $sql, $match ) ) { unset( $this->locks[ $match[1] ] ); return '1'; }
		return 'wp_arc_api_log';
	}
}
$wpdb = new TestDatabase();
class SafetyItem { public function __construct( private float $total ) {} public function get_total() { return $this->total; } }
class SafetyOrder extends WC_Order {
	public int $id;
	public string $status = 'processing';
	public string $gateway = 'stripe_card';
	public bool $paid = true;
	public bool $save_fails = false;
	public float $refunded = 0;
	public float $room_total = 8500;
	public string $currency = 'BDT';
	public function get_currency() { return $this->currency; }
	public function get_id() { return $this->id; }
	public function get_status() { return $this->status; }
	public function get_payment_method() { return $this->gateway; }
	public function get_payment_method_title() { return $this->gateway; }
	public function get_total_refunded() { return $this->refunded; }
	public function is_paid() { return $this->paid && in_array( $this->status, [ 'processing', 'completed' ], true ); }
	public function get_date_paid() { return $this->paid ? new DateTimeImmutable() : null; }
	public function get_billing_first_name() { return 'Test'; }
	public function get_billing_last_name() { return 'Guest'; }
	public function get_billing_phone() { return '01700000000'; }
	public function get_billing_email() { return 'guest@example.test'; }
	public function get_transaction_id() { return ''; }
	public function get_order_key() { return 'wc_order_test_' . $this->id; }
	public function get_items( $type = '' ) { return [ new SafetyItem( $this->room_total ) ]; }
	public function save() { global $orders; if ( $this->save_fails ) { return 0; } $orders[ $this->id ] = clone $this; return $this->id; }
	public function read_meta_data( $force = false ) { global $orders; $this->meta = $orders[ $this->id ]->meta; }
	public function update_status( $status, $note = '' ) { $this->status = $status; $this->save(); }
	public function add_order_note( $note ) { global $notes; $notes[ $this->id ][] = $note; }
}
function make_order( $id, $gateway = 'stripe_card', $status = 'processing', $paid = true ) {
	global $orders, $current_order;
	$order = new SafetyOrder();
	$order->id = $id; $order->gateway = $gateway; $order->status = $status; $order->paid = $paid;
	$order->total = 8700; // A separate gateway fee must not be posted as room payment.
	$in = new DateTimeImmutable( 'tomorrow', wp_timezone() );
	$order->meta = [ '_arc_order_type' => 'room', '_arc_sync_status' => 'pending', '_arc_id_number' => '1234567890', '_arc_booking' => [
		'company_code' => 'GPH01', 'room_type_id' => 1, 'room_type_name' => 'Executive Presidential Suite', 'room_id' => 3, 'room_number' => '103',
		'check_in' => $in->format( 'Y-m-d' ), 'check_out' => $in->modify( '+1 day' )->format( 'Y-m-d' ),
		'adults' => 2, 'children' => 2, 'nights' => 1, 'rate_per_night' => 8500, 'total_price' => 8500,
	] ];
	$orders[ $id ] = $order; $current_order = $id;
	return $order;
}
Settings::update( [ 'company_code' => 'GPH01' ] );
$schema = json_decode( file_get_contents( ARC_PLUGIN_DIR . 'agent-instructions/hotel-all-Api-End-point.json' ), true );
add_filter( 'arc_api_mock_result', function ( $result, $path, $body ) use ( $schema ) {
	global $mode, $current_order, $orders, $post_count, $payloads;
	if ( str_ends_with( $path, 'available-rooms' ) ) {
		if ( 'read-failure' === $mode ) { return Api_Result::error( 'CONNECTION_ERROR', 'test', 0 ); }
		if ( 'cancel-before' === $mode ) { $orders[ $current_order ]->status = 'cancelled'; }
		if ( 'reentrant' === $mode ) { Reservation_Sync::maybe_sync( $current_order ); }
		$rows = $result->data();
		if ( 'unavailable' === $mode ) { $rows = array_values( array_filter( $rows, static fn( $row ) => 3 !== $row['roomId'] ) ); }
		if ( 'price-change' === $mode ) { foreach ( $rows as &$row ) { if ( 3 === $row['roomId'] ) { $row['totalPrice'] = 9000; } } unset( $row ); }
		if ( 'capacity' === $mode ) { foreach ( $rows as &$row ) { $row['maxChildren'] = 0; } unset( $row ); }
		return Api_Result::success( $rows );
	}
	if ( str_ends_with( $path, 'create-reservation-json' ) ) {
		$post_count++; $payloads[] = $body;
		verify( [] === array_diff_key( $body, $schema['components']['schemas']['WebBookingRequest']['properties'] ), 'Real reservation payload uses only documented live fields' );
		verify( 'sending' === $orders[ $current_order ]->get_meta( '_arc_sync_status' ) && wp_next_scheduled( 'arc_observe_reservation_sync', [ $current_order ] ), 'Durable write intent and recovery observer exist before POST' );
		if ( 'timeout' === $mode ) { return Api_Result::error( 'CONNECTION_ERROR', 'test', 0 ); }
		if ( 'server-error' === $mode ) { return Api_Result::error( 'SERVER_ERROR', 'test', 503 ); }
		if ( 'bad-json' === $mode ) { return Api_Result::error( 'INVALID_JSON', 'test', 200 ); }
		if ( 'empty-success' === $mode ) { return Api_Result::success( [] ); }
		if ( 'throw-after-send' === $mode ) { throw new RuntimeException( 'Simulated post-send interruption' ); }
		if ( 'cancel-after' === $mode ) { $orders[ $current_order ]->status = 'cancelled'; }
		$data = $result->data();
		$data['bookingNumber'] = 'WEB-TEST-' . $current_order;
		$data['bookingId'] = $current_order;
		$data['roomNumber'] = '103';
		$data['guestPhone'] = $body['phone'];
		$data['companyCode'] = $body['companyCode'];
		$data['checkInDate'] = $body['checkInDate']; $data['checkOutDate'] = $body['checkOutDate'];
		$data['totalAmount'] = 'amount-mismatch' === $mode ? 8501 : 8500;
		$data['paidAmount'] = $body['amountPaid']; $data['dueAmount'] = 8500 - $body['amountPaid'];
		return Api_Result::success( $data );
	}
	if ( str_contains( $path, '/track/' ) ) {
		$reference = strtoupper( rawurldecode( basename( $path ) ) );
		if ( preg_match( '/^WEB-(?:REC|TRACK|LINK)-(\d+)$/', $reference, $match ) && isset( $orders[ (int) $match[1] ] ) ) {
			$order = $orders[ (int) $match[1] ]; $booking = $order->get_meta( '_arc_booking' );
			$row = $result->data()[0];
			$row['bookingNumber'] = $reference; $row['bookingId'] = $order->id; $row['companyCode'] = 'GPH01';
			$row['checkInDate'] = $booking['check_in'] . 'T14:00:00'; $row['checkOutDate'] = $booking['check_out'] . 'T12:00:00';
			$row['totalAmount'] = $booking['total_price']; $row['paidAmount'] = $order->gateway === 'cod' ? 0 : $booking['total_price'];
			$row['dueAmount'] = $row['totalAmount'] - $row['paidAmount']; $row['roomType'] = $booking['room_type_name']; $row['roomNumber'] = '103';
			$row['status'] = $order->status === 'cancelled' ? 'Cancelled' : 'Reserved';
			return Api_Result::success( [ $row ] );
		}
	}
	if ( str_ends_with( $path, 'cancel-reservation-json' ) ) {
		verify( array_keys( $body ) === [ 'bookingNumber','phone','reason' ], 'Controller cancellation uses only supported live request fields' );
	}
	return $result;
}, 10, 3 );

make_order( 100 );
verify( Reservation_Sync::maybe_sync( 100 ), 'Paid card order is confirmed through the real sync class' );
verify( $payloads[0]['roomId'] === 3 && $payloads[0]['amountPaid'] === 8500.0 && $orders[100]->total === 8700.0, 'Physical room alias works and the gateway fee stays outside ERP room payment' );
$before = $post_count;
verify( Reservation_Sync::maybe_sync( 100 ) && $post_count === $before, 'Duplicate payment callbacks make no additional reservation POST' );
foreach ( [ 'cod', 'arc_pay_at_hotel' ] as $i => $gateway ) {
	$id = 110 + $i; make_order( $id, $gateway, 'processing', true );
	verify( Reservation_Sync::maybe_sync( $id ) && end( $payloads )['amountPaid'] === 0.0, 'Offline gateway remains unpaid even when WooCommerce calls processing a paid status' );
}
foreach ( [ 'cancelled', 'refunded', 'failed' ] as $i => $status ) {
	$id = 120 + $i; make_order( $id, 'stripe_card', $status ); $before = $post_count;
	verify( ! Reservation_Sync::maybe_sync( $id ) && $before === $post_count, 'Cancelled/refunded/failed orders cannot create reservations' );
}
make_order( 130, 'bacs', 'on-hold', false ); $before = $post_count;
verify( ! Reservation_Sync::maybe_sync( 130 ) && $before === $post_count, 'Unpaid bank transfer order makes no reservation POST' );
foreach ( [ 'unavailable', 'price-change', 'capacity', 'cancel-before' ] as $i => $scenario ) {
	$mode = $scenario; $id = 140 + $i; make_order( $id ); $before = $post_count;
	verify( ! Reservation_Sync::maybe_sync( $id ) && $before === $post_count, 'Preflight blocks ' . $scenario . ' before any write' );
}
foreach ( [ 'timeout', 'server-error', 'bad-json', 'empty-success', 'throw-after-send' ] as $i => $scenario ) {
	$mode = $scenario; $id = 150 + $i; make_order( $id ); $before = $post_count;
	verify( ! Reservation_Sync::maybe_sync( $id ) && $orders[$id]->get_meta( '_arc_sync_status' ) === 'unknown' && $post_count === $before + 1, 'Ambiguous ' . $scenario . ' makes one write and enters unknown state' );
	Reservation_Sync::maybe_sync( $id );
	verify( $post_count === $before + 1 && ! wp_next_scheduled( 'arc_retry_reservation_sync', [ $id ] ), 'Unknown result is never automatically reposted' );
}
$mode = 'reentrant'; make_order( 160 ); $before = $post_count;
verify( Reservation_Sync::maybe_sync( 160 ) && $post_count === $before + 1, 'Reentrant worker is excluded by the actual lock implementation' );
verify( [] === $wpdb->locks, 'Order and room locks are released after success/failure/exception' );
$mode = 'amount-mismatch'; make_order( 161 ); $before = $post_count;
verify( ! Reservation_Sync::maybe_sync( 161 ) && $orders[161]->get_meta( '_arc_aspnet_reservation_no' ) && 'manual' === $orders[161]->get_meta( '_arc_sync_status' ), 'Mismatched API amount retains the confirmed reference for manual review' );
Reservation_Sync::maybe_sync( 161 ); verify( $post_count === $before + 1, 'Manual state with an existing reference cannot create a duplicate' );
$mode = 'cancel-after'; make_order( 162 );
verify( ! Reservation_Sync::maybe_sync( 162 ) && 'cancelled' === $orders[162]->status && $orders[162]->get_meta( '_arc_aspnet_reservation_no' ), 'Cancellation during POST retains its reference without changing the cancelled order back to active' );
$mode = 'success'; make_order( 163 ); $orders[163]->save_fails = true; $before = $post_count;
verify( ! Reservation_Sync::maybe_sync( 163 ) && $before === $post_count, 'Failed durable save prevents reservation POST' );
make_order( 164 ); $wpdb->deny = true; $before = $post_count;
verify( ! Reservation_Sync::maybe_sync( 164 ) && $before === $post_count, 'Unavailable database lock fails closed' ); $wpdb->deny = false;
make_order( 165 ); $orders[165]->meta['_arc_sync_status'] = 'sending'; $before = $post_count;
Reservation_Sync::observe_inflight( 165 );
verify( 'unknown' === $orders[165]->get_meta( '_arc_sync_status' ) && $before === $post_count, 'Crash recovery observer marks sending as unknown without POST' );
$mode = 'read-failure'; make_order( 166 ); $before = $post_count;
Reservation_Sync::maybe_sync( 166 ); Reservation_Sync::maybe_sync( 166 );
verify( $post_count === $before && wp_next_scheduled( 'arc_retry_reservation_sync', [ 166 ] ) && count( $events['arc_retry_reservation_sync'] ) === 1, 'Read-only failures schedule one bounded unique retry' );
$mode = 'success'; make_order( 167 ); $orders[167]->room_total = 8000; $before = $post_count;
verify( ! Reservation_Sync::maybe_sync( 167 ) && $before === $post_count && $orders[167]->total === 8700.0, 'Discounted room amount is reviewed without altering collected money' );

verify( Guest_Identity::matching_phone( '+880 1700-000000', '01700000000' ), 'Complete Bangladesh phone formats normalize consistently' );
verify( ! Guest_Identity::matching_phone( '01700000000', '0' ) && ! Guest_Identity::matching_phone( '01700000000', '+' ), 'Suffix-only and normalized-empty phone bypasses are rejected' );
verify( ! Guest_Identity::matching_phone( '01700000000', '1700000000' ), 'Partial phone suffix is rejected even if it has ten digits' );
$booking = [ 'bookingNumber' => 'WEB-TEST', 'phone' => '01700000000', 'companyCode' => 'GPH01' ];
verify( null === Guest_Identity::find_booking( [ $booking ], '01700000000', '01700000000', 'GPH01' ) && null === Guest_Identity::find_booking( [ $booking ], 'WEB-TEST', '01700000000', 'OTHER' ), 'Tracking requires exact booking reference and tenant as well as phone' );
$_SERVER['REMOTE_ADDR'] = '192.0.2.1';
for ( $i=0; $i<5; $i++ ) { Guest_Identity::rate_limit( 'track_test', 'WEB-TEST' ); }
verify( ! Guest_Identity::rate_limit( 'track_test', 'WEB-TEST' ), 'Lookup rate limit bounds repeated attempts' );
$filtered = Order_Query::get_orders( [ 'limit'=>5, 'meta_query'=>[ [ 'key'=>'_arc_sync_status','value'=>'unknown' ] ] ] );
verify( isset( $query_args['arc_meta_query'] ) && ! isset( $query_args['meta_query'] ) && $filtered, 'Legacy WooCommerce metadata queries use the explicit CPT adapter' );
Scheduler::ensure(); verify( wp_next_scheduled( 'arc_sync_rooms_cron' ), 'Room sync recurrence is actually scheduled' );
Settings::update( [ 'company_code'=>'GPH01','auto_sync_interval'=>'hourly' ] ); Scheduler::ensure();
verify( 'hourly' === wp_get_scheduled_event( 'arc_sync_rooms_cron' )->schedule, 'Changing sync frequency replaces the old recurrence' );
Settings::update( [ 'company_code'=>'GPH01','auto_sync_interval'=>'manual','room_gateways'=>[] ] ); Scheduler::ensure();
verify( ! wp_next_scheduled( 'arc_sync_rooms_cron' ) && [] === Settings::get( 'room_gateways' ), 'Manual sync and zero room gateways are respected' );
Scheduler::clear(); verify( [] === $events, 'All plugin cron hooks including observers and retries are removed' );
verify( 0 === $transport_calls, 'Safety regression suite performs no live network request' );

class JsonResponse extends RuntimeException {
	public function __construct( public array $payload, public int $status = 200 ) { parent::__construct( 'JSON response' ); }
}
function wp_send_json_success( $data, $status=200 ) { throw new JsonResponse( [ 'success'=>true,'data'=>$data ],$status ); }
function wp_send_json_error( $data, $status=400 ) { throw new JsonResponse( [ 'success'=>false,'data'=>$data ],$status ); }
function check_ajax_referer( $action,$field ) { if ( ( $_POST[$field] ?? '' ) !== $action ) { wp_send_json_error( [],403 ); } }
function nocache_headers() {}
function call_ajax( $controller,$method,$data ) {
	$_POST = $data;
	try { $controller->$method(); } catch ( JsonResponse $response ) { return $response; }
	throw new RuntimeException( 'No JSON response returned' );
}
Settings::update( [ 'company_code'=>'GPH01' ] );
make_order( 300 ); $orders[300]->meta['_arc_sync_status']='synced'; $orders[300]->meta['_arc_aspnet_reservation_no']='WEB-TRACK-300';
$track = new \ARC\HotelCore\Track\Track_Controller();
$lookup = [ 'nonce'=>'arc_track_booking_action','booking_number'=>'WEB-TRACK-300','guest_phone'=>'+8801700000000' ];
$result = call_ajax( $track,'ajax_track',$lookup );
verify( $result->payload['success'] && ! isset( $result->payload['data']['guestName'],$result->payload['data']['phone'],$result->payload['data']['email'] ), 'Actual tracking handler returns a private minimal result for the exact identity pair' );
$result = call_ajax( $track,'ajax_track',array_replace( $lookup,[ 'guest_phone'=>'0' ] ) );
verify( ! $result->payload['success'], 'Actual AJAX lookup rejects suffix-only phone' );
$result = call_ajax( $track,'ajax_cancel',array_replace( $lookup,[ 'reason'=>'Test cancellation' ] ) );
verify( 403 === $result->status, 'Tracking nonce cannot authorize cancellation' );
$result = call_ajax( $track,'ajax_cancel',array_replace( $lookup,[ 'nonce'=>'arc_cancel_booking_action','reason'=>'Test cancellation' ] ) );
verify( $result->payload['success'] && $orders[300]->status === 'cancelled' && [] === $wpdb->locks, 'Confirmed cancellation updates its matching WooCommerce order and releases the lock' );
$poll = new Reservation_Sync();
$result = call_ajax( $poll,'ajax_poll_status',[ 'nonce'=>'arc_poll_nonce_300','order_id'=>300,'order_key'=>'wrong-key' ] );
verify( 403 === $result->status, 'Polling rejects a valid nonce without the exact order key' );
$orders[300]->meta['_arc_sync_status']='unknown';
$result = call_ajax( $poll,'ajax_poll_status',[ 'nonce'=>'arc_poll_nonce_300','order_id'=>300,'order_key'=>'wc_order_test_300' ] );
verify( $result->payload['data']['terminal'], 'Authorized polling exposes a terminal review state rather than an endless spinner' );
for ( $id=400; $id<460; $id++ ) {
	make_order( $id ); $orders[$id]->meta['_arc_sync_status']='synced'; $orders[$id]->meta['_arc_aspnet_reservation_no']='WEB-REC-'.$id; $orders[$id]->meta['_arc_aspnet_room_number']='101';
}
foreach ( $orders as $id=>$order ) { if ( $id<400 ) { $orders[$id]->meta['_arc_sync_status']='manual'; } }
$stats = ( new \ARC\HotelCore\Booking\Reconcile() )->run_reconciliation();
verify( $stats['success'] && $stats['checked']===60 && $stats['updated']===60, 'Reconciliation snapshots and processes every order beyond the first 50 without pagination skips' );
verify( $orders[450]->get_meta( '_arc_aspnet_room_number' ) === '103' && $orders[450]->total === 8700.0, 'Reconciliation updates assigned rooms without changing financial order totals' );
make_order( 470 ); $orders[470]->meta['_arc_sync_attempts']=1; $before=$post_count;
verify( ! Reservation_Sync::maybe_sync( 470 ) && $orders[470]->get_meta( '_arc_sync_status' )==='unknown' && $post_count===$before, 'Legacy uncertain retries are quarantined after the upgrade' );
verify( 0 === $transport_calls, 'Expanded controller/reconcile regressions perform no live network request' );

make_order( 480 ); $orders[480]->currency='USD'; $before=$post_count;
verify( ! Reservation_Sync::maybe_sync( 480 ) && $before===$post_count, 'Unsupported order currency cannot be posted as BDT room payment' );
make_order( 481 ); $orders[481]->meta['_arc_booking']['company_code']='OTHER'; $before=$post_count;
verify( ! Reservation_Sync::maybe_sync( 481 ) && $before===$post_count, 'Tenant switch cannot submit a previous property booking' );
$wc->cart->items=[ room_item() ]; $wc->cart->items[0]['arc_room']['company_code']='GPH01';
$_POST=[ 'arc_id_number'=>'1234567890','arc_guest_2'=>'Guest Two','arc_terms'=>'1' ];
$wpdb->deny=true; $errors=new WP_Error();
( new \ARC\HotelCore\Checkout\Checkout_Validation() )->validate_room_checkout( [ 'billing_phone'=>'01700000000' ],$errors );
verify( isset( $errors->errors['arc_verification_busy'] ), 'Unavailable SQL locking blocks room checkout before payment' ); $wpdb->deny=false;
class HposTestUtil { public static function custom_orders_table_usage_is_enabled() { return true; } }
class_alias( HposTestUtil::class,'Automattic\WooCommerce\Utilities\OrderUtil' );
Order_Query::get_orders( [ 'limit'=>5,'meta_query'=>[ [ 'key'=>'_arc_sync_status','value'=>'manual' ] ] ] );
verify( isset( $query_args['meta_query'] ) && ! isset( $query_args['arc_meta_query'] ), 'HPOS branch preserves native metadata query arguments' );
echo "\n{$passed} plugin safety checks passed.\n";
