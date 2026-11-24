<?php
/** Backward-compatible health accessor for the plugin's actual WP-Cron queue. */
namespace ARC\HotelCore\Admin;
defined( 'ABSPATH' ) || exit;
class Action_Scheduler_Status {
	public static function get_metrics(): array {
		return \ARC\HotelCore\Support\Scheduler::metrics();
	}
}
