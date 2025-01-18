<?php
/*
Plugin Name:          WP Auto Alt Text
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

/**
 * If this file is called directly, abort.
 */
if (!defined('WPINC')) {
    die;
}

/**
 * Defines the AUTO_ALT_TEXT_DEBUG constant based on the WP_DEBUG constant.
 * If the AUTO_ALT_TEXT_DEBUG constant is not defined, it is set to the value of WP_DEBUG.
 * This allows for easy debugging of the Auto Alt Text plugin.
 */
if (!defined('AUTO_ALT_TEXT_DEBUG')) {
	define('AUTO_ALT_TEXT_DEBUG', WP_DEBUG);
}

/**
 * Represents the main class for the WP Auto Alt Text plugin.
 * This class is responsible for managing the plugin's initialization, loading dependencies,
 * registering hooks and actions, and creating instances of the plugin's core components.
 */
class WP_Auto_Alt_Text_Plugin {
	private static $instance = null;
	private $components = [];

	/**
  * Returns the singleton instance of the WP_Auto_Alt_Text_Plugin class.
  *
  * This method follows the Singleton pattern to ensure that only one instance of the
  * WP_Auto_Alt_Text_Plugin class is created and used throughout the application.
  *
  * @return WP_Auto_Alt_Text_Plugin The singleton instance of the WP_Auto_Alt_Text_Plugin class.
  */
	public static function get_instance() {
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
  * Initializes the WP Auto Alt Text plugin.
  * This method performs the following tasks:
  * - Loads the plugin dependencies
  * - Initializes the core plugin components
  * - Registers the necessary hooks and actions for the plugin
  * - Adds a WP-CLI command for the plugin if WP-CLI is defined and active
  */
	public function init() {
		$this->load_dependencies();
		$this->initialize_core_components();
		$this->register_hooks();

		if (defined('WP_CLI') && WP_CLI) {
			$cli = new Auto_Alt_Text_CLI(
				$this->components['openai'],
				$this->components['statistics']
			);
			WP_CLI::add_command('auto-alt-text', $cli);
		}
	}

	/**
  * Loads the required dependencies for the Auto Alt Text plugin.
  * This method includes various class files that provide functionality
  * for the plugin, such as OpenAI integration, rate limiting, options
  * page, activator, batch processing, statistics, AJAX handling, image
  * processing, dashboard widget, page builders, WooCommerce integration,
  * CLI commands, REST API, and logging.
  */
	private function load_dependencies() {
		require_once __DIR__ . '/includes/config.php';
		require_once __DIR__ . '/includes/interfaces/interface-cli-command.php';
		require_once __DIR__ . '/includes/class-language-manager.php';
		require_once __DIR__ . '/includes/class-openai.php';
		require_once __DIR__ . '/includes/class-rate-limiter.php';
		require_once __DIR__ . '/includes/options-page.php';
		require_once __DIR__ . '/includes/class-activator.php';
		require_once __DIR__ . '/includes/class-cache-manager.php';
		require_once __DIR__ . '/includes/class-batch-processor.php';
		require_once __DIR__ . '/includes/class-statistics.php';
		require_once __DIR__ . '/includes/class-statistics-page.php';
		require_once __DIR__ . '/includes/class-ajax-handler.php';
		require_once __DIR__ . '/includes/class-image-process.php';
		require_once __DIR__ . '/includes/class-dashboard-widget.php';
		require_once __DIR__ . '/includes/class-page-builders.php';
		require_once __DIR__ . '/includes/class-woocommerce.php';
		require_once __DIR__ . '/includes/class-cli.php';
		require_once __DIR__ . '/includes/class-rest-api.php';
		require_once __DIR__ . '/includes/class-logger.php';
		require_once __DIR__ . '/admin/class-admin.php';
	}

	/**
  * Initializes the core components of the Auto Alt Text plugin.
  * This method creates instances of various classes that provide
  * functionality for the plugin, such as OpenAI integration,
  * admin interface, statistics, AJAX handling, dashboard widget,
  * and image processing.
  */
	private function initialize_core_components() {
		$this->components['language_manager'] = new Auto_Alt_Text_Language_Manager();
    $this->components['openai'] 					= new Auto_Alt_Text_OpenAI();
    $this->components['admin']						= new Auto_Alt_Text_Admin();
    $this->components['statistics'] 			= new Auto_Alt_Text_Statistics();
    $this->components['statistics_page'] 	= new Auto_Alt_Text_Statistics_Page();
    $this->components['ajax_handler'] 		= new Auto_Alt_Text_Ajax_Handler();
    $this->components['dashboard_widget'] = new Auto_Alt_Text_Dashboard_Widget();
    $this->components['image_processor'] 	= new Auto_Alt_Text_Image_Process(
			$this->components['openai'],
			$this->components['language_manager']
		);
	}

	/**
  * Registers various hooks and actions for the Auto Alt Text plugin.
  * This method sets up the following hooks:
  * - 'plugins_loaded' action to handle plugin initialization
  * - 'init' action to initialize plugin features
  * - 'admin_enqueue_scripts' action to enqueue the Auto Alt Text script in the admin area
  * - 'wp_ajax_apply_alt_text' action to handle AJAX requests for applying alt text
  * - 'admin_init' action to add privacy policy content for the plugin
  * - 'wp_privacy_personal_data_exporters' filter to add a data exporter for the plugin
  */
	private function register_hooks() {
		add_action('plugins_loaded', [$this, 'handle_plugins_loaded']);
		add_action('init', [$this, 'initialize_features']);
		add_action('admin_enqueue_scripts', [$this->components['admin'], 'enqueueAutoAltTextScript']);
		add_action('wp_ajax_apply_alt_text', [$this->components['ajax_handler'], 'apply_alt_text']);

		add_action('add_attachment', [$this->components['image_processor'], 'handle_new_attachment']);
    add_action('edit_attachment', [$this->components['image_processor'], 'handle_attachment_update']);


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
	}

	/**
  * Handles the initialization of the Auto Alt Text plugin when it is loaded.
  * This method performs the following tasks:
  * - Loads the plugin's text domain for internationalization
  * - Activates the plugin using the Auto_Alt_Text_Activator class
  * - Checks if the WooCommerce plugin is active and creates an instance of Auto_Alt_Text_WooCommerce if so
  */
	public function handle_plugins_loaded() {
		load_plugin_textdomain('wp-auto-alt-text', false, basename(dirname(__FILE__)) . '/languages');
		Auto_Alt_Text_Activator::activate();

		if (class_exists('WooCommerce')) {
				new Auto_Alt_Text_WooCommerce();
		}
	}

	/**
  * Initializes the various features of the Auto Alt Text plugin.
  * This method creates instances of the following classes:
  * - Auto_Alt_Text_Page_Builders: Handles integration with page builder plugins
  * - Auto_Alt_Text_Logger: Provides logging functionality for the plugin
  * - Auto_Alt_Text_REST_API: Implements the plugin's REST API endpoints
  */
	public function initialize_features() {
		new Auto_Alt_Text_Page_Builders();
		new Auto_Alt_Text_Logger();
		new Auto_Alt_Text_REST_API();
	}
}

WP_Auto_Alt_Text_Plugin::get_instance()->init();