<?php
/**
 * Plugin Name: Aristocrats Hotel Core
 * Plugin URI:  https://dusrasoftltd.com
 * Description: Enterprise ASP.NET Hotel Management API integration for WordPress, WooCommerce, and Elementor Pro.
 * Version:     1.0.0
 * Author:      DUSRA Soft Limited
 * Author URI:  https://dusrasoftltd.com
 * Text Domain: arc-hotel-core
 * Domain Path: /languages
 * Requires at least: 6.2
 * Requires PHP: 8.2
 * WC requires at least: 8.0
 *
 * @package ARC\HotelCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define core constants
define( 'ARC_VERSION', '1.0.0' );
define( 'ARC_PLUGIN_FILE', __FILE__ );
define( 'ARC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ARC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Declare WooCommerce HPOS and Feature compatibility
add_action( 'before_woocommerce_init', function() {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		// Compatible with High-Performance Order Storage (HPOS)
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', ARC_PLUGIN_FILE, true );
		// Classic shortcode checkout supported; Block Checkout is intentionally unsupported in v1
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', ARC_PLUGIN_FILE, false );
	}
} );

// Register Autoloader
require_once ARC_PLUGIN_DIR . 'includes/Autoloader.php';
\ARC\HotelCore\Autoloader::register();

// Register activation and deactivation hooks
register_activation_hook( ARC_PLUGIN_FILE, [ '\ARC\HotelCore\Plugin', 'activate' ] );
register_deactivation_hook( ARC_PLUGIN_FILE, [ '\ARC\HotelCore\Plugin', 'deactivate' ] );

// Bootstrap the plugin on plugins_loaded
add_action( 'plugins_loaded', function() {
	\ARC\HotelCore\Plugin::instance();
} );
