<?php
class Auto_Alt_Text_Language_Manager {
    private $active_plugin;
    private $default_language;

    public function __construct() {
        $this->active_plugin = $this->detect_language_plugin();
        $this->default_language = get_option(AUTO_ALT_TEXT_LANGUAGE_OPTION, 'en');
    }

    /**
     * Detects the active language plugin and returns its configuration.
     *
     * This method checks for the presence of various language plugin constants
     * and returns an array with the plugin name, version, and a handler function
     * for the detected plugin. If no plugin is detected, it returns `null`.
     *
     * @return array|null The language plugin configuration, or `null` if no plugin is detected.
     */
    public function detect_language_plugin() {
        if (defined('ICL_SITEPRESS_VERSION')) {
            return [
                'name' => 'wpml',
                'version' => ICL_SITEPRESS_VERSION,
                'handler' => [$this, 'handle_wpml']
            ];
        }

        if (defined('POLYLANG_VERSION')) {
            return [
                'name' => 'polylang',
                'version' => POLYLANG_VERSION,
                'handler' => [$this, 'handle_polylang']
            ];
        }

        if (defined('TRP_PLUGIN_VERSION')) {
            return [
                'name' => 'translatepress',
                'version' => TRP_PLUGIN_VERSION,
                'handler' => [$this, 'handle_translatepress']
            ];
        }

        if (defined('WEGLOT_VERSION')) {
            return [
                'name' => 'weglot',
                'version' => WEGLOT_VERSION,
                'handler' => [$this, 'handle_weglot']
            ];
        }

        return null;
    }

    /**
     * Gets the current language based on the active language plugin.
     *
     * If no language plugin is active, this method returns the default language.
     * Otherwise, it calls the appropriate handler function for the active plugin
     * to retrieve the current language.
     *
     * @return string The current language.
     */
    public function get_current_language() {
        if (!$this->active_plugin) {
            return $this->default_language;
        }

        $handler = $this->active_plugin['handler'];
        return call_user_func($handler);
    }

    /**
     * Retrieves the post language based on the active language plugin.
     *
     * This method checks the active language plugin and calls the appropriate handler function to
     * retrieve the post language. If no language plugin is active or the plugin is not supported,
     * it returns the default language.
     *
     * @param int $post_id The ID of the post to retrieve the language for.
     * @return string The post language.
     */
    public function get_post_language($post_id) {
        switch ($this->active_plugin['name']) {
            case 'wpml':
                return $this->get_wpml_language($post_id);
            case 'polylang':
                return $this->get_polylang_language($post_id);
            case 'translatepress':
                return $this->get_translatepress_language($post_id);
            case 'weglot':
                return $this->get_weglot_language($post_id);
            default:
                return $this->default_language;
        }
    }

    /**
     * Retrieves the default language based on the active language plugin.
     *
     * This method checks the active language plugin and returns the default language
     * for that plugin. If no language plugin is active, it returns the default language
     * set in the class.
     *
     * @return string The default language.
     */
    public function get_default_language() {
        switch ($this->active_plugin['name']) {
            case 'wpml':
                return apply_filters('wpml_default_language', null);
            case 'polylang':
                return pll_default_language();
            case 'translatepress':
                return TRP_Settings::get_default_language();
            case 'weglot':
                return weglot_get_original_language();
            default:
                return $this->default_language;
        }
    }

    /**
     * Retrieves the post translations for the given post ID based on the active language plugin.
     *
     * This method checks the active language plugin and calls the appropriate handler function to
     * retrieve the post translations. If no language plugin is active or the plugin is not supported,
     * it returns an array with the default language and the given post ID.
     *
     * @param int $post_id The ID of the post to retrieve translations for.
     * @return array An array of post IDs keyed by language codes.
     */
    public function get_post_translations($post_id) {
        switch ($this->active_plugin['name']) {
            case 'wpml':
                return $this->get_wpml_translations($post_id);
            case 'polylang':
                return $this->get_polylang_translations($post_id);
            case 'translatepress':
                return $this->get_translatepress_translations($post_id);
            case 'weglot':
                return $this->get_weglot_translations($post_id);
            default:
                return [$this->default_language => $post_id];
        }
    }

