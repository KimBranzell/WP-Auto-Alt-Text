<?php
class Auto_Alt_Text_OpenAI  {
    private const API_URL = 'https://api.openai.com/v1/chat/completions';
    private const MODEL = 'gpt-4o';
    private const MAX_TOKENS = 300;
    private const AUTO_GENERATE_OPTION = 'auto_alt_text_auto_generate';

    private $api_key;
    private $last_error;
    private $rate_limiter;
    private $statistics;
    private $language_manager;

    public function __construct() {
        $encrypted_key = get_option('auto_alt_text_api_key');
        $this->api_key = $encrypted_key ? $this->decrypt_api_key($encrypted_key) : '';
        $this->rate_limiter = new Auto_Alt_Text_Rate_Limiter();
        $this->statistics = new Auto_Alt_Text_Statistics();
        $this->language_manager = new Auto_Alt_Text_Language_Manager();
    }

    /**
     * Encrypts the given API key using the AUTH_SALT constant.
     *
     * This method is used to securely store the API key in the WordPress options table.
     *
     * @param string $key The API key to encrypt.
     * @return string The encrypted API key.
     */
    public function encrypt_api_key($key) {
        if (!defined('AUTH_SALT')) {
            return $key;
        }
        $iv = substr(AUTH_SALT, 0, 16);
        return base64_encode(openssl_encrypt($key, 'AES-256-CBC', AUTH_SALT, 0, $iv));
    }

    /**
     * Decrypts the given encrypted API key using the AUTH_SALT constant.
     *
     * This method is used to retrieve the API key from the securely stored encrypted value in the WordPress options table.
     *
     * @param string $encrypted_key The encrypted API key to decrypt.
     * @return string The decrypted API key.
     */
    public function decrypt_api_key($encrypted_key) {
        if (!defined('AUTH_SALT')) {
            return $encrypted_key;
        }
        $iv = substr(AUTH_SALT, 0, 16);
        return openssl_decrypt(base64_decode($encrypted_key), 'AES-256-CBC', AUTH_SALT, 0, $iv);
    }

    /**
     * Registers a WordPress setting for the "auto_alt_text_auto_generate" option.
     *
     * This setting controls whether the plugin should automatically generate alt text for images.
     * The setting is of type "boolean" and has a default value of "true".
     * The "rest_sanitize_boolean" function is used to sanitize the input value.
     */
    public function register_settings() {
        register_setting(
            'auto_alt_text_settings',
            self::AUTO_GENERATE_OPTION,
            [
                'type' => 'boolean',
                'default' => true,
                'sanitize_callback' => 'rest_sanitize_boolean'
            ]
        );
    }

    /**
     * Retrieves the privacy policy content for the WP Auto Alt Text plugin.
     *
     * The returned array contains the title and content of the privacy policy.
     * The title is localized using the `__()` function, and the content is also localized.
     *
     * @return array An array containing the privacy policy title and content.
     */
    public static function get_privacy_policy_content() {
        return array(
            'title' => __('WP Auto Alt Text Privacy Notice'),
            'content' => __('This plugin processes images through OpenAI\'s API to generate alt text. Image URLs are temporarily shared with OpenAI for processing. No personal data is permanently stored by the service. Generated alt texts are stored in your WordPress database. You can delete this data at any time through the Media Library.')
        );
    }

    /**
     * Exports the generated alt text data for the given user.
     *
     * This function retrieves all the attachments (images) uploaded by the specified user,
     * and then extracts the generated alt text for each attachment. The generated alt text
     * is returned as an array of associative arrays, where each inner array has a 'name'
     * and 'value' key.
     *
     * @param int $user_id The ID of the user whose alt text data should be exported.
     * @return array An array of associative arrays, where each inner array contains the
     *               'name' and 'value' of the generated alt text for a user's attachment.
     */
    public function export_user_data($user_id) {
        $attachments = get_posts(array(
            'post_type' => 'attachment',
            'author' => $user_id,
            'posts_per_page' => -1
        ));

        $data = array();
        foreach ($attachments as $attachment) {
            $alt_text = get_post_meta($attachment->ID, '_wp_attachment_image_alt', true);
            if ($alt_text) {
                $data[] = array(
                    'name' => __('Generated Alt Text'),
                    'value' => $alt_text
                );
            }
        }
        return $data;
    }

