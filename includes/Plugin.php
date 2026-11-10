<?php
/**
 * Main Plugin Orchestrator.
 *
 * @package ARC\HotelCore
 */

namespace ARC\HotelCore;

use ARC\HotelCore\Settings\Settings_Page;
use ARC\HotelCore\Api\Api_Logger;
use ARC\HotelCore\Rooms\Room_Post_Type;
use ARC\HotelCore\Rooms\Room_Sync;
use ARC\HotelCore\Cart\Cart_Guard;
use ARC\HotelCore\Checkout\Checkout_Fields;
use ARC\HotelCore\Checkout\Checkout_Validation;
use ARC\HotelCore\Payment\Gateway_Filter;
use ARC\HotelCore\Booking\Reservation_Sync;
use ARC\HotelCore\Admin\Order_Meta_Box;
use ARC\HotelCore\Admin\Orders_Columns;
use ARC\HotelCore\Track\Track_Controller;
use ARC\HotelCore\Elementor\Shortcodes;
use ARC\HotelCore\Elementor\Elementor_Init;
use ARC\HotelCore\Elementor\Room_Booking_Form;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main Plugin Singleton.
 */
class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	protected static ?self $instance = null;

	/**
	 * Get active plugin instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Protected constructor.
	 */
	protected function __construct() {
		$this->init_hooks();
		$this->init_modules();
	}

	/**
	 * Register core hooks.
	 *
	 * @return void
	 */
	protected function init_hooks(): void {
		add_action( 'init', [ $this, 'load_textdomain' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend_scripts' ] );
		add_action( 'arc_daily_log_purge', [ $this, 'purge_logs' ] );
		add_action( 'admin_notices', [ $this, 'check_environment_notices' ] );
	}

	/**
	 * Load plugin textdomain for translations.
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'arc-hotel-core',
			false,
			dirname( plugin_basename( ARC_PLUGIN_FILE ) ) . '/languages'
		);
	}

	/**
	 * Enqueue global frontend assets.
	 *
	 * @return void
	 */
	public function enqueue_frontend_scripts(): void {
		wp_enqueue_style(
			'arc-hotel-core-css',
			ARC_PLUGIN_URL . 'assets/css/hotel-core.css',
			[],
			ARC_VERSION
		);

		wp_enqueue_script(
			'arc-hotel-search-js',
			ARC_PLUGIN_URL . 'assets/js/hotel-search.js',
			[ 'jquery' ],
			ARC_VERSION,
			true
		);

		wp_localize_script(
			'arc-hotel-search-js',
			'arcSearchSettings',
			[
				'maxStay'    => \ARC\HotelCore\Settings\Settings::get( 'max_stay_nights', 30 ),
				'isLoggedIn' => is_user_logged_in(),
				'restUrl'    => esc_url_raw( rest_url( 'arc/v1/fresh-nonces' ) ),
			]
		);
	}

	/**
	 * Initialize plugin submodules.
	 *
	 * @return void
	 */
	protected function init_modules(): void {
		// Rooms CPT and Sync
		( new Room_Post_Type() )->init();
		( new Room_Sync() )->init();

		// Shortcodes & Elementor
		( new Shortcodes() )->init();
		Room_Booking_Form::init();
		if ( did_action( 'elementor/loaded' ) || class_exists( '\Elementor\Plugin' ) ) {
			( new Elementor_Init() )->init();
		}

		// Cart Guard & Price Authority
		( new Cart_Guard() )->init();

		// Cache Safety for Full-Page Cache Plugins
		( new \ARC\HotelCore\Cache\Cache_Safety() )->init();

		// Checkout Mode, Scoped Fields, and Anti-Double-Booking Validation
		( new Checkout_Fields() )->init();
		( new Checkout_Validation() )->init();

		// Payment Gateways & Filtering
		( new Gateway_Filter() )->init();

		// Reservation Synchronization Engine
		( new Reservation_Sync() )->init();
		( new \ARC\HotelCore\Booking\Reconcile() )->init();

		// Custom Emails
		( new \ARC\HotelCore\Emails\Email_Manager() )->init();

		// Track & Cancel Controller
		( new Track_Controller() )->init();

		// Admin Order Screens & Settings
		if ( is_admin() ) {
			( new Settings_Page() )->init();
			( new Order_Meta_Box() )->init();
			( new Orders_Columns() )->init();
			( new \ARC\HotelCore\Admin\Log_Page() )->init();
			( new \ARC\HotelCore\Admin\Notices() )->init();
		}
	}

	/**
	 * Plugin activation routine.
	 *
	 * @return void
	 */
	public static function activate(): void {
		// 1. Install API Log Table
		Api_Logger::install_table();

		// 2. Schedule daily log purge cron if not scheduled
		if ( ! wp_next_scheduled( 'arc_daily_log_purge' ) ) {
			wp_schedule_event( time(), 'daily', 'arc_daily_log_purge' );
		}

		// Schedule daily reconciliation
		if ( ! wp_next_scheduled( 'arc_daily_reconcile' ) ) {
			wp_schedule_event( time(), 'daily', 'arc_daily_reconcile' );
		}

		// 3. Ensure WooCommerce virtual room product container exists
		self::ensure_room_container_product();

		// 4. Initial room categories sync
		if ( class_exists( '\ARC\HotelCore\Rooms\Room_Sync' ) ) {
			Room_Sync::sync_all();
		}

		// Clear rewrite rules
		flush_rewrite_rules();
	}

	/**
	 * Plugin deactivation routine.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		// Clear scheduled cron events
		$timestamp = wp_next_scheduled( 'arc_daily_log_purge' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'arc_daily_log_purge' );
		}

		$rec_timestamp = wp_next_scheduled( 'arc_daily_reconcile' );
		if ( $rec_timestamp ) {
			wp_unschedule_event( $rec_timestamp, 'arc_daily_reconcile' );
		}

		flush_rewrite_rules();
	}

	/**
	 * Action callback for daily log purge.
	 *
	 * @return void
	 */
	public function purge_logs(): void {
		Api_Logger::purge_old_logs( 30 );
	}

	/**
	 * Ensure the hidden virtual WooCommerce room container product exists.
	 *
	 * @return int Product ID.
	 */
	public static function ensure_room_container_product(): int {
		if ( ! class_exists( 'WC_Product_Simple' ) ) {
			return 0;
		}

		// Check if product already exists
		$existing_id = (int) get_option( 'arc_room_product_id', 0 );
		if ( $existing_id > 0 && get_post( $existing_id ) ) {
			return $existing_id;
		}

		// Look up by meta
		$query = new \WP_Query( [
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => [
				[
					'key'   => '_is_hotel_room',
					'value' => 'yes',
				],
			],
		] );

		if ( ! empty( $query->posts ) ) {
			$found_id = (int) $query->posts[0];
			update_option( 'arc_room_product_id', $found_id, 'no' );
			return $found_id;
		}

		// Ensure category 'hotel-rooms' exists
		$cat = get_term_by( 'slug', 'hotel-rooms', 'product_cat' );
		if ( ! $cat ) {
			$cat_res = wp_insert_term( 'Hotel Rooms', 'product_cat', [ 'slug' => 'hotel-rooms' ] );
			$cat_id  = is_array( $cat_res ) ? $cat_res['term_id'] : 0;
		} else {
			$cat_id = $cat->term_id;
		}

		// Create virtual product
		$product = new \WC_Product_Simple();
		$product->set_name( 'Hotel Room Reservation' );
		$product->set_status( 'publish' );
		$product->set_catalog_visibility( 'hidden' ); // Hidden from catalog and search
		$product->set_virtual( true );
		$product->set_sold_individually( true );
		$product->set_tax_status( 'none' );
		$product->set_price( 0 );
		$product->set_regular_price( 0 );
		$product->set_description( 'Auto-generated container product for hotel room reservations.' );

		if ( $cat_id > 0 ) {
			$product->set_category_ids( [ $cat_id ] );
		}

		$product_id = $product->save();

		if ( $product_id ) {
			update_post_meta( $product_id, '_is_hotel_room', 'yes' );
			update_option( 'arc_room_product_id', $product_id, 'no' );
		}

		return (int) $product_id;
	}

	/**
	 * Display admin notices for required dependencies or misconfigurations.
	 *
	 * @return void
	 */
	public function check_environment_notices(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		if ( ! class_exists( 'WooCommerce' ) ) {
			?>
			<div class="notice notice-error">
				<p>
					<strong><?php esc_html_e( 'Aristocrats Hotel Core', 'arc-hotel-core' ); ?>:</strong>
					<?php esc_html_e( 'WooCommerce is required for hotel bookings and payments. Please install and activate WooCommerce.', 'arc-hotel-core' ); ?>
				</p>
			</div>
			<?php
		}
	}
}
