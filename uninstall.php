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
	'fetch_wc_bulk_failed',
	'fetch_wc_mirror_pending',
	'fetch_wc_seen_events',
	'fetch_wc_store_status',
) as $option ) {
	delete_option( $option );
}
delete_transient( 'fetch_wc_access_token' );
// Order-import locks left by a request that died mid-import, and the queues
// of every Fetch store other than the first (their names end in "_<store id>").
foreach ( array( 'fetch\\_wc\\_lock\\_%', 'fetch\\_wc\\_pending\\_products\\_%', 'fetch\\_wc\\_pending\\_deactivations\\_%', 'fetch\\_wc\\_bulk\\_failed\\_%', 'fetch\\_wc\\_mirror\\_pending\\_%' ) as $pattern ) {
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '{$pattern}'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( '', array(), 'fetch-for-woocommerce' );
}
