<?php
/**
 * PHPUnit bootstrap file
 *
 * @package Woo_Razorpay
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	throw new Exception( "Could not find $_tests_dir/includes/functions.php, have you run bin/install-wp-tests.sh ?" );
}

// Give access to tests_add_filter() function.
require_once $_tests_dir . '/includes/functions.php';

/**
 * Manually load the plugin being tested. And also woocommerce pligin
 */
function _manually_load_plugin() {
	require dirname( dirname( __FILE__ ) ) . '/woo-razorpay.php';
	require dirname( dirname( __FILE__ ) ) . '../../woocommerce/woocommerce.php';
}

function is_woocommerce_active() {
   return true;
}

function woothemes_queue_update($file, $file_id, $product_id) {
   return true;
}

tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

// Start up the WP testing environment.
require $_tests_dir . '/includes/bootstrap.php';

$wc_tests_framework_base_dir = dirname( dirname( __FILE__ ) ) . '../../woocommerce/tests/framework/';
require_once( $wc_tests_framework_base_dir . 'helpers/class-wc-helper-customer.php'  );
require_once( $wc_tests_framework_base_dir . 'helpers/class-wc-helper-product.php'  );
require_once( $wc_tests_framework_base_dir . 'helpers/class-wc-helper-shipping.php'  );
require_once( $wc_tests_framework_base_dir . 'helpers/class-wc-helper-order.php'  );
