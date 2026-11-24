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
		add_action( 'elementor/theme/register_locations', [ $this, 'register_locations' ] );
		add_filter( 'upload_mimes', [ $this, 'allow_json_mime' ] );
		add_filter( 'wp_check_filetype_and_ext', [ $this, 'correct_json_filetype' ], 10, 4 );
	}

	/**
	 * Register core Elementor theme locations so Theme Builder recognizes single and archive.
	 *
	 * @param \ElementorPro\Modules\ThemeBuilder\Classes\Locations_Manager $elementor_theme_manager Theme manager.
	 * @return void
	 */
	public function register_locations( $elementor_theme_manager ): void {
		if ( is_object( $elementor_theme_manager ) && method_exists( $elementor_theme_manager, 'register_all_core_location' ) ) {
			$elementor_theme_manager->register_all_core_location();
		}
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

	/**
	 * Allow JSON MIME type for administrators during Elementor template imports.
	 *
	 * @param array $mimes Current allowed MIME types.
	 * @return array Modified MIME types.
	 */
	public function allow_json_mime( array $mimes ): array {
		if ( current_user_can( 'manage_options' ) || current_user_can( 'manage_woocommerce' ) ) {
			$mimes['json'] = 'application/json';
		}
		return $mimes;
	}

	/**
	 * Ensure WordPress filetype checker properly recognizes JSON extensions.
	 *
	 * @param array      $data     Filetype data.
	 * @param string     $file     Full path to the file.
	 * @param string     $filename The name of the file.
	 * @param array|null $mimes    Array of allowed MIME types.
	 * @return array
	 */
	public function correct_json_filetype( array $data, string $file, string $filename, ?array $mimes = null ): array {
		if ( ! empty( $data['ext'] ) && ! empty( $data['type'] ) ) {
			return $data;
		}

		$ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		if ( 'json' === $ext && ( current_user_can( 'manage_options' ) || current_user_can( 'manage_woocommerce' ) ) ) {
			$data['ext']  = 'json';
			$data['type'] = 'application/json';
		}

		return $data;
	}
}
