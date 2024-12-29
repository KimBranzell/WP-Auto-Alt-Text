<?php
class Auto_Alt_Text_OpenAI  {
    private const API_URL = 'https://api.openai.com/v1/chat/completions';
    private const MODEL = 'gpt-4o';
    private const CACHE_EXPIRATION = DAY_IN_SECONDS;
    private const MAX_TOKENS = 300;
    private const AUTO_GENERATE_OPTION = 'auto_alt_text_auto_generate';

    private $api_key;
    private $last_error;
    private $rate_limiter;
    private $statistics;

    public function __construct() {
        $encrypted_key = get_option('auto_alt_text_api_key');
        $this->api_key = $encrypted_key ? $this->decrypt_api_key($encrypted_key) : '';
        $this->rate_limiter = new Auto_Alt_Text_Rate_Limiter();
        $this->statistics = new Auto_Alt_Text_Statistics();
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
     * Generates a cache key for the given image source.
     *
     * The cache key is constructed by combining the image source and the current timestamp,
     * and then hashing the result using the MD5 algorithm. This ensures that each image
     * source has a unique cache key, which can be used to store and retrieve the generated
     * alt text for that image.
     *
     * @param string $image_source The source of the image, such as the image URL or file path.
     * @return string The cache key for the given image source.
     */
    private function get_cache_key($image_source) {
        $timestamp = current_time('timestamp');
        return 'auto_alt_text_' . md5($image_source . $timestamp);
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

        $cache_key = $this->get_cache_key($image_source);
        $cached_result = get_transient($cache_key);


        if ($cached_result !== false && !$preview_mode) {
            return $cached_result;
        }

        if (empty($this->api_key)) {
            $this->last_error = 'OpenAI API key is not configured';
            return null;
        }

        // Always get file path from attachment ID
        $image_path = get_attached_file($attachment_id);

        // Convert file to base64
        $image_data = base64_encode(file_get_contents($image_path));
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
                    set_transient($cache_key, $generated_text, DAY_IN_SECONDS);
                }

                Auto_Alt_Text_Logger::log("Alt text generated successfully", "info", [
                    'attachment_id' => $attachment_id,
                    'tokens_used' => $tokens_used
                ]);

                return $generated_text;
            }

            return null;

        } catch (Exception $e) {
            $this->last_error = $e->getMessage();
            Auto_Alt_Text_Logger::log("API call failed", "error", [
                'error' => $e->getMessage(),
                'attachment_id' => $attachment_id
            ]);
            return null;
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
        $template = get_option('alt_text_prompt_template');

        if (!empty($template)) {
            return str_replace('{LANGUAGE}', $language_name, $template);
        }

        return "You are an expert in accessibility and SEO optimization, tasked with generating alt text for images. Analyze the image provided and generate a concise, descriptive alt text in {$language_name} tailored to the following requirements:

            1. Keep it short (1-2 sentences) and descriptive, focusing on the essential elements in the image.
            2. Don't include phrases like 'image of' or 'picture of'.
            3. Write the text in {$language} language.
            4. For ambiguous images, describe them neutrally.
            5. Use plain and easy-to-understand language.
            6. If {$language} is unsupported, default to English.
            7. Maintain proper grammar and syntax in {$language_name}

            Output:
            A single, SEO-friendly alt text description";
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

    /**
     * Clears the cache for a specific image URL or all cached alt text.
     *
     * If an image URL is provided, it deletes the transient for that specific image.
     * If no image URL is provided, it deletes all transients for automatically generated alt text.
     *
     * @param string|null $image_url The URL of the image to clear the cache for, or null to clear all cached alt text.
     */
    public function clear_cache($image_url = null) {
        if ($image_url) {
            delete_transient($this->get_cache_key($image_url));
            return;
        }

        global $wpdb;
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
            WHERE option_name LIKE '_transient_auto_alt_text_%'"
        );
    }
}
