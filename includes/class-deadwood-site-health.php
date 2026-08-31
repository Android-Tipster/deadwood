<?php
/**
 * Surfaces the finding inside the WordPress Site Health screen.
 *
 * @package Deadwood
 */

defined( 'ABSPATH' ) || exit;

/**
 * Adds one Site Health test.
 *
 * Site Health is where a site owner already looks when they are worried, and
 * where an agency screenshots from. Reporting there costs one filter and
 * reaches people who would never open another Tools page.
 */
class Deadwood_Site_Health {

	/**
	 * Register the test.
	 */
	public static function init() {
		add_filter( 'site_status_tests', array( __CLASS__, 'register' ) );
	}

	/**
	 * Add the direct test.
	 *
	 * @param array $tests Existing tests.
	 * @return array
	 */
	public static function register( $tests ) {
		$tests['direct']['deadwood_abandoned'] = array(
			'label' => __( 'Abandoned and removed plugins', 'deadwood' ),
			'test'  => array( __CLASS__, 'run' ),
		);

		return $tests;
	}

	/**
	 * Report on the last stored scan.
	 *
	 * This deliberately reads stored results rather than scanning. Site Health
	 * runs on page load, and doing dozens of network calls there would make the
	 * screen crawl for everyone.
	 *
	 * @return array
	 */
	public static function run() {
		$result = array(
			'label'       => __( 'No plugin on this site has been abandoned', 'deadwood' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Security', 'deadwood' ),
				'color' => 'blue',
			),
			'description' => '',
			'actions'     => sprintf(
				'<p><a href="%s">%s</a></p>',
				esc_url( admin_url( 'tools.php?page=deadwood' ) ),
				esc_html__( 'Open the full Deadwood report', 'deadwood' )
			),
			'test'        => 'deadwood_abandoned',
		);

		$store  = new Deadwood_Store();
		$stored = $store->load();

		if ( null === $stored ) {
			$result['status']      = 'recommended';
			$result['label']       = __( 'Deadwood has not scanned this site yet', 'deadwood' );
			$result['description'] = '<p>' . esc_html__(
				'Nothing has been checked, so nothing can be said about it either way.',
				'deadwood'
			) . '</p>';
			return $result;
		}

		$counts  = isset( $stored['summary']['counts'] ) ? $stored['summary']['counts'] : array();
		$closed  = isset( $counts[ Deadwood_Verdict::STATUS_CLOSED ] ) ? (int) $counts[ Deadwood_Verdict::STATUS_CLOSED ] : 0;
		$stale   = isset( $counts[ Deadwood_Verdict::STATUS_ABANDONED ] ) ? (int) $counts[ Deadwood_Verdict::STATUS_ABANDONED ] : 0;
		$unknown = isset( $counts[ Deadwood_Verdict::STATUS_UNKNOWN ] ) ? (int) $counts[ Deadwood_Verdict::STATUS_UNKNOWN ] : 0;

		if ( $closed > 0 ) {
			$result['status'] = 'critical';
			$result['label']  = sprintf(
				/* translators: %d: number of plugins */
				_n(
					'%d plugin here has been pulled from WordPress.org',
					'%d plugins here have been pulled from WordPress.org',
					$closed,
					'deadwood'
				),
				$closed
			);
			$result['description'] = '<p>' . esc_html__(
				'A plugin removed from the directory receives no further updates of any kind, including security releases. WordPress does not tell you when this happens, and the plugin keeps running.',
				'deadwood'
			) . '</p>';

			return $result;
		}

		if ( $stale > 0 ) {
			$result['status'] = 'recommended';
			$result['label']  = sprintf(
				/* translators: %d: number of plugins */
				_n(
					'%d plugin here is no longer maintained',
					'%d plugins here are no longer maintained',
					$stale,
					'deadwood'
				),
				$stale
			);
			$result['description'] = '<p>' . esc_html__(
				'These are still in the directory, but the author has not shipped a release in a long time. That is not proof of a problem on its own, and the report explains what else was found for each one.',
				'deadwood'
			) . '</p>';

			return $result;
		}

		if ( $unknown > 0 ) {
			$result['status'] = 'recommended';
			$result['label']  = sprintf(
				/* translators: %d: number of items */
				_n(
					'%d item could not be checked',
					'%d items could not be checked',
					$unknown,
					'deadwood'
				),
				$unknown
			);
			$result['description'] = '<p>' . esc_html__(
				'The rest of this site looks fine. These particular items were not verified either way, which is different from having passed.',
				'deadwood'
			) . '</p>';

			return $result;
		}

		$result['description'] = '<p>' . esc_html__(
			'Everything installed here is still listed in the WordPress.org directory and has shipped a release recently enough that somebody is clearly watching it.',
			'deadwood'
		) . '</p>';

		return $result;
	}
}
