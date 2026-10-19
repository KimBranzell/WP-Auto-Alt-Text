<?php
if ( ! defined( 'ABSPATH' ) ) exit;

require_once plugin_dir_path(__FILE__) . 'config.php';
require_once plugin_dir_path(__FILE__) . 'class-format-checker.php';

add_action('admin_menu', 'auto_alt_text_menu');
add_action('admin_init', 'auto_alt_text_register_settings');

add_action('admin_enqueue_scripts', 'auto_alt_text_admin_styles');

function auto_alt_text_admin_styles($hook) {
    if ('toplevel_page_auto-alt-text' !== $hook) {
        return;
    }
    wp_enqueue_style(
        'auto-alt-text-admin',
        plugin_dir_url( dirname( __FILE__ ) ) . 'css/admin-style.css',
        [],
        AUTO_ALT_TEXT_VERSION
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
        'auto_alt_text_render_status_page'  // Function
    );
}

/**
 * Returns an array of supported languages for the Auto Alt Text plugin.
 *
 * The array keys are the language codes, and the values are the corresponding language names.
 *
 * @return array An associative array of supported languages.
 */
function auto_alt_text_get_supported_languages() {
    return AUTO_ALT_TEXT_LANGUAGES;
}

/**
 * A model's label in the model select: name, estimated cost and the recommended marker.
 *
 * @param string $model_id Registry ID.
 * @return string Unescaped label, or '' for an unknown ID.
 */
