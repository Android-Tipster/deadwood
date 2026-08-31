<?php
/**
 * Turns directory facts into a status and a score.
 *
 * @package Deadwood
 */

defined( 'ABSPATH' ) || exit;

/**
 * The risk model.
 *
 * Two ideas run through all of it.
 *
 * The first is that removal from the directory is categorically different from
 * slow maintenance. A removal is a decision somebody made about this code, and
 * WordPress tells the site owner nothing about it. Everything else is a matter
 * of degree.
 *
 * The second is that an old release date is a leading indicator, not a verdict.
 * A small plugin can be finished. The model therefore treats staleness on its
 * own as moderate, and reserves the high scores for staleness that comes with
 * corroborating neglect: a support forum nobody answers, or a compatibility
 * claim that has fallen years behind WordPress itself.
 */
class Deadwood_Scoring {

	/* Days since the last release. */
	const AGING_AFTER     = 365;
	const ABANDONED_AFTER = 730;

	/**
	 * Assess one inventory item against what the directory says about it.
	 *
	 * @param array $item   Inventory row from Deadwood_Inventory.
	 * @param array $lookup Result from Deadwood_Registry::lookup().
	 * @return Deadwood_Verdict
	 */
	public function assess( array $item, array $lookup ) {
		$verdict                    = new Deadwood_Verdict( $item['slug'], $item['name'], $item['type'] );
		$verdict->active            = ! empty( $item['active'] );
		$verdict->installed_version = isset( $item['version'] ) ? $item['version'] : '';

		$outcome = isset( $lookup['outcome'] ) ? $lookup['outcome'] : Deadwood_Registry::ERROR;

		if ( Deadwood_Registry::ERROR === $outcome ) {
			$verdict->status         = Deadwood_Verdict::STATUS_UNKNOWN;
			$verdict->score          = 0;
			$verdict->unknown_reason = isset( $lookup['message'] ) ? $lookup['message'] : 'The directory could not be reached.';
			return $verdict;
		}

		if ( Deadwood_Registry::UNLISTED === $outcome ) {
			$verdict->status = Deadwood_Verdict::STATUS_UNLISTED;
			$verdict->score  = 0;
			$verdict->add_factor(
				'unlisted',
				'Not distributed through WordPress.org.',
				'The directory has no entry for this slug and no record of removing one. Commercial, custom and host bundled code all look like this, and none of it is a fault.',
				0
			);
			return $verdict;
		}

		if ( Deadwood_Registry::CLOSED === $outcome ) {
			return $this->assess_closed( $verdict, $lookup );
		}

		return $this->assess_listed( $verdict, isset( $lookup['data'] ) ? $lookup['data'] : array() );
	}

	/**
	 * Score a plugin the directory has removed.
	 *
	 * @param Deadwood_Verdict $verdict Verdict under construction.
	 * @param array            $lookup  Registry result.
	 * @return Deadwood_Verdict
	 */
	private function assess_closed( Deadwood_Verdict $verdict, array $lookup ) {
		$closed = isset( $lookup['closed'] ) ? $lookup['closed'] : array();
		$reason = isset( $closed['reason'] ) ? $closed['reason'] : '';
		$date   = isset( $closed['date'] ) ? $closed['date'] : '';

		$evidence = 'The WordPress.org page for this slug is marked closed.';
		if ( '' !== $date ) {
			$evidence .= ' Closed on ' . $date . '.';
		}
		if ( '' !== $reason ) {
			$evidence .= ' Stated reason: ' . $reason;
		}
		$evidence .= ' A closed plugin receives no further updates, including security releases, and WordPress does not warn you that it happened.';

		$verdict->status = Deadwood_Verdict::STATUS_CLOSED;
		$verdict->score  = 100;
		$verdict->add_factor( 'closed', 'Removed from the WordPress.org directory.', $evidence, 100 );

		if ( $verdict->active ) {
			$verdict->add_factor(
				'closed_and_active',
				'It is running right now.',
				'This plugin is active, so the removed code is executing on every request.',
				0
			);
		}

		$verdict->directory = array(
			'closed_reason' => $reason,
			'closed_date'   => $date,
		);

		return $verdict;
	}

