<?php
/**
 * Plugin Name:       Deadwood
 * Plugin URI:        https://github.com/Android-Tipster/deadwood
 * Description:       Finds plugins and themes on this site that have been abandoned by their authors or pulled from the WordPress.org directory, and tells you the day it happens.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Noah Albert
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       deadwood
 *
 * @package Deadwood
 */

defined( 'ABSPATH' ) || exit;

define( 'DEADWOOD_VERSION', '1.0.0' );
define( 'DEADWOOD_FILE', __FILE__ );
define( 'DEADWOOD_DIR', plugin_dir_path( __FILE__ ) );
define( 'DEADWOOD_URL', plugin_dir_url( __FILE__ ) );

require_once DEADWOOD_DIR . 'includes/class-deadwood-verdict.php';
require_once DEADWOOD_DIR . 'includes/class-deadwood-registry.php';
require_once DEADWOOD_DIR . 'includes/class-deadwood-scoring.php';
require_once DEADWOOD_DIR . 'includes/class-deadwood-inventory.php';
require_once DEADWOOD_DIR . 'includes/class-deadwood-scanner.php';
require_once DEADWOOD_DIR . 'includes/class-deadwood-store.php';
require_once DEADWOOD_DIR . 'includes/class-deadwood-scheduler.php';
require_once DEADWOOD_DIR . 'includes/class-deadwood-notifier.php';
require_once DEADWOOD_DIR . 'includes/class-deadwood-admin.php';
require_once DEADWOOD_DIR . 'includes/class-deadwood-site-health.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once DEADWOOD_DIR . 'includes/class-deadwood-cli.php';
	WP_CLI::add_command( 'deadwood', 'Deadwood_CLI' );
}

/**
 * Boot the plugin once WordPress has loaded its own pluggable pieces.
 */
function deadwood_boot() {
	Deadwood_Scheduler::init();
	Deadwood_Admin::init();
	Deadwood_Site_Health::init();
}
add_action( 'plugins_loaded', 'deadwood_boot' );

register_activation_hook( __FILE__, array( 'Deadwood_Scheduler', 'on_activate' ) );
register_deactivation_hook( __FILE__, array( 'Deadwood_Scheduler', 'on_deactivate' ) );
