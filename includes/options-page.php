<?php
require_once plugin_dir_path(__FILE__) . 'config.php';
require_once plugin_dir_path(__FILE__) . 'class-format-checker.php';

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
    if (!defined('AUTO_ALT_TEXT_AUTO_GENERATE_OPTION')) {
        define('AUTO_ALT_TEXT_AUTO_GENERATE_OPTION', 'auto_alt_text_auto_generate');
    }
}

add_action('admin_menu', 'auto_alt_text_menu');
add_action('admin_init', 'auto_alt_text_register_settings');

add_action('admin_enqueue_scripts', 'auto_alt_text_admin_styles');

function auto_alt_text_admin_styles($hook) {
    if ($hook != 'settings_page_auto-alt-text') {
        return;
    }
    wp_enqueue_style(
        'auto-alt-text-admin',
        plugin_dir_url( dirname( __FILE__ ) ) . 'css/admin-style.css',
        [],
        '1.0.0'
    );
}

/**
 * Registers the Auto Alt Text options page in the WordPress admin menu.
 *
 * This function adds an options page for the Auto Alt Text plugin to the WordPress admin menu.
 * The options page is accessible to users with the 'manage_options' capability.
 */
function auto_alt_text_menu() {
    add_menu_page(
        'Auto Alt Text',          // Page title
        'Auto Alt Text',          // Menu title
        'manage_options',         // Capability
        'auto-alt-text',          // Menu slug
        'auto_alt_text_options',  // Function
        'dashicons-format-image'  // Icon
    );

    add_submenu_page(
        'auto-alt-text',         // Parent slug
        'Inställningar',              // Page title
        'Inställningar',              // Menu title
        'manage_options',        // Capability
        'auto-alt-text',         // Menu slug
        'auto_alt_text_options'  // Function
    );

    add_submenu_page(
        'auto-alt-text',         // Parent slug
        'Status',                // Page title
        'Status',                // Menu title
        'manage_options',        // Capability
        'auto-alt-text-status',  // Menu slug
        'add_format_compatibility_section'  // Function
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
		'sv' => 'Svenska',
		'no' => 'Norska',
		'dk' => 'Danska',
		'fi' => 'Finska',
		'en' => 'Engelska',
		'es' => 'Spanska',
		'fr' => 'Franska',
		'de' => 'Tyska',
		'it' => 'Italienska',
		'pt' => 'Portugisiska',
		'nl' => 'Holländska',
		'ja' => 'Japanska',
		'zh' => 'Kinesiska',
		'ko' => 'Koreanska',
		'ar' => 'Arabiska',
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

    global $wp_settings_errors;
    $wp_settings_errors = array();
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
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="auto_generate">
                                <?php _e('Aktivera generering av alt-texter', 'auto-alt-text'); ?>
                            </label>
                        </th>
                        <td>
                            <label class="switch">
                                <input type="checkbox"
                                    id="auto_generate"
                                    name="auto_alt_text_auto_generate"
                                    value="1"
                                    <?php checked(get_option('auto_alt_text_auto_generate', true)); ?>>
                                <span class="slider round"></span>
                            </label>
                            <p class="description">
                                <?php _e('När detta alternativ är aktiverat kommer de alternativa texterna att automatiskt genereras.', 'auto-alt-text'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="card">
                <div class="brand-settings-section">
                    <h2>Brand Settings</h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php _e('Aktivera brand-tonalitet', 'wp-auto-alt-text'); ?></th>
                            <td>
                                <label class="switch">
                                    <input type="checkbox"
                                        name="wp_auto_alt_text_enable_brand_tonality"
                                        id="enable_brand_tonality"
                                        value="1"
                                        <?php checked(get_option('wp_auto_alt_text_enable_brand_tonality', false)); ?>>
                                    <span class="slider round"></span>
                                </label>
                                <p class="description">
                                    <?php _e('När detta alternativ är aktiverat kommer den alternativa texten att optimeras för sökmotorer med inspiration från varumärkesbeskrivningarna i fälten nedan.', 'wp-auto-alt-text'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th>Varumärkets namn</th>
                            <td><input type="text" name="aat_brand_name" value="<?php echo esc_attr(get_option('aat_brand_name')); ?>" /></td>
                        </tr>
                        <tr>
                            <th><?php _e('Varumärkesbeskrivning', 'wp-auto-alt-text'); ?></th>
                            <td><textarea name="aat_brand_description" rows="3" placeholder="<?php _e('Ange en beskrivning av ditt varumärke', 'wp-auto-alt-text'); ?>"><?php echo esc_textarea(get_option('aat_brand_description')); ?></textarea></td>
                        </tr>
                    </table>
                </div>
            </div>



            <div class="card">
                <h2><?php _e('API', 'wp-auto-alt-text'); ?></h2>
                <?php
                $encrypted_key = get_option('auto_alt_text_api_key');
                $openai = new Auto_Alt_Text_OpenAI();
                $decrypted_key = $encrypted_key ? $openai->decrypt_api_key($encrypted_key) : '';
                ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="api_key">
                            <?php _e('OpenAI API-nyckel', 'wp-auto-alt-text'); ?>
                            </label>
                        </th>
                        <td>
                            <input type="text"
                                name="auto_alt_text_api_key"
                                class="regular-text"
                                value="<?php echo esc_attr($decrypted_key); ?>"
                            />
                        </td>
                    </tr>
                </table>
            </div>

            <div class="card">
                <h2><?php _e('Språk', 'wp-auto-alt-text'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Alternativa textens språk', 'wp-auto-alt-text'); ?></th>
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
                            <p class="description"><?php _e('Välj språket för genererade alternativa texter', 'wp-auto-alt-text'); ?></p>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="card">
                <h2><?php _e('Cache', 'wp-auto-alt-text'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="cache_duration">
                                <?php _e('Hur länge ska cache bevaras (i dagar)?', 'wp-auto-alt-text'); ?>
                            </label>
                        </th>
                        <td>
                            <input type="number"
                                id="cache_duration"
                                name="aat_cache_expiration"
                                value="<?php echo esc_attr(get_option('aat_cache_expiration', 30)); ?>"
                                min="1"
                                max="365"
                            />
                            <p class="description">
                                <?php _e('Antal dagar för att cacha genererade alternativa texter. Lägre värden använder fler API-anrop men håller innehållet fräschare.', 'wp-auto-alt-text'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="card">
                <h2><?php _e('Feedback', 'wp-auto-alt-text'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Aktivera feedbacksystem', 'wp-auto-alt-text'); ?></th>
                        <td>
                            <label class="switch">
                                <input type="checkbox"
                                    name="auto_alt_text_enable_feedback"
                                    id="enable_feedback"
                                    value="1"
                                    <?php checked(get_option('auto_alt_text_enable_feedback', true)); ?>>
                                <span class="slider round"></span>
                            </label>
                            <p class="description">
                                <?php _e('Tillåt användare att ge feedback och begära förbättringar av genererade alternativa texter.', 'wp-auto-alt-text'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- <div class="card">
                <h2><?php _e('AI Prompt Template', 'wp-auto-alt-text'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Custom Template', 'wp-auto-alt-text'); ?></th>
                        <td>
                            <textarea name="alt_text_prompt_template" id="alt-text-prompt-template" class="large-text code" rows="4">
                                <?php echo esc_textarea(get_option('alt_text_prompt_template',
                                'You are an expert in accessibility and SEO optimization, tasked with generating alt text for images. Analyze the image provided and generate a concise, descriptive alt text in {LANGUAGE} tailored to the following requirements:

                                1. First detect if there is any text in the image
                                2. If text is present, identify its language and include it in your response
                                3. Generate a concise alt text in {LANGUAGE} that:
                                    - Describes the image content
                                    - Includes any detected text (maintaining original language)
                                    - Maintains cultural context
                                4. Keep it under 2 sentences
                                5. Don\'t include phrases like "image of" or "picture of".
                                6. Write the text in {LANGUAGE} language.
                                7. For ambiguous images, describe them neutrally.
                                8. Use plain and easy-to-understand language.
                                9. If {LANGUAGE} is unsupported, default to English.
                                10. Maintain proper grammar and syntax in {LANGUAGE}

                                Output:
                                A single, SEO-friendly alt text description')); ?>
                            </textarea>
                            <div id="template-counter" class="description">
                                <div>Characters: <span id="char-count">0</span>/10000</div>
                            </div>
                            <p class="description">
                                <?php _e('Use {LANGUAGE} in your template to automatically insert the selected language.', 'wp-auto-alt-text'); ?>
                                <br>
                                <strong><?php _e('Example:', 'wp-auto-alt-text'); ?></strong>
                                <?php _e('Generate a descriptive alt text in {LANGUAGE} that captures the main elements of the image.', 'wp-auto-alt-text'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
            </div> -->

            <?php submit_button(); ?>
        </form>
        <script>
            jQuery(document).ready(function($) {
                function toggleBrandFields() {
                    var enabled = $('#enable_brand_tonality').is(':checked');
                    var brandFields = $('.brand-settings-section tr:not(:first-child)');

                    if (enabled) {
                        brandFields.removeClass('disabled-field');
                    } else {
                        brandFields.addClass('disabled-field');
                    }
                }

                $('#enable_brand_tonality').on('change', toggleBrandFields);
                toggleBrandFields(); // Run on page load
            });
        </script>
    </div>
    <?php

}

function add_format_compatibility_section() {
    ?>
    <div class="card">
        <h2><?php _e('Image Format Support', 'wp-auto-alt-text'); ?></h2>
        <table class="form-table">
            <tr>
                <th scope="row"><?php _e('Supported Formats', 'wp-auto-alt-text'); ?></th>
                <td>
                    <ul class="format-status">
                        <li>
                            <span class="format-name">JPEG/JPG</span>
                            <span class="status-badge supported">✓ Fully Supported</span>
                        </li>
                        <li>
                            <span class="format-name">PNG</span>
                            <span class="status-badge supported">✓ Fully Supported</span>
                        </li>
                        <li>
                            <span class="format-name">WebP</span>
                            <span class="status-badge supported">✓ Fully Supported</span>
                        </li>
                        <li>
                            <span class="format-name">AVIF</span>
                            <div>
                            <span class="status-badge partial">⚠ Auto-converts to JPEG for processing</span>
                            </div>
                        </li>
                    </ul>
                </td>
            </tr>
        </table>
    </div>
    <div class="card">
        <h2><?php _e('AVIF Support Status', 'wp-auto-alt-text'); ?></h2>
        <?php
        $avif_status = Auto_Alt_Text_Format_Checker::get_avif_status();
        ?>
        <table class="form-table">
            <tr>
                <td>
                    <ul class="format-status">
                        <li>
                            <span class="format-name">Server Support</span>
                            <span class="status-badge <?php echo $avif_status['server_support'] ? 'supported' : 'not-supported'; ?>">
                                <?php echo $avif_status['server_support'] ? '✓ Available' : '⚠ Not Available'; ?>
                            </span>
                        </li>
                        <li>
                            <span class="format-name">GD Library</span>
                            <span class="status-badge <?php echo $avif_status['gd_support'] ? 'supported' : 'not-supported'; ?>">
                                <?php echo $avif_status['gd_support'] ? '✓ Supported' : '⚠ Not Supported'; ?>
                            </span>
                        </li>
                        <li>
                            <span class="format-name">WordPress</span>
                            <span class="status-badge <?php echo $avif_status['wordpress_support'] ? 'supported' : 'not-supported'; ?>">
                                <?php echo $avif_status['wordpress_support'] ? '✓ Compatible' : '⚠ Not Compatible'; ?>
                            </span>
                        </li>
                    </ul>
                </td>
            </tr>
        </table>
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
    // register_setting(
    //     SETTINGS_GROUP,
    //     'alt_text_prompt_template',     // New setting for prompt template
    //     'auto_alt_text_sanitize'
    // );
    register_setting(
        SETTINGS_GROUP,
        'aat_brand_name',
        'auto_alt_text_sanitize'
    );
    register_setting(
        SETTINGS_GROUP,
        'wp_auto_alt_text_enable_brand_tonality',
        'auto_alt_text_sanitize'
    );
    register_setting(
        SETTINGS_GROUP,
        'aat_brand_position',
        'auto_alt_text_sanitize'
    );
    register_setting(
        SETTINGS_GROUP,
        'aat_product_keywords',
        'auto_alt_text_sanitize'
    );
    register_setting(
        SETTINGS_GROUP,
        'aat_brand_description',
        'auto_alt_text_sanitize'
    );
    register_setting(
        SETTINGS_GROUP,
        'aat_cache_expiration',
        [
            'type' => 'integer',
            'default' => 30,
            'sanitize_callback' => function($input) {
                $value = absint($input);
                return max(1, min(365, $value)); // Ensure value is between 1 and 365
            }
        ]
    );
    register_setting(
        SETTINGS_GROUP,
        'auto_alt_text_auto_generate',
        [
            'type' => 'boolean',
            'default' => true,
            'sanitize_callback' => 'rest_sanitize_boolean'
        ]
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

    if ($option_name === 'sanitize_option_auto_alt_text_api_key') {
        add_settings_error(
            'auto_alt_text_settings',
            'settings_updated',
            __('Settings saved successfully.', 'wp-auto-alt-text'),
            'updated'
        );
    }

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
            $openai = new Auto_Alt_Text_OpenAI();
            $encrypted = $openai->encrypt_api_key($trimmed_input);
            return $encrypted;

        // case 'sanitize_option_alt_text_prompt_template':
        //     $min_length = 50;
        //     $max_length = 10000;
        //     $required_keywords = ['alt text', 'descriptive', 'concise'];

        //     if (strlen($input) < $min_length || strlen($input) > $max_length) {
        //         add_settings_error(
        //             'alt_text_prompt_template',
        //             'invalid_prompt_length',
        //             sprintf(__('Prompt template must be between %d and %d characters.', 'wp-auto-alt-text'), $min_length, $max_length)
        //         );
        //         return get_option('alt_text_prompt_template');
        //     }

        //     foreach ($required_keywords as $keyword) {
        //         if (stripos($input, $keyword) === false) {
        //             add_settings_error(
        //                 'alt_text_prompt_template',
        //                 'missing_keyword',
        //                 sprintf(__('Prompt template must include the keyword "%s".', 'wp-auto-alt-text'), $keyword)
        //             );
        //             return get_option('alt_text_prompt_template');
        //         }
        //     }
        //     return sanitize_textarea_field($input);

        case AUTO_ALT_TEXT_LANGUAGE_OPTION:
            if (!in_array($input, ['en', 'sv'])) {
                return 'en'; // Default to English if invalid
            }
            break;
    }
    return sanitize_text_field($input);
}
?>
