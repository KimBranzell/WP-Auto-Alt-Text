<?php
/* PHPUnit bootstrap: loads the wp-phpunit test library and this plugin. */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

$_tests_dir = getenv( 'WP_PHPUNIT__DIR' ) ?: dirname( __DIR__ ) . '/vendor/wp-phpunit/wp-phpunit';

require_once $_tests_dir . '/includes/functions.php';

tests_add_filter( 'muplugins_loaded', function () {
	require dirname( __DIR__ ) . '/wp-auto-alt-text.php';
} );

require $_tests_dir . '/includes/bootstrap.php';
