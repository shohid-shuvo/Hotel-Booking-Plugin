<?php
/**
 * Action Scheduler Health & Queue Monitor.
 *
 * @package ARC\HotelCore\Admin
 */

namespace ARC\HotelCore\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Monitors pending and past-due Action Scheduler / WP-Cron tasks.
 */
class Action_Scheduler_Status {

	/**
	 * Get Action Scheduler metrics for arc_ tasks.
	 *
	 * @return array Metrics summary.
	 */
	public static function get_metrics(): array {
		global $wpdb;

		$table_name = $wpdb->prefix . 'actionscheduler_actions';

		// Verify table exists
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
		if ( ! $table_exists ) {
			return [
				'pending'   => 0,
				'past_due'  => 0,
				'last_run'  => null,
				'available' => false,
			];
		}

		$current_utc = gmdate( 'Y-m-d H:i:s' );
		$grace_time  = gmdate( 'Y-m-d H:i:s', time() - 300 ); // Overdue by > 5 minutes

		// Total pending arc_ actions
		$pending = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table_name} WHERE hook LIKE %s AND status = 'pending'",
				'arc_%'
			)
		);

		// Past-due pending arc_ actions
		$past_due = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table_name} WHERE hook LIKE %s AND status = 'pending' AND scheduled_date_gmt < %s",
				'arc_%',
				$grace_time
			)
		);

		// Last execution timestamp of any arc_ action
		$last_run = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MAX(last_attempt_gmt) FROM {$table_name} WHERE hook LIKE %s AND status IN ('complete', 'failed')",
				'arc_%'
			)
		);

		return [
			'pending'   => $pending,
			'past_due'  => $past_due,
			'last_run'  => $last_run,
			'available' => true,
		];
	}
}
