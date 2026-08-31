<?php
/**
 * Weekly background scan.
 *
 * @package Deadwood
 */

defined( 'ABSPATH' ) || exit;

/**
 * Owns the cron event and the activation lifecycle.
 */
class Deadwood_Scheduler {

	const HOOK = 'deadwood_weekly_scan';

	/**
	 * Hook the scheduled event.
	 */
	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
	}

	/**
	 * Schedule the weekly scan and run a first pass shortly after activation.
	 *
	 * The first scan is deferred rather than run inline, because activation
	 * should not block on a few dozen network round trips.
	 */
	public static function on_activate() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'weekly', self::HOOK );
		}
	}

	/**
	 * Clear the scheduled event.
	 */
	public static function on_deactivate() {
		$timestamp = wp_next_scheduled( self::HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
		}
	}

	/**
	 * Run a scan, store it, and send an alert if anything changed.
	 *
	 * @return array Summary of the run.
	 */
	public static function run() {
		$scanner = new Deadwood_Scanner();
		$store   = new Deadwood_Store();

		$result = $scanner->scan();
		$news   = $store->changes_worth_reporting( $result['verdicts'] );

		$store->save( $result['verdicts'], $result['summary'] );

		if ( ! empty( $news ) ) {
			$notifier = new Deadwood_Notifier();
			$notifier->send( $news, $result['summary'] );
		}

		/**
		 * Fires after a scheduled scan completes.
		 *
		 * @param array $result Verdicts and summary.
		 * @param array $news   Changes worth reporting.
		 */
		do_action( 'deadwood_scan_complete', $result, $news );

		return $result['summary'];
	}
}
