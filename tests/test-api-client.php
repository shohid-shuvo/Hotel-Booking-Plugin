<?php
/** Exercise the actual HTTP/cache/redaction path without making network requests. */
define( 'ARC_TEST_BOOTSTRAP_ONLY', true );
define( 'ARC_API_MOCK', false );
if ( in_array( '--unsafe-url', $argv, true ) ) { define( 'ARC_API_BASE_URL', 'http://example.test' ); }
require __DIR__ . '/test-checkout-snippet.php';

function update_option( $key, $value, $autoload = null ) { global $options; $options[$key] = $value; return true; }
function wp_http_validate_url( $url ) { return filter_var( $url, FILTER_VALIDATE_URL ) ? $url : false; }
function trailingslashit( $text ) { return rtrim( $text,'/' ) . '/'; }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
function wp_generate_uuid4() { return 'test-correlation-id'; }
function is_wp_error( $response ) { return $response instanceof WP_Error; }
function wp_remote_retrieve_response_code( $response ) { return $response['response']['code']; }
function wp_remote_retrieve_body( $response ) { return $response['body']; }
class HttpTestDatabase {
	public string $prefix = 'wp_';
	public array $logs = [];
	public function prepare( $sql, ...$values ) { return $sql; }
	public function get_var( $sql ) { return 'wp_arc_api_log'; }
	public function insert( $table, $data, $formats ) { $this->logs[] = $data; return true; }
}
$wpdb = new HttpTestDatabase();
$http_requests = []; $http_results = [];
$client = new \ARC\HotelCore\Api\Api_Client();
if ( in_array( '--missing-company', $argv, true ) ) {
	verify( ! $client->post( 'api/v1/public-booking/create-reservation-json', [] )->ok() && 0 === $transport_calls, 'Unconfigured install cannot send a reservation' );
	exit;
}
\ARC\HotelCore\Settings\Settings::update( [ 'company_code'=>'GPH01' ] );
if ( in_array( '--unsafe-url', $argv, true ) ) {
	verify( ! $client->post( 'api/v1/public-booking/create-reservation-json', [] )->ok() && 0 === $transport_calls, 'Insecure API URL cannot transmit guest data' );
	exit;
}
function response( $data, $code=200, $success=true ) { return [ 'response'=>[ 'code'=>$code ], 'body'=>json_encode( [ 'succeeded'=>$success,'message'=>'Guest private text','data'=>$data ] ) ]; }
$http_results[] = response( [ 'hotelName'=>'Hotel One' ] );
$first = $client->get( 'api/v1/public-booking/hotel-info', [], [ 'cache_ttl'=>60 ] );
$second = $client->get( 'api/v1/public-booking/hotel-info', [], [ 'cache_ttl'=>60 ] );
verify( $first->ok() && $second->ok() && 1 === $transport_calls, 'Hotel profile cache uses the tenant-injected key consistently' );
\ARC\HotelCore\Settings\Settings::update( [ 'company_code'=>'SGL02' ] );
$http_results[] = response( [ 'hotelName'=>'Hotel Two' ] );
verify( $client->get( 'api/v1/public-booking/hotel-info', [], [ 'cache_ttl'=>60 ] )->data()['hotelName'] === 'Hotel Two' && 2 === $transport_calls, 'Switching tenant never reuses another hotel cache' );
$http_results[] = response( [] ); $http_results[] = response( [] );
$client->get( 'api/v1/public-booking/available-rooms', [], [ 'cache_ttl'=>600 ] );
$client->get( 'api/v1/public-booking/available-rooms', [], [ 'cache_ttl'=>600 ] );
verify( 4 === $transport_calls, 'Live availability is never cached even when a caller supplies a TTL' );
$http_results[] = response( true );
$client->post( 'api/v1/public-booking/cancel-reservation-json', [ 'bookingNumber'=>'WEB-TEST','phone'=>'01700000000','reason'=>'Test' ] );
$request = end( $http_requests ); $body = json_decode( $request[1]['body'],true );
verify( ! isset( $body['companyCode'] ) && 0 === $request[1]['redirection'] && $request[1]['sslverify'], 'Cancellation uses its exact live body and cannot follow a write redirect' );
$before = $transport_calls; $http_results[] = response( null,503,false );
verify( ! $client->post( 'api/v1/public-booking/create-reservation-json', [ 'fullName'=>'Private Guest','phone'=>'01700000000','nationalIdOrPassport'=>'1234567890' ] )->ok() && $transport_calls === $before + 1, 'POST server error makes exactly one HTTP attempt' );
$last_log = end( $wpdb->logs );
verify( ! str_contains( $last_log['request_payload'],'Private Guest' ) && ! str_contains( $last_log['request_payload'],'01700000000' ) && ! str_contains( $last_log['error_message'],'Guest private text' ), 'Failed request logs redact identity and free-form errors' );
$http_results[] = response( [ 'phone'=>'01700000000','registrationToken'=>'private-reg-token','guestName'=>'Private Guest' ],200,'false' );
verify( ! $client->post( 'api/v1/public-booking/create-reservation-json', [] )->ok(), 'Malformed success flag is not accepted as a successful booking' );
$before = $transport_calls; $http_results[] = [ 'response'=>[ 'code'=>200 ], 'body'=>'raw private response 01700000000' ];
verify( $client->post( 'api/v1/public-booking/create-reservation-json', [] )->error_code() === 'INVALID_JSON' && $before + 1 === $transport_calls, 'Invalid JSON remains a single-attempt write failure' );
$last_log = end( $wpdb->logs );
verify( null === $last_log['response_payload'], 'Unstructured private HTTP response is never saved in logs' );
echo "\n{$passed} HTTP client checks passed.\n";
