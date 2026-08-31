<?php
/**
 * Persists scan results and works out what changed since the last run.
 *
 * @package Deadwood
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stores the most recent scan and answers what is new.
 *
 * Change detection is the reason this class exists. A list of risks is
 * something you read once and stop reading. The day a plugin you run is
 * pulled from the directory is a piece of news, and news is worth an email.
 */
class Deadwood_Store {

	const OPTION_RESULTS = 'deadwood_results';
	const OPTION_SETTINGS = 'deadwood_settings';

	/**
	 * Save a scan.
	 *
	 * @param array<int,Deadwood_Verdict> $verdicts Verdicts.
	 * @param array                       $summary  Summary block.
	 */
	public function save( array $verdicts, array $summary ) {
		$rows = array();

		foreach ( $verdicts as $v ) {
			$rows[] = $v->to_array();
		}

		update_option(
			self::OPTION_RESULTS,
			array(
				'version'  => DEADWOOD_VERSION,
				'summary'  => $summary,
				'verdicts' => $rows,
			),
			false
		);
	}

	/**
	 * Load the last scan.
	 *
	 * @return array{summary:array,verdicts:array<int,Deadwood_Verdict>}|null
	 */
	public function load() {
		$stored = get_option( self::OPTION_RESULTS );

		if ( ! is_array( $stored ) || empty( $stored['verdicts'] ) ) {
			return null;
		}

		$verdicts = array();
		foreach ( $stored['verdicts'] as $row ) {
			if ( is_array( $row ) ) {
				$verdicts[] = Deadwood_Verdict::from_array( $row );
			}
		}

		return array(
			'summary'  => isset( $stored['summary'] ) ? $stored['summary'] : array(),
			'verdicts' => $verdicts,
		);
	}

	/**
	 * Compare a fresh scan against what was stored.
	 *
	 * Only changes worth telling somebody about are returned. A plugin sliding
	 * from healthy to aging is not news; a plugin being removed from the
	 * directory is.
	 *
	 * @param array<int,Deadwood_Verdict> $fresh New verdicts.
	 * @return array<int,array{verdict:Deadwood_Verdict,from:string,kind:string}>
	 */
	public function changes_worth_reporting( array $fresh ) {
		$previous = $this->load();

		/*
		 * With no baseline there is nothing to compare against, and announcing
		 * every existing risk as though it just happened would be wrong. The
		 * first scan establishes the baseline and reports nothing as new.
		 */
		if ( null === $previous ) {
			return array();
		}

		$before = array();
		foreach ( $previous['verdicts'] as $v ) {
			$before[ $v->type . ':' . $v->slug ] = $v->status;
		}

		$news = array();

		foreach ( $fresh as $v ) {
			$key  = $v->type . ':' . $v->slug;
			$from = isset( $before[ $key ] ) ? $before[ $key ] : '';

			if ( '' === $from ) {
				/*
				 * Newly installed. Worth reporting only when it arrives
				 * already carrying a problem.
				 */
				if ( $v->is_actionable() ) {
					$news[] = array(
						'verdict' => $v,
						'from'    => '',
						'kind'    => 'installed',
					);
				}
				continue;
			}

			if ( $from === $v->status ) {
				continue;
			}

			if ( Deadwood_Verdict::STATUS_CLOSED === $v->status ) {
				$news[] = array(
					'verdict' => $v,
					'from'    => $from,
					'kind'    => 'closed',
				);
				continue;
			}

			if ( Deadwood_Verdict::STATUS_ABANDONED === $v->status
				&& Deadwood_Verdict::STATUS_CLOSED !== $from ) {
				$news[] = array(
					'verdict' => $v,
					'from'    => $from,
					'kind'    => 'abandoned',
				);
			}
		}

		return $news;
	}

	/**
	 * Read settings with defaults applied.
	 *
	 * @return array
	 */
	public function settings() {
		$defaults = array(
			'email_enabled'   => true,
			'email_recipient' => get_option( 'admin_email' ),
		);

		$stored = get_option( self::OPTION_SETTINGS );

		return is_array( $stored ) ? array_merge( $defaults, $stored ) : $defaults;
	}

	/**
	 * Save settings.
	 *
	 * @param array $settings Settings to merge over the current ones.
	 */
	public function save_settings( array $settings ) {
		update_option( self::OPTION_SETTINGS, array_merge( $this->settings(), $settings ), false );
	}

	/**
	 * Remove everything this plugin stored.
	 */
	public function purge() {
		delete_option( self::OPTION_RESULTS );
		delete_option( self::OPTION_SETTINGS );
	}
}