    /**
     * Plugin specific translation and language handlers
     */

    /**
     * POLYLANG
     */

    /**
     * Retrieves the Polylang translations for the given post ID.
     *
     * If the Polylang plugin is not active or the `pll_get_post_translations` function does not exist,
     * this method will return an array with the default language and the given post ID.
     *
     * Otherwise, it will retrieve the Polylang translations for the post and log the details for debugging.
     *
     * @param int $post_id The ID of the post to retrieve translations for.
     * @return array An array of post IDs keyed by language codes.
     */
    private function get_polylang_translations($post_id) {
        if (!function_exists('pll_get_post_translations')) {
            return [$this->default_language => $post_id];
        }

        $translations = pll_get_post_translations($post_id);

        // Log translation details for debugging
        Auto_Alt_Text_Logger::log("Polylang translations retrieved", "debug", [
            'post_id' => $post_id,
            'translations' => $translations
        ]);

        return $translations;
    }

    /**
     * Retrieves the Polylang language for the given post ID.
     *
     * If the Polylang plugin is not active or the `pll_get_post_language` function does not exist,
     * this method will return the default language.
     *
     * Otherwise, it will retrieve the Polylang language for the post and log the details for debugging.
     *
     * @param int $post_id The ID of the post to retrieve the language for.
     * @return string The language code for the post, or the default language if the language could not be determined.
     */
    private function get_polylang_language($post_id) {
        if (!function_exists('pll_get_post_language')) {
            return $this->default_language;
        }

        $language = pll_get_post_language($post_id);

        // Log language detection for debugging
        Auto_Alt_Text_Logger::log("Polylang language detected", "debug", [
            'post_id' => $post_id,
            'language' => $language
        ]);

        return $language ?: $this->default_language;
    }

    /**
     * WPML
     */

    /**
     * Retrieves the WPML translations for the given post ID.
     *
     * This method uses the WPML plugin's API to retrieve the translations for the post. It first
     * retrieves the translation ID (trid) for the post, and then uses that to get the details of
     * all the translations. The resulting array maps the language codes to the corresponding post IDs.
     *
     * If no translations are found, the method returns an array with the default language and the
     * original post ID.
     *
     * @param int $post_id The ID of the post to retrieve translations for.
     * @return array An array of post IDs keyed by language codes.
     */
    private function get_wpml_translations($post_id) {
        $translations = [];
        $trid = apply_filters('wpml_element_trid', null, $post_id, 'post_attachment');

        if ($trid) {
            $translation_details = apply_filters('wpml_get_element_translations', null, $trid, 'post_attachment');

            foreach ($translation_details as $lang => $translation) {
                $translations[$lang] = $translation->element_id;
            }
        }

        Auto_Alt_Text_Logger::log("WPML translations retrieved", "debug", [
            'post_id' => $post_id,
            'translations' => $translations
        ]);

        return !empty($translations) ? $translations : [$this->default_language => $post_id];
    }

    /**
     * Retrieves the WPML language for the given post ID.
     *
     * This method uses the WPML plugin's API to retrieve the language details for the post. It
     * extracts the language code from the details and returns it, or the default language if
     * the language details are not available.
     *
     * @param int $post_id The ID of the post to retrieve the language for.
     * @return string The language code for the post, or the default language if not found.
     */
    private function get_wpml_language($post_id) {
        $language_details = apply_filters('wpml_post_language_details', null, $post_id);
        $language = $language_details['language_code'] ?? $this->default_language;

        Auto_Alt_Text_Logger::log("WPML language detected", "debug", [
            'post_id' => $post_id,
            'language' => $language
        ]);

        return $language;
    }