function auto_alt_text_model_option_label($model_id) {
    $model = Auto_Alt_Text_Models::get($model_id);
    if (null === $model) {
        return '';
    }
    return sprintf(
        /* translators: 1: model name, 2: estimated USD per 1,000 images, 3: " (rekommenderad)" or empty */
        __('%1$s – ca %2$s USD per 1 000 bilder%3$s', 'WP-Auto-Alt-Text'),
        $model['label'],
        number_format_i18n(Auto_Alt_Text_Models::cost_per_1000($model_id), 2),
        $model['recommended'] ? __(' (rekommenderad)', 'WP-Auto-Alt-Text') : ''
    );
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
        wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'WP-Auto-Alt-Text' ) );
    }

    global $wp_settings_errors;
    $wp_settings_errors = array();
    ?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        <form method="post" action="options.php">
            <?php
            wp_nonce_field('auto_alt_text_nonce_action', 'auto_alt_text_nonce');
            settings_fields(AUTO_ALT_TEXT_SETTINGS_GROUP);
            do_settings_sections('auto-alt-text');
            settings_errors();
            ?>

			<div class="card">
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="auto_generate">
                                <?php esc_html_e('Generera alt-texter automatiskt', 'WP-Auto-Alt-Text'); ?>
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
                                <?php esc_html_e('Köar nya bilder och produktbilder som saknar alt-text. Manuell generering (knappen i mediebiblioteket, massåtgärder, WP-CLI och REST) fungerar alltid.', 'WP-Auto-Alt-Text'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="overwrite_policy">
                                <?php esc_html_e('Skriv över befintliga alt-texter vid massgenerering', 'WP-Auto-Alt-Text'); ?>
                            </label>
                        </th>
                        <td>
                            <?php $overwrite_policy = Auto_Alt_Text_Provenance::default_policy(); ?>
                            <select id="overwrite_policy" name="<?php echo esc_attr(Auto_Alt_Text_Provenance::POLICY_OPTION); ?>">
                                <option value="missing" <?php selected($overwrite_policy, 'missing'); ?>><?php esc_html_e('Nej – fyll bara i saknade alt-texter', 'WP-Auto-Alt-Text'); ?></option>
                                <option value="ai" <?php selected($overwrite_policy, 'ai'); ?>><?php esc_html_e('Ja, men bara oredigerade AI-texter', 'WP-Auto-Alt-Text'); ?></option>
                                <option value="all" <?php selected($overwrite_policy, 'all'); ?>><?php esc_html_e('Ja, alla – även texter skrivna av en redaktör', 'WP-Auto-Alt-Text'); ?></option>
                            </select>
                            <p class="description">
                                <?php esc_html_e('Gäller massåtgärder, REST /batch och WP-CLI (som kan välja med --overwrite). Generera-knappen för en enskild bild skriver alltid över.', 'WP-Auto-Alt-Text'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="card">
                <div class="brand-settings-section">
                    <h2><?php esc_html_e('Namn och varumärke', 'WP-Auto-Alt-Text'); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="naming"><?php esc_html_e('Namn i alt-texten', 'WP-Auto-Alt-Text'); ?></label>
                            </th>
                            <td>
                                <?php $naming = Auto_Alt_Text_Prompt::naming(); ?>
                                <select id="naming" name="<?php echo esc_attr(Auto_Alt_Text_Prompt::NAMING_OPTION); ?>">
                                    <option value="visible" <?php selected($naming, 'visible'); ?>><?php esc_html_e('Bara det som syns i bilden', 'WP-Auto-Alt-Text'); ?></option>
                                    <option value="product" <?php selected($naming, 'product'); ?>><?php esc_html_e('Produktnamn', 'WP-Auto-Alt-Text'); ?></option>
                                    <option value="product_brand" <?php selected($naming, 'product_brand'); ?>><?php esc_html_e('Produkt- och varumärkesnamn', 'WP-Auto-Alt-Text'); ?></option>
                                </select>
                                <p class="description">
                                    <?php esc_html_e('Med Produktnamn finns produktens namn alltid med i alt-texten när bilden hör till en enda produkt. Sidor och inlägg används bara som sammanhang.', 'WP-Auto-Alt-Text'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr class="aat-brand-name-row">
                            <th scope="row"><label for="aat_brand_name"><?php esc_html_e('Varumärkets namn', 'WP-Auto-Alt-Text'); ?></label></th>
                            <td><input type="text" id="aat_brand_name" name="aat_brand_name" value="<?php echo esc_attr(get_option('aat_brand_name')); ?>" /></td>
                        </tr>
                        <tr class="aat-brand-description-row">
                            <th scope="row"><label for="aat_brand_description"><?php esc_html_e('Varumärkesbeskrivning', 'WP-Auto-Alt-Text'); ?></label></th>
                            <td>
                                <textarea id="aat_brand_description" name="aat_brand_description" rows="3" placeholder="<?php echo esc_attr__( 'Ange en beskrivning av ditt varumärke', 'WP-Auto-Alt-Text' ); ?>"><?php echo esc_textarea(get_option('aat_brand_description')); ?></textarea>
                                <p class="description"><?php esc_html_e('Används som bakgrund för ordval, aldrig ordagrant.', 'WP-Auto-Alt-Text'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="extra_instructions"><?php esc_html_e('Extra instruktioner', 'WP-Auto-Alt-Text'); ?></label>
                            </th>
                            <td>
                                <textarea id="extra_instructions" name="<?php echo esc_attr(Auto_Alt_Text_Prompt::EXTRA_OPTION); ?>" rows="3" class="large-text" maxlength="<?php echo esc_attr(Auto_Alt_Text_Prompt::EXTRA_MAX_LENGTH); ?>"><?php echo esc_textarea(get_option(Auto_Alt_Text_Prompt::EXTRA_OPTION, '')); ?></textarea>
                                <p class="description">
                                    <?php esc_html_e('Läggs till sist i pluginets instruktioner, till exempel "Nämn alltid färgen". De kan inte ändra svarets format.', 'WP-Auto-Alt-Text'); ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>



            <div class="card">
                <h2><?php esc_html_e('API', 'WP-Auto-Alt-Text'); ?></h2>
                <?php
                $key_from_constant = defined('AUTO_ALT_TEXT_OPENAI_API_KEY') && AUTO_ALT_TEXT_OPENAI_API_KEY;
                $has_saved_key = !empty(get_option('auto_alt_text_api_key'));
                ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="api_key">
                            <?php esc_html_e('OpenAI API-nyckel', 'WP-Auto-Alt-Text'); ?>
                            </label>
                        </th>
                        <td>
                            <input type="password"
                                id="api_key"
                                name="auto_alt_text_api_key"
                                class="regular-text"
                                value=""
                                autocomplete="off"
                                placeholder="<?php echo esc_attr($has_saved_key ? __('Sparad nyckel – lämna tomt för att behålla', 'WP-Auto-Alt-Text') : 'sk-…'); ?>"
                                <?php disabled($key_from_constant); ?>
                            />
                            <?php if ($key_from_constant) : ?>
                                <p class="description"><?php esc_html_e('Nyckeln är definierad i wp-config.php (AUTO_ALT_TEXT_OPENAI_API_KEY).', 'WP-Auto-Alt-Text'); ?></p>
                            <?php endif; ?>
                            <p class="description">
                                <?php esc_html_e('Pluginen använder OpenAI Responses API och anpassar automatiskt takten efter OpenAI:s aktuella rate limit-headers.', 'WP-Auto-Alt-Text'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="model"><?php esc_html_e('Modell', 'WP-Auto-Alt-Text'); ?></label>
                        </th>
                        <td>
                            <?php
                            // The raw setting, not current_id(): with no saved choice the site follows the default (spec §6),
                            // and saving the page must not pin today's default.
                            $selected_model = Auto_Alt_Text_Models::sanitize(get_option(Auto_Alt_Text_Models::OPTION, ''));
                            ?>
                            <select id="model" name="<?php echo esc_attr(Auto_Alt_Text_Models::OPTION); ?>">
                                <option value="" <?php selected($selected_model, ''); ?>>
                                    <?php
                                    echo esc_html(sprintf(
                                        /* translators: %s: the default model's name and cost hint */
                                        __('Standard: %s', 'WP-Auto-Alt-Text'),
                                        auto_alt_text_model_option_label(Auto_Alt_Text_Models::default_id())
                                    ));
                                    ?>
                                </option>
                                <?php foreach (array_keys(Auto_Alt_Text_Models::all()) as $model_id) : ?>
                                    <option value="<?php echo esc_attr($model_id); ?>" <?php selected($selected_model, $model_id); ?>>
                                        <?php echo esc_html(auto_alt_text_model_option_label($model_id)); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">
                                <?php esc_html_e('Vattenmärkning: OpenAI kan märka AI-genererad text med en osynlig vattenstämpel. Den slås på i ditt OpenAI-konto under Organization/Project settings → Data controls → Text provenance, inte här.', 'WP-Auto-Alt-Text'); ?>
                                <a href="https://help.openai.com/en/articles/8912793-provenance-signals-in-openai-generated-content" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Läs mer', 'WP-Auto-Alt-Text'); ?></a>
                            </p>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="card">
                <h2><?php esc_html_e('Språk', 'WP-Auto-Alt-Text'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Alternativa textens språk', 'WP-Auto-Alt-Text'); ?></th>
                        <td>
                            <select name="<?php echo esc_attr( AUTO_ALT_TEXT_LANGUAGE_OPTION ); ?>" class="regular-text">
                                <?php
                                $current_language = get_option(AUTO_ALT_TEXT_LANGUAGE_OPTION, 'en');
                                foreach (auto_alt_text_get_supported_languages() as $code => $name) {
                                    printf(
                                        '<option value="%s" %s>%s</option>',
                                        esc_attr($code),
                                        selected($current_language, $code, false),
                                        esc_html($name)
                                    );
                                }
                                ?>
                            </select>
                            <p class="description"><?php _e('Välj standardspråk för genererade alternativa texter. När WPML eller Polylang används följer genereringen i stället den aktuella språkversionen för bilagan.', 'wp-auto-alt-text'); ?></p>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="card">
                <h2><?php esc_html_e('Cache', 'WP-Auto-Alt-Text'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                                <label for="cache_duration">
                                <?php esc_html_e('Hur länge ska cache bevaras (i dagar)?', 'WP-Auto-Alt-Text'); ?>
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
                                <?php esc_html_e('Antal dagar för att cacha genererade alternativa texter. Lägre värden använder fler API-anrop men håller innehållet fräschare.', 'WP-Auto-Alt-Text'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="card">
                <h2><?php esc_html_e('Feedback', 'WP-Auto-Alt-Text'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Aktivera feedbacksystem', 'WP-Auto-Alt-Text'); ?></th>
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
                                <?php esc_html_e('Tillåt användare att ge feedback och begära förbättringar av genererade alternativa texter.', 'WP-Auto-Alt-Text'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
            </div>

            <?php submit_button(); ?>
        </form>
        <script>
            jQuery(document).ready(function($) {
                // The brand name is used at "Produkt- och varumärkesnamn"; the description at both product levels.
                function toggleBrandFields() {
                    var naming = $('#naming').val();
                    $('.aat-brand-name-row').toggleClass('disabled-field', naming !== 'product_brand');
                    $('.aat-brand-description-row').toggleClass('disabled-field', naming === 'visible');
                }

                $('#naming').on('change', toggleBrandFields);
                toggleBrandFields();
            });
        </script>
    </div>
    <?php

}

function auto_alt_text_render_status_page() {
    ?>
    <div class="card">
        <h2><?php esc_html_e('Image Format Support', 'WP-Auto-Alt-Text'); ?></h2>
        <table class="form-table">
            <tr>
                <th scope="row"><?php esc_html_e('Supported Formats', 'WP-Auto-Alt-Text'); ?></th>
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
        <h2><?php esc_html_e('AVIF Support Status', 'WP-Auto-Alt-Text'); ?></h2>
        <?php
        $avif_status = Auto_Alt_Text_Format_Checker::get_avif_status();
        ?>
        <table class="form-table">
            <tr>
                <td>
                    <ul class="format-status">
                        <li>
                            <span class="format-name">Server Support</span>
                            <span class="status-badge <?php echo esc_attr( $avif_status['server_support'] ? 'supported' : 'not-supported' ); ?>">
                                <?php echo esc_html( $avif_status['server_support'] ? '✓ Available' : '⚠ Not Available' ); ?>
                            </span>
                        </li>
                        <li>
                            <span class="format-name">GD Library</span>
                            <span class="status-badge <?php echo esc_attr( $avif_status['gd_support'] ? 'supported' : 'not-supported' ); ?>">
                                <?php echo esc_html( $avif_status['gd_support'] ? '✓ Supported' : '⚠ Not Supported' ); ?>
                            </span>
                        </li>
                        <li>
                            <span class="format-name">WordPress</span>
                            <span class="status-badge <?php echo esc_attr( $avif_status['wordpress_support'] ? 'supported' : 'not-supported' ); ?>">
                                <?php echo esc_html( $avif_status['wordpress_support'] ? '✓ Compatible' : '⚠ Not Compatible' ); ?>
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
        AUTO_ALT_TEXT_SETTINGS_GROUP,                 // Option group
        'auto_alt_text_api_key',        // Option name
        'auto_alt_text_sanitize'        // Sanitization callback
    );
    register_setting(
        AUTO_ALT_TEXT_SETTINGS_GROUP,
        'language',
        'auto_alt_text_sanitize'
    );
    register_setting(
        AUTO_ALT_TEXT_SETTINGS_GROUP,
        'aat_brand_name',
        'auto_alt_text_sanitize'
    );
    register_setting(
        AUTO_ALT_TEXT_SETTINGS_GROUP,
        'aat_brand_position',
        'auto_alt_text_sanitize'
    );
    register_setting(
        AUTO_ALT_TEXT_SETTINGS_GROUP,
        'aat_product_keywords',
        'auto_alt_text_sanitize'
    );
    register_setting(
        AUTO_ALT_TEXT_SETTINGS_GROUP,
        'aat_brand_description',
        'auto_alt_text_sanitize'
    );
    register_setting(
        AUTO_ALT_TEXT_SETTINGS_GROUP,
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
        AUTO_ALT_TEXT_SETTINGS_GROUP,
        'auto_alt_text_auto_generate',
        [
            'type' => 'boolean',
            'default' => true,
            'sanitize_callback' => 'rest_sanitize_boolean'
        ]
    );
    register_setting(
        AUTO_ALT_TEXT_SETTINGS_GROUP,
        'auto_alt_text_enable_feedback',
        [
            'type' => 'boolean',
            'default' => true,
            'sanitize_callback' => 'rest_sanitize_boolean'
        ]
    );
    register_setting(
        AUTO_ALT_TEXT_SETTINGS_GROUP,
        Auto_Alt_Text_Provenance::POLICY_OPTION,
        [
            'type' => 'string',
            'default' => Auto_Alt_Text_Provenance::POLICY_MISSING,
            'sanitize_callback' => ['Auto_Alt_Text_Provenance', 'sanitize_policy'],
        ]
    );
    register_setting(
        AUTO_ALT_TEXT_SETTINGS_GROUP,
        Auto_Alt_Text_Prompt::NAMING_OPTION,
        [
            'type' => 'string',
            'default' => Auto_Alt_Text_Prompt::NAMING_VISIBLE,
            'sanitize_callback' => ['Auto_Alt_Text_Prompt', 'sanitize_naming'],
        ]
    );
    register_setting(
        AUTO_ALT_TEXT_SETTINGS_GROUP,
        Auto_Alt_Text_Prompt::EXTRA_OPTION,
        [
            'type' => 'string',
            'default' => '',
            'sanitize_callback' => ['Auto_Alt_Text_Prompt', 'sanitize_extra'],
        ]
    );
    register_setting(
        AUTO_ALT_TEXT_SETTINGS_GROUP,
        Auto_Alt_Text_Models::OPTION,
        [
            'type' => 'string',
            'default' => '',
            'sanitize_callback' => 'auto_alt_text_sanitize_model',
        ]
    );
}

/**
 * Whether a string looks like an OpenAI secret key.
 */
function auto_alt_text_is_valid_api_key_format($key) {
    return (bool) preg_match('/^sk-(proj-)?[A-Za-z0-9_-]{80,}$/', (string) $key);
}

/**
 * Model setting: registry IDs only. Warns, but still saves, when the key
 * cannot use the effective model (the chosen one, or the registry default
 * when no explicit choice is saved; spec §6).
 */
function auto_alt_text_sanitize_model($value) {
    $value = Auto_Alt_Text_Models::sanitize($value);
    if (in_array('model_unavailable', wp_list_pluck(get_settings_errors(Auto_Alt_Text_Models::OPTION), 'code'), true)) {
        return $value;
    }
    $effective = '' === $value ? Auto_Alt_Text_Models::default_id() : $value;
    if (false === (new Auto_Alt_Text_OpenAI())->is_model_available($effective)) {
        add_settings_error(
            Auto_Alt_Text_Models::OPTION,
            'model_unavailable',
            sprintf(
                /* translators: %s: model ID */
                esc_html__('Modellen %s är inte tillgänglig för den här API-nyckeln. Välj en annan modell eller byt nyckel, annars misslyckas genereringen.', 'WP-Auto-Alt-Text'),
                esc_html($effective)
            ),
            'warning'
        );
    }
    return $value;
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
            esc_html__('Settings saved successfully.', 'WP-Auto-Alt-Text'),
            'updated'
        );
    }

    switch($option_name) {
        case 'sanitize_option_auto_alt_text_api_key':
            $trimmed_input = trim((string) $input);
            $current = get_option('auto_alt_text_api_key');

            // The field never echoes the key back, so empty means "keep it".
            if ('' === $trimmed_input) {
                return $current;
            }

            $openai = new Auto_Alt_Text_OpenAI();

            // On the first save update_option() falls through to add_option(),
            // which sanitizes again: the value is then already encrypted.
            if (auto_alt_text_is_valid_api_key_format($openai->decrypt_api_key($trimmed_input))) {
                return $trimmed_input;
            }

            if (!auto_alt_text_is_valid_api_key_format($trimmed_input)) {
                add_settings_error(
                    'auto_alt_text_api_key',
                    'invalid_api_key',
                    esc_html__('Invalid OpenAI API key format. The key must start with "sk-" or "sk-proj-" followed by at least 80 characters.', 'WP-Auto-Alt-Text')
                );
                return $current;
            }

            return $openai->encrypt_api_key($trimmed_input);

        case 'sanitize_option_' . AUTO_ALT_TEXT_LANGUAGE_OPTION:
            // 'language' is a generic option name: only validate values coming
            // from this plugin's settings form and leave other writers alone.
            if (!isset($_POST['option_page']) || AUTO_ALT_TEXT_SETTINGS_GROUP !== $_POST['option_page']) {
                return $input;
            }
            if (!is_string($input) || !array_key_exists($input, auto_alt_text_get_supported_languages())) {
                return 'en'; // Default to English if invalid
            }
            break;
    }
    return sanitize_text_field($input);
}
?>
