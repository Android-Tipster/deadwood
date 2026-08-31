<?php
/**
 * The result of assessing one installed plugin or theme.
 *
 * @package Deadwood
 */

defined( 'ABSPATH' ) || exit;

/**
 * A single assessment, carrying its status, score and the evidence behind both.
 *
 * The status vocabulary is deliberately small and each value means one thing:
 *
 * closed    The item was in the WordPress.org directory and has been removed.
 *           Sites keep running it with no notice from WordPress. Always critical.
 * abandoned The item is still listed but the author stopped shipping updates
 *           long enough ago that nobody is watching it.
 * aging     Updates have slowed past the point of comfort but not past the
 *           abandonment threshold.
 * healthy   Listed, updated recently, no risk factors worth reporting.
 * unlisted  Not in the directory and never was. Commercial, custom or bundled
 *           code. This is not a fault, and it is never scored as one.
 * unknown   The check could not be completed. Never treated as a pass.
 */
class Deadwood_Verdict {

	const STATUS_CLOSED    = 'closed';
	const STATUS_ABANDONED = 'abandoned';
	const STATUS_AGING     = 'aging';
	const STATUS_HEALTHY   = 'healthy';
	const STATUS_UNLISTED  = 'unlisted';
	const STATUS_UNKNOWN   = 'unknown';

	/** @var string */
	public $slug;

	/** @var string */
	public $name;

	/** @var string One of plugin|theme. */
	public $type;

	/** @var string Status constant. */
	public $status = self::STATUS_UNKNOWN;

	/** @var int Risk score from 0 (no concern) to 100 (act now). */
	public $score = 0;

	/** @var bool Whether the item is currently active on the site. */
	public $active = false;

	/** @var string Version installed on this site. */
	public $installed_version = '';

	/** @var array<int,array{code:string,label:string,evidence:string,weight:int}> */
	public $factors = array();

	/** @var array Raw directory data, kept so a verdict can be re-examined. */
	public $directory = array();

	/** @var string Why the status could not be determined, when unknown. */
	public $unknown_reason = '';

	/** @var int Unix timestamp of the assessment. */
	public $checked_at = 0;

	/**
	 * Build a verdict for one item.
	 *
	 * @param string $slug Directory slug.
	 * @param string $name Human readable name.
	 * @param string $type plugin or theme.
	 */
	public function __construct( $slug, $name, $type = 'plugin' ) {
		$this->slug       = $slug;
		$this->name       = $name;
		$this->type       = $type;
		$this->checked_at = time();
	}

	/**
	 * Record a contributing risk factor with the evidence it was drawn from.
	 *
	 * @param string $code     Stable machine code.
	 * @param string $label    Short human sentence.
	 * @param string $evidence The observation the label rests on.
	 * @param int    $weight   Points this factor contributes to the score.
	 */
	public function add_factor( $code, $label, $evidence, $weight ) {
		$this->factors[] = array(
			'code'     => $code,
			'label'    => $label,
			'evidence' => $evidence,
			'weight'   => (int) $weight,
		);
	}

	/**
	 * Whether this verdict is one a site owner should act on.
	 *
	 * @return bool
	 */
	public function is_actionable() {
		return in_array( $this->status, array( self::STATUS_CLOSED, self::STATUS_ABANDONED ), true );
	}

	/**
	 * Whether the check failed to reach a conclusion.
	 *
	 * @return bool
	 */
	public function is_unknown() {
		return self::STATUS_UNKNOWN === $this->status;
	}

	/**
	 * Sort weight so the worst items surface first.
	 *
	 * @return int
	 */
	public function severity_rank() {
		$order = array(
			self::STATUS_CLOSED    => 5,
			self::STATUS_ABANDONED => 4,
			self::STATUS_AGING     => 3,
			self::STATUS_UNKNOWN   => 2,
			self::STATUS_UNLISTED  => 1,
			self::STATUS_HEALTHY   => 0,
		);
		return isset( $order[ $this->status ] ) ? $order[ $this->status ] : 0;
	}

	/**
	 * Convert to a plain array for storage.
	 *
	 * @return array
	 */
	public function to_array() {
		return array(
			'slug'              => $this->slug,
			'name'              => $this->name,
			'type'              => $this->type,
			'status'            => $this->status,
			'score'             => $this->score,
			'active'            => $this->active,
			'installed_version' => $this->installed_version,
			'factors'           => $this->factors,
			'directory'         => $this->directory,
			'unknown_reason'    => $this->unknown_reason,
			'checked_at'        => $this->checked_at,
		);
	}

	/**
	 * Rebuild a verdict from stored data.
	 *
	 * @param array $data Stored array.
	 * @return Deadwood_Verdict
	 */
	public static function from_array( array $data ) {
		$v = new self(
			isset( $data['slug'] ) ? $data['slug'] : '',
			isset( $data['name'] ) ? $data['name'] : '',
			isset( $data['type'] ) ? $data['type'] : 'plugin'
		);
		foreach ( array( 'status', 'score', 'active', 'installed_version', 'factors', 'directory', 'unknown_reason', 'checked_at' ) as $key ) {
			if ( isset( $data[ $key ] ) ) {
				$v->$key = $data[ $key ];
			}
		}
		return $v;
	}
}