    /**
     * TranslatePress
     */

    /**
     * Retrieves the translations for the given post ID using the TranslatePress plugin.
     *
     * This method retrieves the translation languages configured in the TranslatePress settings,
     * and maps the given post ID to each of those languages. The resulting array maps language
     * codes to the post ID.
     *
     * @param int $post_id The ID of the post to retrieve translations for.
     * @return array An array of post IDs keyed by language codes.
     */
    private function get_translatepress_translations($post_id) {
        global $TRP_LANGUAGE;
        $translations = [];

        // Get TranslatePress settings
        $settings = get_option('trp_settings', []);
        $languages = $settings['translation-languages'] ?? [$this->default_language];

        foreach ($languages as $language) {
            // TranslatePress uses the same post ID across languages
            $translations[$language] = $post_id;
        }

        Auto_Alt_Text_Logger::log("TranslatePress translations mapped", "debug", [
            'post_id' => $post_id,
            'languages' => $languages
        ]);

        return $translations;
    }

    /**
     * Retrieves the current language using the TranslatePress plugin.
     *
     * This method is used to get the current language when the TranslatePress plugin is active.
     * It first checks the global `$TRP_LANGUAGE` variable, and if that is not set, it falls back
     * to the default language.
     *
     * @param int $post_id The ID of the post to retrieve the language for.
     * @return string The language code for the post, or the default language if not found.
     */
    private function get_translatepress_language($post_id) {
        global $TRP_LANGUAGE;

        $language = !empty($TRP_LANGUAGE) ? $TRP_LANGUAGE : $this->default_language;

        Auto_Alt_Text_Logger::log("TranslatePress language detected", "debug", [
            'post_id' => $post_id,
            'language' => $language
        ]);

        return $language;
    }

    /**
     * WeGlot
     */

    /**
     * Retrieves the translations for the given post ID using the Weglot plugin.
     *
     * This method checks if the Weglot plugin is active and available, and then retrieves the list of
     * available languages and the original language. It then maps the post ID to each available language
     * and returns an array of post IDs keyed by language codes.
     *
     * If the Weglot plugin is not active or available, the method returns an array with the default
     * language and the given post ID.
     *
     * @param int $post_id The ID of the post to retrieve the translations for.
     * @return array An array of post IDs keyed by language codes.
     */
    private function get_weglot_translations($post_id) {
        $translations = [];

        if (function_exists('weglot_get_languages_available')) {
            $languages = weglot_get_languages_available();
            $original_language = weglot_get_original_language();

            foreach ($languages as $language) {
                // Weglot uses same post ID across languages like TranslatePress
                $translations[$language] = $post_id;
            }

            Auto_Alt_Text_Logger::log("Weglot translations mapped", "debug", [
                'post_id' => $post_id,
                'languages' => $languages,
                'original_language' => $original_language
            ]);
        }

        return !empty($translations) ? $translations : [$this->default_language => $post_id];
    }

    /**
     * Retrieves the current language using the Weglot plugin.
     *
     * This method is used as the handler for the 'weglot' active plugin in the `get_current_language()` method.
     * It checks if the `weglot_get_current_language` function exists, and if so, it calls that function to get the current language.
     * If the function does not exist, it returns the default language.
     *
     * @param int $post_id The ID of the post to retrieve the language for.
     * @return string The current language.
     */
    private function get_weglot_language($post_id) {
        $language = function_exists('weglot_get_current_language')
            ? weglot_get_current_language()
            : $this->default_language;

        Auto_Alt_Text_Logger::log("Weglot language detected", "debug", [
            'post_id' => $post_id,
            'language' => $language
        ]);

        return $language;
    }

