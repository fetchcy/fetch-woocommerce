<?php
/**
 * Wires the plugin together once WooCommerce is loaded.
 */

defined( 'ABSPATH' ) || exit;

class Fetch_WC_Plugin {

	const RETRY_HOOK = 'fetch_wc_retry_pending';

	public static function boot() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'missing_woocommerce_notice' ) );
			return;
		}

		Fetch_WC_Product_Sync::init();
		Fetch_WC_Order_Sync::init();
		Fetch_WC_Webhook::init();
		Fetch_WC_Order_Actions::init();
		Fetch_WC_Admin::init();

		add_action( self::RETRY_HOOK, array( 'Fetch_WC_Product_Sync', 'retry_pending' ) );
		// Action Scheduler is ready on `init`; scheduling earlier is a no-op.
		add_action( 'init', array( __CLASS__, 'schedule' ) );
	}

	public static function schedule() {
		if ( ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}
		Fetch_WC_Order_Sync::schedule_poll();
		if ( ! as_has_scheduled_action( self::RETRY_HOOK, array(), Fetch_WC_Product_Sync::GROUP ) ) {
			as_schedule_recurring_action( time() + HOUR_IN_SECONDS, HOUR_IN_SECONDS, self::RETRY_HOOK, array(), Fetch_WC_Product_Sync::GROUP );
		}
	}

	public static function missing_woocommerce_notice() {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Fetch for WooCommerce needs WooCommerce to be installed and active.', 'fetch-for-woocommerce' ) . '</p></div>';
	}

	public static function activate() {
		Fetch_WC_Settings::update( array() );
	}

	public static function deactivate() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), Fetch_WC_Product_Sync::GROUP );
		}
		Fetch_WC_Api::forget_token();
	}
}
