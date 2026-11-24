<?php
/** Guest identity comparison and bounded public lookup attempts. */
namespace ARC\HotelCore\Support;
defined( 'ABSPATH' ) || exit;

class Guest_Identity {
	public static function normalize_phone( string $phone ): string {
		$phone = preg_replace( '/[\s+\-()]/', '', trim( $phone ) );
		if ( ! preg_match( '/^\d{8,15}$/', $phone ) ) {
			return '';
		}
		// Normalize only complete Bangladesh mobile numbers, never arbitrary suffixes.
		if ( preg_match( '/^8801\d{9}$/', $phone ) ) {
			$phone = substr( $phone, 2 );
		}
		return $phone;
	}
	public static function matching_phone( string $left, string $right ): bool {
		$left = self::normalize_phone( $left );
		$right = self::normalize_phone( $right );
		return '' !== $left && '' !== $right && hash_equals( $left, $right );
	}
	public static function find_booking( array $data, string $reference, string $phone, string $company ): ?array {
		$rows = isset( $data['bookingNumber'] ) ? [ $data ] : $data;
		foreach ( $rows as $row ) {
			if ( is_array( $row ) && (string) ( $row['bookingNumber'] ?? '' ) === $reference
				&& ( '' !== $company && (string) ( $row['companyCode'] ?? '' ) === $company )
				&& self::matching_phone( (string) ( $row['phone'] ?? $row['guestPhone'] ?? '' ), $phone ) ) {
				return $row;
			}
		}
		return null;
	}
	public static function rate_limit( string $operation, string $reference, int $limit = 5, int $window = 900 ): bool {
		$ip = (string) ( $_SERVER['REMOTE_ADDR'] ?? 'unknown' );
		$keys = [ 'arc_rate_' . $operation . '_' . md5( $ip ), 'arc_rate_' . $operation . '_ref_' . md5( $reference ) ];
		foreach ( $keys as $key ) {
			if ( (int) get_transient( $key ) >= $limit ) {
				return false;
			}
		}
		foreach ( $keys as $key ) {
			set_transient( $key, (int) get_transient( $key ) + 1, $window );
		}
		return true;
	}
}
