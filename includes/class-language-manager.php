<?php
class Auto_Alt_Text_Language_Manager {
    private $active_plugin;
    private $default_language;
    private $translator;

    public function __construct(?Auto_Alt_Text_OpenAI $translator = null) {
        $this->active_plugin = $this->detect_language_plugin();
        $this->default_language = get_option(AUTO_ALT_TEXT_LANGUAGE_OPTION, 'en');
        $this->translator = $translator;
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
                'version' => constant('ICL_SITEPRESS_VERSION'),
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
        if (!$this->active_plugin) {
            return $this->default_language;
        }

        switch ($this->active_plugin['name']) {
            case 'wpml':
                return $this->get_wpml_language($post_id);
            case 'polylang':
                return $this->get_polylang_language($post_id);
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
                return apply_filters('wpml_default_language', null);
            case 'polylang':
                $callback = 'pll_default_language';
                return function_exists($callback) ? $callback() : $this->default_language;
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
            return [$this->default_language => (int) $post_id];
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
        $callback = 'pll_get_post_translations';
        if (!function_exists($callback)) {
            return [$this->default_language => $post_id];
        }

        $translations = $callback($post_id);

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
        $callback = 'pll_get_post_language';
        if (!function_exists($callback)) {
            return $this->default_language;
        }

        $language = $callback($post_id);

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
     * Retrieves the current language using the WPML plugin.
     *
     * This method is used as the handler for the 'wpml' active plugin in the `get_current_language()` method.
     * It calls the `wpml_current_language` filter to get the current language.
     *
     * @return string The current language.
     */
    private function handle_wpml() {
        // Try admin language context first
        $admin_lang = apply_filters('wpml_current_language', null, array('admin' => true));
        if (!empty($admin_lang)) {
            Auto_Alt_Text_Logger::log("WPML admin language detected", "debug", [
                'language' => $admin_lang
            ]);
            return $admin_lang;
        }

        // Fallback to standard language context
        $current_lang = apply_filters('wpml_current_language', null);

        Auto_Alt_Text_Logger::log("WPML language detected", "debug", [
            'language' => $current_lang ?: $this->default_language
        ]);

        return $current_lang ?: $this->default_language;
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
        $callback = 'pll_current_language';
        if (!function_exists($callback)) {
            return $this->default_language;
        }

        // Try admin language first during uploads
        $admin_lang = $callback('admin');
        if (!empty($admin_lang)) {
            Auto_Alt_Text_Logger::log("Polylang admin language detected", "debug", [
                'language' => $admin_lang
            ]);
            return $admin_lang;
        }

        // Fallback to standard language detection
        $current_lang = $callback();

        Auto_Alt_Text_Logger::log("Polylang language detected", "debug", [
            'language' => $current_lang ?: $this->default_language
        ]);

        return $current_lang ?: $this->default_language;
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
                $languages = apply_filters('wpml_active_languages', null);
                return is_array($languages) ? array_keys($languages) : [$this->get_default_language()];
            case 'polylang':
                $callback = 'pll_languages_list';
                return function_exists($callback) ? $callback(['fields' => 'locale']) : [];
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
        $alt_text = sanitize_text_field((string) $alt_text);
        if ($alt_text === '') {
            return;
        }

        update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt_text);

        $language = $this->get_post_language($attachment_id);
        if (!empty($language)) {
            update_post_meta($attachment_id, '_wp_attachment_image_alt_' . $language, $alt_text);
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
        $base_alt_text = sanitize_text_field((string) $base_alt_text);
        $translation_context = $this->resolve_translation_context($attachment_id);
        $source_attachment_id = $translation_context['source_attachment_id'];
        $source_language = $translation_context['source_language'];

        if ($base_alt_text === '') {
            return [
                'source_attachment_id' => $source_attachment_id,
                'source_language' => $source_language,
                'translations' => []
            ];
        }

        $this->store_language_specific_alt_text(
            $source_attachment_id,
            $source_language,
            $base_alt_text,
            $source_attachment_id
        );

        $results = [
            $source_language => [
                'attachment_id' => $source_attachment_id,
                'status' => 'source',
                'alt_text' => $base_alt_text,
            ],
        ];

        if (!$this->active_plugin) {
            return [
                'source_attachment_id' => $source_attachment_id,
                'source_language' => $source_language,
                'translations' => $results,
            ];
        }

        $translator = $this->translator ?: new Auto_Alt_Text_OpenAI();

        foreach ($translation_context['translations'] as $language => $translated_id) {
            if (!$this->should_translate_language($language, $translated_id, $source_language, $source_attachment_id)) {
                continue;
            }

            $translated_alt = $translator->translate_alt_text(
                $this->build_translation_prompt($base_alt_text, $language)
            );

            if (empty($translated_alt)) {
                $results[$language] = [
                    'attachment_id' => $translated_id,
                    'status' => 'failed',
                    'alt_text' => '',
                ];
                continue;
            }

            $translated_alt = sanitize_text_field($translated_alt);
            $this->store_language_specific_alt_text(
                $source_attachment_id,
                $language,
                $translated_alt,
                $translated_id
            );

            $results[$language] = [
                'attachment_id' => $translated_id,
                'status' => 'translated',
                'alt_text' => $translated_alt,
            ];
        }

        return [
            'source_attachment_id' => $source_attachment_id,
            'source_language' => $source_language,
            'translations' => $results,
        ];
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

        $translation_context = $this->resolve_translation_context($attachment_id);
        $source_attachment_id = $translation_context['source_attachment_id'];
        $target_attachment_id = $this->get_attachment_id_for_language(
            $source_attachment_id,
            $language,
            $translation_context['translations']
        );

        $alt_text = get_post_meta($target_attachment_id, '_wp_attachment_image_alt', true);

        if (empty($alt_text)) {
            $alt_text = get_post_meta(
                $source_attachment_id,
                '_wp_attachment_image_alt_' . $language,
                true
            );
        }

        // Fallback to default language if translation doesn't exist
        if (empty($alt_text) && $language !== $translation_context['source_language']) {
            $fallback_attachment_id = $this->get_attachment_id_for_language(
                $source_attachment_id,
                $translation_context['source_language'],
                $translation_context['translations']
            );
            $alt_text = get_post_meta($fallback_attachment_id, '_wp_attachment_image_alt', true);

            if (empty($alt_text)) {
                $alt_text = get_post_meta(
                    $source_attachment_id,
                    '_wp_attachment_image_alt_' . $translation_context['source_language'],
                    true
                );
            }
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
        $processed_sources = [];

        foreach ($attachment_ids as $attachment_id) {
            $translation_context = $this->resolve_translation_context($attachment_id);
            $source_attachment_id = $translation_context['source_attachment_id'];

            if (isset($processed_sources[$source_attachment_id])) {
                continue;
            }

            $processed_sources[$source_attachment_id] = true;

            $base_alt_text = get_post_meta($source_attachment_id, '_wp_attachment_image_alt', true);
            if (empty($base_alt_text)) {
                $base_alt_text = get_post_meta(
                    $source_attachment_id,
                    '_wp_attachment_image_alt_' . $translation_context['source_language'],
                    true
                );
            }

            if (!empty($base_alt_text)) {
                $results[$source_attachment_id] = $this->generate_multilingual_alt_text(
                    $source_attachment_id,
                    $base_alt_text
                );
                continue;
            }

            $results[$source_attachment_id] = [
                'source_attachment_id' => $source_attachment_id,
                'source_language' => $translation_context['source_language'],
                'translations' => [],
                'status' => 'skipped',
            ];
        }

        return $results;
    }

    /**
     * Resolves the source attachment and language for a translation group.
     *
     * @param int $attachment_id Attachment ID to resolve.
     * @return array<string,mixed>
     */
    private function resolve_translation_context($attachment_id) {
        $attachment_id = (int) $attachment_id;
        $translations = $this->normalize_translations($attachment_id, $this->get_post_translations($attachment_id));
        $source_language = $this->resolve_source_language($attachment_id, $translations);
        $source_attachment_id = isset($translations[$source_language])
            ? (int) $translations[$source_language]
            : $attachment_id;

        return [
            'source_attachment_id' => $source_attachment_id,
            'source_language' => $source_language,
            'translations' => $translations,
        ];
    }

    /**
     * Normalizes a translation map and ensures the current attachment exists in it.
     *
     * @param int   $attachment_id Source attachment candidate.
     * @param array $translations  Raw translation map.
     * @return array<string,int>
     */
    private function normalize_translations($attachment_id, $translations) {
        $normalized = [];

        if (is_array($translations)) {
            foreach ($translations as $language => $translated_id) {
                $language = $this->normalize_language_code($language);
                $translated_id = (int) $translated_id;

                if ($language === '' || $translated_id <= 0) {
                    continue;
                }

                $normalized[$language] = $translated_id;
            }
        }

        $current_language = $this->normalize_language_code($this->get_post_language($attachment_id));
        if ($current_language !== '' && !isset($normalized[$current_language])) {
            $normalized[$current_language] = (int) $attachment_id;
        }

        if (empty($normalized)) {
            $normalized[$this->get_default_language()] = (int) $attachment_id;
        }

        return $normalized;
    }

    /**
     * Determines the canonical source language for a translation group.
     *
     * @param int              $attachment_id Attachment ID to inspect.
     * @param array<string,int> $translations Normalized translation map.
     * @return string
     */
    private function resolve_source_language($attachment_id, array $translations) {
        $default_language = $this->normalize_language_code($this->get_default_language());
        if ($default_language !== '' && isset($translations[$default_language])) {
            return $default_language;
        }

        $current_language = $this->normalize_language_code($this->get_post_language($attachment_id));
        if ($current_language !== '' && isset($translations[$current_language])) {
            return $current_language;
        }

        return (string) array_key_first($translations);
    }

    /**
     * Resolves an attachment ID for a given language in the current translation group.
     *
     * @param int              $attachment_id Attachment ID in the translation group.
     * @param string           $language      Target language.
     * @param array<string,int> $translations Translation map.
     * @return int
     */
    private function get_attachment_id_for_language($attachment_id, $language, array $translations) {
        $language = $this->normalize_language_code($language);

        if (isset($translations[$language])) {
            return (int) $translations[$language];
        }

        return (int) $attachment_id;
    }

    /**
     * Checks whether a language should be translated.
     *
     * @param string $language             Target language.
     * @param int    $translated_id        Target attachment ID.
     * @param string $source_language      Source language.
     * @param int    $source_attachment_id Source attachment ID.
     * @return bool
     */
    private function should_translate_language($language, $translated_id, $source_language, $source_attachment_id) {
        $language = $this->normalize_language_code($language);
        $translated_id = (int) $translated_id;

        if ($language === '' || $translated_id <= 0) {
            return false;
        }

        if ($language === $source_language) {
            return false;
        }

        return $translated_id !== (int) $source_attachment_id;
    }

    /**
     * Builds a concise translation prompt for localized alt text.
     *
     * @param string $base_alt_text Source alt text.
     * @param string $language      Target language code.
     * @return string
     */
    private function build_translation_prompt($base_alt_text, $language) {
        $language = $this->normalize_language_code($language);
        $language_name = $this->get_language_name($language);

        return sprintf(
            'Translate this alt text into %s (%s). Keep the same meaning, accessibility value, and concise tone. Return only the translated alt text: %s',
            $language_name,
            $language,
            $base_alt_text
        );
    }

    /**
     * Gets a human-readable language name for prompts and logs.
     *
     * @param string $language Language code.
     * @return string
     */
    private function get_language_name($language) {
        $language = $this->normalize_language_code($language);

        if (defined('AUTO_ALT_TEXT_LANGUAGES') && isset(AUTO_ALT_TEXT_LANGUAGES[$language])) {
            return AUTO_ALT_TEXT_LANGUAGES[$language];
        }

        return strtoupper($language ?: $this->default_language);
    }

    /**
     * Normalizes language codes for array lookups.
     *
     * @param mixed $language Language code candidate.
     * @return string
     */
    private function normalize_language_code($language) {
        return sanitize_key((string) $language);
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
    private function store_language_specific_alt_text($attachment_id, $language, $alt_text, $translated_id = null) {
        $attachment_id = (int) $attachment_id;
        $language = $this->normalize_language_code($language);
        $alt_text = sanitize_text_field((string) $alt_text);

        if ($attachment_id <= 0 || $language === '' || $alt_text === '') {
            return;
        }

        update_post_meta($attachment_id, '_wp_attachment_image_alt_' . $language, $alt_text);

        if (!$this->active_plugin) {
            update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt_text);
            return;
        }

        switch ($this->active_plugin['name']) {
            case 'wpml':
                if ($translated_id === null) {
                    $translated_id = apply_filters('wpml_object_id', $attachment_id, 'attachment', false, $language);
                }
                if ($translated_id) {
                    update_post_meta($translated_id, '_wp_attachment_image_alt', $alt_text);
                }
                break;

            case 'polylang':
                if ($translated_id === null) {
                    $callback = 'pll_get_post';
                    $translated_id = function_exists($callback) ? $callback($attachment_id, $language) : 0;
                }
                if ($translated_id) {
                    update_post_meta($translated_id, '_wp_attachment_image_alt', $alt_text);
                }
                break;

            default:
                update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt_text);
                break;
        }
    }
}
