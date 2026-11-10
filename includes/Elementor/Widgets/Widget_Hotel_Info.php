<?php
/**
 * Elementor Widget: Hotel Info.
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
 * Hotel Info Elementor Widget.
 */
class Widget_Hotel_Info extends Widget_Base {

	/**
	 * Widget Name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'arc_hotel_info';
	}

	/**
	 * Widget Title.
	 *
	 * @return string
	 */
	public function get_title(): string {
		return esc_html__( 'Hotel Info', 'arc-hotel-core' );
	}

	/**
	 * Widget Icon.
	 *
	 * @return string
	 */
	public function get_icon(): string {
		return 'eicon-info-box';
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
			'content_section',
			[
				'label' => esc_html__( 'Hotel Details', 'arc-hotel-core' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			]
		);

		$this->add_control(
			'info_field',
			[
				'label'   => esc_html__( 'Information Field', 'arc-hotel-core' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'phone',
				'options' => [
					'hotelName'     => esc_html__( 'Hotel Name', 'arc-hotel-core' ),
					'phone'         => esc_html__( 'Phone Number', 'arc-hotel-core' ),
					'email'         => esc_html__( 'Email Address', 'arc-hotel-core' ),
					'address'       => esc_html__( 'Physical Address', 'arc-hotel-core' ),
					'times'         => esc_html__( 'Check-in & Check-out Times', 'arc-hotel-core' ),
					'contactPerson' => esc_html__( 'Contact Person', 'arc-hotel-core' ),
				],
			]
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'style_section',
			[
				'label' => esc_html__( 'Style', 'arc-hotel-core' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_control(
			'text_color',
			[
				'label'     => esc_html__( 'Text Color', 'arc-hotel-core' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .arc-hotel-info-output' => 'color: {{VALUE}};',
				],
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
		$field    = $settings['info_field'] ?? 'phone';

		$shortcodes = new Shortcodes();
		echo '<div class="arc-hotel-info-output">';
		echo $shortcodes->render_hotel_info( [ 'field' => $field ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';
	}
}
