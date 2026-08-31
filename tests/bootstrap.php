<?php
/**
 * Enough of WordPress to run Deadwood's logic outside WordPress.
 *
 * The stubs are deliberately real where it matters. wp_remote_get actually
 * performs the request, so the registry can be exercised against the live
 * directory rather than against an imagined version of it.
 *
 * @package Deadwood
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'DEADWOOD_VERSION', '1.0.0-test' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['wp_version']       = '7.1';
$GLOBALS['deadwood_options'] = array();
$GLOBALS['deadwood_trans']   = array();
$GLOBALS['deadwood_mail']    = array();
$GLOBALS['deadwood_http']    = 0;

/**
 * Minimal WP_Error.
 */
class WP_Error {
	/** @var string */
	private $code;
	/** @var string */
	private $message;

	/**
	 * Constructor.
	 *
	 * @param string $code    Code.
	 * @param string $message Message.
	 */
	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}

	/**
	 * Message accessor.
	 *
	 * @return string
	 */
	public function get_error_message() {
		return $this->message;
	}
}

/**
 * Whether a value is an error.
 *
 * @param mixed $thing Value.
 * @return bool
 */
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

/**
 * Perform a real HTTP GET.
 *
 * @param string $url  URL.
 * @param array  $args Arguments.
 * @return array|WP_Error
 */
function wp_remote_get( $url, $args = array() ) {
	$GLOBALS['deadwood_http']++;

	$timeout = isset( $args['timeout'] ) ? (int) $args['timeout'] : 15;
	$agent   = isset( $args['user-agent'] ) ? $args['user-agent'] : 'Deadwood-test';

	$ch = curl_init( $url );
	curl_setopt_array(
		$ch,
		array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_TIMEOUT        => $timeout,
			CURLOPT_USERAGENT      => $agent,
			CURLOPT_SSL_VERIFYPEER => false,
		)
	);

	$body = curl_exec( $ch );
	$code = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
	$err  = curl_error( $ch );

	if ( false === $body ) {
		return new WP_Error( 'http_request_failed', $err ? $err : 'request failed' );
	}

	return array(
		'response' => array( 'code' => $code ),
		'body'     => $body,
	);
}

/**
 * Response code accessor.
 *
 * @param array $response Response.
 * @return int
 */
function wp_remote_retrieve_response_code( $response ) {
	return isset( $response['response']['code'] ) ? (int) $response['response']['code'] : 0;
}

/**
 * Response body accessor.
 *
 * @param array $response Response.
 * @return string
 */
function wp_remote_retrieve_body( $response ) {
	return isset( $response['body'] ) ? $response['body'] : '';
}

/**
 * Append query arguments to a URL.
 *
 * @param array  $args Arguments.
 * @param string $url  Base URL.
 * @return string
 */
function add_query_arg( $args, $url = '' ) {
	$parts = array();
	foreach ( $args as $k => $v ) {
		$parts[] = rawurlencode( $k ) . '=' . rawurlencode( $v );
	}
	$glue = ( false === strpos( $url, '?' ) ) ? '?' : '&';
	return $url . $glue . implode( '&', $parts );
}

/**
 * Transient read.
 *
 * @param string $key Key.
 * @return mixed
 */
function get_transient( $key ) {
	if ( ! isset( $GLOBALS['deadwood_trans'][ $key ] ) ) {
		return false;
	}
	$row = $GLOBALS['deadwood_trans'][ $key ];
	if ( $row['expires'] < time() ) {
		unset( $GLOBALS['deadwood_trans'][ $key ] );
		return false;
	}
	return $row['value'];
}

/**
 * Transient write.
 *
 * @param string $key   Key.
 * @param mixed  $value Value.
 * @param int    $ttl   Seconds.
 * @return bool
 */
function set_transient( $key, $value, $ttl = 0 ) {
	$GLOBALS['deadwood_trans'][ $key ] = array(
		'value'   => $value,
		'expires' => time() + ( $ttl > 0 ? $ttl : 300 ),
	);
	return true;
}

/**
 * Option read.
 *
 * @param string $key     Key.
 * @param mixed  $default Fallback.
 * @return mixed
 */
function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['deadwood_options'] ) ? $GLOBALS['deadwood_options'][ $key ] : $default;
}

/**
 * Option write.
 *
 * @param string $key      Key.
 * @param mixed  $value    Value.
 * @param bool   $autoload Ignored.
 * @return bool
 */
