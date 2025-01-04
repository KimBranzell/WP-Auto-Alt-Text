<?php
class Auto_Alt_Text_Image_Process {
  private $openai;

  public function __construct(Auto_Alt_Text_OpenAI $openai) {
      $this->openai = $openai;
      add_action('add_attachment', array($this, 'handle_new_attachment'));
      add_filter('attachment_fields_to_edit', array($this, 'add_custom_generate_alt_text_button'), 10, 2);
      add_action('wp_ajax_generate_alt_text_for_attachment', array($this, 'generate_alt_text_for_attachment'));
      add_action('wp_ajax_process_image_batch', array($this, 'process_image_batch'));
  }

  /**
   * Handles the processing of a newly uploaded attachment.
   *
   * This method is called when a new attachment is uploaded to the WordPress media library.
   * It checks if the attachment is an image, generates alternative text for the image using the OpenAI API,
   * and updates the `_wp_attachment_image_alt` post meta with the generated alt text.
   * If the alt text generation is successful, a success notice is displayed in the WordPress admin.
   * If there is an error, an error notice is displayed instead.
   *
   * @param int $attachment_id The ID of the newly uploaded attachment.
   */
  public function handle_new_attachment($attachment_id) {
    Auto_Alt_Text_Logger::log("Processing new image upload", "info", [
      'attachment_id' => $attachment_id
    ]);

    $recently_processed = get_transient('recently_processed_' . $attachment_id);
    if ($recently_processed) {
        return;
    }

    if (!wp_attachment_is_image($attachment_id)) {
        return;
    }

    try {
        $image_url = $this->get_image_url_for_openai($attachment_id);
        $alt_text = $this->openai->generate_alt_text($image_url, $attachment_id, 'upload');

        if (empty($alt_text)) {
            throw new Exception('Failed to generate alt text');
        }

        update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field($alt_text));

        add_action('admin_notices', function() {
            echo '<div class="notice notice-success is-dismissible">';
            echo '<p>Alt text successfully generated and applied.</p>';
            echo '</div>';
        });
        error_log('Alt text generated and applied for attachment ID: ' . $attachment_id);
        // Set a transient to prevent duplicate processing
        set_transient('recently_processed_' . $attachment_id, true, 30);
    } catch (Exception $e) {
        add_action('admin_notices', function() use ($e) {
            echo '<div class="notice notice-error is-dismissible">';
            echo '<p>Error generating alt text: ' . esc_html($e->getMessage()) . '</p>';
            echo '</div>';
        });
    }
  }

  /**
   * Adds a custom "Generate Alt Text" button to the WordPress media library attachment form.
   *
   * This method checks if the OpenAI API key is configured. If not, it displays a warning notice
   * instead of the button. If the API key is set, it creates a button that, when clicked, triggers
   * the generation of alternative text for the attachment using the OpenAI API.
   *
   * @param array $form_fields The attachment form fields.
   * @param WP_Post $post The attachment post object.
   * @return array The modified attachment form fields.
   */
  public function add_custom_generate_alt_text_button($form_fields, $post) {
    $api_key = get_option('auto_alt_text_api_key');

    // If no API key is set, show a notice instead of the button
    if (empty($api_key)) {
        $form_fields['generate_alt_text'] = array(
            'label' => __('Generate Alt Text', 'wp-auto-alt-text'),
            'input' => 'html',
            'html' => '<div class="notice notice-warning inline"><p>' .
                    __('Please configure your OpenAI API key in the Auto Alt Text settings to enable AI generation.', 'wp-auto-alt-text') .
                    ' <a href="' . admin_url('options-general.php?page=auto-alt-text') . '">' .
                    __('Configure Now', 'wp-auto-alt-text') . '</a></p></div>'
        );
        return $form_fields;
    }

    $nonce = wp_create_nonce('auto_alt_text_nonce');
    $form_fields['generate_alt_text'] = array(
        'label' => __('Generate Alt Text', 'wp-auto-alt-text'),
        'input' => 'html',
        'html' => '<button class="generate-alt-text-button" id="generate-alt-text" data-attachment-id="' . $post->ID . '" data-nonce="' . $nonce . '">' . __('Generate Alternative Text with AI', 'wp-auto-alt-text') .
        '<div class="generate-alt-text-loader loader loader--style2" title="1" style="display: none;">
          <svg version="1.1" id="loader-1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" x="0px" y="0px"
            width="40px" height="40px" viewBox="0 0 50 50" style="enable-background:new 0 0 50 50;" xml:space="preserve">
          <path fill="#2271b1" d="M25.251,6.461c-10.318,0-18.683,8.365-18.683,18.683h4.068c0-8.071,6.543-14.615,14.615-14.615V6.461z">
            <animateTransform attributeType="xml"
              attributeName="transform"
              type="rotate"
              from="0 25 25"
              to="360 25 25"
              dur="0.6s"
              repeatCount="indefinite"/>
            </path>
          </svg>
        </div>
        </button>',
        // You can also include a nonce for security
    );

    return $form_fields;
  }

  /**
   * Generates alternative text for an attachment using the OpenAI API.
   *
   * This method first verifies the attachment ID and nonce, then retrieves the image URL for the
   * attachment. It then uses the Auto_Alt_Text_OpenAI class to generate alternative text for the
   * image. If the generation is successful, the alternative text is saved as post metadata for the
   * attachment. The method then returns a JSON response with the generated alternative text.
   *
   * @return void
   */
  public function generate_alt_text_for_attachment() {
    if (!isset($_POST['attachment_id']) || !isset($_POST['nonce'])) {
      wp_send_json_error('Missing attachment ID or nonce verification failed.');
    }

    $attachment_id = intval($_POST['attachment_id']);
    $image_url = $this->get_image_url_for_openai($attachment_id);
    $nonce = sanitize_text_field($_POST['nonce']);

    if (!wp_verify_nonce($nonce, 'generate_alt_text_nonce')) {
      wp_send_json_error('Nonce verification failed.');
    }

    if (!$image_url) {
      wp_send_json_error('Invalid attachment ID.');
    }

    $openai = new Auto_Alt_Text_OpenAI();
    $alt_text = $openai->generate_alt_text($image_url, $attachment_id);


    if ($alt_text) {
        update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field($alt_text));
        wp_send_json_success(array('alt_text' => $alt_text));
    } else {
        wp_send_json_error('Failed to generate alt text');
    }
  }

  /**
   * Get the image URL for OpenAI processing.
   *
   * This method checks if the current environment is a local environment, and if so, it retrieves the
   * attached file path for the given attachment ID. It then reads the file contents, encodes them in
   * base64, and returns a data URL for the image. If the file does not exist, it throws an exception.
   * If the environment is not a local environment, it simply returns the attachment URL using the
   * `wp_get_attachment_url()` function.
   *
   * @param int $attachment_id The ID of the attachment to get the image URL for.
   * @return string The image URL for OpenAI processing.
   * @throws Exception If the image file does not exist or could not be read.
   */
  public function get_image_url_for_openai($attachment_id) {
    if ($this->is_local_environment()) {
      $path = get_attached_file($attachment_id);

      if (file_exists($path)) {
          $type = pathinfo($path, PATHINFO_EXTENSION);
          $data = file_get_contents($path);
          return 'data:image/' . $type . ';base64,' . base64_encode($data);
      } else {
          throw new Exception('Image not found or could not be read.');
      }
    } else {
        return wp_get_attachment_url($attachment_id);
    }
  }

  /**
   * Checks if the current WordPress instance is running in a local environment.
   *
   * This method checks the HTTP host or server name to determine if the site is running on a local
   * development environment, such as a `.ddev.site` or `.test` domain.
   *
   * @return bool True if the site is running in a local environment, false otherwise.
   */
  private function is_local_environment() {
	$host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'];
	return strpos($host, '.ddev.site') !== false || strpos($host, '.test') !== false;
  }

  /**
   * Processes a batch of image uploads and generates alt text for each image.
   *
   * This method is called via AJAX to process a batch of image uploads. It retrieves the IDs of the
   * uploaded images from the request, generates alt text for each image using the OpenAI API, and
   * updates the post metadata with the generated alt text. The method then returns a success response
   * with the generated alt text for each image.
   */
  public function process_image_batch() {
    check_ajax_referer('auto_alt_text_batch_nonce', 'nonce');

    $ids = json_decode(stripslashes($_POST['ids']));
    $results = [];

    // Batch size validation
    if (count($ids) > 50) {
      wp_send_json_error('Batch size exceeds maximum limit of 50 images');
      return;
    }

    foreach ($ids as $id) {
      Auto_Alt_Text_Logger::log("Processing new batch image upload", "info", [
        'attachment_id' => $id
      ]);

      try {
        $image_url = $this->get_image_url_for_openai($id);
        $alt_text = $this->openai->generate_alt_text($image_url, $id, 'batch');

        if ($alt_text) {
          update_post_meta($id, '_wp_attachment_image_alt', sanitize_text_field($alt_text));
          $results[$id] = [
            'alt_text' => $alt_text,
            'status' => 'success',
            'processed_at' => current_time('mysql')
          ];
        }

        // Rate limiting between API calls
        usleep(500000);

      } catch (Exception $e) {
        $results[$id] = [
          'status' => 'error',
          'error' => $e->getMessage(),
          'processed_at' => current_time('mysql')
        ];
        continue;
      }

      // Memory management
      gc_collect_cycles();
    }

    wp_send_json_success([
      'results' => $results,
      'total_processed' => count($results),
      'batch_completed_at' => current_time('mysql')
    ]);
  }
}

?>