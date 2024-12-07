<?php
class Auto_Alt_Text_Admin {
  private const SCRIPT_HANDLE = 'auto-alt-text-admin-js';
  private const SCRIPT_PATH = 'js/admin.js';
  private const STYLE_HANDLE = 'auto_alt_text_css';

  /**
   * Constructor for the admin class.
   */
  public function __construct() {
      add_action('admin_enqueue_scripts', [$this, 'enqueueAutoAltTextScript']);
      add_action('admin_enqueue_scripts', [$this, 'enqueueStyles']);
  }

  /**
   * Enqueues the plugin's CSS styles for the admin area.
   */
  public function enqueueStyles(): void {
    wp_enqueue_style(
        self::STYLE_HANDLE,
        plugin_dir_url(dirname(__FILE__)) . 'css/style.css'
    );
  }

  /**
   * Enqueue the Auto Alt Text script for the admin area.
   */
  public function enqueueAutoAltTextScript(): void {
      $scriptUrl = plugin_dir_url(__FILE__) . self::SCRIPT_PATH;
      wp_enqueue_script(self::SCRIPT_HANDLE, $scriptUrl, ['jquery'], '1.0.0', true);

      // Localize the script with new data
      wp_localize_script(self::SCRIPT_HANDLE, 'autoAltTextData', [
          'ajaxurl' => admin_url('admin-ajax.php'),
          // 'nonce' => wp_create_nonce('your_nonce') // Uncomment if using a nonce
      ]);
  }
}