function update_option( $key, $value, $autoload = true ) {
	$GLOBALS['deadwood_options'][ $key ] = $value;
	return true;
}

/**
 * Option delete.
 *
 * @param string $key Key.
 * @return bool
 */
function delete_option( $key ) {
	unset( $GLOBALS['deadwood_options'][ $key ] );
	return true;
}

/**
 * Site option read.
 *
 * @param string $key     Key.
 * @param mixed  $default Fallback.
 * @return mixed
 */
function get_site_option( $key, $default = false ) {
	return get_option( $key, $default );
}

/**
 * Site transient read.
 *
 * @param string $key Key.
 * @return mixed
 */
function get_site_transient( $key ) {
	return get_transient( $key );
}

/**
 * Strip tags.
 *
 * @param string $text Text.
 * @return string
 */
function wp_strip_all_tags( $text ) {
	return trim( wp_kses_strip( $text ) );
}

/**
 * Helper for wp_strip_all_tags.
 *
 * @param string $text Text.
 * @return string
 */
function wp_kses_strip( $text ) {
	return preg_replace( '/<[^>]*>/', '', (string) $text );
}

/**
 * Site home URL.
 *
 * @param string $path Path.
 * @return string
 */
function home_url( $path = '' ) {
	return 'https://example.test/' . ltrim( $path, '/' );
}

/**
 * Admin URL.
 *
 * @param string $path Path.
 * @return string
 */
function admin_url( $path = '' ) {
	return 'https://example.test/wp-admin/' . ltrim( $path, '/' );
}

/**
 * URL parsing.
 *
 * @param string $url       URL.
 * @param int    $component Component constant.
 * @return mixed
 */
function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

/**
 * Number formatting.
 *
 * @param int $number Number.
 * @return string
 */
function number_format_i18n( $number ) {
	return number_format( (int) $number );
}

/**
 * Email validation.
 *
 * @param string $email Email.
 * @return bool
 */
function is_email( $email ) {
	return (bool) filter_var( $email, FILTER_VALIDATE_EMAIL );
}

/**
 * Multisite check.
 *
 * @return bool
 */
function is_multisite() {
	return false;
}

/**
 * Mail capture.
 *
 * @param string $to      Recipient.
 * @param string $subject Subject.
 * @param string $body    Body.
 * @param array  $headers Headers.
 * @return bool
 */
function wp_mail( $to, $subject, $body, $headers = array() ) {
	$GLOBALS['deadwood_mail'][] = compact( 'to', 'subject', 'body', 'headers' );
	return true;
}

/**
 * Singular or plural.
 *
 * @param string $single Singular.
 * @param string $plural Plural.
 * @param int    $number Count.
 * @param string $domain Domain.
 * @return string
 */
function _n( $single, $plural, $number, $domain = '' ) {
	return ( 1 === (int) $number ) ? $single : $plural;
}

/**
 * Passthrough translation.
 *
 * @param string $text   Text.
 * @param string $domain Domain.
 * @return string
 */
function __( $text, $domain = '' ) {
	return $text;
}

/**
 * Human readable time difference.
 *
 * @param int $from From timestamp.
 * @param int $to   To timestamp.
 * @return string
 */
function human_time_diff( $from, $to = 0 ) {
	$to   = $to ? $to : time();
	$diff = abs( $to - $from );
	if ( $diff < HOUR_IN_SECONDS ) {
		return max( 1, (int) round( $diff / MINUTE_IN_SECONDS ) ) . ' mins';
	}
	if ( $diff < DAY_IN_SECONDS ) {
		return max( 1, (int) round( $diff / HOUR_IN_SECONDS ) ) . ' hours';
	}
	return max( 1, (int) round( $diff / DAY_IN_SECONDS ) ) . ' days';
}

require_once dirname( __DIR__ ) . '/includes/class-deadwood-verdict.php';
require_once dirname( __DIR__ ) . '/includes/class-deadwood-registry.php';
require_once dirname( __DIR__ ) . '/includes/class-deadwood-scoring.php';
require_once dirname( __DIR__ ) . '/includes/class-deadwood-inventory.php';
require_once dirname( __DIR__ ) . '/includes/class-deadwood-scanner.php';
require_once dirname( __DIR__ ) . '/includes/class-deadwood-store.php';
require_once dirname( __DIR__ ) . '/includes/class-deadwood-notifier.php';
