<?php

// path to test lib bootstrap.php
$test_lib_bootstrap_file = dirname( __FILE__ ) . '/includes/bootstrap.php';

if ( ! file_exists( $test_lib_bootstrap_file ) ) {
    echo PHP_EOL . "Error : unable to find " . $test_lib_bootstrap_file . PHP_EOL;
    exit( '' . PHP_EOL );
}

// set plugin and options for activation
$GLOBALS[ 'wp_tests_options' ] = array(
    'active_plugins' => array(
        'woocommerce/woocommerce.php',
        basename(realpath(dirname(__FILE__) . '/../../')) . '/woo-razorpay.php'
    ),
    'wpsp_test' => true
);

// call test-lib's bootstrap.php
require_once $test_lib_bootstrap_file;

require_once 'tests/phpunit/util/class-util.php';
require_once PLUGIN_DIR . '/vendor/autoload.php';
$current_user = new WP_User( 1 );
$current_user->set_role( 'administrator' );

echo PHP_EOL;
echo 'Using Wordpress core : ' . ABSPATH . PHP_EOL;
echo PHP_EOL;