    /**
     * Saves the provided API key after encrypting it.
     *
     * This method is used to securely store the OpenAI API key in the WordPress options table.
     * The key is encrypted before being saved to ensure it is not stored in plain text.
     *
     * @param string $key The OpenAI API key to be saved.
     */
    public function save_api_key($key) {
        $encrypted_key = $this->encrypt_api_key($key);
        update_option('auto_alt_text_api_key', $encrypted_key);
    }

    /**
     * Returns the last error that occurred during the API call.
     *
     * This method can be used to retrieve the error message that was generated
     * during the last failed API call. It is useful for debugging and error
     * handling purposes.
     *
     * @return string The last error message, or an empty string if no error occurred.
     */
    public function get_last_error() {
        return $this->last_error;
    }

    /**
     * Tests the connection to the OpenAI API by sending a simple test message.
     *
     * This method is used to verify that the OpenAI API key is configured correctly and that the
     * connection to the API is working as expected. It sends a simple 'Test connection' message
     * to the API and returns `true` if the connection is successful, or `false` if an exception
     * is thrown.
     *
     * @return bool True if the connection to the OpenAI API is successful, false otherwise.
     */
    public function test_api_key() {
        try {
            $response = $this->callAPI([
                'model' => self::MODEL,
                'messages' => [
                    ['role' => 'user', 'content' => 'Test connection']
                ],
                'max_tokens' => 5
            ]);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Converts an AVIF image to a temporary JPEG file.
     *
     * This private function checks the MIME type of the provided image URL. If the MIME type is 'image/avif',
     * it creates a unique temporary JPEG file, converts the AVIF image to the temporary JPEG, and returns
     * an array with information about the conversion. If the MIME type is not 'image/avif', it returns an
     * array indicating that no conversion was performed.
     *
     * @param string $image_url The URL of the image to be converted.
     * @return array An array with information about the conversion, including whether the conversion was
     *               performed, the path to the temporary JPEG file, and the original image URL.
     */
    private function convert_if_avif($image_path) {
        $mime_type = wp_check_filetype($image_path)['type'];

        if ($mime_type === 'image/avif') {
            // Create unique temp file name
            $upload_dir = wp_upload_dir();
            $temp_jpg_path = $upload_dir['path'] . '/temp_' . uniqid() . '.jpg';

            // Convert AVIF to temporary JPG
            $image = imagecreatefromavif($image_path);
            imagejpeg($image, $temp_jpg_path, 90);
            imagedestroy($image);

            return [
                'path' => $temp_jpg_path,
                'is_temp' => true
            ];
        }

        return [
            'path' => $image_path,
            'is_temp' => false
        ];
    }

    /**
     * Translates the given image description using the OpenAI API.
     *
     * This method sends a request to the OpenAI API to translate the provided image description prompt. It sets the
     * system message to indicate that the AI should act as a professional translator, and passes the prompt as the
     * user message. The translated text is then returned, or null if an error occurs.
     *
     * @param string $prompt The image description prompt to be translated.
     * @return string|null The translated image description, or null if an error occurred.
     */
    public function translate_alt_text($prompt) {
        $response_data = $this->callAPI([
            'model' => self::MODEL,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You are a professional translator. Translate the given image description accurately while maintaining its descriptive quality.'
                ],
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ],
            'max_tokens' => 150,
            'temperature' => 0.7
        ]);

        if ($response_data && isset($response_data['choices'][0]['message']['content'])) {
            return trim($response_data['choices'][0]['message']['content']);
        }

        return null;
    }

