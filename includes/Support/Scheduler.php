<?php
/** The plugin uses WP-Cron exclusively, including on in-place version upgrades. */
namespace ARC\HotelCore\Support;
use ARC\HotelCore\Settings\Settings;
defined( 'ABSPATH' ) || exit;

class Scheduler {
	public const HOOKS = [ 'arc_sync_rooms_cron', 'arc_daily_log_purge', 'arc_daily_reconcile', 'arc_retry_reservation_sync', 'arc_observe_reservation_sync' ];
	public static function ensure(): void {
		$schedules = [ 'arc_daily_log_purge' => 'daily', 'arc_daily_reconcile' => 'daily', 'arc_sync_rooms_cron' => Settings::get( 'auto_sync_interval', 'daily' ) ];
		foreach ( $schedules as $hook => $schedule ) {
			$event = wp_get_scheduled_event( $hook );
			if ( 'manual' === $schedule ) {
				wp_unschedule_hook( $hook );
				continue;
			}
			if ( $event && $event->schedule !== $schedule ) {
				wp_unschedule_hook( $hook );
				$event = false;
			}
			if ( ! $event ) {
				wp_schedule_event( time() + 60, $schedule, $hook );
			}
		}
	}
	public static function clear(): void {
		foreach ( self::HOOKS as $hook ) {
			wp_unschedule_hook( $hook );
		}
	}
	public static function metrics(): array {
		$metrics = [ 'pending' => 0, 'past_due' => 0, 'last_run' => get_option( 'arc_cron_last_run', null ), 'available' => true ];
		foreach ( (array) get_option( 'cron', [] ) as $timestamp => $hooks ) {
			if ( ! is_numeric( $timestamp ) || ! is_array( $hooks ) ) { continue; }
			foreach ( $hooks as $hook => $events ) {
				if ( in_array( $hook, self::HOOKS, true ) ) {
					$metrics['pending'] += count( $events );
					if ( (int) $timestamp < time() - 300 ) { $metrics['past_due'] += count( $events ); }
				}
			}
		}
		return $metrics;
	}
}
