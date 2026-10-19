<?php
class Auto_Alt_Text_OpenAI  {
    private const API_URL = 'https://api.openai.com/v1/responses';
    private const MODELS_URL = 'https://api.openai.com/v1/models';
    private const MAX_API_RETRIES = 1;          // sync default: 2 attempts total (was 3 → 4 attempts)
    private const MAX_RETRY_DELAY_SECONDS = 5.0; // was hardcoded 15.0
    /** Pacing estimates: a ~1 MP image at detail auto, and a 512 px image at detail low. */
    private const ESTIMATED_IMAGE_INPUT_TOKENS = 1300;
    private const ESTIMATED_LOW_DETAIL_IMAGE_TOKENS = 320;
    private const ESTIMATED_CHARS_PER_TOKEN = 4;
    private const DEFAULT_MAX_OUTPUT_TOKENS = 300;

    private $api_key;
    private $last_error;
    private $rate_limiter;
    private $statistics;
    private $language_manager;
    private $max_api_attempts;
    private $last_error_class = null;
    private $context_post_id = null;

    /** Per-run overrides from WP-CLI and the eval: 'naming', 'model', 'detail'. */
    private $run_overrides = [];

    public function __construct(?Auto_Alt_Text_Language_Manager $language_manager = null) {
        if (defined('AUTO_ALT_TEXT_OPENAI_API_KEY') && AUTO_ALT_TEXT_OPENAI_API_KEY) {
            $this->api_key = (string) AUTO_ALT_TEXT_OPENAI_API_KEY;
        } else {
            $encrypted_key = get_option('auto_alt_text_api_key');
            $this->api_key = $encrypted_key ? (string) $this->decrypt_api_key($encrypted_key) : '';
        }
        $this->rate_limiter = new Auto_Alt_Text_Rate_Limiter();
        $this->statistics = new Auto_Alt_Text_Statistics();
        $this->language_manager = $language_manager ?? new Auto_Alt_Text_Language_Manager();
        $this->max_api_attempts = self::MAX_API_RETRIES + 1;
    }

    /**
     * Returns the configured OpenAI model name.
     *
     * @return string
     */
    public static function get_model_name() {
        return Auto_Alt_Text_Models::current_id();
    }

