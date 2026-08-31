<?php
/**
 * Deadwood test suite.
 *
 * Run with:  php tests/run-tests.php
 *            php tests/run-tests.php --live    (also hits api.wordpress.org)
 *
 * @package Deadwood
 */

require_once __DIR__ . '/bootstrap.php';

$GLOBALS['pass'] = 0;
$GLOBALS['fail'] = 0;

/**
 * Assert a condition.
 *
 * @param string $label What is being checked.
 * @param bool   $cond  Result.
 * @param string $note  Extra detail shown on failure.
 */
function check( $label, $cond, $note = '' ) {
	if ( $cond ) {
		$GLOBALS['pass']++;
		echo "  ok    $label\n";
		return;
	}
	$GLOBALS['fail']++;
	echo "  FAIL  $label";
	echo ( '' !== $note ) ? "  ($note)\n" : "\n";
}

/**
 * Assert equality.
 *
 * @param string $label    Label.
 * @param mixed  $expected Expected.
 * @param mixed  $actual   Actual.
 */
function check_same( $label, $expected, $actual ) {
	check( $label, $expected === $actual, 'expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
}

/**
 * Build an inventory row.
 *
 * @param array $over Overrides.
 * @return array
 */
function item( array $over = array() ) {
	return array_merge(
		array(
			'type'           => 'plugin',
			'file'           => 'demo/demo.php',
			'slug'           => 'demo',
			'slug_confident' => true,
			'name'           => 'Demo',
			'version'        => '1.0.0',
			'author'         => 'Someone',
			'active'         => true,
		),
		$over
	);
}

/**
 * Build a directory payload.
 *
 * @param array $over Overrides.
 * @return array
 */
function dir_data( array $over = array() ) {
	return array_merge(
		array(
			'slug'                     => 'demo',
			'version'                  => '1.0.0',
			'last_updated'             => gmdate( 'Y-m-d', time() - 10 * DAY_IN_SECONDS ) . ' 1:00pm GMT',
			'added'                    => '2015-01-01',
			'active_installs'          => 1000,
			'rating'                   => 90.0,
			'num_ratings'              => 20,
			'support_threads'          => 0,
			'support_threads_resolved' => 0,
			'tested'                   => '7.1',
			'requires'                 => '5.0',
			'requires_php'             => '7.0',
			'homepage'                 => '',
		),
		$over
	);
}

/**
 * Find a factor by code.
 *
 * @param Deadwood_Verdict $v    Verdict.
 * @param string           $code Factor code.
 * @return array|null
 */
function factor( Deadwood_Verdict $v, $code ) {
	foreach ( $v->factors as $f ) {
		if ( $f['code'] === $code ) {
			return $f;
		}
	}
	return null;
}

$scoring = new Deadwood_Scoring();

echo "\nStatus classification\n";

$v = $scoring->assess( item(), array( 'outcome' => Deadwood_Registry::FOUND, 'data' => dir_data() ) );
check_same( 'a recently updated plugin is healthy', Deadwood_Verdict::STATUS_HEALTHY, $v->status );
check_same( 'a healthy plugin scores zero', 0, $v->score );

$v = $scoring->assess( item(), array( 'outcome' => Deadwood_Registry::CLOSED, 'closed' => array( 'reason' => 'Guideline Violation', 'date' => 'March 3, 2024' ) ) );
check_same( 'a removed plugin is closed', Deadwood_Verdict::STATUS_CLOSED, $v->status );
check_same( 'a removed plugin scores 100', 100, $v->score );
check( 'the closure reason is carried into the verdict', false !== strpos( factor( $v, 'closed' )['evidence'], 'Guideline Violation' ) );
check( 'the closure date is carried into the verdict', false !== strpos( factor( $v, 'closed' )['evidence'], 'March 3, 2024' ) );
check( 'an active removed plugin is called out separately', null !== factor( $v, 'closed_and_active' ) );

$v = $scoring->assess( item(), array( 'outcome' => Deadwood_Registry::UNLISTED ) );
check_same( 'a plugin that was never listed is unlisted', Deadwood_Verdict::STATUS_UNLISTED, $v->status );
check_same( 'an unlisted plugin is never scored as a fault', 0, $v->score );
check( 'an unlisted plugin is not actionable', ! $v->is_actionable() );

$v = $scoring->assess( item(), array( 'outcome' => Deadwood_Registry::ERROR, 'message' => 'connection reset' ) );
check_same( 'a failed lookup is unknown', Deadwood_Verdict::STATUS_UNKNOWN, $v->status );
check( 'a failed lookup is never reported as healthy', Deadwood_Verdict::STATUS_HEALTHY !== $v->status );
check( 'a failed lookup keeps the reason', 'connection reset' === $v->unknown_reason );

echo "\nStaleness bands\n";

$days = static function ( $n ) {
	return gmdate( 'Y-m-d', time() - $n * DAY_IN_SECONDS ) . ' 1:00pm GMT';
};

$v = $scoring->assess( item(), array( 'outcome' => Deadwood_Registry::FOUND, 'data' => dir_data( array( 'last_updated' => $days( 400 ) ) ) ) );
check_same( '400 days quiet is aging', Deadwood_Verdict::STATUS_AGING, $v->status );

$v = $scoring->assess( item(), array( 'outcome' => Deadwood_Registry::FOUND, 'data' => dir_data( array( 'last_updated' => $days( 800 ) ) ) ) );
check_same( '800 days quiet is abandoned', Deadwood_Verdict::STATUS_ABANDONED, $v->status );
check( 'abandonment is actionable', $v->is_actionable() );

$a = $scoring->assess( item(), array( 'outcome' => Deadwood_Registry::FOUND, 'data' => dir_data( array( 'last_updated' => $days( 800 ) ) ) ) );
$b = $scoring->assess( item(), array( 'outcome' => Deadwood_Registry::FOUND, 'data' => dir_data( array( 'last_updated' => $days( 2000 ) ) ) ) );
check( 'longer silence scores higher', $b->score > $a->score, $a->score . ' then ' . $b->score );
check( 'a plugin quiet for years still scores below a removal', $b->score < 100 );

echo "\nCorroborating evidence\n";

$v = $scoring->assess( item(), array( 'outcome' => Deadwood_Registry::FOUND, 'data' => dir_data( array( 'tested' => '6.2' ) ) ) );
check( 'a stale compatibility claim is reported', null !== factor( $v, 'tested_gap' ) );

$v = $scoring->assess( item(), array( 'outcome' => Deadwood_Registry::FOUND, 'data' => dir_data( array( 'tested' => '7.0' ) ) ) );
check( 'one release behind is not worth reporting', null === factor( $v, 'tested_gap' ) );

$v = $scoring->assess( item(), array( 'outcome' => Deadwood_Registry::FOUND, 'data' => dir_data( array( 'support_threads' => 4, 'support_threads_resolved' => 0 ) ) ) );
check( 'four unanswered threads is too small a sample to judge', null === factor( $v, 'support_neglect' ) );

$v = $scoring->assess( item(), array( 'outcome' => Deadwood_Registry::FOUND, 'data' => dir_data( array( 'support_threads' => 20, 'support_threads_resolved' => 1 ) ) ) );
check( 'a forum nobody answers is reported', null !== factor( $v, 'support_neglect' ) );

$v = $scoring->assess( item(), array( 'outcome' => Deadwood_Registry::FOUND, 'data' => dir_data( array( 'support_threads' => 20, 'support_threads_resolved' => 18 ) ) ) );
check( 'a forum that is answered is not held against it', null === factor( $v, 'support_neglect' ) );

$v = $scoring->assess( item( array( 'version' => '1.0.0' ) ), array( 'outcome' => Deadwood_Registry::FOUND, 'data' => dir_data( array( 'version' => '2.5.0' ) ) ) );
check( 'running behind the current release is reported', null !== factor( $v, 'behind_latest' ) );

$v = $scoring->assess( item( array( 'version' => '3.0.0' ) ), array( 'outcome' => Deadwood_Registry::FOUND, 'data' => dir_data( array( 'version' => '2.5.0' ) ) ) );
check( 'running ahead of the directory is not a fault', null === factor( $v, 'behind_latest' ) );

$v = $scoring->assess( item(), array( 'outcome' => Deadwood_Registry::FOUND, 'data' => dir_data( array( 'active_installs' => 300000 ) ) ) );
$blast = factor( $v, 'blast_radius' );
check( 'install count is shown as context', null !== $blast );
check_same( 'install count carries no score', 0, $blast['weight'] );

echo "\nDate handling\n";

$v = $scoring->assess( item(), array( 'outcome' => Deadwood_Registry::FOUND, 'data' => dir_data( array( 'last_updated' => '2023-04-04 7:15pm GMT' ) ) ) );
check_same( 'the directory date format parses', Deadwood_Verdict::STATUS_ABANDONED, $v->status );

$v = $scoring->assess( item(), array( 'outcome' => Deadwood_Registry::FOUND, 'data' => dir_data( array( 'last_updated' => 'not a date' ) ) ) );
check_same( 'an unreadable date becomes unknown rather than a guess', Deadwood_Verdict::STATUS_UNKNOWN, $v->status );

$v = $scoring->assess( item(), array( 'outcome' => Deadwood_Registry::FOUND, 'data' => dir_data( array( 'last_updated' => '' ) ) ) );
check_same( 'a missing date becomes unknown', Deadwood_Verdict::STATUS_UNKNOWN, $v->status );

echo "\nScanner guards\n";

/**
 * Registry stub returning a fixed outcome.
 */
class Fake_Registry extends Deadwood_Registry {
	/** @var array */
	private $out;

	/**
	 * Constructor.
	 *
	 * @param array $out Outcome to return.
	 */
	public function __construct( array $out ) {
		$this->out = $out;
	}

	/**
	 * Return the canned outcome.
	 *
	 * @param string $slug  Slug.
	 * @param string $type  Type.
	 * @param bool   $force Force.
	 * @return array
	 */
	public function lookup( $slug, $type = 'plugin', $force = false ) {
		return $this->out;
	}
}

/**
 * Inventory stub returning fixed rows.
 */
class Fake_Inventory extends Deadwood_Inventory {
	/** @var array */
	private $rows;

	/**
	 * Constructor.
	 *
	 * @param array $rows Rows.
	 */
	public function __construct( array $rows ) {
		$this->rows = $rows;
	}

	/**
	 * Return the canned rows.
	 *
	 * @return array
	 */
	public function collect() {
		return $this->rows;
	}
}

$scanner = new Deadwood_Scanner(
	new Fake_Registry( array( 'outcome' => Deadwood_Registry::UNLISTED ) ),
	new Deadwood_Scoring(),
	new Fake_Inventory( array( item( array( 'slug_confident' => false, 'slug' => 'guessed-name' ) ) ) )
);
$res = $scanner->scan();
check_same(
	'a guessed slug cannot produce a confident "never listed" claim',
	Deadwood_Verdict::STATUS_UNKNOWN,
	$res['verdicts'][0]->status
);

$scanner = new Deadwood_Scanner(
	new Fake_Registry( array( 'outcome' => Deadwood_Registry::UNLISTED ) ),
	new Deadwood_Scoring(),
	new Fake_Inventory( array( item( array( 'slug_confident' => true ) ) ) )
);
$res = $scanner->scan();
check_same(
	'a slug WordPress itself resolved is trusted',
	Deadwood_Verdict::STATUS_UNLISTED,
	$res['verdicts'][0]->status
);

$scanner = new Deadwood_Scanner(
	new Fake_Registry( array( 'outcome' => Deadwood_Registry::CLOSED, 'closed' => array( 'reason' => 'Unused.', 'date' => 'July 19, 2018' ) ) ),
	new Deadwood_Scoring(),
	new Fake_Inventory( array( item( array( 'slug_confident' => false, 'slug' => 'blaze', 'name' => 'BlazeAI' ) ) ) )
);
$res = $scanner->scan();
check_same(
	'a guessed slug colliding with a removed plugin is not reported as a removal',
	Deadwood_Verdict::STATUS_UNKNOWN,
	$res['verdicts'][0]->status
);
check_same( 'and that item carries no risk score', 0, $res['verdicts'][0]->score );
check(
	'and the collision is spelled out',
	false !== strpos( $res['verdicts'][0]->unknown_reason, 'unrelated code sharing a folder name' ),
	$res['verdicts'][0]->unknown_reason
);
check(
	'and the removal date is quoted so it can be settled quickly',
	false !== strpos( $res['verdicts'][0]->unknown_reason, 'July 19, 2018' )
);

$scanner = new Deadwood_Scanner(
	new Fake_Registry( array( 'outcome' => Deadwood_Registry::CLOSED, 'closed' => array( 'reason' => 'Guideline Violation', 'date' => '' ) ) ),
	new Deadwood_Scoring(),
	new Fake_Inventory( array( item( array( 'slug_confident' => true, 'slug' => 'real-one' ) ) ) )
);
$res = $scanner->scan();
check_same(
	'a removal on a slug WordPress resolved is still reported',
	Deadwood_Verdict::STATUS_CLOSED,
	$res['verdicts'][0]->status
);

$scanner = new Deadwood_Scanner(
	new Fake_Registry( array( 'outcome' => Deadwood_Registry::CLOSED, 'closed' => array( 'reason' => '', 'date' => '' ) ) ),
	new Deadwood_Scoring(),
	new Fake_Inventory(
		array(
			item( array( 'slug' => 'aaa', 'name' => 'Aaa' ) ),
			item( array( 'slug' => 'bbb', 'name' => 'Bbb' ) ),
		)
	)
);
$res = $scanner->scan();
check_same( 'the summary counts removals', 2, $res['summary']['counts'][ Deadwood_Verdict::STATUS_CLOSED ] );
check_same( 'the summary counts actionable items', 2, $res['summary']['actionable'] );

echo "\nChange detection\n";

$GLOBALS['deadwood_options'] = array();
$store                       = new Deadwood_Store();

$healthy = $scoring->assess( item( array( 'slug' => 'thing', 'name' => 'Thing' ) ), array( 'outcome' => Deadwood_Registry::FOUND, 'data' => dir_data() ) );

check_same( 'the first scan announces nothing as new', 0, count( $store->changes_worth_reporting( array( $healthy ) ) ) );

$store->save( array( $healthy ), array( 'counts' => array(), 'total' => 1, 'actionable' => 0, 'scanned_at' => time() ) );

$closed = $scoring->assess( item( array( 'slug' => 'thing', 'name' => 'Thing' ) ), array( 'outcome' => Deadwood_Registry::CLOSED, 'closed' => array( 'reason' => 'Author Request.', 'date' => '' ) ) );
$news   = $store->changes_worth_reporting( array( $closed ) );
check_same( 'a plugin being pulled is news', 1, count( $news ) );
check_same( 'the change is typed as a removal', 'closed', $news[0]['kind'] );

$store->save( array( $closed ), array( 'counts' => array(), 'total' => 1, 'actionable' => 1, 'scanned_at' => time() ) );
check_same( 'the same removal is not announced twice', 0, count( $store->changes_worth_reporting( array( $closed ) ) ) );

$GLOBALS['deadwood_options'] = array();
$store                       = new Deadwood_Store();
$store->save( array( $healthy ), array( 'counts' => array(), 'total' => 1, 'actionable' => 0, 'scanned_at' => time() ) );
$aging = $scoring->assess( item( array( 'slug' => 'thing', 'name' => 'Thing' ) ), array( 'outcome' => Deadwood_Registry::FOUND, 'data' => dir_data( array( 'last_updated' => $days( 400 ) ) ) ) );
check_same( 'drifting from healthy to aging is not worth an email', 0, count( $store->changes_worth_reporting( array( $aging ) ) ) );

echo "\nAlerts\n";

$GLOBALS['deadwood_mail']    = array();
$GLOBALS['deadwood_options'] = array( 'admin_email' => 'owner@example.test' );

$notifier = new Deadwood_Notifier();
$summary  = array(
	'counts' => array(
		Deadwood_Verdict::STATUS_CLOSED    => 1,
		Deadwood_Verdict::STATUS_ABANDONED => 0,
		Deadwood_Verdict::STATUS_AGING     => 0,
		Deadwood_Verdict::STATUS_HEALTHY   => 4,
		Deadwood_Verdict::STATUS_UNLISTED  => 0,
		Deadwood_Verdict::STATUS_UNKNOWN   => 2,
	),
);

$sent = $notifier->send( array( array( 'verdict' => $closed, 'from' => 'healthy', 'kind' => 'closed' ) ), $summary );
check( 'an alert is sent when something changed', $sent && 1 === count( $GLOBALS['deadwood_mail'] ) );
check( 'the subject names the removal', false !== strpos( $GLOBALS['deadwood_mail'][0]['subject'], 'pulled from WordPress.org' ) );
check( 'the body carries the stated reason', false !== strpos( $GLOBALS['deadwood_mail'][0]['body'], 'Author Request.' ) );
check( 'the body reports unchecked items rather than hiding them', false !== strpos( $GLOBALS['deadwood_mail'][0]['body'], 'could not be checked: 2' ) );

$GLOBALS['deadwood_mail'] = array();
check( 'no alert is sent when nothing changed', ! $notifier->send( array(), $summary ) );
check_same( 'and no mail was queued', 0, count( $GLOBALS['deadwood_mail'] ) );

$GLOBALS['deadwood_mail'] = array();
$store                    = new Deadwood_Store();
$store->save_settings( array( 'email_enabled' => false ) );
check( 'alerts respect being turned off', ! $notifier->send( array( array( 'verdict' => $closed, 'from' => 'healthy', 'kind' => 'closed' ) ), $summary ) );

echo "\nNo banned punctuation in user facing strings\n";

$offenders = array();
foreach ( glob( dirname( __DIR__ ) . '/{includes/*.php,*.php,readme.txt}', GLOB_BRACE ) as $file ) {
	$text = file_get_contents( $file );
	foreach ( array( "\xE2\x80\x94" => 'em dash', "\xE2\x80\x93" => 'en dash' ) as $ch => $name ) {
		if ( false !== strpos( $text, $ch ) ) {
			$offenders[] = basename( $file ) . ' contains a raw ' . $name;
		}
	}
}
check( 'no raw em or en dashes in the source', empty( $offenders ), implode( '; ', $offenders ) );

if ( in_array( '--live', $argv, true ) ) {
	echo "\nLive directory checks\n";

	$registry = new Deadwood_Registry();

	$r = $registry->lookup( 'akismet' );
	check_same( 'a maintained plugin is found', Deadwood_Registry::FOUND, $r['outcome'] );

	$r = $registry->lookup( 'limit-login-attempts' );
	check_same( 'the abandoned plugin from the census is still listed', Deadwood_Registry::FOUND, $r['outcome'] );
	$v = $scoring->assess( item( array( 'slug' => 'limit-login-attempts', 'version' => '1.7.1' ) ), $r );
	check_same( 'and it is scored as abandoned', Deadwood_Verdict::STATUS_ABANDONED, $v->status );

	$r = $registry->lookup( 'wp-gdpr-compliance' );
	check_same( 'a plugin the directory removed is detected as closed', Deadwood_Registry::CLOSED, $r['outcome'] );
	check( 'and the stated reason is captured', ! empty( $r['closed']['reason'] ), var_export( $r, true ) );

	$r = $registry->lookup( 'blaze' );
	check_same( 'the blaze slug is still a removal', Deadwood_Registry::CLOSED, $r['outcome'] );
	check_same( 'and its removal date is read off the page', 'July 19, 2018', $r['closed']['date'] );

	$r = $registry->lookup( 'wp-rocket' );
	check_same( 'a commercial plugin is not mistaken for a removal', Deadwood_Registry::UNLISTED, $r['outcome'] );

	$r = $registry->lookup( 'this-slug-does-not-exist-xyz123' );
	check_same( 'a nonsense slug is unlisted, not closed', Deadwood_Registry::UNLISTED, $r['outcome'] );

	echo '  (' . $GLOBALS['deadwood_http'] . " http requests)\n";
}

echo "\n" . str_repeat( '=', 46 ) . "\n";
printf( "%d passed, %d failed\n\n", $GLOBALS['pass'], $GLOBALS['fail'] );

exit( $GLOBALS['fail'] > 0 ? 1 : 0 );
