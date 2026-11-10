<?php
/**
 * Elementor Widget: Search Results.
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
 * Search Results Elementor Widget.
 */
class Widget_Search_Results extends Widget_Base {

	/**
	 * Widget Name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'arc_search_results';
	}

	/**
	 * Widget Title.
	 *
	 * @return string
	 */
	public function get_title(): string {
		return esc_html__( 'Live Room Search Results', 'arc-hotel-core' );
	}

	/**
	 * Widget Icon.
	 *
	 * @return string
	 */
	public function get_icon(): string {
		return 'eicon-post-list';
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
		echo $shortcodes->render_search_results(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
