<?php
/*
Plugin Name:          Auto Alt Text
Plugin URI:           https://branzell.kim
Author:               Kim Branzell
Author URI:           https://branzell.kim
Description:          Automatically generates alt text for images in the Media Library using OpenAI.
Version:              1.0
Requires at least:    6.0
Requires PHP:         8.0
License:              Apache License 2.0 (Apache-2.0)
License URI:          https://www.apache.org/licenses/LICENSE-2.0
Text Domain:          auto-alt-text-plugin
Domain Path:          /languages
*/

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Include dependencies
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/class-openai.php';
require_once __DIR__ . '/includes/class-rate-limiter.php';
require_once __DIR__ . '/includes/options-page.php';
require_once __DIR__ . '/includes/class-activator.php';
require_once __DIR__ . '/includes/class-statistics.php';
require_once __DIR__ . '/includes/class-statistics-page.php';
require_once __DIR__ . '/includes/class-ajax-handler.php';
require_once __DIR__ . '/includes/class-image-process.php';
require_once __DIR__ . '/includes/class-dashboard-widget.php';
require_once __DIR__ . '/admin/class-admin.php';

register_activation_hook(__FILE__, ['Auto_Alt_Text_Activator', 'activate']);

$openai = new Auto_Alt_Text_OpenAI();
$admin = new Auto_Alt_Text_Admin();
$statistics = new Auto_Alt_Text_Statistics();
$statistics_page = new Auto_Alt_Text_Statistics_Page();
$ajax_handler = new Auto_Alt_Text_Ajax_Handler();
$dashboard_widget = new Auto_Alt_Text_Dashboard_Widget();
$image_processor = new Auto_Alt_Text_Image_Process($openai);


add_action('admin_enqueue_scripts', [$admin, 'enqueueAutoAltTextScript']);

add_action('admin_init', function() {
	if (function_exists('wp_add_privacy_policy_content')) {
			wp_add_privacy_policy_content(
					'WP Auto Alt Text',
					Auto_Alt_Text_OpenAI::get_privacy_policy_content()['content']
			);
	}
});

add_filter('wp_privacy_personal_data_exporters', function($exporters) {
	$exporters['wp-auto-alt-text'] = array(
			'exporter_friendly_name' => __('WP Auto Alt Text Generated Content'),
			'callback' => array(new Auto_Alt_Text_OpenAI(), 'export_user_data'),
	);
	return $exporters;
});

// Load text domain for translations
function myplugin_load_textdomain() {
	load_plugin_textdomain( 'wp-auto-alt-text', false, basename( dirname( __FILE__ ) ) . '/languages' );
}

add_action( 'plugins_loaded', 'myplugin_load_textdomain' );
add_action(	'plugins_loaded', ['Auto_Alt_Text_Activator', 'activate'] );
add_action(	'add_attachment', [$image_processor, 'auto_generate_alt_text_on_upload'] );
add_action(	'wp_ajax_apply_alt_text', [$ajax_handler, 'apply_alt_text']	);


// Code to initialize the plugin functionality
if (!function_exists('auto_alt_text_run')) {
  function auto_alt_text_run() {
	$openai = new Auto_Alt_Text_OpenAI();
	$plugin_admin = new Auto_Alt_Text_Admin();
	$plugin_image_process = new Auto_Alt_Text_Image_Process($openai);
  }
}

auto_alt_text_run();
