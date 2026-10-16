<?php
/* PHPUnit bootstrap: loads the wp-phpunit test library, WooCommerce (when present) and this plugin. */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

$_tests_dir = getenv( 'WP_PHPUNIT__DIR' ) ?: dirname( __DIR__ ) . '/vendor/wp-phpunit/wp-phpunit';

require_once $_tests_dir . '/includes/functions.php';

// WooCommerce is optional: set WC_PLUGIN_FILE, or unzip it into .wp-core/wp-content/plugins/.
$_wc_plugin_file = getenv( 'WC_PLUGIN_FILE' ) ?: dirname( __DIR__ ) . '/.wp-core/wp-content/plugins/woocommerce/woocommerce.php';

tests_add_filter( 'muplugins_loaded', function () use ( $_wc_plugin_file ) {
	if ( file_exists( $_wc_plugin_file ) ) {
		require $_wc_plugin_file;
	}
	require dirname( __DIR__ ) . '/wp-auto-alt-text.php';
} );

tests_add_filter( 'setup_theme', function () {
	if ( class_exists( 'WC_Install' ) ) {
		WC_Install::install();
		// Reload roles so the capabilities WooCommerce just added are visible.
		$GLOBALS['wp_roles'] = null;
		wp_roles();
	}
} );

require $_tests_dir . '/includes/bootstrap.php';
