<?php
/**
 * Collects what is installed on this site and works out each item's slug.
 *
 * @package Deadwood
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds the list of things worth checking.
 *
 * Getting the slug right matters more than anything else here. A wrong slug
 * produces a confident wrong answer: ask the directory about a name it has
 * never heard and it will truthfully say it has no entry, which would read as
 * "never listed" for a plugin that is listed perfectly well under its real
 * slug. So the folder name is the last resort rather than the first guess.
 * WordPress already resolves installed code to directory slugs when it checks
 * for updates, and that mapping is authoritative, so it is read first.
 */
class Deadwood_Inventory {

	/**
	 * Every plugin and theme on the site, with the best slug available.
	 *
	 * @return array<int,array>
	 */
	public function collect() {
		return array_merge( $this->plugins(), $this->themes() );
	}

	/**
	 * All installed plugins.
	 *
	 * @return array<int,array>
	 */
	public function plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$installed = get_plugins();
		$active    = (array) get_option( 'active_plugins', array() );
		$network   = is_multisite() ? array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) : array();
		$slug_map  = $this->slug_map_from_update_transient();

		$rows = array();

		foreach ( $installed as $file => $meta ) {
			$slug = isset( $slug_map[ $file ] ) ? $slug_map[ $file ] : $this->slug_from_file( $file );

			$rows[] = array(
				'type'           => 'plugin',
				'file'           => $file,
				'slug'           => $slug,
				'slug_confident' => isset( $slug_map[ $file ] ),
				'name'           => isset( $meta['Name'] ) ? $meta['Name'] : $file,
				'version'        => isset( $meta['Version'] ) ? $meta['Version'] : '',
				'author'         => isset( $meta['Author'] ) ? wp_strip_all_tags( $meta['Author'] ) : '',
				'active'         => in_array( $file, $active, true ) || in_array( $file, $network, true ),
			);
		}

		return $rows;
	}

	/**
	 * All installed themes.
	 *
	 * @return array<int,array>
	 */
	public function themes() {
		$rows    = array();
		$current = get_stylesheet();
		$parent  = get_template();

		foreach ( wp_get_themes() as $stylesheet => $theme ) {
			$rows[] = array(
				'type'           => 'theme',
				'file'           => $stylesheet,
				'slug'           => $stylesheet,
				'slug_confident' => true,
				'name'           => $theme->get( 'Name' ) ? $theme->get( 'Name' ) : $stylesheet,
				'version'        => $theme->get( 'Version' ) ? $theme->get( 'Version' ) : '',
				'author'         => $theme->get( 'Author' ) ? wp_strip_all_tags( $theme->get( 'Author' ) ) : '',
				'active'         => ( $stylesheet === $current || $stylesheet === $parent ),
			);
		}

		return $rows;
	}

	/**
	 * Map plugin files to directory slugs using WordPress's own update data.
	 *
	 * The update transient holds entries for everything core has successfully
	 * matched against the directory, in both the response and no_update lists.
	 * Reading both is what makes the mapping useful, because a plugin that is
	 * already current appears only in no_update.
	 *
	 * @return array<string,string>
	 */
	private function slug_map_from_update_transient() {
		$map    = array();
		$update = get_site_transient( 'update_plugins' );

		if ( ! is_object( $update ) ) {
			return $map;
		}

		foreach ( array( 'no_update', 'response' ) as $bucket ) {
			if ( empty( $update->$bucket ) || ! is_array( $update->$bucket ) ) {
				continue;
			}

			foreach ( $update->$bucket as $file => $info ) {
				$slug = '';

				if ( is_object( $info ) && ! empty( $info->slug ) ) {
					$slug = $info->slug;
				} elseif ( is_array( $info ) && ! empty( $info['slug'] ) ) {
					$slug = $info['slug'];
				}

				if ( '' !== $slug ) {
					$map[ $file ] = $slug;
				}
			}
		}

		return $map;
	}

	/**
	 * Fall back to reading a slug out of the plugin file path.
	 *
	 * @param string $file Plugin file, such as akismet/akismet.php.
	 * @return string
	 */
	private function slug_from_file( $file ) {
		if ( false !== strpos( $file, '/' ) ) {
			$parts = explode( '/', $file );
			return $parts[0];
		}

		return basename( $file, '.php' );
	}
}
