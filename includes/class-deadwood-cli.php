<?php
/**
 * WP-CLI commands.
 *
 * @package Deadwood
 */

defined( 'ABSPATH' ) || exit;

/**
 * Check a site, or a fleet of them, from the command line.
 *
 * This is the entry point that matters for anyone running more than one site.
 * A person maintaining eighty client installs is never going to click through
 * eighty dashboards, and that person is the one who most needs the answer.
 */
class Deadwood_CLI {

	/**
	 * Scan this site and print what was found.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * [--all]
	 * : Include healthy items. Off by default, since the point is the exceptions.
	 *
	 * [--fresh]
	 * : Ignore cached directory lookups.
	 *
	 * [--fail-on=<status>]
	 * : Exit non zero when anything is at or above this severity. Useful in CI.
	 * ---
	 * options:
	 *   - closed
	 *   - abandoned
	 *   - aging
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp deadwood scan
	 *     wp deadwood scan --all --format=json
	 *     wp deadwood scan --fail-on=closed
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags.
	 */
	public function scan( $args, $assoc_args ) {
		$format  = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';
		$all     = isset( $assoc_args['all'] );
		$fresh   = isset( $assoc_args['fresh'] );
		$fail_on = isset( $assoc_args['fail-on'] ) ? $assoc_args['fail-on'] : '';

		$scanner = new Deadwood_Scanner();
		$store   = new Deadwood_Store();

		WP_CLI::log( 'Checking installed plugins and themes against the WordPress.org directory.' );

		$result = $scanner->scan( $fresh );
		$news   = $store->changes_worth_reporting( $result['verdicts'] );
		$store->save( $result['verdicts'], $result['summary'] );

		$rows = array();

		foreach ( $result['verdicts'] as $v ) {
			if ( ! $all && in_array( $v->status, array( Deadwood_Verdict::STATUS_HEALTHY, Deadwood_Verdict::STATUS_UNLISTED ), true ) ) {
				continue;
			}

			$labels = array();
			foreach ( $v->factors as $factor ) {
				if ( (int) $factor['weight'] > 0 || 'closed' === $factor['code'] ) {
					$labels[] = $factor['label'];
				}
			}

			if ( $v->is_unknown() ) {
				$labels[] = $v->unknown_reason;
			}

			$rows[] = array(
				'name'    => $v->name,
				'slug'    => $v->slug,
				'type'    => $v->type,
				'status'  => $v->status,
				'score'   => $v->score,
				'active'  => $v->active ? 'yes' : 'no',
				'finding' => implode( ' ', $labels ),
			);
		}

		if ( empty( $rows ) ) {
			WP_CLI::success( 'Nothing to report. Everything installed here is listed and maintained.' );
		} else {
			WP_CLI\Utils\format_items( $format, $rows, array( 'name', 'slug', 'type', 'status', 'score', 'active', 'finding' ) );
		}

		$counts = $result['summary']['counts'];

		WP_CLI::log(
			sprintf(
				'pulled %d, unmaintained %d, slowing %d, healthy %d, not listed %d, unchecked %d',
				$counts[ Deadwood_Verdict::STATUS_CLOSED ],
				$counts[ Deadwood_Verdict::STATUS_ABANDONED ],
				$counts[ Deadwood_Verdict::STATUS_AGING ],
				$counts[ Deadwood_Verdict::STATUS_HEALTHY ],
				$counts[ Deadwood_Verdict::STATUS_UNLISTED ],
				$counts[ Deadwood_Verdict::STATUS_UNKNOWN ]
			)
		);

		if ( ! empty( $news ) ) {
			WP_CLI::warning( sprintf( '%d change since the last scan. See the report for detail.', count( $news ) ) );
		}

		if ( '' !== $fail_on ) {
			$this->maybe_fail( $fail_on, $counts );
		}
	}

	/**
	 * Exit non zero when the run breached the requested threshold.
	 *
	 * @param string $fail_on Threshold.
	 * @param array  $counts  Status counts.
	 */
	private function maybe_fail( $fail_on, array $counts ) {
		$ladder = array(
			'closed'    => array( Deadwood_Verdict::STATUS_CLOSED ),
			'abandoned' => array( Deadwood_Verdict::STATUS_CLOSED, Deadwood_Verdict::STATUS_ABANDONED ),
			'aging'     => array( Deadwood_Verdict::STATUS_CLOSED, Deadwood_Verdict::STATUS_ABANDONED, Deadwood_Verdict::STATUS_AGING ),
		);

		if ( ! isset( $ladder[ $fail_on ] ) ) {
			return;
		}

		$hits = 0;
		foreach ( $ladder[ $fail_on ] as $status ) {
			$hits += isset( $counts[ $status ] ) ? (int) $counts[ $status ] : 0;
		}

		if ( $hits > 0 ) {
			WP_CLI::error( sprintf( '%d item at or above "%s".', $hits, $fail_on ) );
		}
	}

	/**
	 * Print the stored summary without scanning.
	 *
	 * ## EXAMPLES
	 *
	 *     wp deadwood status
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags.
	 */
	public function status( $args, $assoc_args ) {
		$store  = new Deadwood_Store();
		$stored = $store->load();

		if ( null === $stored ) {
			WP_CLI::warning( 'No scan stored yet. Run: wp deadwood scan' );
			return;
		}

		$summary = $stored['summary'];
		$counts  = isset( $summary['counts'] ) ? $summary['counts'] : array();

		$rows = array();
		foreach ( $counts as $status => $count ) {
			$rows[] = array(
				'status' => $status,
				'count'  => $count,
			);
		}

		WP_CLI\Utils\format_items( 'table', $rows, array( 'status', 'count' ) );

		if ( ! empty( $summary['scanned_at'] ) ) {
			WP_CLI::log( sprintf( 'Scanned %s ago.', human_time_diff( (int) $summary['scanned_at'], time() ) ) );
		}
	}
}
