<?php
/**
 * Logging to WooCommerce > Status > Logs (source "fetch"), plus a short
 * activity list shown on the plugin's settings page.
 */

defined( 'ABSPATH' ) || exit;

class Fetch_WC_Logger {

	const ACTIVITY_OPTION = 'fetch_wc_activity';
	const ACTIVITY_MAX    = 50;

	public static function info( $message, array $context = array() ) {
		self::log( 'info', $message, $context );
	}

	public static function error( $message, array $context = array() ) {
		self::log( 'error', $message, $context );
	}

	private static function log( $level, $message, array $context ) {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->log( $level, $message . ( $context ? ' ' . wp_json_encode( $context ) : '' ), array( 'source' => 'fetch' ) );
		}

		$activity = get_option( self::ACTIVITY_OPTION, array() );
		if ( ! is_array( $activity ) ) {
			$activity = array();
		}
		array_unshift(
			$activity,
			array(
				'time'    => time(),
				'level'   => $level,
				'message' => $message,
			)
		);
		update_option( self::ACTIVITY_OPTION, array_slice( $activity, 0, self::ACTIVITY_MAX ), false );
	}

	public static function activity() {
		$activity = get_option( self::ACTIVITY_OPTION, array() );
		return is_array( $activity ) ? $activity : array();
	}
}
