<?php

// change the next line to points to your wordpress dir
if (file_exists(realpath(dirname(__FILE__) . '/../../').'/.wp-install/web/'))
{
    define( 'ABSPATH',  realpath(dirname(__FILE__) . '/../../').'/.wp-install/web/');
}
else
{
    define( 'ABSPATH',  realpath(dirname(__FILE__) . '/../../../../../').'/');
}

define( 'PLUGIN_DIR',  realpath(dirname(__FILE__) . '/../../'));

define( 'WP_DEBUG', false );

// WARNING WARNING WARNING!
// tests DROPS ALL TABLES in the database. DO NOT use a production database

define( 'DB_NAME', 'wptt_tests' );
define( 'DB_USER', 'root' );
define( 'DB_PASSWORD', 'root' );
define( 'DB_HOST', '127.0.0.1' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );

$table_prefix = 'wpttests'; // Only numbers, letters, and underscores please!

define( 'WP_TESTS_DOMAIN', '127.0.0.1' );
define( 'WP_TESTS_EMAIL', 'admin@wp.test' );
define( 'WP_TESTS_TITLE', 'Test Blog' );

define( 'WP_PHP_BINARY', 'php' );

define( 'WPLANG', '' );
