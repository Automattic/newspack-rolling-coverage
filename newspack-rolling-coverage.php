<?php
/**
 * Plugin Name: Newspack Rolling Coverage
 * Description: Live blog and rolling coverage of ongoing news events.
 * Version: 0.1.0
 * Author: Automattic
 * Author URI: https://newspack.com/
 * License: GPL3
 * Text Domain: newspack-rolling-coverage
 * Domain Path: /languages/
 *
 * @package newspack-rolling-coverage
 */

defined( 'ABSPATH' ) || exit;

// Define NEWSPACK_ROLLING_COVERAGE_PLUGIN_DIR.
if ( ! defined( 'NEWSPACK_ROLLING_COVERAGE_PLUGIN_DIR' ) ) {
	define( 'NEWSPACK_ROLLING_COVERAGE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}

// Define NEWSPACK_ROLLING_COVERAGE_PLUGIN_FILE.
if ( ! defined( 'NEWSPACK_ROLLING_COVERAGE_PLUGIN_FILE' ) ) {
	define( 'NEWSPACK_ROLLING_COVERAGE_PLUGIN_FILE', __FILE__ );
}

define( 'NEWSPACK_ROLLING_COVERAGE_VERSION', '0.1.0' );
define( 'NEWSPACK_ROLLING_COVERAGE_URL', plugin_dir_url( __FILE__ ) );
define( 'NEWSPACK_ROLLING_COVERAGE_REST_NAMESPACE', 'rolling-coverage/v1' );

require_once __DIR__ . '/vendor/autoload.php';

Newspack_Rolling_Coverage\Initializer::init();

// Load language files.
add_action(
	'init',
	function () {
		load_plugin_textdomain( 'newspack-rolling-coverage', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}
);
