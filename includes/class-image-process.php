<?php
if ( ! defined( 'ABSPATH' ) ) exit;
class Auto_Alt_Text_Image_Process {
  private $openai;
  private $language_manager;

  public function __construct(Auto_Alt_Text_OpenAI $openai, Auto_Alt_Text_Language_Manager $language_manager) {
      $this->openai = $openai;
      $this->language_manager = $language_manager;

      // Priority 20: WPML and Polylang assign the attachment language at 10.
      add_action('add_attachment', array($this, 'handle_new_attachment'), 20);
      add_filter('attachment_fields_to_edit', array($this, 'add_custom_generate_alt_text_button'), 10, 2);
      add_filter('attachment_fields_to_edit', array($this, 'add_context_field'), 11, 2);
  }

  /**
   * Queues a newly uploaded image for background alt text generation.
   *
   * The attachment's language belongs to WPML/Polylang; generation reads it
   * when the job runs. No API call happens in the upload request.
   *
   * @param int $attachment_id The ID of the newly uploaded attachment.
   */
  public function handle_new_attachment($attachment_id) {
    if (!wp_attachment_is_image($attachment_id)) {
        return;
    }

    if (!get_option('auto_alt_text_auto_generate', true)) {
        return;
    }

    $queued = (new Auto_Alt_Text_Queue())->enqueue($attachment_id, 'upload');

    Auto_Alt_Text_Logger::log("Attachment queued for alt text generation", "info", [
        'attachment_id' => $attachment_id,
        'queued' => $queued,
    ]);
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
    if (empty($api_key) && !defined('AUTO_ALT_TEXT_OPENAI_API_KEY')) {
        $form_fields['generate_alt_text'] = array(
            'label' => __('Generate Alt Text', 'WP-Auto-Alt-Text'),
            'input' => 'html',
            'html' => '<div class="notice notice-warning inline"><p>' .
                    esc_html__('Please configure your OpenAI API key in the Auto Alt Text settings to enable AI generation.', 'WP-Auto-Alt-Text') .
                    ' <a href="' . esc_url(admin_url('admin.php?page=auto-alt-text')) . '">' .
                    esc_html__('Configure Now', 'WP-Auto-Alt-Text') . '</a></p></div>'
        );
        return $form_fields;
    }

    $nonce = wp_create_nonce('auto_alt_text_nonce');
    $form_fields['generate_alt_text'] = array(
        'label' => __('Generate Alt Text', 'WP-Auto-Alt-Text'),
        'input' => 'html',
        'html' => '<button class="generate-alt-text-button" id="generate-alt-text" data-attachment-id="' . $post->ID . '" data-nonce="' . $nonce . '">' . __('Generate Alternative Text with AI', 'WP-Auto-Alt-Text') .
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
   * "Alt-textkontext" (generation-quality spec §4a): which product, page or
   * post the alt text is tied to. Resolved here only on the attachment edit
   * screen. Media modals receive these fields in AJAX grid responses for
   * every attachment, so there a placeholder is rendered and app.js fills it.
   */
  public function add_context_field($form_fields, $post) {
    if (!$post instanceof WP_Post || !wp_attachment_is_image($post->ID)) {
        return $form_fields;
    }

    if (self::is_attachment_edit_screen()) {
        $text = Auto_Alt_Text_Context::describe(Auto_Alt_Text_Context::explain($post->ID));
        $html = '<span class="aat-alt-context" data-loaded="1">' . esc_html($text) . '</span>';
    } else {
        // Filled later by app.js: a status region named after the field's label.
        $html = '<span class="aat-alt-context" role="status" aria-live="polite" aria-label="' . esc_attr__('Alt-textkontext', 'WP-Auto-Alt-Text')
            . '" data-attachment-id="' . esc_attr($post->ID)
            . '" data-nonce="' . esc_attr(wp_create_nonce('auto_alt_text_nonce')) . '"></span>';
    }

    $form_fields['aat_alt_context'] = array(
        'label' => __('Alt-textkontext', 'WP-Auto-Alt-Text'),
        'input' => 'html',
        'html'  => $html,
    );
    return $form_fields;
  }

  private static function is_attachment_edit_screen() {
    if (wp_doing_ajax() || !function_exists('get_current_screen')) {
        return false;
    }
    $screen = get_current_screen();
    return $screen && 'post' === $screen->base && 'attachment' === $screen->post_type;
  }
}
?>
