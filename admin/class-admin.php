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

  /**
   * Registers the AJAX handler for processing image batches.
   */
  public function register_ajax_handlers() {
      add_action('wp_ajax_process_image_batch', [$this, 'handle_batch_processing']);
  }

  /**
   * Handles the processing of a batch of images for automatic alt text generation.
   *
   * This method is called via AJAX when the user initiates a batch processing operation.
   * It checks the AJAX nonce, retrieves the attachment IDs from the request, creates a
   * new Auto_Alt_Text_Batch_Processor instance, and processes the batch. The results
   * are then sent back to the client as a JSON response.
   */
  public function handle_batch_processing() {
      check_ajax_referer('auto_alt_text_batch_nonce', 'nonce');

      $attachment_ids = isset($_POST['ids']) ? array_map('intval', $_POST['ids']) : [];
      $batch_processor = new Auto_Alt_Text_Batch_Processor();
      $results = $batch_processor->process_batch($attachment_ids);

      wp_send_json_success($results);
  }
}