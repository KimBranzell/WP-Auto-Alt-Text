<?php

class Auto_Alt_Text_Image_Process {
  private $openai;

  public function __construct(OpenAI $openai) {
      $this->openai = $openai;
      add_action('add_attachment', array($this, 'handle_new_attachment'));
      add_filter('attachment_fields_to_edit', array($this, 'add_custom_generate_alt_text_button'), 10, 2);
      add_action('wp_ajax_generate_alt_text_for_attachment', array($this, 'generate_alt_text_for_attachment'));
  }

  /**
   * Automatically generate alt text when a new image is uploaded.
   */
  public function auto_generate_alt_text_on_upload($attachment_id) {
    // Ensure the attachment is an image
    if (wp_attachment_is_image($attachment_id)) {
      // Get the image metadata or any relevant description
      $attachment = get_post($attachment_id);
      $image_description = $attachment->post_title; // or any other source of description

      // Generate alt text using your function
      $alt_text = generate_alt_text_with_openai($image_description);

      // Update the image's alt text
      update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field($alt_text));
    }
  }

  public function handle_new_attachment($attachment_id) {
    if (wp_attachment_is_image($attachment_id)) {
      $image_url = $this->get_image_url_for_openai($attachment_id);
      $alt_text = $this->openai->get_image_description($image_url);

      update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field($alt_text));
    }
  }

  public function add_custom_generate_alt_text_button($form_fields, $post) {
    $nonce = wp_create_nonce('generate_alt_text_nonce');
    $form_fields['generate_alt_text'] = array(
        'label' => 'Generate Alt Text',
        'input' => 'html',
        'html' => '<button class="generate-alt-text-button" id="generate-alt-text" data-attachment-id="' . $post->ID . '" data-nonce="' . $nonce . '">Generate Alternative Text with AI
        <div class="loader loader--style2" title="1" style="display: none;">
          <svg version="1.1" id="loader-1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" x="0px" y="0px"
            width="40px" height="40px" viewBox="0 0 50 50" style="enable-background:new 0 0 50 50;" xml:space="preserve">
          <path fill="#000" d="M25.251,6.461c-10.318,0-18.683,8.365-18.683,18.683h4.068c0-8.071,6.543-14.615,14.615-14.615V6.461z">
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
    // Check for the required POST variables and nonce verification
    if (!isset($_POST['attachment_id']) || !isset($_POST['nonce'])) {
      wp_die('Missing attachment ID or nonce verification failed.');
    }

    $attachment_id = intval($_POST['attachment_id']);
    $image_url = $this->get_image_url_for_openai($attachment_id);
    $nonce = sanitize_text_field($_POST['nonce']);

    // Verify the nonce
    if (!wp_verify_nonce($nonce, 'generate_alt_text_nonce')) {
      wp_die('Nonce verification failed.');
    }

    if (!$image_url) {
        wp_die('Invalid attachment ID.');
    }

    // Assuming OpenAI class is correctly set up and included
    $openai = new OpenAI();
    $alt_text = $openai->get_image_description($image_url);

    echo sanitize_text_field($alt_text);
    wp_die();
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
    return strpos($host, '.ddev.site') !== false;
  }
}

?>


