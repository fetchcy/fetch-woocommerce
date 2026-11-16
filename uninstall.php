<?php
/**
 * Removes the plugin's settings and queues. Product and order meta (_fetch_*)
 * stays, so reinstalling reconnects to the same Fetch products and orders.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

foreach ( array(
	'fetch_wc_settings',
	'fetch_wc_activity',
	'fetch_wc_pending_products',
	'fetch_wc_pending_deactivations',
	'fetch_wc_seen_events',
) as $option ) {
	delete_option( $option );
}
delete_transient( 'fetch_wc_access_token' );
// Order-import locks left by a request that died mid-import.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'fetch\\_wc\\_lock\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( '', array(), 'fetch-for-woocommerce' );
}