    /**
     * Generates alt text for an image using the OpenAI API.
     *
     * This method is responsible for generating alt text for an image by sending a request to the OpenAI API.
     * It first checks if the alt text has been cached, and if not, it generates a new alt text description
     * using the OpenAI API. The generated alt text is then saved to the post meta and cached for future use.
     *
     * @param string $image_source The source of the image, such as the image URL or file path.
     * @param int $attachment_id The ID of the attachment for which the alt text is being generated.
     * @param string $generation_type The type of alt text generation, either 'manual' or 'auto'.
     * @param bool $preview_mode Whether the alt text is being generated in preview mode.
     * @return string|null The generated alt text, or null if an error occurred.
     */
    public function generate_alt_text($image_source, $attachment_id, $generation_type = 'manual', $preview_mode = false) {
        Auto_Alt_Text_Logger::log("Starting alt text generation", "info", [
            'attachment_id' => $attachment_id,
            'type' => $generation_type,
            'preview' => $preview_mode
        ]);

        if (!get_option('auto_alt_text_auto_generate', true)) {
            return null;
        }

        // Get file path from attachment ID
        $image_path = get_attached_file($attachment_id);

        // Check cache first using our enhanced Cache Manager
        if (!$preview_mode) {
            $cached_response = Auto_Alt_Text_Cache_Manager::get_cached_response($image_path);
            if ($cached_response !== false) {
                Auto_Alt_Text_Logger::log("Retrieved alt text from cache", "info", [
                    'attachment_id' => $attachment_id
                ]);
                return $cached_response;
            }
        }

        $current_language = $this->language_manager->get_current_language();


        if (empty($this->api_key)) {
            $this->last_error = 'OpenAI API key is not configured';
            return null;
        }

        // Always get file path from attachment ID
        $image_path = get_attached_file($attachment_id);

        // Handle AVIF conversion if needed
        $processed_image = $this->convert_if_avif($image_path);

        // Convert file to base64
        $image_data = base64_encode(file_get_contents($processed_image['path']));
        $image_url = 'data:image/jpeg;base64,' . $image_data;

        try {
            $instruction = $this->get_instruction();
            $response_data = $this->callAPI([
                'model' => self::MODEL,
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => [
                            ['type' => 'text', 'text' => $instruction],
                            ['type' => 'image_url', 'image_url' => ['url' => $image_url]]
                        ]
                    ]
                ],
                'max_tokens' => self::MAX_TOKENS
            ]);

            if ($response_data && isset($response_data['choices'][0]['message']['content'])) {
                $generated_text = $response_data['choices'][0]['message']['content'];
                $tokens_used = $response_data['usage']['total_tokens'];

                // Track the generation
                $this->statistics->track_generation(
                    $attachment_id,
                    $generated_text,
                    $tokens_used,
                    $generation_type,
                    !$preview_mode
                );

                // Only save alt text if not in preview mode
                if (!$preview_mode) {
                    update_post_meta($attachment_id, '_wp_attachment_image_alt', $generated_text);
                }

                if (!$preview_mode && $generated_text) {
                    Auto_Alt_Text_Cache_Manager::set_cached_response($image_path, $generated_text);
                    Auto_Alt_Text_Logger::log("Cached generated alt text", "info", [
                        'attachment_id' => $attachment_id,
                        'generated_text' => $generated_text
                    ]);
                    $this->language_manager->sync_alt_text($attachment_id, $generated_text);
                }

                Auto_Alt_Text_Logger::log("Alt text generated successfully", "info", [
                    'attachment_id' => $attachment_id,
                    'tokens_used' => $tokens_used
                ]);

                // Clean up temporary file if one was created
                if ($processed_image['is_temp'] && file_exists($processed_image['path'])) {
                    unlink($processed_image['path']);
                }

                return $generated_text;
            }

