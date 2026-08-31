<?php
/**
 * Runs the real Deadwood classes against a live site, read only.
 *
 * The site's installed plugin list is read over the WordPress REST API and fed
 * to the shipped Scanner, so this exercises the actual product code rather
 * than a reimplementation of it. Nothing is written to the target site.
 *
 * This is a development and fleet auditing helper. It is excluded from the
 * distributed plugin by .distignore.
 *
 * Usage: php tools/audit-remote.php <site_url> <user> <app_password> <label>
 *
 * @package Deadwood
 */

require_once dirname( __DIR__ ) . '/tests/bootstrap.php';

if ( $argc < 5 ) {
	fwrite( STDERR, "usage: php tools/audit-remote.php <site_url> <user> <password> <label>\n" );
	exit( 2 );
}

list( , $site, $user, $pass, $label ) = $argv;

/**
 * Fetch the installed plugin list over REST.
 *
 * @param string $site Site URL.
 * @param string $user Username.
 * @param string $pass Application password.
 * @return array
 */
function deadwood_fetch_plugins( $site, $user, $pass ) {
	$url = rtrim( $site, '/' ) . '/wp-json/wp/v2/plugins?context=edit';

	$ch = curl_init( $url );
	curl_setopt_array(
		$ch,
		array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_TIMEOUT        => 45,
			CURLOPT_SSL_VERIFYPEER => false,
			CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; deadwood-audit/1.0)',
			CURLOPT_USERPWD        => $user . ':' . $pass,
			CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
		)
	);

	$body = curl_exec( $ch );
	$code = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );

	if ( 200 !== $code ) {
		fwrite( STDERR, "  REST returned $code\n" );
		fwrite( STDERR, '  ' . substr( (string) $body, 0, 240 ) . "\n" );
		return array();
	}

	$json = json_decode( $body, true );

	return is_array( $json ) ? $json : array();
}

/**
 * Inventory backed by a remote site.
 */
class Deadwood_Remote_Inventory extends Deadwood_Inventory {
	/** @var array */
	private $rows;

	/**
	 * Constructor.
	 *
	 * @param array $rows Inventory rows.
	 */
	public function __construct( array $rows ) {
		$this->rows = $rows;
	}

	/**
	 * Return the remote rows.
	 *
	 * @return array
	 */
	public function collect() {
		return $this->rows;
	}
}

$raw = deadwood_fetch_plugins( $site, $user, $pass );

if ( empty( $raw ) ) {
	fwrite( STDERR, "no plugin list for $label\n" );
	exit( 1 );
}

$rows = array();

foreach ( $raw as $p ) {
	$plugin_file = isset( $p['plugin'] ) ? $p['plugin'] : '';
	$slug        = '';

	if ( false !== strpos( $plugin_file, '/' ) ) {
		$parts = explode( '/', $plugin_file );
		$slug  = $parts[0];
	} elseif ( ! empty( $p['textdomain'] ) ) {
		$slug = $p['textdomain'];
	} else {
		$slug = basename( $plugin_file, '.php' );
	}

	/*
	 * The REST plugin list carries no directory slug, so this is a guess from
	 * the folder name and is flagged as one. The Scanner refuses to draw a
	 * confident conclusion from a guessed slug, which is what stops an
	 * unrelated plugin sharing a folder name from being reported as removed.
	 */
	$rows[] = array(
		'type'           => 'plugin',
		'file'           => $plugin_file,
		'slug'           => $slug,
		'slug_confident' => false,
		'name'           => isset( $p['name'] ) ? $p['name'] : $plugin_file,
		'version'        => isset( $p['version'] ) ? $p['version'] : '',
		'author'         => isset( $p['author'] ) ? wp_strip_all_tags( $p['author'] ) : '',
		'active'         => isset( $p['status'] ) && 'active' === $p['status'],
	);
}

$scanner = new Deadwood_Scanner( new Deadwood_Registry(), new Deadwood_Scoring(), new Deadwood_Remote_Inventory( $rows ) );
$result  = $scanner->scan();

echo "\n=== $label (" . count( $rows ) . " plugins) ===\n";

foreach ( $result['verdicts'] as $v ) {
	if ( Deadwood_Verdict::STATUS_HEALTHY === $v->status ) {
		continue;
	}

	printf(
		"%-12s %-3d %-32s %s%s\n",
		$v->status,
		$v->score,
		substr( $v->slug, 0, 32 ),
		$v->active ? '[active] ' : '',
		substr( $v->name, 0, 44 )
	);

	foreach ( $v->factors as $f ) {
		if ( (int) $f['weight'] > 0 || 'closed' === $f['code'] ) {
			echo '             . ' . $f['label'] . "\n";
		}
	}

	if ( $v->is_unknown() ) {
		echo '             . ' . substr( $v->unknown_reason, 0, 160 ) . "\n";
	}
}

$c = $result['summary']['counts'];
printf(
	"\nsummary: pulled %d | unmaintained %d | slowing %d | healthy %d | not listed %d | unchecked %d\n",
	$c[ Deadwood_Verdict::STATUS_CLOSED ],
	$c[ Deadwood_Verdict::STATUS_ABANDONED ],
	$c[ Deadwood_Verdict::STATUS_AGING ],
	$c[ Deadwood_Verdict::STATUS_HEALTHY ],
	$c[ Deadwood_Verdict::STATUS_UNLISTED ],
	$c[ Deadwood_Verdict::STATUS_UNKNOWN ]
);
