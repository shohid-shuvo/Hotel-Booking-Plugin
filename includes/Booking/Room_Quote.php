<?php
/** One date validator and deterministic API room selection for every booking path. */
namespace ARC\HotelCore\Booking;

use ARC\HotelCore\Api\Api_Client;
use ARC\HotelCore\Api\Api_Result;
use ARC\HotelCore\Settings\Settings;
defined( 'ABSPATH' ) || exit;

class Room_Quote {
	public static function validate_dates( string $in, string $out ): bool {
		$tz = wp_timezone();
		$a = \DateTimeImmutable::createFromFormat( '!Y-m-d', $in, $tz );
		$b = \DateTimeImmutable::createFromFormat( '!Y-m-d', $out, $tz );
		if ( ! $a || ! $b || $a->format( 'Y-m-d' ) !== $in || $b->format( 'Y-m-d' ) !== $out ) {
			return false;
		}
		$today = new \DateTimeImmutable( 'today', $tz );
		$days = (int) $a->diff( $b )->format( '%r%a' );
		return $a >= $today && $days > 0 && $days <= (int) Settings::get( 'max_stay_nights', 30 )
			&& $a <= $today->modify( '+' . (int) Settings::get( 'max_advance_days', 365 ) . ' days' );
	}
	public static function search( string $in, string $out, int $adults, int $children, int $type = 0 ): Api_Result {
		if ( ! self::validate_dates( $in, $out ) || $adults < 1 || $adults > 8 || $children < 0 || $children > 6 || $type < 0 ) {
			return Api_Result::error( 'INVALID_DATES', __( 'Please select valid dates and guest counts within the booking limits.', 'arc-hotel-core' ), 400 );
		}
		$query = [ 'checkIn' => Settings::stay_datetime( $in, true ), 'checkOut' => Settings::stay_datetime( $out, false ), 'adults' => $adults ];
		if ( $type > 0 ) {
			$query['roomTypeId'] = $type;
		}
		$result = ( new Api_Client() )->get( 'api/v1/public-booking/available-rooms', $query, [ 'use_cache' => false ] );
		if ( ! $result->ok() ) {
			return $result;
		}
		if ( ! is_array( $result->data() ) ) {
			return Api_Result::error( 'INVALID_RESPONSE', __( 'Room availability could not be verified.', 'arc-hotel-core' ), 502 );
		}
		$rows = [];
		foreach ( $result->data() as $row ) {
			if ( ! is_array( $row ) || (int) ( $row['roomId'] ?? 0 ) <= 0 || (int) ( $row['roomTypeId'] ?? 0 ) <= 0
				|| ( $type > 0 && (int) $row['roomTypeId'] !== $type )
				|| $adults > (int) ( $row['maxAdults'] ?? 0 ) || $children > (int) ( $row['maxChildren'] ?? 0 )
				|| (int) ( $row['nights'] ?? 0 ) <= 0 || ! is_numeric( $row['pricePerNight'] ?? null ) || ! is_numeric( $row['totalPrice'] ?? null )
				|| ! is_finite( (float) $row['pricePerNight'] ) || ! is_finite( (float) $row['totalPrice'] ) || (float) $row['totalPrice'] <= 0 || (float) $row['pricePerNight'] <= 0 ) {
				continue;
			}
			$row += [ 'description' => '', 'thumbnailUrl' => '', 'imageUrls' => [], 'amenities' => [] ];
			$rows[] = $row;
		}
		usort( $rows, static fn( $a, $b ) => [ (float) $a['totalPrice'], (int) $a['roomId'] ] <=> [ (float) $b['totalPrice'], (int) $b['roomId'] ] );
		return Api_Result::success( $rows );
	}
	public static function select( array $rows, int $type, int $room_id = 0 ): ?array {
		foreach ( $rows as $row ) {
			if ( (int) $row['roomTypeId'] === $type && ( $room_id <= 0 || (int) $row['roomId'] === $room_id ) ) {
				return $row;
			}
		}
		return null;
	}
	public static function normalize_booking( array $booking ): array {
		$api_id = (int) ( $booking['api_room_id'] ?? 0 );
		$room_id = (int) ( $booking['room_id'] ?? 0 );
		if ( $api_id > 0 && $room_id > 0 && $api_id !== $room_id ) {
			return [];
		}
		$booking['api_room_id'] = $booking['room_id'] = max( $api_id, $room_id );
		return $booking;
	}
	public static function booking_data( array $row, string $in, string $out, int $adults, int $children ): array {
		return [
			'company_code' => Settings::get( 'company_code' ),
			'room_type_id' => (int) $row['roomTypeId'], 'room_type_name' => sanitize_text_field( $row['roomTypeName'] ?? '' ),
			'api_room_id' => (int) $row['roomId'], 'room_id' => (int) $row['roomId'], 'room_number' => sanitize_text_field( $row['roomNumber'] ?? '' ),
			'check_in' => $in, 'check_out' => $out, 'adults' => $adults, 'children' => $children,
			'nights' => (int) $row['nights'], 'rate_per_night' => (float) $row['pricePerNight'], 'total_price' => (float) $row['totalPrice'],
			'thumbnail_url' => esc_url_raw( $row['thumbnailUrl'] ?: ( $row['imageUrls'][0] ?? '' ) ),
		];
	}
}
