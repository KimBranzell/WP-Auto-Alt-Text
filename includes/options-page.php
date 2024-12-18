<?php
require_once plugin_dir_path(__FILE__) . 'config.php';

if (!@include_once(plugin_dir_path(__FILE__) . 'config.php')) {
    // Fallback definitions if config file fails to load
    if (!defined('SETTINGS_GROUP')) {
        define('SETTINGS_GROUP', 'auto-alt-text-settings-group');
    }
    if (!defined('AUTO_ALT_TEXT_API_KEY_OPTION')) {
        define('AUTO_ALT_TEXT_API_KEY_OPTION', 'auto_alt_text_api_key');
    }
    if (!defined('AUTO_ALT_TEXT_LANGUAGE_OPTION')) {
        define('AUTO_ALT_TEXT_LANGUAGE_OPTION', 'language');
    }
}

add_action('admin_menu', 'auto_alt_text_menu');
add_action('admin_init', 'auto_alt_text_register_settings');

/**
 * Registers the Auto Alt Text options page in the WordPress admin menu.
 *
 * This function adds an options page for the Auto Alt Text plugin to the WordPress admin menu.
 * The options page is accessible to users with the 'manage_options' capability.
 */
function auto_alt_text_menu() {
    add_options_page(
        'Auto Alt Text Options',
        'Auto Alt Text',
        'manage_options',
        'auto-alt-text',
        'auto_alt_text_options'
    );
}

/**
 * Returns an array of supported languages for the Auto Alt Text plugin.
 *
 * The array keys are the language codes, and the values are the corresponding language names.
 *
 * @return array An associative array of supported languages.
 */
function get_supported_languages() {
    return [
        'sv' => 'Swedish',
        'no' => 'Norwegian',
        'dk' => 'Danish',
        'fi' => 'Finnish',
        'en' => 'English',
        'es' => 'Spanish',
        'fr' => 'French',
        'de' => 'German',
        'it' => 'Italian',
        'pt' => 'Portuguese',
        'nl' => 'Dutch',
        'ru' => 'Russian',
        'ja' => 'Japanese',
        'zh' => 'Chinese',
        'ko' => 'Korean',
        'ar' => 'Arabic',
    ];
}

/**
 * Renders the options page for the Auto Alt Text plugin.
 *
 * This function checks if the current user has the necessary permissions to access the options page.
 * If the user has permission, it renders the options page with an HTML form that allows the user to
 * configure the API key and language settings for the Auto Alt Text service.
 */
function auto_alt_text_options() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }
    ?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        <form method="post" action="options.php">
            <?php
            wp_nonce_field('auto_alt_text_nonce_action', 'auto_alt_text_nonce');
            settings_fields(SETTINGS_GROUP);
            do_settings_sections('auto-alt-text');
            settings_errors();
            ?>

            <div class="card">
                <h2><?php _e('API Configuration', 'wp-auto-alt-text'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('OpenAI API Key', 'wp-auto-alt-text'); ?></th>
                        <td>
                            <input type="text" name="auto_alt_text_api_key" class="regular-text"
                                value="<?php echo esc_attr(get_option('auto_alt_text_api_key', '')); ?>" />
                        </td>
                    </tr>
                </table>
            </div>

            <div class="card">
                <h2><?php _e('Language Settings', 'wp-auto-alt-text'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Alt Text Language', 'wp-auto-alt-text'); ?></th>
                        <td>
                            <select name="<?php echo AUTO_ALT_TEXT_LANGUAGE_OPTION; ?>" class="regular-text">
                                <?php
                                $current_language = get_option(AUTO_ALT_TEXT_LANGUAGE_OPTION, 'en');
                                foreach (get_supported_languages() as $code => $name) {
                                    printf(
                                        '<option value="%s" %s>%s</option>',
                                        esc_attr($code),
                                        selected($current_language, $code, false),
                                        esc_html($name)
                                    );
                                }
                                ?>
                            </select>
                            <p class="description"><?php _e('Select the language for generated alt text descriptions', 'wp-auto-alt-text'); ?></p>
                        </td>
                    </tr>
                </table>
            </div>

            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

/**
 * Registers the settings for the Auto Alt Text plugin.
 *
 * This function registers two settings:
 * - 'auto_alt_text_api_key': The API key for the Auto Alt Text service.
 * - 'language': The language to use for the Auto Alt Text service.
 *
 * Both settings are sanitized using the 'auto_alt_text_sanitize' function.
 */
function auto_alt_text_register_settings() {
    register_setting(
        SETTINGS_GROUP,                 // Option group
        'auto_alt_text_api_key',        // Option name
        'auto_alt_text_sanitize'        // Sanitization callback
    );
    register_setting(
        SETTINGS_GROUP,
        'language',
        'auto_alt_text_sanitize'
    );
    register_setting(
        'auto_alt_text_options', // option group
        AUTO_ALT_TEXT_API_KEY_OPTION, // option name
        'auto_alt_text_sanitize' // sanitize callback
    );
}

/**
 * Sanitizes the input value for the Auto Alt Text API key setting.
 *
 * Validates the API key format to ensure it starts with "sk-" and is at least 32 characters long.
 * If the input is invalid, an error is added to the settings API and the current API key value is returned.
 * Otherwise, the input is sanitized using `sanitize_text_field()`.
 *
 * @param string $input The input value to be sanitized.
 * @return string The sanitized input value.
 */
function auto_alt_text_sanitize($input) {
    $option_name = current_filter();
    error_log('Current filter: ' . $option_name);

    switch($option_name) {
        case 'sanitize_option_auto_alt_text_api_key':
            $trimmed_input = trim($input);
            $matches = preg_match('/^sk-(proj-)?[A-Za-z0-9_-]{80,}$/', $trimmed_input);

            if (!$matches) {
                add_settings_error(
                    'auto_alt_text_api_key',
                    'invalid_api_key',
                    __('Invalid OpenAI API key format. The key must start with "sk-" or "sk-proj-" followed by at least 80 characters.', 'wp-auto-alt-text')
                );
                return get_option('auto_alt_text_api_key');
            }
            $openai = new OpenAI();
            $encrypted = $openai->encrypt_api_key($trimmed_input);
            return $encrypted;

        case AUTO_ALT_TEXT_LANGUAGE_OPTION:
            if (!in_array($input, ['en', 'sv'])) {
                return 'en'; // Default to English if invalid
            }
            break;
    }
    return sanitize_text_field($input);
}
?>
