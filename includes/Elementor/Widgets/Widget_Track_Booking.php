<?php
/**
 * Elementor Widget: Track Booking.
 *
 * @package ARC\HotelCore\Elementor\Widgets
 */

namespace ARC\HotelCore\Elementor\Widgets;

use Elementor\Widget_Base;
use ARC\HotelCore\Elementor\Shortcodes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Track Booking Elementor Widget.
 */
class Widget_Track_Booking extends Widget_Base {

	/**
	 * Widget Name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'arc_track_booking';
	}

	/**
	 * Widget Title.
	 *
	 * @return string
	 */
	public function get_title(): string {
		return esc_html__( 'Track & Cancel Booking', 'arc-hotel-core' );
	}

	/**
	 * Widget Icon.
	 *
	 * @return string
	 */
	public function get_icon(): string {
		return 'eicon-form-horizontal';
	}

	/**
	 * Widget Categories.
	 *
	 * @return array
	 */
	public function get_categories(): array {
		return [ 'aristocrats-hotel' ];
	}

	/**
	 * Render widget output.
	 *
	 * @return void
	 */
	protected function render(): void {
		$shortcodes = new Shortcodes();
		echo $shortcodes->render_track_booking(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
