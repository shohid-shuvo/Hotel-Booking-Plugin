<?php
/** Connection-owned locks. A crashed PHP worker cannot leave a live lock behind. */
namespace ARC\HotelCore\Support;
defined( 'ABSPATH' ) || exit;

class Database_Lock {
	private static array $held = [];
	private static function name( string $key ): string {
		global $wpdb;
		return 'arc_' . hash( 'sha256', ( defined( 'DB_NAME' ) ? DB_NAME : '' ) . ':' . $wpdb->prefix . ':' . $key );
	}
	public static function acquire( string $key ): bool {
		global $wpdb;
		if ( isset( self::$held[ $key ] ) ) {
			return false;
		}
		$name = substr( self::name( $key ), 0, 64 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) ) ) {
			return false;
		}
		self::$held[ $key ] = $name;
		return true;
	}
	public static function release( string $key ): void {
		global $wpdb;
		if ( isset( self::$held[ $key ] ) ) {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::$held[ $key ] ) );
			unset( self::$held[ $key ] );
		}
	}
}