    /**
     * Retrieves the current language using the WPML plugin.
     *
     * This method is used as the handler for the 'wpml' active plugin in the `get_current_language()` method.
     * It calls the `wpml_current_language` filter to get the current language.
     *
     * @return string The current language.
     */
    private function handle_wpml() {
        return apply_filters('wpml_current_language', null);
    }

    /**
     * Retrieves the current language using the Polylang plugin.
     *
     * This method is used as the handler for the 'polylang' active plugin in the `get_current_language()` method.
     * It calls the `pll_current_language` function to get the current language, or returns the default language if the function does not exist.
     *
     * @return string The current language.
     */
    private function handle_polylang() {
        if (!function_exists('pll_current_language')) {
            return $this->default_language;
        }

        // Try admin language first during uploads
        $admin_lang = pll_current_language('admin');
        if (!empty($admin_lang)) {
            return $admin_lang;
        }

        // Fallback to standard language detection
        $current_lang = pll_current_language();

        return $current_lang ?: $this->default_language;
    }

    /**
     * Retrieves the current language using the TranslatePress plugin.
     *
     * This method is used as the handler for the 'translatepress' active plugin in the `get_current_language()` method.
     * It checks the global `$TRP_LANGUAGE` variable to get the current language, and returns the default language if the variable is empty.
     *
     * @return string The current language.
     */
    private function handle_translatepress() {
        global $TRP_LANGUAGE;
        return !empty($TRP_LANGUAGE) ? $TRP_LANGUAGE : $this->default_language;
    }

    /**
     * Retrieves the current language using the Weglot plugin.
     *
     * This method is used as the handler for the 'weglot' active plugin in the `get_current_language()` method.
     * It calls the `weglot_get_current_language` function to get the current language, or returns the default language if the function does not exist.
     *
     * @return string The current language.
     */
    private function handle_weglot() {
        if (function_exists('weglot_get_current_language')) {
            return weglot_get_current_language();
        }
        return $this->default_language;
    }

    /**
     * Retrieves the list of available languages based on the active translation plugin.
     *
     * This method checks the active translation plugin and returns the list of available languages accordingly.
     * If no translation plugin is active, it returns an array containing the default language.
     *
     * @return array An array of available language codes.
     */
    public function get_available_languages() {
        switch ($this->active_plugin['name']) {
            case 'wpml':
                return apply_filters('wpml_active_languages', null);
            case 'polylang':
                return function_exists('pll_languages_list') ? pll_languages_list(['fields' => 'locale']) : [];
            case 'translatepress':
                global $TRP_LANGUAGE;
                return !empty($TRP_LANGUAGE) ? get_option('trp_settings')['translation-languages'] : [];
            case 'weglot':
                return function_exists('weglot_get_languages_available') ? weglot_get_languages_available() : [];
            default:
                return [$this->default_language];
        }
    }

    /**
     * Synchronizes the alternative text (alt text) for an attachment across all available languages.
     *
     * This method retrieves the list of available languages using the `get_available_languages()` method,
     * and then calls the `store_language_specific_alt_text()` method to update the alt text for each language.
     *
     * @param int    $attachment_id The ID of the attachment.
     * @param string $alt_text      The alternative text to be synchronized.
     */
    public function sync_alt_text($attachment_id, $alt_text) {
        if (!$this->active_plugin) {
            return;
        }

        $languages = $this->get_available_languages();
        foreach ($languages as $lang) {
            $this->store_language_specific_alt_text($attachment_id, $lang, $alt_text);
        }
    }

