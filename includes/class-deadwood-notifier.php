<?php
/**
 * Sends the one email this plugin is allowed to send.
 *
 * @package Deadwood
 */

defined( 'ABSPATH' ) || exit;

/**
 * Email alerts for changes, not for standing state.
 *
 * The rule here is that an alert only goes out when something happened. A
 * weekly note repeating risks the owner has already seen is the fastest way to
 * train somebody to filter your plugin into the bin, and then the one message
 * that mattered gets filtered with it.
 */
class Deadwood_Notifier {

	/**
	 * Send an alert about what changed.
	 *
	 * @param array $news    Changes from Deadwood_Store.
	 * @param array $summary Scan summary.
	 * @return bool Whether the mail was handed to WordPress successfully.
	 */
	public function send( array $news, array $summary ) {
		if ( empty( $news ) ) {
			return false;
		}

		$store    = new Deadwood_Store();
		$settings = $store->settings();

		if ( empty( $settings['email_enabled'] ) ) {
			return false;
		}

		$to = ! empty( $settings['email_recipient'] ) ? $settings['email_recipient'] : get_option( 'admin_email' );

		if ( ! is_email( $to ) ) {
			return false;
		}

		$closed = array_filter(
			$news,
			static function ( $item ) {
				return 'closed' === $item['kind'];
			}
		);

		$site = wp_parse_url( home_url(), PHP_URL_HOST );

		if ( ! empty( $closed ) ) {
			$subject = sprintf(
				/* translators: 1: count, 2: site host */
				_n(
					'%1$d plugin on %2$s was pulled from WordPress.org',
					'%1$d plugins on %2$s were pulled from WordPress.org',
					count( $closed ),
					'deadwood'
				),
				count( $closed ),
				$site
			);
		} else {
			$subject = sprintf(
				/* translators: 1: count, 2: site host */
				_n(
					'%1$d plugin on %2$s is no longer maintained',
					'%1$d plugins on %2$s are no longer maintained',
					count( $news ),
					'deadwood'
				),
				count( $news ),
				$site
			);
		}

		return wp_mail( $to, $subject, $this->body( $news, $summary ), array( 'Content-Type: text/plain; charset=UTF-8' ) );
	}

	/**
	 * Compose the message.
	 *
	 * @param array $news    Changes.
	 * @param array $summary Scan summary.
	 * @return string
	 */
	private function body( array $news, array $summary ) {
		$lines = array();

		$lines[] = sprintf( 'Deadwood checked %s and found something that changed.', home_url( '/' ) );
		$lines[] = '';

		foreach ( $news as $item ) {
			$v = $item['verdict'];

			if ( 'closed' === $item['kind'] ) {
				$lines[] = sprintf( '[REMOVED] %s', $v->name );
				$lines[] = '  This plugin has been pulled from the WordPress.org directory.';
				$lines[] = '  It will receive no further updates of any kind, including security fixes.';

				$reason = isset( $v->directory['closed_reason'] ) ? $v->directory['closed_reason'] : '';
				$date   = isset( $v->directory['closed_date'] ) ? $v->directory['closed_date'] : '';

				if ( '' !== $date ) {
					$lines[] = sprintf( '  Closed on: %s', $date );
				}
				if ( '' !== $reason ) {
					$lines[] = sprintf( '  Stated reason: %s', $reason );
				}
				$lines[] = sprintf( '  Currently active on this site: %s', $v->active ? 'yes' : 'no' );
			} elseif ( 'abandoned' === $item['kind'] ) {
				$lines[] = sprintf( '[UNMAINTAINED] %s', $v->name );
				$lines[] = '  The author has stopped shipping updates for this plugin.';
			} else {
				$lines[] = sprintf( '[NEW, ALREADY AT RISK] %s', $v->name );
				$lines[] = '  This was installed since the last scan and already carries a problem.';
			}

			foreach ( $v->factors as $factor ) {
				if ( 0 === (int) $factor['weight'] && 'closed' !== $factor['code'] ) {
					continue;
				}
				$lines[] = sprintf( '  %s', $factor['label'] );
			}

			$lines[] = '';
		}

		$counts  = isset( $summary['counts'] ) ? $summary['counts'] : array();
		$lines[] = 'Where the whole site stands:';
		$lines[] = sprintf(
			'  removed from the directory: %d, unmaintained: %d, slowing down: %d, healthy: %d',
			isset( $counts[ Deadwood_Verdict::STATUS_CLOSED ] ) ? $counts[ Deadwood_Verdict::STATUS_CLOSED ] : 0,
			isset( $counts[ Deadwood_Verdict::STATUS_ABANDONED ] ) ? $counts[ Deadwood_Verdict::STATUS_ABANDONED ] : 0,
			isset( $counts[ Deadwood_Verdict::STATUS_AGING ] ) ? $counts[ Deadwood_Verdict::STATUS_AGING ] : 0,
			isset( $counts[ Deadwood_Verdict::STATUS_HEALTHY ] ) ? $counts[ Deadwood_Verdict::STATUS_HEALTHY ] : 0
		);

		$unknown = isset( $counts[ Deadwood_Verdict::STATUS_UNKNOWN ] ) ? $counts[ Deadwood_Verdict::STATUS_UNKNOWN ] : 0;
		if ( $unknown > 0 ) {
			$lines[] = sprintf( '  could not be checked: %d (these are not passes, and the report says why for each)', $unknown );
		}

		$lines[] = '';
		$lines[] = sprintf( 'Full report: %s', admin_url( 'tools.php?page=deadwood' ) );
		$lines[] = '';
		$lines[] = 'To stop these emails, go to Tools then Deadwood and turn off alerts.';

		return implode( "\n", $lines );
	}
}
