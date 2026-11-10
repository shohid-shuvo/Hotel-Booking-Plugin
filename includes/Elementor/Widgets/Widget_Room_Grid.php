<?php
/**
 * Elementor Widget: Room Types Grid.
 *
 * @package ARC\HotelCore\Elementor\Widgets
 */

namespace ARC\HotelCore\Elementor\Widgets;

use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use ARC\HotelCore\Elementor\Shortcodes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Room Types Grid Elementor Widget.
 */
class Widget_Room_Grid extends Widget_Base {

	/**
	 * Widget Name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'arc_room_grid';
	}

	/**
	 * Widget Title.
	 *
	 * @return string
	 */
	public function get_title(): string {
		return esc_html__( 'Room Showcase Grid', 'arc-hotel-core' );
	}

	/**
	 * Widget Icon.
	 *
	 * @return string
	 */
	public function get_icon(): string {
		return 'eicon-gallery-grid';
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
	 * Register controls.
	 *
	 * @return void
	 */
	protected function register_controls(): void {
		$this->start_controls_section(
			'layout_section',
			[
				'label' => esc_html__( 'Grid Layout', 'arc-hotel-core' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			]
		);

		$this->add_control(
			'columns',
			[
				'label'   => esc_html__( 'Columns', 'arc-hotel-core' ),
				'type'    => Controls_Manager::SELECT,
				'default' => '3',
				'options' => [
					'1' => '1',
					'2' => '2',
					'3' => '3',
					'4' => '4',
				],
			]
		);

		$this->add_control(
			'limit',
			[
				'label'   => esc_html__( 'Max Rooms to Display', 'arc-hotel-core' ),
				'type'    => Controls_Manager::NUMBER,
				'default' => 6,
				'min'     => 1,
				'max'     => 50,
			]
		);

		$this->add_control(
			'category',
			[
				'label'       => esc_html__( 'Filter by Category', 'arc-hotel-core' ),
				'type'        => Controls_Manager::TEXT,
				'description' => esc_html__( 'Leave empty to display all rooms, or enter a category slug.', 'arc-hotel-core' ),
				'default'     => '',
			]
		);

		$this->end_controls_section();
	}

	/**
	 * Render widget output.
	 *
	 * @return void
	 */
	protected function render(): void {
		$settings = $this->get_settings_for_display();
		$columns  = absint( $settings['columns'] ?? 3 );
		$limit    = (int) ( $settings['limit'] ?? 6 );
		$category = sanitize_title( $settings['category'] ?? '' );

		$shortcodes = new Shortcodes();
		echo $shortcodes->render_rooms_grid( [
			'columns'  => $columns,
			'limit'    => $limit,
			'category' => $category,
		] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
