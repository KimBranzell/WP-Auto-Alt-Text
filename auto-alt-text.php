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
License:              Do What The F*ck You Want To Public License v2 (WTFPL-2.0)
License URI:          http://www.wtfpl.net/
Text Domain:          auto-alt-text-plugin
Domain Path:          /languages
*/

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Include dependencies
require_once __DIR__ . '/includes/class-openai.php';
require_once __DIR__ . '/includes/class-image-process.php';
require_once __DIR__ . '/includes/options-page.php';
require_once __DIR__ . '/admin/class-admin.php';


// Code to initialize the plugin functionality
if (!function_exists('auto_alt_text_run')) {
  function auto_alt_text_run() {
      $openai = new OpenAI();
      $plugin_admin = new Auto_Alt_Text_Admin();
      $plugin_image_process = new Auto_Alt_Text_Image_Process($openai);
  }
}

auto_alt_text_run();