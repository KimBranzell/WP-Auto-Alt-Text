<?php
class Auto_Alt_Text_Language_Manager {
    private $active_plugin;
    private $default_language;

    public function __construct() {
        $this->active_plugin = $this->detect_language_plugin();
        $this->default_language = $this->normalize_language_code(
            get_option(AUTO_ALT_TEXT_LANGUAGE_OPTION, 'en')
        );
    }

    /**
     * Returns the active multilingual plugin name.
     *
     * @return string|null The plugin name or null when no plugin is active.
     */
    public function get_active_plugin_name() {
        return $this->active_plugin['name'] ?? null;
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
                'version' => constant('POLYLANG_VERSION'),
                'handler' => [$this, 'handle_polylang']
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
        return $this->normalize_language_code(call_user_func($handler));
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
        if (!$this->active_plugin) {
            return $this->default_language;
        }

        switch ($this->active_plugin['name']) {
            case 'wpml':
                return $this->normalize_language_code($this->get_wpml_language($post_id));
            case 'polylang':
                return $this->normalize_language_code($this->get_polylang_language($post_id));
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
        if (!$this->active_plugin) {
            return $this->default_language;
        }

        switch ($this->active_plugin['name']) {
            case 'wpml':
                return $this->normalize_language_code(apply_filters('wpml_default_language', null));
            case 'polylang':
                return $this->normalize_language_code($this->call_polylang_function('pll_default_language'));
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
        if (!$this->active_plugin) {
            return [$this->default_language => $post_id];
        }

        switch ($this->active_plugin['name']) {
            case 'wpml':
                return $this->get_wpml_translations($post_id);
            case 'polylang':
                return $this->get_polylang_translations($post_id);
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

        $translations = $this->normalize_translation_map(
            $this->call_polylang_function('pll_get_post_translations', [$post_id]),
            $post_id
        );

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

        $language = $this->call_polylang_function('pll_get_post_language', [$post_id]);

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

            if (is_array($translation_details)) {
                foreach ($translation_details as $lang => $translation) {
                    if (!isset($translation->element_id)) {
                        continue;
                    }

                    $translations[$lang] = (int) $translation->element_id;
                }
            }
        }

        $translations = $this->normalize_translation_map($translations, $post_id);

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
     * Retrieves the current language using the WPML plugin.
     *
     * This method is used as the handler for the 'wpml' active plugin in the `get_current_language()` method.
     * It calls the `wpml_current_language` filter to get the current language.
     *
     * @return string The current language.
     */
    private function handle_wpml() {
        $current_lang = apply_filters('wpml_current_language', null);

        Auto_Alt_Text_Logger::log("WPML language detected", "debug", [
            'language' => $current_lang ?: $this->default_language
        ]);

        return $this->normalize_language_code($current_lang ?: $this->default_language);
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
        $admin_lang = $this->call_polylang_function('pll_current_language', ['admin']);
        if (!empty($admin_lang)) {
            Auto_Alt_Text_Logger::log("Polylang admin language detected", "debug", [
                'language' => $admin_lang
            ]);
            return $this->normalize_language_code($admin_lang);
        }

        // Fallback to standard language detection
        $current_lang = $this->call_polylang_function('pll_current_language');

        Auto_Alt_Text_Logger::log("Polylang language detected", "debug", [
            'language' => $current_lang ?: $this->default_language
        ]);

        return $this->normalize_language_code($current_lang ?: $this->default_language);
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
        if (!$this->active_plugin) {
            return [$this->default_language];
        }

        switch ($this->active_plugin['name']) {
            case 'wpml':
                $languages = apply_filters('wpml_active_languages', null, ['skip_missing' => 0]);
                if (!is_array($languages)) {
                    return [$this->get_default_language()];
                }

                return array_values(
                    array_filter(
                        array_map([$this, 'normalize_language_code'], array_keys($languages))
                    )
                );
            case 'polylang':
                return function_exists('pll_languages_list')
                    ? array_values(
                        array_filter(
                            array_map(
                                [$this, 'normalize_language_code'],
                                (array) $this->call_polylang_function('pll_languages_list', [['fields' => 'slug']])
                            )
                        )
                    )
                    : [];
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
        if (empty($alt_text)) {
            return;
        }

        $current_language = $this->get_post_language($attachment_id);
        $this->store_language_specific_alt_text($attachment_id, $current_language, $alt_text);
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
    public function generate_multilingual_alt_text($attachment_id, $base_alt_text, $force_overwrite = false) {
        if (!$this->active_plugin || empty($base_alt_text)) {
            return [];
        }

        $source_language = $this->get_post_language($attachment_id);
        $translations = $this->get_post_translations($attachment_id);

        if (empty($source_language) || count($translations) < 2) {
            return [];
        }

        $openai = new Auto_Alt_Text_OpenAI($this);
        $translated_alts = [];

        foreach ($translations as $language => $translated_attachment_id) {
            if (empty($translated_attachment_id) || $language === $source_language) {
                continue;
            }

            $existing_alt_text = get_post_meta($translated_attachment_id, '_wp_attachment_image_alt', true);

            if (!$force_overwrite && !empty($existing_alt_text)) {
                $translated_alts[$language] = $existing_alt_text;
                continue;
            }

            $translated_alt = $openai->translate_alt_text($base_alt_text, $source_language, $language);

            if (empty($translated_alt)) {
                continue;
            }

            $this->store_language_specific_alt_text($attachment_id, $language, $translated_alt);
            $translated_alts[$language] = $translated_alt;
        }

        return $translated_alts;
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
        $alt_text = '';

        if (!$language) {
            $language = $this->get_current_language();
        }

        $language = $this->normalize_language_code($language);
        $translated_id = $this->get_translated_attachment_id($attachment_id, $language);

        if ($translated_id) {
            $alt_text = get_post_meta($translated_id, '_wp_attachment_image_alt', true);
            if (!empty($alt_text)) {
                return $alt_text;
            }
        }

        // Fallback to default language if translation doesn't exist
        if (empty($alt_text) && $language !== $this->default_language) {
            $default_id = $this->get_translated_attachment_id($attachment_id, $this->get_default_language());
            if ($default_id) {
                $alt_text = get_post_meta($default_id, '_wp_attachment_image_alt', true);
            }
        }

        return $alt_text ?: get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
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
            $base_alt_text = $this->get_alt_text($attachment_id, $this->get_post_language($attachment_id));
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
        if (empty($alt_text)) {
            return;
        }

        $translated_id = $this->get_translated_attachment_id($attachment_id, $language);

        if (!$translated_id) {
            $translated_id = (int) $attachment_id;
        }

        update_post_meta($translated_id, '_wp_attachment_image_alt', $alt_text);
    }

    /**
     * Retrieves the translated attachment ID for the requested language.
     *
     * @param int    $attachment_id The source attachment ID.
     * @param string $language      The requested language code.
     * @return int The translated attachment ID or the original ID when appropriate.
     */
    private function get_translated_attachment_id($attachment_id, $language) {
        $language = $this->normalize_language_code($language);

        if (empty($language)) {
            return (int) $attachment_id;
        }

        if (!$this->active_plugin) {
            return (int) $attachment_id;
        }

        $translations = $this->get_post_translations($attachment_id);

        if (isset($translations[$language])) {
            return (int) $translations[$language];
        }

        $current_language = $this->get_post_language($attachment_id);
        if ($language === $current_language) {
            return (int) $attachment_id;
        }

        return 0;
    }

    /**
     * Normalizes translation maps to language => attachment ID pairs.
     *
     * @param array $translations Raw translation data.
     * @param int   $post_id       The original attachment ID.
     * @return array<string,int> Normalized translation map.
     */
    private function normalize_translation_map($translations, $post_id) {
        $normalized = [];

        if (!is_array($translations)) {
            $translations = [];
        }

        foreach ($translations as $language => $translation) {
            $normalized_language = $this->normalize_language_code($language);

            if (empty($normalized_language)) {
                continue;
            }

            if (is_object($translation) && isset($translation->element_id)) {
                $normalized[$normalized_language] = (int) $translation->element_id;
                continue;
            }

            if (is_array($translation) && isset($translation['element_id'])) {
                $normalized[$normalized_language] = (int) $translation['element_id'];
                continue;
            }

            if (is_numeric($translation)) {
                $normalized[$normalized_language] = (int) $translation;
            }
        }

        $current_language = $this->normalize_language_code($this->get_post_language($post_id));

        if (empty($normalized)) {
            $normalized[$current_language ?: $this->get_default_language()] = (int) $post_id;
        }

        if (!empty($current_language) && !isset($normalized[$current_language])) {
            $normalized[$current_language] = (int) $post_id;
        }

        return $normalized;
    }

    /**
     * Normalizes language codes from multilingual plugins and locale values.
     *
     * @param string|null $language The raw language value.
     * @return string The normalized language code.
     */
    private function normalize_language_code($language) {
        if (empty($language) || !is_string($language)) {
            return 'en';
        }

        $normalized_language = strtolower(trim($language));
        $normalized_language = str_replace('_', '-', $normalized_language);

        if ('dk' === $normalized_language) {
            return 'da';
        }

        if (isset(AUTO_ALT_TEXT_LANGUAGES[$normalized_language])) {
            return $normalized_language;
        }

        $primary_language = strtok($normalized_language, '-');

        if ('dk' === $primary_language) {
            return 'da';
        }

        if (!empty($primary_language)) {
            return $primary_language;
        }

        return 'en';
    }

    /**
     * Calls a Polylang function only when it is available.
     *
     * @param string $function_name The Polylang function name.
     * @param array  $arguments     Optional arguments to pass.
     * @return mixed|null The function result or null when unavailable.
     */
    private function call_polylang_function($function_name, $arguments = []) {
        if (!function_exists($function_name)) {
            return null;
        }

        return call_user_func_array($function_name, $arguments);
    }
}