    /**
     * Returns the default max output tokens for alt text generation.
     *
     * @return int
     */
    public static function get_default_max_output_tokens() {
        return Auto_Alt_Text_Models::current()['max_output_tokens'];
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

        // Create a 16-byte IV by padding AUTH_SALT with zeros if needed
        $iv = str_pad(substr(AUTH_SALT, 0, 16), 16, "\0");

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

        // Create a 16-byte IV by padding AUTH_SALT with zeros if needed
        $iv = str_pad(substr(AUTH_SALT, 0, 16), 16, "\0");

        return openssl_decrypt(base64_decode($encrypted_key), 'AES-256-CBC', AUTH_SALT, 0, $iv);
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
        $this->api_key = $key;
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
     * Overrides how many total API attempts a call may make. The queue
     * runner sets 1 — retry timing lives in the queue, not in sleep().
     */
    public function set_max_api_attempts($attempts) {
        $this->max_api_attempts = max(1, (int) $attempts);
    }

    /**
     * The post that queued the next generation (e.g. the saved product); used
     * to resolve context. Applies to the next generate_alt_text() call only.
     */
    public function set_context_post_id($post_id) {
        $this->context_post_id = $post_id ? (int) $post_id : null;
    }

    /**
     * Overrides for every following generation of this instance (spec §3):
     * WP-CLI passes --naming and --model here instead of writing options.
     */
    public function set_run_overrides(array $overrides) {
        $this->run_overrides = array_intersect_key($overrides, array_flip(['naming', 'model', 'detail']));
    }

    /**
     * Error class of the last failed call:
     * 'quota' | 'auth' | 'rate_limit' | 'transient' | 'permanent' | null.
     */
    public function get_last_error_class() {
        return $this->last_error_class;
    }

    /**
     * Checks the key by listing models: free, and no tokens are spent.
     *
     * @return bool True when OpenAI accepts the key.
     */
    public function test_api_key() {
        if (empty($this->api_key)) {
            return false;
        }
        $response = wp_remote_get(self::MODELS_URL, $this->auth_request_args());
        return !is_wp_error($response) && 200 === wp_remote_retrieve_response_code($response);
    }

    /**
     * Whether the key can use a model (spec §6).
     *
     * @return bool|null True or false, or null when there is no key or OpenAI did not answer clearly.
     */
    public function is_model_available($model_id) {
        if (empty($this->api_key) || !is_string($model_id) || '' === $model_id) {
            return null;
        }
        $response = wp_remote_get(self::MODELS_URL . '/' . rawurlencode($model_id), $this->auth_request_args());
        if (is_wp_error($response)) {
            return null;
        }
        $code = wp_remote_retrieve_response_code($response);
        if (200 === $code) {
            return true;
        }
        return 404 === $code ? false : null;
    }

    private function auth_request_args() {
        return [
            'headers' => ['Authorization' => 'Bearer ' . $this->api_key],
            'timeout' => 15,
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
    public function translate_alt_text($prompt, $source_language = '', $target_language = '') {
        try {
            if (!empty($target_language)) {
                $prompt = sprintf(
                    'Translate the following image description from %s to %s while keeping the same descriptive quality, accessibility value, and natural tone. Return only the translated alt text: %s',
                    $this->get_language_name($source_language ?: 'en'),
                    $this->get_language_name($target_language),
                    $prompt
                );
            }

            $response = $this->request_text($this->build_response_request(
                [[
                    'role'    => 'user',
                    'content' => [['type' => 'input_text', 'text' => $prompt]],
                ]],
                null,
                'You are a professional translator. Translate the given image description accurately while maintaining its descriptive quality.'
            ));

            return 'ok' === $response['status'] ? $response['text'] : null;
        } catch (Exception $e) {
            $this->last_error = $e->getMessage();
            return null;
        }
    }

    /**
     * Generates alt text for an attachment and stores it (spec §4–§6).
     * Builds the prompt and cache key first and serves a cached answer when
     * the same image, model and prompt were seen before; otherwise calls the
     * API through execute_generation(). Stores the alt text, provenance,
     * statistics and cache only on success.
     *
     * @param string      $image_source      Kept for callers; the attachment's own file or URL is used.
     * @param int         $attachment_id
     * @param string      $generation_type   upload, woocommerce, batch, cli, api, manual, …
     * @param bool        $preview_mode      Return the text without storing it.
     * @param string|null $language_override Language code for this generation.
     * @return string|null The alt text, or null on failure (see get_last_error()).
     */
    public function generate_alt_text($image_source, $attachment_id, $generation_type = 'manual', $preview_mode = false, $language_override = null) {
        $attachment_id = (int) $attachment_id;
        Auto_Alt_Text_Logger::log("Starting alt text generation", "info", [
            'attachment_id' => $attachment_id,
            'type' => $generation_type,
            'preview' => $preview_mode
        ]);

        $overrides = $this->run_overrides;
        $overrides['context_post_id'] = $this->context_post_id;
        $overrides['preview'] = (bool) $preview_mode;
        if (!empty($language_override)) {
            $overrides['language'] = $language_override;
        }
        // One hint per generation: a reused instance must not carry it to the next image.
        $this->context_post_id = null;

        $job = $this->prepare_generation($attachment_id, (string) $generation_type, $overrides);

        if (!$preview_mode) {
            $cached = Auto_Alt_Text_Cache_Manager::get_cached_response($job['cache_key']);
            if (is_string($cached) && '' !== $cached) {
                Auto_Alt_Text_Logger::log("Retrieved alt text from cache", "info", ['attachment_id' => $attachment_id]);
                update_post_meta($attachment_id, '_wp_attachment_image_alt', $cached);
                Auto_Alt_Text_Provenance::record($attachment_id, $generation_type, $cached, $job['model']['id']);
                return $cached;
            }
        }

        $result = $this->execute_generation($job);
        if ('ok' !== $result['status']) {
            Auto_Alt_Text_Logger::log("Alt text generation failed", "error", [
                'attachment_id' => $attachment_id,
                'error'         => $result['error'],
                'error_class'   => $result['error_class'],
            ]);
            return null;
        }

        $text = $result['text'];
        $this->statistics->track_generation(
            $attachment_id,
            $text,
            $result['total_tokens'],
            $generation_type,
            !$preview_mode,
            false,
            null,
            $result['model'],
            $result['input_tokens'],
            $result['output_tokens']
        );

        if (!$preview_mode) {
            update_post_meta($attachment_id, '_wp_attachment_image_alt', $text);
            Auto_Alt_Text_Provenance::record($attachment_id, $generation_type, $text, $result['model']);
            Auto_Alt_Text_Cache_Manager::set_cached_response($job['cache_key'], $text);
        }

        Auto_Alt_Text_Logger::log("Alt text generated successfully", "info", [
            'attachment_id' => $attachment_id,
            'model'         => $result['model'],
            'tokens_used'   => $result['total_tokens'],
            'name_fix'      => $result['name_fix'],
        ]);
        return $text;
    }

    /**
     * The generation pipeline without side effects of its own: it stores no alt
     * text, provenance, cache entry or statistics. The shared transport
     * bookkeeping in callAPI() (rate limiter, quota monitor, auth pause,
     * connector status) still applies. The evaluation harness calls this (spec §7.2).
     *
     * @param array $overrides model, naming, language, detail, context_post_id, preview, feedback.
     * @return array status, text, first_text, name_fix, model, effort, detail, naming, context,
     *               input_tokens, output_tokens, reasoning_tokens, total_tokens, latency_ms, error, error_class.
     */
    public function run_generation($attachment_id, $generation_type, array $overrides = []) {
        return $this->execute_generation($this->prepare_generation((int) $attachment_id, (string) $generation_type, $overrides));
    }

    /**
     * Everything a generation needs before the API call. Cheap: the image is
     * read and resized only in execute_generation(), after the cache check.
     */
    private function prepare_generation($attachment_id, $generation_type, array $overrides) {
        $model = Auto_Alt_Text_Models::get($overrides['model'] ?? null) ?? Auto_Alt_Text_Models::current();
        $language = $this->resolve_generation_language($attachment_id, $overrides['language'] ?? null);
        $explanation = Auto_Alt_Text_Context::explain($attachment_id, $overrides['context_post_id'] ?? null);
        $context = $explanation['context'];
        $naming = Auto_Alt_Text_Prompt::naming($overrides['naming'] ?? null);
        $prompt = Auto_Alt_Text_Prompt::from_settings($attachment_id, $language, $context, [
            'naming'   => $naming,
            'feedback' => $overrides['feedback'] ?? null,
        ]);
        $detail = isset($overrides['detail'])
            ? (string) $overrides['detail']
            : Auto_Alt_Text_Image_Source::resolve_image_detail($attachment_id, $generation_type, !empty($overrides['preview']));
        $requires_name = Auto_Alt_Text_Prompt::NAMING_VISIBLE !== $naming
            && $context
            && in_array($context['type'], Auto_Alt_Text_Prompt::PRODUCT_CONTEXT_TYPES, true);

        return [
            'attachment_id' => $attachment_id,
            'model'         => $model,
            'naming'        => $naming,
            'context'       => $context,
            'detail'        => $detail,
            'prompt'        => $prompt,
            'required_name' => $requires_name ? (string) ($context['product_name'] ?? $context['title']) : null,
            'cache_key'     => Auto_Alt_Text_Cache_Manager::get_cache_key($attachment_id, [
                'file'         => Auto_Alt_Text_Image_Source::fingerprint($attachment_id),
                'model'        => $model['id'],
                'effort'       => $model['effort'],
                'detail'       => $detail,
                'instructions' => $prompt['instructions'],
                'input'        => $prompt['input'],
            ]),
        ];
    }

    /** Prepares the image, calls the API and applies the name guarantee. Writes nothing. */
    private function execute_generation(array $job) {
        $started = microtime(true);
        $result = [
            'status'           => 'error',
            'text'             => '',
            'first_text'       => '',
            'name_fix'         => null,
            'model'            => $job['model']['id'],
            'effort'           => $job['model']['effort'],
            'detail'           => $job['detail'],
            'naming'           => $job['naming'],
            'context'          => $job['context'],
            'input_tokens'     => 0,
            'output_tokens'    => 0,
            'reasoning_tokens' => 0,
            'total_tokens'     => 0,
            'latency_ms'       => 0,
            'error'            => null,
            'error_class'      => null,
        ];
        $this->last_error = null;
        $this->last_error_class = null;

        if (empty($this->api_key)) {
            $this->last_error = 'OpenAI API key is not configured';
            $this->last_error_class = 'permanent';
            return $this->finish_result($result, $started);
        }

        $image = Auto_Alt_Text_Image_Source::for_attachment($job['attachment_id'], $job['detail']);
        if (is_wp_error($image)) {
            $this->last_error = $image->get_error_message();
            $this->last_error_class = 'image_download_failed' === $image->get_error_code() ? 'transient' : 'permanent';
            return $this->finish_result($result, $started);
        }

        try {
            $response = $this->request_text($this->build_response_request(
                [[
                    'role'    => 'user',
                    'content' => [
                        ['type' => 'input_text', 'text' => $job['prompt']['input']],
                        $image->input_image(),
                    ],
                ]],
                null,
                $job['prompt']['instructions'],
                $job['model']['id']
            ));
            $this->add_usage($result, $response);

            $text = 'ok' === $response['status'] ? Auto_Alt_Text_Prompt::clean_output($response['text']) : '';
            if ('' !== $text) {
                $result['first_text'] = $text;
                [$result['text'], $result['name_fix']] = $this->ensure_name($text, $job, $result);
                $result['status'] = 'ok';
            } elseif ('ok' === $response['status']) {
                $this->last_error = __('OpenAI returned an empty response. Please try again.', 'WP-Auto-Alt-Text');
                $this->last_error_class = 'transient';
            }
        } catch (Exception $e) {
            $this->last_error = $e->getMessage();
        } finally {
            $image->cleanup();
        }

        return $this->finish_result($result, $started);
    }

    /**
     * G9: at the product levels a product image's alt text always names the
     * product: one text-only rewrite, then a prefix. Returns [text, name_fix].
     */
    private function ensure_name($text, array $job, array &$result) {
        $name = $job['required_name'];
        if (null === $name || '' === $name) {
            return [$text, null];
        }
        if (Auto_Alt_Text_Prompt::contains_name($text, $name)) {
            return [$text, 'model'];
        }

        try {
            $rewrite = $this->request_text($this->build_response_request(
                [[
                    'role'    => 'user',
                    'content' => [['type' => 'input_text', 'text' => Auto_Alt_Text_Prompt::name_rewrite_input($text, $name)]],
                ]],
                null,
                $job['prompt']['instructions'],
                $job['model']['id']
            ));
            $this->add_usage($result, $rewrite);
            $rewritten = 'ok' === $rewrite['status'] ? Auto_Alt_Text_Prompt::clean_output($rewrite['text']) : '';
            if ('' !== $rewritten && Auto_Alt_Text_Prompt::contains_name($rewritten, $name)) {
                return [$rewritten, 'rewrite'];
            }
        } catch (Exception $e) {
            // The first answer stands; the prefix below adds the name.
        }

        return [Auto_Alt_Text_Prompt::prefix_name($name, $text), 'prefix'];
    }

    private function add_usage(array &$result, array $response) {
        foreach (['input_tokens', 'output_tokens', 'reasoning_tokens', 'total_tokens'] as $key) {
            $result[$key] += (int) ($response[$key] ?? 0);
        }
    }

    private function finish_result(array $result, $started) {
        $result['latency_ms'] = (int) round((microtime(true) - $started) * 1000);
        if ('ok' === $result['status']) {
            $this->last_error = null;
            $this->last_error_class = null;
            return $result;
        }
        $this->last_error = $this->last_error ?: __('Unable to generate alt text right now. Please try again.', 'WP-Auto-Alt-Text');
        $this->last_error_class = $this->last_error_class ?: 'transient';
        $result['error'] = $this->last_error;
        $result['error_class'] = $this->last_error_class;
        return $result;
    }

    /**
     * Resolves the effective language for an alt text generation request.
     *
     * @param int         $attachment_id The attachment being generated.
     * @param string|null $language_override Optional override language.
     * @return string
     */
    private function resolve_generation_language($attachment_id, $language_override = null) {
        if (!empty($language_override)) {
            return $this->normalize_language_code($language_override);
        }

        $language = $this->language_manager->get_post_language($attachment_id);

        if (empty($language)) {
            $language = get_option(AUTO_ALT_TEXT_LANGUAGE_OPTION, 'en');
        }

        return $this->normalize_language_code($language);
    }

    /**
     * Gets the human-readable language name from the language code.
     *
     * @param string $language_code The language code (e.g., 'en', 'es', 'fr').
     * @return string The human-readable language name.
     */
    private function get_language_name($language_code) {
        return Auto_Alt_Text_Prompt::language_name($this->normalize_language_code($language_code));
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

        $request = trim($this->get_improvement_instructions($improvement_type) . ($custom_feedback ? ' Additional feedback: ' . $custom_feedback : ''));
        $result = $this->run_generation((int) $attachment_id, 'manual', [
            'preview'  => true,
            'feedback' => ['type' => (string) $improvement_type, 'text' => $request, 'previous' => (string) $original_alt_text],
        ]);

        if ('ok' === $result['status']) {
            (new Auto_Alt_Text_Statistics())->update_feedback_regeneration_count();
            return $result['text'];
        }

        // request_text() and the pipeline already set a specific last_error; keep it.
        Auto_Alt_Text_Logger::log("Alt text regeneration failed", "error", [
            'attachment_id' => $attachment_id,
            'error' => $result['error']
        ]);
        return null;
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
     * Normalizes locale-based language codes before lookup.
     *
     * @param string $language_code The language code to normalize.
     * @return string The normalized language code.
     */
    private function normalize_language_code($language_code) {
        if (empty($language_code) || !is_string($language_code)) {
            return 'en';
        }

        $normalized_language = strtolower(trim($language_code));
        $normalized_language = str_replace('_', '-', $normalized_language);

        if ('dk' === $normalized_language) {
            return 'da';
        }

        $primary_language = strtok($normalized_language, '-');

        return !empty($primary_language) ? $primary_language : 'en';
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
        $this->last_error_class = null;
        $estimated_tokens = $this->estimate_request_tokens($data);
        $args = [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->api_key,
            ],
            'body' => wp_json_encode($data),
            'timeout' => 30,
            'sslverify' => true,
        ];
        $max_attempts = $this->max_api_attempts;

        for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
            $this->rate_limiter->wait_for_capacity($estimated_tokens);
            $this->rate_limiter->record_request($estimated_tokens);

            $response = wp_remote_post(self::API_URL, $args);

            if (is_wp_error($response)) {
                // WordPress's AI plugin (Connector Approval) blocks provider calls
                // from plugins an admin hasn't approved. Retrying cannot help.
                if (Auto_Alt_Text_Connector_Status::ERROR_CODE === $response->get_error_code()) {
                    $this->last_error_class = 'auth';
                    Auto_Alt_Text_Connector_Status::mark_blocked($response->get_error_message());
                    Auto_Alt_Text_Logger::log('OpenAI request blocked by WordPress AI connector approval', 'error', [
                        'wp_error' => $response->get_error_message(),
                    ]);
                    throw new Exception( esc_html__( 'WordPress AI has blocked the OpenAI connection for WP Auto Alt Text. An administrator needs to approve it under Tools → AI connector approval.', 'WP-Auto-Alt-Text' ) );
                }

                if ($attempt < $max_attempts && $this->should_retry_transport_error($response)) {
                    usleep((int) ceil($this->calculate_retry_delay([], $attempt) * 1000000));
                    continue;
                }

                Auto_Alt_Text_Logger::log('OpenAI request failed before reaching the API', 'error', [
                    'wp_error_code' => $response->get_error_code(),
                    'wp_error'      => $response->get_error_message(),
                    'attempt'       => $attempt,
                ]);
                $this->last_error_class = 'transient';
                $friendly_error = $this->map_api_error_message($response->get_error_message(), 0);
                throw new Exception( esc_html( $friendly_error ) );
            }

            $headers = $this->normalize_response_headers(wp_remote_retrieve_headers($response));
            $this->rate_limiter->record_response_headers($headers);

            $http_code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            $response_data = json_decode($body, true);

            if (200 === $http_code && !empty($body) && is_array($response_data)) {
                $this->last_error_class = null;
                Auto_Alt_Text_Quota_Monitor::record_success();
                Auto_Alt_Text_Queue::clear_auth_pause();
                Auto_Alt_Text_Connector_Status::clear();
                return $response_data;
            }

            $raw_error = is_array($response_data)
                ? ($response_data['error']['message'] ?? __('Unknown API error', 'WP-Auto-Alt-Text'))
                : __('Unknown API error', 'WP-Auto-Alt-Text');
            $friendly_error = $this->map_api_error_message($raw_error, $http_code);
            $error_class = $this->classify_api_error(is_array($response_data) ? $response_data : [], $http_code);
            $this->last_error_class = $error_class;

            Auto_Alt_Text_Logger::log('OpenAI API request failed', 'error', [
                'http_code' => $http_code,
                'raw_error' => $raw_error,
                'error_class' => $error_class,
                'friendly_error' => $friendly_error,
                'attempt' => $attempt,
            ]);

            if ('quota' === $error_class) {
                Auto_Alt_Text_Quota_Monitor::record_failure($raw_error);
                throw new Exception( esc_html__( 'OpenAI quota exhausted — check your OpenAI billing. Generation is paused until the quota is restored.', 'WP-Auto-Alt-Text' ) );
            }

            if ($attempt < $max_attempts && in_array($error_class, ['rate_limit', 'transient'], true)) {
                if ('rate_limit' === $error_class) {
                    $this->rate_limiter->record_rate_limit_error($headers, $attempt);
                    continue;
                }
                usleep((int) ceil($this->calculate_retry_delay($headers, $attempt) * 1000000));
                continue;
            }

            throw new Exception( esc_html( $friendly_error ) );
        }

        throw new Exception( esc_html__( 'Unable to generate alt text right now. Please try again.', 'WP-Auto-Alt-Text' ) );
    }

    /**
     * Classifies an API error response for retry policy.
     *
     * @return string 'quota' | 'auth' | 'rate_limit' | 'transient' | 'permanent'
     */
    private function classify_api_error($response_data, $http_code) {
        $type = strtolower((string) ($response_data['error']['type'] ?? ''));
        $code = strtolower((string) ($response_data['error']['code'] ?? ''));
        $message = strtolower((string) ($response_data['error']['message'] ?? ''));

        if ('insufficient_quota' === $type || 'insufficient_quota' === $code
            || 'billing_hard_limit_reached' === $code
            || strpos($message, 'exceeded your current quota') !== false
            || strpos($message, 'billing hard limit') !== false) {
            return 'quota';
        }

        if (401 === $http_code) {
            // Invalid or revoked key: the queue pauses instead of failing jobs.
            return 'auth';
        }

        if (429 === $http_code) {
            return 'rate_limit';
        }

        if ($http_code >= 500 || 408 === $http_code) {
            return 'transient';
        }

        if (200 === $http_code) {
            // A 200 only reaches classification when the body was empty or
            // unparseable — a proxy/transport artifact, not an API verdict.
            return 'transient';
        }

        return 'permanent';
    }

    /**
     * Maps low-level API errors to user-friendly messages.
     *
     * @param string $raw_error Raw API or transport error message.
     * @param int $http_code HTTP status code.
     * @return string User-facing error message.
     */
    private function map_api_error_message($raw_error, $http_code = 0) {
        $error_message = is_string($raw_error) ? strtolower($raw_error) : '';

        if ($http_code === 401 || strpos($error_message, 'invalid_api_key') !== false || strpos($error_message, 'incorrect api key') !== false) {
            return __('Authentication failed. Please verify your OpenAI API key.', 'WP-Auto-Alt-Text');
        }

        if (strpos($error_message, 'exceeded your current quota') !== false || strpos($error_message, 'billing hard limit') !== false) {
            return __('OpenAI quota exhausted — check your OpenAI billing. Generation is paused until the quota is restored.', 'WP-Auto-Alt-Text');
        }

        if ($http_code === 429 || strpos($error_message, 'rate limit') !== false) {
            return __('OpenAI rate limit reached. Please wait and try again.', 'WP-Auto-Alt-Text');
        }

        if ($http_code === 400 || strpos($error_message, 'max_output_tokens') !== false || strpos($error_message, 'unsupported parameter') !== false || strpos($error_message, 'input_image') !== false || strpos($error_message, 'responses') !== false) {
            return __('Model request format error. Please verify plugin compatibility with the current OpenAI model.', 'WP-Auto-Alt-Text');
        }

        if ($http_code >= 500 || strpos($error_message, 'server error') !== false) {
            return __('OpenAI service is temporarily unavailable. Please try again shortly.', 'WP-Auto-Alt-Text');
        }

        if (strpos($error_message, 'curl') !== false || strpos($error_message, 'timed out') !== false || strpos($error_message, 'could not resolve host') !== false || strpos($error_message, 'ssl') !== false) {
            return __('Network error while contacting OpenAI. Please check server connectivity and try again.', 'WP-Auto-Alt-Text');
        }

        return __('Unable to generate alt text right now. Please try again.', 'WP-Auto-Alt-Text');
    }

    /**
     * Builds a Responses API request (spec §6): registry model, `store: false`,
     * and a reasoning effort only for models that take one.
     */
    private function build_response_request($input, $max_output_tokens = null, $instructions = null, $model_id = null) {
        $model = (null !== $model_id ? Auto_Alt_Text_Models::get($model_id) : null) ?? Auto_Alt_Text_Models::current();
        $request = [
            'model'             => $model['id'],
            'input'             => $input,
            'max_output_tokens' => max(1, (int) ($max_output_tokens ?: $model['max_output_tokens'])),
            'store'             => false,
        ];
        if (is_string($instructions) && '' !== $instructions) {
            $request['instructions'] = $instructions;
        }
        if (null !== $model['effort']) {
            $request['reasoning'] = ['effort' => $model['effort']];
        }
        return $request;
    }

    /**
     * Estimates the request token footprint for pacing decisions.
     *
     * @param array $data Request payload.
     * @return int
     */
    private function estimate_request_tokens($data) {
        $max_output_tokens = isset($data['max_output_tokens']) ? (int) $data['max_output_tokens'] : self::DEFAULT_MAX_OUTPUT_TOKENS;
        $text_characters = is_string($data['instructions'] ?? null) ? strlen($data['instructions']) : 0;
        $image_tokens = 0;

        $this->collect_request_metrics($data['input'] ?? [], $text_characters, $image_tokens);

        $estimated_input_tokens = (int) ceil($text_characters / self::ESTIMATED_CHARS_PER_TOKEN) + $image_tokens;

        return max(1, $estimated_input_tokens + max(1, $max_output_tokens));
    }

    /**
     * Sums text characters and estimated image tokens in a request's input.
     */
    private function collect_request_metrics($node, &$text_characters, &$image_tokens) {
        if (!is_array($node)) {
            return;
        }

        if ('input_image' === ($node['type'] ?? null)) {
            $image_tokens += 'low' === ($node['detail'] ?? 'auto')
                ? self::ESTIMATED_LOW_DETAIL_IMAGE_TOKENS
                : self::ESTIMATED_IMAGE_INPUT_TOKENS;
            return;
        }

        foreach ($node as $key => $value) {
            if ('text' === $key && is_string($value)) {
                $text_characters += strlen($value);
                continue;
            }
            $this->collect_request_metrics($value, $text_characters, $image_tokens);
        }
    }

    /**
     * Converts response headers to a normalized lowercase array.
     *
     * @param mixed $headers Response headers.
     * @return array
     */
    private function normalize_response_headers($headers) {
        if (is_object($headers)) {
            if (method_exists($headers, 'getAll')) {
                $headers = $headers->getAll();
            } else {
                $headers = (array) $headers;
            }
        }

        if (!is_array($headers)) {
            return [];
        }

        $normalized_headers = [];

        foreach ($headers as $key => $value) {
            $normalized_key = strtolower(trim((string) $key));

            if (is_array($value)) {
                $normalized_headers[$normalized_key] = implode(', ', array_map('strval', $value));
                continue;
            }

            $normalized_headers[$normalized_key] = is_scalar($value) ? (string) $value : '';
        }

        return $normalized_headers;
    }

    /**
     * Determines whether a transport error should be retried.
     *
     * @param WP_Error $error Transport error.
     * @return bool
     */
    private function should_retry_transport_error($error) {
        if (!$error instanceof WP_Error) {
            return false;
        }

        $message = strtolower($error->get_error_message());
        $retryable_fragments = [
            'timed out',
            'timeout',
            'could not resolve host',
            'connection refused',
            'connection reset',
            'temporarily unavailable',
        ];

        foreach ($retryable_fragments as $fragment) {
            if (false !== strpos($message, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Calculates the next retry delay using response hints and jitter.
     *
     * @param array $headers Response headers.
     * @param int   $attempt Retry attempt.
     * @return float
     */
    private function calculate_retry_delay($headers, $attempt) {
        $reset_hint = 0.0;

        if (is_array($headers)) {
            $reset_hint = max(
                $this->parse_retry_after_header($headers['retry-after'] ?? null),
                $this->parse_retry_after_header($headers['x-ratelimit-reset-requests'] ?? null),
                $this->parse_retry_after_header($headers['x-ratelimit-reset-tokens'] ?? null)
            );
        }

        $exponential_delay = min(self::MAX_RETRY_DELAY_SECONDS, (float) pow(2, max(0, (int) $attempt - 1)));
        $jitter = wp_rand(100, 600) / 1000;

        return min(self::MAX_RETRY_DELAY_SECONDS, max($reset_hint, $exponential_delay + $jitter));
    }

    /**
     * Parses a retry-after or reset header value into seconds.
     *
     * @param mixed $value Header value.
     * @return float
     */
    private function parse_retry_after_header($value) {
        if (is_numeric($value)) {
            return max(0.0, (float) $value);
        }

        if (!is_string($value) || '' === trim($value)) {
            return 0.0;
        }

        $normalized_value = strtolower(trim($value));

        if (preg_match_all('/(\d+(?:\.\d+)?)(ms|s|m|h|d)/', $normalized_value, $matches, PREG_SET_ORDER)) {
            $seconds = 0.0;

            foreach ($matches as $match) {
                $segment_value = (float) $match[1];

                switch ($match[2]) {
                    case 'd':
                        $seconds += $segment_value * DAY_IN_SECONDS;
                        break;
                    case 'h':
                        $seconds += $segment_value * HOUR_IN_SECONDS;
                        break;
                    case 'm':
                        $seconds += $segment_value * MINUTE_IN_SECONDS;
                        break;
                    case 's':
                        $seconds += $segment_value;
                        break;
                    case 'ms':
                        $seconds += $segment_value / 1000;
                        break;
                }
            }

            return max(0.0, $seconds);
        }

        $timestamp = strtotime($value);

        if (false === $timestamp) {
            return 0.0;
        }

        return max(0.0, $timestamp - time());
    }

    /**
     * Reads a Responses API payload (spec §6). Text comes only from
     * `output_text` content items (or the top-level `output_text` convenience
     * field); reasoning summaries and other items are ignored.
     *
     * @return array{status:string,text:string,input_tokens:int,output_tokens:int,reasoning_tokens:int,total_tokens:int}
     */
    private function parse_response($response_data) {
        $usage = is_array($response_data['usage'] ?? null) ? $response_data['usage'] : [];
        $result = [
            'status'           => 'empty',
            'text'             => '',
            'input_tokens'     => (int) ($usage['input_tokens'] ?? 0),
            'output_tokens'    => (int) ($usage['output_tokens'] ?? 0),
            'reasoning_tokens' => (int) ($usage['output_tokens_details']['reasoning_tokens'] ?? 0),
        ];
        $result['total_tokens'] = (int) ($usage['total_tokens'] ?? ($result['input_tokens'] + $result['output_tokens']));

        $parts = [];
        $refused = false;
        foreach ((array) ($response_data['output'] ?? []) as $item) {
            foreach ((array) ($item['content'] ?? []) as $content) {
                $type = is_array($content) ? ($content['type'] ?? '') : '';
                if ('refusal' === $type) {
                    $refused = true;
                } elseif ('output_text' === $type && is_string($content['text'] ?? null)) {
                    $parts[] = $content['text'];
                }
            }
        }
        if (empty($parts) && is_string($response_data['output_text'] ?? null)) {
            $parts[] = $response_data['output_text'];
        }
        $result['text'] = trim(implode("\n", $parts));

        if ('incomplete' === ($response_data['status'] ?? '') && 'max_output_tokens' === ($response_data['incomplete_details']['reason'] ?? '')) {
            $result['status'] = 'incomplete';
        } elseif ('' !== $result['text']) {
            $result['status'] = 'ok';
        } elseif ($refused) {
            $result['status'] = 'refused';
        }
        return $result;
    }

    /**
     * Sends a request and parses it. An answer cut off by the token limit is
     * retried once with twice the limit; a final non-ok status sets the error.
     */
    private function request_text(array $request) {
        $result = $this->parse_response($this->callAPI($request));

        if ('incomplete' === $result['status']) {
            $request['max_output_tokens'] = 2 * (int) $request['max_output_tokens'];
            $retry = $this->parse_response($this->callAPI($request));
            foreach (['input_tokens', 'output_tokens', 'reasoning_tokens', 'total_tokens'] as $key) {
                $retry[$key] += $result[$key];
            }
            $result = $retry;
        }

        if ('incomplete' === $result['status']) {
            $this->last_error = __('The model ran out of output tokens.', 'WP-Auto-Alt-Text');
            $this->last_error_class = 'permanent';
        } elseif ('refused' === $result['status']) {
            $this->last_error = __('The model declined to describe this image.', 'WP-Auto-Alt-Text');
            $this->last_error_class = 'permanent';
        } elseif ('empty' === $result['status']) {
            $this->last_error = __('OpenAI returned an empty response. Please try again.', 'WP-Auto-Alt-Text');
            $this->last_error_class = 'transient';
        }
        return $result;
    }
}
