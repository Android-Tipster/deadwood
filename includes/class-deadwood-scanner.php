<?php
/**
 * Runs a scan: inventory, then one directory lookup per item, then a verdict.
 *
 * @package Deadwood
 */

defined( 'ABSPATH' ) || exit;

/**
 * Orchestrates a full pass over the site.
 */
class Deadwood_Scanner {

	/** @var Deadwood_Registry */
	private $registry;

	/** @var Deadwood_Scoring */
	private $scoring;

	/** @var Deadwood_Inventory */
	private $inventory;

	/**
	 * Constructor.
	 *
	 * @param Deadwood_Registry|null  $registry  Directory client.
	 * @param Deadwood_Scoring|null   $scoring   Risk model.
	 * @param Deadwood_Inventory|null $inventory Site inventory.
	 */
	public function __construct( $registry = null, $scoring = null, $inventory = null ) {
		$this->registry  = $registry ? $registry : new Deadwood_Registry();
		$this->scoring   = $scoring ? $scoring : new Deadwood_Scoring();
		$this->inventory = $inventory ? $inventory : new Deadwood_Inventory();
	}

	/**
	 * Scan the whole site.
	 *
	 * @param bool          $force    Bypass the lookup cache.
	 * @param callable|null $progress Called with ( $done, $total, $verdict ).
	 * @return array{verdicts:array<int,Deadwood_Verdict>,summary:array}
	 */
	public function scan( $force = false, $progress = null ) {
		$items    = $this->inventory->collect();
		$verdicts = array();
		$total    = count( $items );
		$done     = 0;

		foreach ( $items as $item ) {
			$lookup  = $this->registry->lookup( $item['slug'], $item['type'], $force );
			$verdict = $this->scoring->assess( $item, $lookup );

			$verdict = $this->guard_guessed_slug( $item, $verdict );

			$verdicts[] = $verdict;
			$done++;

			if ( is_callable( $progress ) ) {
				call_user_func( $progress, $done, $total, $verdict );
			}

			/*
			 * WordPress.org is a free service being asked a question per
			 * installed plugin. Uncached lookups are spaced so a large site
			 * does not arrive as a burst.
			 */
			if ( $done < $total ) {
				usleep( 150000 );
			}
		}

		usort(
			$verdicts,
			static function ( $a, $b ) {
				$rank = $b->severity_rank() - $a->severity_rank();
				if ( 0 !== $rank ) {
					return $rank;
				}
				return $b->score - $a->score;
			}
		);

		return array(
			'verdicts' => $verdicts,
			'summary'  => $this->summarise( $verdicts ),
		);
	}

	/**
	 * Refuse to draw a confident conclusion from a slug that was guessed.
	 *
	 * When WordPress itself has not mapped a plugin to a directory slug, the
	 * only thing left is the folder name, and a folder name is not an identity.
	 * Two answers become untrustworthy the moment the slug is a guess.
	 *
	 * A wrong guess produces "no directory entry", which reads as commercial
	 * code when the truth may be that the folder is simply named differently.
	 *
	 * Worse, a folder name can collide with a slug the directory removed years
	 * ago and reused for nothing. That produces a removal notice about a
	 * completely unrelated plugin, which is the most damaging thing this tool
	 * could get wrong: it is alarming, it looks specific, and it is false.
	 *
	 * Both are therefore reported as unchecked, with the collision spelled out
	 * so the reader can settle it in one look.
	 *
	 * @param array            $item    Inventory row.
	 * @param Deadwood_Verdict $verdict Verdict to inspect.
	 * @return Deadwood_Verdict
	 */
	private function guard_guessed_slug( array $item, Deadwood_Verdict $verdict ) {
		if ( ! empty( $item['slug_confident'] ) ) {
			return $verdict;
		}

		if ( Deadwood_Verdict::STATUS_UNLISTED === $verdict->status ) {
			$verdict->status         = Deadwood_Verdict::STATUS_UNKNOWN;
			$verdict->factors        = array();
			$verdict->unknown_reason = sprintf(
				'WordPress has no directory mapping for this plugin, so the slug "%s" was taken from the folder name, and the directory has no entry under it. That usually means the plugin is commercial, but it can also mean the folder is named differently from the slug.',
				$item['slug']
			);

			return $verdict;
		}

		if ( Deadwood_Verdict::STATUS_CLOSED === $verdict->status ) {
			$closed_on = isset( $verdict->directory['closed_date'] ) ? $verdict->directory['closed_date'] : '';
			$reason    = isset( $verdict->directory['closed_reason'] ) ? $verdict->directory['closed_reason'] : '';

			$detail = sprintf(
				'The folder name "%s" matches a plugin that WordPress.org removed',
				$item['slug']
			);
			if ( '' !== $closed_on ) {
				$detail .= ' on ' . $closed_on;
			}
			if ( '' !== $reason ) {
				$detail .= ' (' . rtrim( $reason, '.' ) . ')';
			}
			$detail .= sprintf(
				'. WordPress has not mapped this install to the directory, so there is no evidence that "%s" is that plugin rather than unrelated code sharing a folder name. Check its author and its own site before acting on this.',
				$item['name']
			);

			$verdict->status         = Deadwood_Verdict::STATUS_UNKNOWN;
			$verdict->score          = 0;
			$verdict->factors        = array();
			$verdict->unknown_reason = $detail;
		}

		return $verdict;
	}

	/**
	 * Count verdicts by status and note the worst of them.
	 *
	 * @param array<int,Deadwood_Verdict> $verdicts Verdicts.
	 * @return array
	 */
	public function summarise( array $verdicts ) {
		$counts = array(
			Deadwood_Verdict::STATUS_CLOSED    => 0,
			Deadwood_Verdict::STATUS_ABANDONED => 0,
			Deadwood_Verdict::STATUS_AGING     => 0,
			Deadwood_Verdict::STATUS_HEALTHY   => 0,
			Deadwood_Verdict::STATUS_UNLISTED  => 0,
			Deadwood_Verdict::STATUS_UNKNOWN   => 0,
		);

		$actionable = 0;

		foreach ( $verdicts as $v ) {
			if ( isset( $counts[ $v->status ] ) ) {
				$counts[ $v->status ]++;
			}
			if ( $v->is_actionable() ) {
				$actionable++;
			}
		}

		return array(
			'counts'     => $counts,
			'total'      => count( $verdicts ),
			'actionable' => $actionable,
			'scanned_at' => time(),
		);
	}
}
