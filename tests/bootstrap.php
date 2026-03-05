<?php
/**
 * PHPUnit bootstrap file for Bunny.net Offload by Gelform.
 *
 * @package BunnyNetOffloadGelform
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

// Forward-compat with WP test suite.
if ( ! file_exists( "{$_tests_dir}/includes/functions.php" ) ) {
	echo "Could not find {$_tests_dir}/includes/functions.php\nSet WP_TESTS_DIR env variable.\n"; // phpcs:ignore
	exit( 1 );
}

require_once "{$_tests_dir}/includes/functions.php";

/**
 * Load the plugin.
 */
function _manually_load_plugin() {
	require dirname( __DIR__ ) . '/bunny-net-offload-gelform.php';
}
tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

require "{$_tests_dir}/includes/bootstrap.php";
