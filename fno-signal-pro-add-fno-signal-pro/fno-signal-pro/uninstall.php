<?php
/**
 * Uninstall handler — removes plugin data.
 *
 * @package FnO_Signal_Pro
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Remove settings.
delete_option( 'fnosp_settings' );
delete_option( 'fnosp_db_version' );

// Remove cache index + tracked transients.
$index = get_option( 'fnosp_cache_index', array() );
if ( is_array( $index ) ) {
	foreach ( array_keys( $index ) as $key ) {
		delete_transient( $key );
	}
}
delete_option( 'fnosp_cache_index' );
delete_option( 'fnosp_alert_state' );
delete_transient( 'fnosp_admin_notice' );

// Clear the alert cron event.
$ts = wp_next_scheduled( 'fnosp_scan_event' );
while ( $ts ) {
	wp_unschedule_event( $ts, 'fnosp_scan_event' );
	$ts = wp_next_scheduled( 'fnosp_scan_event' );
}
$dts = wp_next_scheduled( 'fnosp_digest_event' );
while ( $dts ) {
	wp_unschedule_event( $dts, 'fnosp_digest_event' );
	$dts = wp_next_scheduled( 'fnosp_digest_event' );
}