	/**
	 * Score a plugin the directory still lists.
	 *
	 * @param Deadwood_Verdict $verdict Verdict under construction.
	 * @param array            $data    Normalised directory fields.
	 * @return Deadwood_Verdict
	 */
	private function assess_listed( Deadwood_Verdict $verdict, array $data ) {
		$verdict->directory = $data;
		$score              = 0;

		$days = $this->days_since( isset( $data['last_updated'] ) ? $data['last_updated'] : '' );

		if ( null === $days ) {
			$verdict->status         = Deadwood_Verdict::STATUS_UNKNOWN;
			$verdict->unknown_reason = 'The directory did not report a usable release date.';
			return $verdict;
		}

		$years = round( $days / 365, 1 );

		if ( $days >= self::ABANDONED_AFTER ) {
			$weight = $this->staleness_weight( $days );
			$score += $weight;
			$verdict->add_factor(
				'stale',
				sprintf( 'No release in %s years.', $years ),
				sprintf(
					'The last version reached the directory on %s. An old release date is a leading indicator rather than proof of a problem, since a small plugin can simply be finished, but it does mean nobody is watching this code if something is found in it.',
					$this->format_date( $data['last_updated'] )
				),
				$weight
			);
		} elseif ( $days >= self::AGING_AFTER ) {
			$score += 15;
			$verdict->add_factor(
				'slowing',
				sprintf( 'No release in %s years.', $years ),
				sprintf( 'The last version reached the directory on %s.', $this->format_date( $data['last_updated'] ) ),
				15
			);
		}

		$score += $this->score_tested_gap( $verdict, $data );
		$score += $this->score_support( $verdict, $data );
		$score += $this->score_version_drift( $verdict, $data );

		if ( $verdict->active && $score > 0 ) {
			$score += 5;
		}

		$verdict->score = min( 99, $score );

		if ( $days >= self::ABANDONED_AFTER ) {
			$verdict->status = Deadwood_Verdict::STATUS_ABANDONED;
		} elseif ( $days >= self::AGING_AFTER ) {
			$verdict->status = Deadwood_Verdict::STATUS_AGING;
		} else {
			$verdict->status = Deadwood_Verdict::STATUS_HEALTHY;
		}

		if ( ! empty( $data['active_installs'] ) ) {
			$verdict->add_factor(
				'blast_radius',
				sprintf( 'Shared with roughly %s other sites.', number_format_i18n( (int) $data['active_installs'] ) ),
				'Context only, and it carries no score. A widely installed plugin is a more attractive target once a flaw is found, and a rarely installed one is less likely to have anyone looking.',
				0
			);
		}

		return $verdict;
	}

	/**
	 * Points for how long the silence has run.
	 *
	 * @param int $days Days since the last release.
	 * @return int
	 */
	private function staleness_weight( $days ) {
		if ( $days >= 1825 ) {
			return 55;
		}
		if ( $days >= 1460 ) {
			return 45;
		}
		if ( $days >= 1095 ) {
			return 38;
		}
		return 30;
	}

	/**
	 * Points for a compatibility claim that has fallen behind WordPress.
	 *
	 * @param Deadwood_Verdict $verdict Verdict under construction.
	 * @param array            $data    Directory fields.
	 * @return int
	 */
	private function score_tested_gap( Deadwood_Verdict $verdict, array $data ) {
		$tested = isset( $data['tested'] ) ? $data['tested'] : '';
		if ( '' === $tested ) {
			return 0;
		}

		$current = $this->current_wp_version();
		if ( '' === $current ) {
			return 0;
		}

		$gap = $this->major_version_gap( $current, $tested );
		if ( $gap < 2 ) {
			return 0;
		}

		$weight = ( $gap >= 4 ) ? 20 : ( ( $gap >= 3 ) ? 14 : 8 );

		$verdict->add_factor(
			'tested_gap',
			sprintf( 'Untested against the last %d WordPress releases.', $gap ),
			sprintf(
				'The author last declared compatibility with WordPress %s, and this site runs %s. The claim is self reported, so it measures author attention rather than whether the code actually breaks.',
				$tested,
				$current
			),
			$weight
		);

		return $weight;
	}

