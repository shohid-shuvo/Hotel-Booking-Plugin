<?php
/**
 * Elementor Widget: Search Bar.
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
 * Search Bar Elementor Widget.
 */
class Widget_Search_Bar extends Widget_Base {

	/**
	 * Widget Name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'arc_search_bar';
	}

	/**
	 * Widget Title.
	 *
	 * @return string
	 */
	public function get_title(): string {
		return esc_html__( 'Room Availability Search Bar', 'arc-hotel-core' );
	}

	/**
	 * Widget Icon.
	 *
	 * @return string
	 */
	public function get_icon(): string {
		return 'eicon-search';
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
		echo $shortcodes->render_search_bar(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
