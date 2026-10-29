<?php
/**
 * Plugin Name:       Fetch for WooCommerce
 * Plugin URI:        https://developer.fetch.com.cy
 * Description:       Sell your WooCommerce catalogue on Fetch (fetch.com.cy). Products, prices and stock sync to Fetch automatically; Fetch orders arrive as WooCommerce orders you can accept, mark ready or reject.
 * Version:           1.0.0
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            Fetch
 * Author URI:        https://fetch.com.cy
 * License:           GPL-2.0-or-later
 * Text Domain:       fetch-for-woocommerce
 * WC requires at least: 8.0
 * WC tested up to:   9.9
 */

defined( 'ABSPATH' ) || exit;

define( 'FETCH_WC_VERSION', '1.0.0' );
define( 'FETCH_WC_FILE', __FILE__ );
define( 'FETCH_WC_DIR', plugin_dir_path( __FILE__ ) );

require_once FETCH_WC_DIR . 'includes/class-fetch-wc-settings.php';
require_once FETCH_WC_DIR . 'includes/class-fetch-wc-logger.php';
require_once FETCH_WC_DIR . 'includes/class-fetch-wc-api.php';
require_once FETCH_WC_DIR . 'includes/class-fetch-wc-product-mapper.php';
require_once FETCH_WC_DIR . 'includes/class-fetch-wc-product-sync.php';
require_once FETCH_WC_DIR . 'includes/class-fetch-wc-order-mapper.php';
require_once FETCH_WC_DIR . 'includes/class-fetch-wc-order-sync.php';
require_once FETCH_WC_DIR . 'includes/class-fetch-wc-webhook.php';
require_once FETCH_WC_DIR . 'includes/class-fetch-wc-order-actions.php';
require_once FETCH_WC_DIR . 'includes/class-fetch-wc-admin.php';
require_once FETCH_WC_DIR . 'includes/class-fetch-wc-plugin.php';

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

add_action( 'plugins_loaded', array( 'Fetch_WC_Plugin', 'boot' ) );

register_activation_hook( __FILE__, array( 'Fetch_WC_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Fetch_WC_Plugin', 'deactivate' ) );