	/**
	 * Points for a support forum that has stopped being answered.
	 *
	 * @param Deadwood_Verdict $verdict Verdict under construction.
	 * @param array            $data    Directory fields.
	 * @return int
	 */
	private function score_support( Deadwood_Verdict $verdict, array $data ) {
		$threads = isset( $data['support_threads'] ) ? (int) $data['support_threads'] : 0;

		/*
		 * Below five threads the ratio is noise, so it is left alone rather
		 * than reported as neglect on a sample of one.
		 */
		if ( $threads < 5 ) {
			return 0;
		}

		$resolved = isset( $data['support_threads_resolved'] ) ? (int) $data['support_threads_resolved'] : 0;
		$ratio    = $resolved / $threads;

		if ( $ratio >= 0.3 ) {
			return 0;
		}

		$weight = ( $ratio <= 0.1 ) ? 18 : 10;

		$verdict->add_factor(
			'support_neglect',
			sprintf( '%d of %d recent support threads resolved.', $resolved, $threads ),
			'Users are asking questions in the plugin forum and getting few answers, which is what maintenance looks like from the outside when it has stopped.',
			$weight
		);

		return $weight;
	}

	/**
	 * Points for running behind the version the directory offers.
	 *
	 * @param Deadwood_Verdict $verdict Verdict under construction.
	 * @param array            $data    Directory fields.
	 * @return int
	 */
	private function score_version_drift( Deadwood_Verdict $verdict, array $data ) {
		$latest    = isset( $data['version'] ) ? $data['version'] : '';
		$installed = $verdict->installed_version;

		if ( '' === $latest || '' === $installed ) {
			return 0;
		}

		if ( version_compare( $installed, $latest, '>=' ) ) {
			return 0;
		}

		$verdict->add_factor(
			'behind_latest',
			sprintf( 'Running %s while %s is available.', $installed, $latest ),
			'This one is entirely in your hands, and it is the cheapest thing on this page to fix.',
			8
		);

		return 8;
	}

	/**
	 * Days between a directory date string and now.
	 *
	 * @param string $date Directory date such as "2023-04-04 7:15pm GMT".
	 * @return int|null Null when the date cannot be read.
	 */
	private function days_since( $date ) {
		if ( ! is_string( $date ) || '' === trim( $date ) ) {
			return null;
		}

		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})/', trim( $date ), $m ) ) {
			return null;
		}

		$then = gmmktime( 0, 0, 0, (int) $m[2], (int) $m[3], (int) $m[1] );
		if ( false === $then ) {
			return null;
		}

		$days = (int) floor( ( time() - $then ) / DAY_IN_SECONDS );

		return max( 0, $days );
	}

	/**
	 * Render a directory date as a plain calendar date.
	 *
	 * @param string $date Directory date string.
	 * @return string
	 */
	private function format_date( $date ) {
		if ( preg_match( '/^(\d{4}-\d{2}-\d{2})/', (string) $date, $m ) ) {
			return $m[1];
		}
		return (string) $date;
	}

	/**
	 * How many major WordPress releases separate two version strings.
	 *
	 * WordPress numbers majors as x.y, so 6.4 to 6.8 is four releases and
	 * 6.8 to 7.1 counts the rollover rather than treating it as a jump.
	 *
	 * @param string $current Current version.
	 * @param string $tested  Version the author declared.
	 * @return int
	 */
	private function major_version_gap( $current, $tested ) {
		$c = $this->version_parts( $current );
		$t = $this->version_parts( $tested );

		if ( null === $c || null === $t ) {
			return 0;
		}

		if ( $c[0] === $t[0] ) {
			return max( 0, $c[1] - $t[1] );
		}

		/*
		 * Across a major rollover the number of intervening releases is not
		 * knowable from the version strings alone, since nobody knows how many
		 * minors a line shipped. Ten per line is the historical shape of it and
		 * is close enough for a risk band.
		 */
		$gap = ( $c[0] - $t[0] ) * 10 + ( $c[1] - $t[1] );

		return max( 0, $gap );
	}

	/**
	 * Split a version into major and minor integers.
	 *
	 * @param string $version Version string.
	 * @return array{0:int,1:int}|null
	 */
	private function version_parts( $version ) {
		if ( ! preg_match( '/^(\d+)\.(\d+)/', (string) $version, $m ) ) {
			return null;
		}
		return array( (int) $m[1], (int) $m[2] );
	}

	/**
	 * The WordPress version this site runs.
	 *
	 * @return string
	 */
	private function current_wp_version() {
		global $wp_version;
		return isset( $wp_version ) ? (string) $wp_version : '';
	}
}
