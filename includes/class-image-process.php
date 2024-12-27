<?php

class Auto_Alt_Text_Image_Process {
  private $openai;

  public function __construct(OpenAI $openai) {
      $this->openai = $openai;
      add_action('add_attachment', array($this, 'handle_new_attachment'));
      add_filter('attachment_fields_to_edit', array($this, 'add_custom_generate_alt_text_button'), 10, 2);
      add_action('wp_ajax_generate_alt_text_for_attachment', array($this, 'generate_alt_text_for_attachment'));
      add_action('wp_ajax_process_image_batch', array($this, 'process_image_batch'));
  }

  /**
   * Automatically generate alt text when a new image is uploaded.
   */
  public function auto_generate_alt_text_on_upload($attachment_id) {
    // Ensure the attachment is an image
    if (wp_attachment_is_image($attachment_id)) {
      $image_url = wp_get_attachment_url($attachment_id);

      // Generate alt text using consolidated method
      $alt_text = $this->openai->generate_alt_text($image_url, $attachment_id);

      // Update the image's alt text
      if ($alt_text) {
          update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field($alt_text));
      }
    }
  }

  public function handle_new_attachment($attachment_id) {
    if (!wp_attachment_is_image($attachment_id)) {
        return;
    }

    try {
        $image_url = $this->get_image_url_for_openai($attachment_id);
        $alt_text = $this->openai->get_image_description($image_url);

        if (empty($alt_text)) {
            throw new Exception('Failed to generate alt text');
        }

        update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field($alt_text));

        add_action('admin_notices', function() {
            echo '<div class="notice notice-success is-dismissible">';
            echo '<p>Alt text successfully generated and applied.</p>';
            echo '</div>';
        });
    } catch (Exception $e) {
        add_action('admin_notices', function() use ($e) {
            echo '<div class="notice notice-error is-dismissible">';
            echo '<p>Error generating alt text: ' . esc_html($e->getMessage()) . '</p>';
            echo '</div>';
        });
    }
  }

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

    $nonce = wp_create_nonce('generate_alt_text_nonce');
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

    $openai = new OpenAI();
    $alt_text = $openai->get_image_description($image_url);


    if ($alt_text) {
        update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field($alt_text));
        wp_send_json_success(array('alt_text' => $alt_text));
    } else {
        wp_send_json_error('Failed to generate alt text');
    }
  }

  /**
   * Get the image URL for OpenAI processing.
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
   * Check if the WordPress instance is running in a local environment.
   */
  private function is_local_environment() {
	$host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'];
	return strpos($host, '.ddev.site') !== false || strpos($host, '.test') !== false;
  }

  public function process_image_batch() {
    check_ajax_referer('auto_alt_text_batch_nonce', 'nonce');

    $ids = json_decode(stripslashes($_POST['ids']));
    $results = [];

    foreach ($ids as $id) {
      $image_url = $this->get_image_url_for_openai($id);
      $alt_text = $this->openai->get_image_description($image_url);

      if ($alt_text) {
        update_post_meta($id, '_wp_attachment_image_alt', sanitize_text_field($alt_text));
        $results[$id] = $alt_text;
      }
    }

    wp_send_json_success($results);
  }
}

?>