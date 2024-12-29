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
require_once __DIR__ . '/includes/class-image-process.php';
require_once __DIR__ . '/includes/class-rate-limiter.php';
require_once __DIR__ . '/includes/options-page.php';
require_once __DIR__ . '/admin/class-admin.php';

// Load text domain for translations
function myplugin_load_textdomain() {
	load_plugin_textdomain( 'wp-auto-alt-text', false, basename( dirname( __FILE__ ) ) . '/languages' );
}

add_action( 'plugins_loaded', 'myplugin_load_textdomain' );

// Code to initialize the plugin functionality
if (!function_exists('auto_alt_text_run')) {
  function auto_alt_text_run() {
	$openai = new OpenAI();
	$plugin_admin = new Auto_Alt_Text_Admin();
	$plugin_image_process = new Auto_Alt_Text_Image_Process($openai);
  }
}

auto_alt_text_run();