    /**
     * Generates multilingual alternative text (alt text) for an attachment.
     *
     * This method retrieves the current language for the attachment, stores the original alt text in the current language,
     * and then uses the OpenAI API to translate the alt text to the current language, maintaining the same descriptive quality.
     * If the translation is successful, the method stores the translated alt text for the current language.
     *
     * @param int    $attachment_id The ID of the attachment.
     * @param string $base_alt_text The original alternative text to be translated.
     * @return string|null The translated alternative text, or null if the translation failed.
     */
    public function generate_multilingual_alt_text($attachment_id, $base_alt_text) {
        $current_language = $this->get_post_language($attachment_id);

        // Store the original alt text in current language
        $translations[$current_language] = $base_alt_text;

        // Get OpenAI instance
        $openai = new Auto_Alt_Text_OpenAI();

        if (empty($current_language)) {
            return;
        }

        $prompt = sprintf(
            "Translate this image description to %s, maintaining the same descriptive quality: %s",
            $current_language,
            $base_alt_text
        );

        $translated_alt = $openai->translate_alt_text($prompt);

        error_log('Translated alt text: ' . $translated_alt);

        if ($translated_alt) {
            $this->store_language_specific_alt_text($attachment_id, $current_language, $translated_alt);
        }

        return $translated_alt;
    }

    /**
     * Retrieves the alternative text (alt text) for an attachment in a specific language.
     *
     * This method first checks if a language is provided. If not, it uses the current language.
     * It then retrieves the alt text for the attachment in the specified language. If the translation
     * does not exist, it falls back to the default language.
     *
     * @param int    $attachment_id The ID of the attachment.
     * @param string $language      The language code for the alt text (optional).
     * @return string The alternative text for the attachment in the specified language.
     */
    public function get_alt_text($attachment_id, $language = null) {
        if (!$language) {
            $language = $this->get_current_language();
        }

        $alt_text = get_post_meta(
            $attachment_id,
            '_wp_attachment_image_alt_' . $language,
            true
        );

        // Fallback to default language if translation doesn't exist
        if (empty($alt_text) && $language !== $this->default_language) {
            $alt_text = get_post_meta(
                $attachment_id,
                '_wp_attachment_image_alt_' . $this->default_language,
                true
            );
        }

        return $alt_text;
    }

    /**
     * Generates translations for the alternative text (alt text) of multiple attachments.
     *
     * This method iterates through the provided attachment IDs, retrieves the base alt text
     * for the default language, and then generates the translated alt text for each available
     * language using the `generate_multilingual_alt_text()` method.
     *
     * @param int[] $attachment_ids The IDs of the attachments to generate translations for.
     * @return array An associative array mapping attachment IDs to their translated alt text.
     */
    public function bulk_generate_translations($attachment_ids) {
        $results = [];

        foreach ($attachment_ids as $attachment_id) {
            $base_alt_text = $this->get_alt_text($attachment_id, $this->default_language);
            if (!empty($base_alt_text)) {
                $results[$attachment_id] = $this->generate_multilingual_alt_text(
                    $attachment_id,
                    $base_alt_text
                );
            }
        }

        return $results;
    }

    /**
     * Stores the alternative text (alt text) for an attachment in a specific language.
     *
     * This private method is used to update the alt text for an attachment in a specific language.
     * It is called by the `sync_alt_text()` method to synchronize the alt text across all available languages.
     *
     * @param int    $attachment_id The ID of the attachment.
     * @param string $language      The language code for the alt text.
     * @param string $alt_text      The alternative text to be stored.
     */
    private function store_language_specific_alt_text($attachment_id, $language, $alt_text) {
        switch ($this->active_plugin['name']) {
            case 'wpml':
                // Get translated attachment ID
                $translated_id = apply_filters('wpml_object_id', $attachment_id, 'attachment', false, $language);
                if ($translated_id) {
                    update_post_meta($translated_id, '_wp_attachment_image_alt', $alt_text);
                }
                break;

            case 'polylang':
                // Get translated attachment ID
                $translated_id = pll_get_post($attachment_id, $language);
                if ($translated_id) {
                    update_post_meta($translated_id, '_wp_attachment_image_alt', $alt_text);
                }
                break;

            case 'translatepress':
                // TranslatePress uses its own translation storage system
                $this->store_translatepress_alt_text($attachment_id, $language, $alt_text);
                break;
        }
    }
}
