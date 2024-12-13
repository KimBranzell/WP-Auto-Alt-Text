<?php
class OpenAI {
    private const API_URL = 'https://api.openai.com/v1/chat/completions';
    private const MODEL = 'gpt-4o';
    private const CACHE_EXPIRATION = DAY_IN_SECONDS;
    private const MAX_TOKENS = 300;

    private $api_key;
    private $last_error;
    private $rate_limiter;


    public function __construct() {
        $this->api_key = get_option('auto_alt_text_api_key');
        $this->rate_limiter = new Auto_Alt_Text_Rate_Limiter();

    }

    public function get_image_description($image_url) {
        if (empty($this->api_key)) {
            $this->last_error = 'OpenAI API key is not configured';
            return null;
        }

        // Check cache
        $cache_key = $this->get_cache_key($image_url);
        $cached_result = get_transient($cache_key);

        if ($cached_result !== false) {
            return $cached_result;
        }

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

            $alt_text = $response_data['choices'][0]['message']['content'] ?? null;

            if ($alt_text) {
                set_transient($cache_key, $alt_text, self::CACHE_EXPIRATION);
            }

            return $alt_text;

        } catch (Exception $e) {
            $this->last_error = $e->getMessage();
            error_log('Auto Alt Text Error: ' . $e->getMessage());
            return null;
        }
    }

    public function generate_alt_text_with_openai($image_description) {
        try {
            $response_data = $this->callAPI([
                'model' => self::MODEL,
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => "Generate a concise alt text for an image with this description: {$image_description}"
                    ]
                ],
                'max_tokens' => self::MAX_TOKENS
            ]);

            return $response_data['choices'][0]['message']['content'] ?? null;
        } catch (Exception $e) {
            error_log('Auto Alt Text Error: ' . $e->getMessage());
            return null;
        }
    }

    private function get_cache_key($image_url) {
        return 'auto_alt_text_' . md5($image_url);
    }

    public function get_last_error() {
        return $this->last_error;
    }

    public function generate_alt_text($image_url) {
        if (empty($this->api_key)) {
            $this->last_error = 'OpenAI API key is not configured';
            return null;
        }

        // Check cache
        $cache_key = $this->get_cache_key($image_url);
        $cached_result = get_transient($cache_key);

        if ($cached_result !== false) {
            return $cached_result;
        }

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

            $alt_text = $response_data['choices'][0]['message']['content'] ?? null;

            if ($alt_text) {
                set_transient($cache_key, $alt_text, self::CACHE_EXPIRATION);
            }

            return $alt_text;

        } catch (Exception $e) {
            $this->last_error = $e->getMessage();
            error_log('Auto Alt Text Error: ' . $e->getMessage());
            return null;
        }
    }

    private function get_instruction() {
        $language = get_option('language', 'en');
        return "You are an expert in accessibility and SEO optimization, tasked with generating alt text for images. Analyze the image provided and generate a concise, descriptive alt text tailored to the following requirements:

            Keep it short (1-2 sentences) and descriptive, focusing on the essential elements in the image.
            Don't include phrases like 'image of' or 'picture of'.
            Write the text in {$language} language.
            For ambiguous images, describe them neutrally.
            Use plain and easy-to-understand language.
            If {$language} is unsupported, default to English.

            Output:
            A single, SEO-friendly alt text description";
    }

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