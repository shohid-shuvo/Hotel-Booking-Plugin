<?php
/**
 * Elementor Integration Initializer.
 *
 * @package ARC\HotelCore\Elementor
 */

namespace ARC\HotelCore\Elementor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads Elementor category and widgets.
 */
class Elementor_Init {

	/**
	 * Hook Elementor actions.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'elementor/elements/categories_registered', [ $this, 'register_category' ] );
		add_action( 'elementor/widgets/register', [ $this, 'register_widgets' ] );
	}

	/**
	 * Register "Aristocrats Hotel" category in Elementor editor.
	 *
	 * @param \Elementor\Elements_Manager $elements_manager Elements manager.
	 * @return void
	 */
	public function register_category( $elements_manager ): void {
		$elements_manager->add_category(
			'aristocrats-hotel',
			[
				'title' => esc_html__( 'Aristocrats Hotel', 'arc-hotel-core' ),
				'icon'  => 'fa fa-hotel',
			]
		);
	}

	/**
	 * Register custom Elementor widgets.
	 *
	 * @param \Elementor\Widgets_Manager $widgets_manager Widgets manager.
	 * @return void
	 */
	public function register_widgets( $widgets_manager ): void {
		require_once __DIR__ . '/Widgets/Widget_Hotel_Info.php';
		require_once __DIR__ . '/Widgets/Widget_Room_Grid.php';
		require_once __DIR__ . '/Widgets/Widget_Search_Bar.php';
		require_once __DIR__ . '/Widgets/Widget_Search_Results.php';
		require_once __DIR__ . '/Widgets/Widget_Room_Booking.php';
		require_once __DIR__ . '/Widgets/Widget_Track_Booking.php';

		$widgets_manager->register( new Widgets\Widget_Hotel_Info() );
		$widgets_manager->register( new Widgets\Widget_Room_Grid() );
		$widgets_manager->register( new Widgets\Widget_Search_Bar() );
		$widgets_manager->register( new Widgets\Widget_Search_Results() );
		$widgets_manager->register( new Widgets\Widget_Room_Booking() );
		$widgets_manager->register( new Widgets\Widget_Track_Booking() );
	}
}
