<?php
class Auto_Alt_Text_Admin {
  private const SCRIPT_HANDLE = 'auto-alt-text-admin-js';
  private const SCRIPT_PATH = 'js/admin.js';

  /**
   * Constructor for the admin class.
   */
  public function __construct() {
      add_action('admin_enqueue_scripts', [$this, 'enqueueAutoAltTextScript']);
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