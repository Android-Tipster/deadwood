<?php
/**
 * Removes everything Deadwood stored.
 *
 * @package Deadwood
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'deadwood_results' );
delete_option( 'deadwood_settings' );

/*
 * Cached directory lookups are transients rather than options, so they are
 * cleared directly. On a site with an external object cache the transients
 * never reached the database, and the delete below is simply a no op.
 */
global $wpdb;

$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_deadwood_reg_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_deadwood_reg_' ) . '%'
	)
);

wp_clear_scheduled_hook( 'deadwood_weekly_scan' );
