<?php
/**
 * Talks to WordPress.org and answers one question per slug: what does the
 * directory currently say about this thing?
 *
 * @package Deadwood
 */

defined( 'ABSPATH' ) || exit;

/**
 * Directory lookups, with caching, one retry, and a hard rule that a lookup
 * which fails is reported as a failure rather than as good news.
 */
class Deadwood_Registry {

	const CACHE_PREFIX = 'deadwood_reg_';
	const CACHE_TTL    = 12 * HOUR_IN_SECONDS;
	const API_BASE     = 'https://api.wordpress.org/plugins/info/1.2/';
	const THEME_API    = 'https://api.wordpress.org/themes/info/1.2/';
	const PAGE_BASE    = 'https://wordpress.org/plugins/';

	/* Outcome kinds returned by lookup(). */
	const FOUND    = 'found';
	const CLOSED   = 'closed';
	const UNLISTED = 'unlisted';
	const ERROR    = 'error';

	/**
	 * Look up one slug.
	 *
	 * Returns an array shaped:
	 *   array(
	 *     'outcome' => self::FOUND|CLOSED|UNLISTED|ERROR,
	 *     'data'    => array   directory fields when found,
	 *     'closed'  => array   reason and date when closed,
	 *     'message' => string  why, when outcome is ERROR,
	 *   )
	 *
	 * @param string $slug  Directory slug.
	 * @param string $type  plugin or theme.
	 * @param bool   $force Bypass the cache.
	 * @return array
	 */
	public function lookup( $slug, $type = 'plugin', $force = false ) {
		$key = self::CACHE_PREFIX . $type . '_' . md5( $slug );

		if ( ! $force ) {
			$cached = get_transient( $key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$result = ( 'theme' === $type ) ? $this->fetch_theme( $slug ) : $this->fetch_plugin( $slug );

		/*
		 * An error is never cached for long. A failed lookup must be retried
		 * soon rather than hardening into a stored non answer.
		 */
		$ttl = ( self::ERROR === $result['outcome'] ) ? 20 * MINUTE_IN_SECONDS : self::CACHE_TTL;
		set_transient( $key, $result, $ttl );

		return $result;
	}

	/**
	 * Ask the plugin API about one slug, then disambiguate a 404.
	 *
	 * @param string $slug Plugin slug.
	 * @return array
	 */
	private function fetch_plugin( $slug ) {
		$url = add_query_arg(
			array(
				'action'        => 'plugin_information',
				'request[slug]' => $slug,
			),
			self::API_BASE
		);

		$response = $this->request( $url );

		if ( is_wp_error( $response ) ) {
			return array(
				'outcome' => self::ERROR,
				'message' => $response->get_error_message(),
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 === $code && is_array( $body ) && empty( $body['error'] ) ) {
			return array(
				'outcome' => self::FOUND,
				'data'    => $this->normalise( $body ),
			);
		}

		/*
		 * A 404 means the API has no record under this slug. That is either a
		 * plugin the directory removed, or code that was never in the
		 * directory at all. Those two need very different treatment, so the
		 * public page is what separates them.
		 */
		if ( 404 === $code ) {
			return $this->classify_missing( $slug );
		}

		return array(
			'outcome' => self::ERROR,
			'message' => sprintf( 'Unexpected response %d from the plugin directory.', (int) $code ),
		);
	}

	/**
	 * Decide whether a slug the API does not know was removed or never listed.
	 *
	 * WordPress.org answers 200 for both cases, so the status cannot be read
	 * from the response code. A removed plugin keeps its own page and carries
	 * a status-closed marker; anything else is redirected to a search page.
	 *
	 * @param string $slug Plugin slug.
	 * @return array
	 */
	private function classify_missing( $slug ) {
		$response = $this->request( self::PAGE_BASE . rawurlencode( $slug ) . '/' );

		if ( is_wp_error( $response ) ) {
			return array(
				'outcome' => self::ERROR,
				'message' => 'Could not reach the plugin page to tell a removal from a commercial plugin.',
			);
		}

		$html = wp_remote_retrieve_body( $response );

		if ( '' === trim( (string) $html ) ) {
			return array(
				'outcome' => self::ERROR,
				'message' => 'The plugin page came back empty.',
			);
		}

		/*
		 * Landed on a search results page: this slug has no directory entry
		 * and almost certainly never did. Commercial and custom code lands
		 * here, and none of it is a fault.
		 */
		if ( preg_match( '/<title>\s*Search Results for/i', $html ) ) {
			return array( 'outcome' => self::UNLISTED );
		}

		if ( false !== strpos( $html, 'status-closed' ) ) {
			return array(
				'outcome' => self::CLOSED,
				'closed'  => array(
					'reason' => $this->extract_closed_reason( $html ),
					'date'   => $this->extract_closed_date( $html ),
				),
			);
		}

		/*
		 * The page exists and is not marked closed, yet the API denied the
		 * slug. That is a contradiction, so it is reported rather than guessed.
		 */
		return array(
			'outcome' => self::ERROR,
			'message' => 'The directory API and the plugin page disagree about this slug.',
		);
	}

	/**
	 * Pull the stated reason a plugin was closed, when one is published.
	 *
	 * @param string $html Plugin page markup.
	 * @return string
	 */
	private function extract_closed_reason( $html ) {
		if ( preg_match( '/Reason:\s*<\/strong>\s*([^<\n]{1,80})/i', $html, $m ) ) {
			return trim( wp_strip_all_tags( $m[1] ) );
		}
		if ( preg_match( '/Reason:\s*([^<\n]{1,80})/i', $html, $m ) ) {
			return trim( wp_strip_all_tags( $m[1] ) );
		}
		return '';
	}

	/**
	 * Pull the closure date when the page states one.
	 *
	 * @param string $html Plugin page markup.
	 * @return string
	 */
	private function extract_closed_date( $html ) {
		/*
		 * The directory writes this as "closed as of July 19, 2018". The
		 * alternative wording is accepted too, since the phrasing is
		 * presentation rather than API and has no promise attached to it.
		 */
		if ( preg_match( '/closed (?:as of|on)\s+(\w+ \d{1,2},\s*\d{4})/i', $html, $m ) ) {
			return trim( preg_replace( '/\s+/', ' ', $m[1] ) );
		}
		return '';
	}

	/**
	 * Ask the theme API about one slug.
	 *
	 * @param string $slug Theme slug.
	 * @return array
	 */
	private function fetch_theme( $slug ) {
		$url = add_query_arg(
			array(
				'action'        => 'theme_information',
				'request[slug]' => $slug,
			),
			self::THEME_API
		);

		$response = $this->request( $url );

		if ( is_wp_error( $response ) ) {
			return array(
				'outcome' => self::ERROR,
				'message' => $response->get_error_message(),
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( is_array( $body ) && ! empty( $body['slug'] ) ) {
			return array(
				'outcome' => self::FOUND,
				'data'    => $this->normalise( $body ),
			);
		}

		/*
		 * The theme API answers with null rather than a 404 for slugs it does
		 * not know, and it publishes no closure marker, so a missing theme is
		 * reported as unlisted rather than being guessed at.
		 */
		return array( 'outcome' => self::UNLISTED );
	}

	/**
	 * Reduce a directory payload to the fields the risk model reads.
	 *
	 * @param array $body Raw API body.
	 * @return array
	 */
	private function normalise( array $body ) {
		return array(
			'slug'                     => isset( $body['slug'] ) ? $body['slug'] : '',
			'version'                  => isset( $body['version'] ) ? $body['version'] : '',
			'last_updated'             => isset( $body['last_updated'] ) ? $body['last_updated'] : '',
			'added'                    => isset( $body['added'] ) ? $body['added'] : '',
			'active_installs'          => isset( $body['active_installs'] ) ? (int) $body['active_installs'] : 0,
			'rating'                   => isset( $body['rating'] ) ? (float) $body['rating'] : 0.0,
			'num_ratings'              => isset( $body['num_ratings'] ) ? (int) $body['num_ratings'] : 0,
			'support_threads'          => isset( $body['support_threads'] ) ? (int) $body['support_threads'] : 0,
			'support_threads_resolved' => isset( $body['support_threads_resolved'] ) ? (int) $body['support_threads_resolved'] : 0,
			'tested'                   => isset( $body['tested'] ) ? $body['tested'] : '',
			'requires'                 => isset( $body['requires'] ) ? $body['requires'] : '',
			'requires_php'             => isset( $body['requires_php'] ) ? $body['requires_php'] : '',
			'homepage'                 => isset( $body['homepage'] ) ? $body['homepage'] : '',
		);
	}

	/**
	 * Perform a request, retrying once before giving up.
	 *
	 * Anything on the risk path gets a second chance, because a single dropped
	 * connection must never reach the user as a finding.
	 *
	 * @param string $url Target URL.
	 * @return array|WP_Error
	 */
	private function request( $url ) {
		$args = array(
			'timeout'    => 15,
			'user-agent' => 'Deadwood/' . DEADWOOD_VERSION . '; ' . home_url( '/' ),
			'headers'    => array( 'Accept' => 'application/json, text/html' ),
		);

		$response = wp_remote_get( $url, $args );

		if ( ! is_wp_error( $response ) ) {
			$code = wp_remote_retrieve_response_code( $response );
			if ( 429 !== $code && $code < 500 ) {
				return $response;
			}
		}

		usleep( 750000 );

		return wp_remote_get( $url, $args );
	}
}
