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
        return function_exists('pll_current_language') ? pll_current_language() : $this->default_language;
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
        update_post_meta(
            $attachment_id,
            '_wp_attachment_image_alt_' . $language,
            $alt_text
        );
    }
}