            return null;

        } catch (Exception $e) {
            $this->last_error = $e->getMessage();
            Auto_Alt_Text_Logger::log("API call failed", "error", [
                'error' => $e->getMessage(),
                'attachment_id' => $attachment_id
            ]);

            // Clean up temporary file if one was created
            if ($processed_image['is_temp'] && file_exists($processed_image['path'])) {
                unlink($processed_image['path']);
            }

            return null;
        }
    }

    /**
     * Gets the human-readable language name from the language code.
     *
     * @param string $language_code The language code (e.g., 'en', 'es', 'fr').
     * @return string The human-readable language name.
     */
    private function get_language_name($language_code) {
        if (defined('AUTO_ALT_TEXT_LANGUAGES') && isset(AUTO_ALT_TEXT_LANGUAGES[$language_code])) {
            return AUTO_ALT_TEXT_LANGUAGES[$language_code];
        }

        // Default to English if the language code is not found
        return 'English';
    }

    /**
     * Sends a chat completion request to the OpenAI API.
     *
     * This is a wrapper around callAPI that specifically handles chat completion requests,
     * setting the model and other parameters consistently.
     *
     * @param array $data The data to be sent in the API request.
     * @return array|null The response data from the API, or null if an error occurred.
     * @throws Exception If the API request fails or the rate limit is exceeded.
     */
    private function send_chat_completion_request($data) {
        // Add the model if not provided
        if (!isset($data['model'])) {
            $data['model'] = self::MODEL;
        }

        // Track this request with the rate limiter
        if (!$this->rate_limiter->can_make_request()) {
            throw new Exception('Rate limit exceeded. Please try again later.');
        }

        try {
            $result = $this->callAPI($data);
            return $result;
        } catch (Exception $e) {
            $this->last_error = $e->getMessage();
            throw $e;
        }
    }

    /**
     * Regenerates alt text based on specific feedback.
     *
     * @param string $image_source The source of the image, such as the image URL or file path.
     * @param int $attachment_id The ID of the attachment for which the alt text is being regenerated.
     * @param string $improvement_type The type of improvement requested (more_descriptive, more_accessible, etc).
     * @param string $custom_feedback Any custom feedback provided by the user.
     * @param string $original_alt_text The original alt text that was generated.
     * @return string|null The regenerated alt text, or null if an error occurred.
     */
    public function regenerate_alt_text_with_feedback($image_source, $attachment_id, $improvement_type, $custom_feedback = '', $original_alt_text = '') {
        Auto_Alt_Text_Logger::log("Starting alt text regeneration with feedback", "info", [
            'attachment_id' => $attachment_id,
            'improvement_type' => $improvement_type
        ]);

        if (empty($image_source)) {
            $this->last_error = 'Invalid image source';
            return null;
        }

        try {
            $language = get_option(AUTO_ALT_TEXT_LANGUAGE_OPTION, 'en');
            $lang_name = $this->get_language_name($language);

            // Map improvement types to specific instructions
            $improvement_instructions = $this->get_improvement_instructions($improvement_type);

            // Build a custom prompt that includes the original alt text and feedback
            $feedback_prompt = "You are an expert in creating image descriptions for accessibility. " .
                "I need you to improve the following alt text for an image: \"{$original_alt_text}\". " .
                "{$improvement_instructions} " .
                ($custom_feedback ? "Additional feedback: {$custom_feedback}. " : "") .
                "The description should be in {$lang_name}. " .
                "Provide only the improved alt text without any additional explanations or quotes.";

            $response_data = $this->send_chat_completion_request([
                'messages' => [
                    ['role' => 'system', 'content' => 'You are an expert in creating accessible and SEO-friendly image descriptions.'],
                    ['role' => 'user', 'content' => $feedback_prompt]
                ],
                'max_tokens' => 150,
                'temperature' => 0.7
            ]);

            if ($response_data && isset($response_data['choices'][0]['message']['content'])) {
                $regenerated_text = trim($response_data['choices'][0]['message']['content']);

                // Track statistics for the regenerated alt text
                if (!empty($regenerated_text)) {
                    $statistics = new Auto_Alt_Text_Statistics();
                    $statistics->update_feedback_regeneration_count();
                }

                return $regenerated_text;
            }

            $this->last_error = 'Failed to generate improved alt text';
            return null;
        } catch (Exception $e) {
            $this->last_error = $e->getMessage();
            Auto_Alt_Text_Logger::log("Alt text regeneration failed", "error", [
                'attachment_id' => $attachment_id,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Gets specific instructions based on the improvement type.
     *
     * @param string $improvement_type The type of improvement requested.
     * @return string Instructions for the AI.
     */
    private function get_improvement_instructions($improvement_type) {
        switch ($improvement_type) {
            case 'more_descriptive':
                return "Make the description more detailed and vivid, including more visual elements from the image.";
            case 'more_concise':
                return "Make the description shorter and more concise while maintaining key information.";
            case 'more_accessible':
                return "Optimize the description for screen readers and accessibility, focusing on spatial relationships and important context.";
            case 'better_seo':
                return "Improve the SEO value of the alt text by incorporating relevant keywords naturally while keeping it descriptive.";
            case 'technical_accuracy':
                return "Ensure technical accuracy in the description, especially for diagrams, charts, or specialized content.";
            case 'brand_voice':
                return "Adjust the tone to better match brand voice, making it more professional/casual as appropriate.";
            default:
                return "Please improve this alt text to be more effective and descriptive.";
        }
    }

    /**
     * Generates the instruction for the OpenAI API call to generate alt text for an image.
     *
     * The instruction is based on the user's language preference and a customizable template.
     * It provides detailed requirements for the generated alt text, such as keeping it concise,
     * avoiding phrases like "image of", using the user's language, and maintaining proper
     * grammar and syntax.
     *
     * @return string The generated instruction for the OpenAI API call.
     */
    private function get_instruction() {
        $language = get_option(AUTO_ALT_TEXT_LANGUAGE_OPTION, 'en');
        $language_name = AUTO_ALT_TEXT_LANGUAGES[$language];
        // $template = get_option('alt_text_prompt_template');

        $brand_name = get_option('aat_brand_name');
        $brand_position = get_option('aat_brand_position');
        $product_keywords = get_option('aat_product_keywords');
        $brand_description = get_option('aat_brand_description');

        // if (!empty($template)) {
        //     return str_replace('{LANGUAGE}', $language_name, $template);
        // }

        $prompt = "You are an expert in accessibility, SEO optimization, and product marketing, tasked with generating alt text for images";

        if (!empty($brand_name)) {
            $prompt .= "from '{$brand_name}'";
        }

        if (!empty($brand_description)) {
            $prompt .= ", '{$brand_description}.'";
        }

        $prompt .= "Your job is to analyze the provided image and generate a concise, SEO-friendly, and accessibility-optimized alt text in {$language_name}, following these guidelines:\n";

        $prompt .= "<h1>Key Requirements</h1>\n";
        $prompt .= "- Keep it between 25-40 words to balance clarity and detail.\n";
        $prompt .= "- Use object-action-context structure, where:\n";
        $prompt .= "-- Object: Identify the main subject or focus of the image.\n";
        $prompt .= "-- Action: Describe the action or interaction depicted in the image.\n";
        $prompt .= "-- Context: Provide additional context or details about the image.\n";
        if (!empty($brand_name)) { $prompt .= "- Include \"{$brand_name}\" naturally at varying positions (beginning, middle or end).\n"; }
        $prompt .= "- Mention product-specific details, such as material, function, or unique features.\n";
        $prompt .= "- Include the image filename as a keyword for SEO optimization.\n";
        $prompt .= "- If the image contains text, transcribe it accurately, even if it exceeds the word limit.\n";
        $prompt .= "- Avoid generic descriptions – make the text informative and engaging.\n";
        $prompt .= "- Do NOT include phrases like 'image of' or 'picture of'.\n";
        $prompt .= "- For ambiguous images, describe them neutrally.\n";
        $prompt .= "- Write in {$language_name}, defaulting to English if {$language_name} is unsupported.\n";
        $prompt .= "- Ensure proper grammar and readability.\n";

        return $prompt;
    }

    /**
     * Calls the OpenAI API with the provided data.
     *
     * This method handles the cURL request to the OpenAI API, including rate limiting, error handling, and response parsing.
     *
     * @param array $data The data to be sent in the API request.
     * @return array|null The response data from the API, or null if an error occurred.
     * @throws Exception If the API request fails or the rate limit is exceeded.
     */
    private function callAPI($data) {
        if (!$this->rate_limiter->can_make_request()) {
            throw new Exception('Rate limit exceeded. Please try again later.');
        }

        $ch = curl_init(self::API_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->api_key
            ]
        ]);

        $response = curl_exec($ch);
        $this->rate_limiter->record_request();

        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (!$response) {
            throw new Exception('cURL Error: ' . curl_error($ch));
        }

        curl_close($ch);
        $response_data = json_decode($response, true);

        if ($http_code !== 200) {
            throw new Exception(
                $response_data['error']['message'] ?? 'Unknown API error'
            );
        }

        return $response_data;
    }
}
