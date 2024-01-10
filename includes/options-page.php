<?php
define('SETTINGS_GROUP', 'auto-alt-text-settings-group');

add_action('admin_menu', 'auto_alt_text_menu');
add_action('admin_init', 'auto_alt_text_register_settings');

function auto_alt_text_menu() {
    add_options_page(
        'Auto Alt Text Options',
        'Auto Alt Text',
        'manage_options',
        'auto-alt-text',
        'auto_alt_text_options'
    );
}

function auto_alt_text_options() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }

    // Start the form
    echo '<form method="post" action="options.php">';
    settings_fields(SETTINGS_GROUP);
    do_settings_sections('auto-alt-text');

    // API Key field
    echo '<h2>' . __('API Key', 'wp-auto-alt-text') . '</h2>';
    echo '<input type="text" name="auto_alt_text_api_key" value="' . esc_attr(get_option('auto_alt_text_api_key')) . '" />';

    // Language selector
    echo '<h2>' . __('Language', 'wp-auto-alt-text') . '</h2>';
    $language = get_option('language');
    echo '<select name="language">';
    echo '<option value="en"' . selected($language, 'en', false) . '>English</option>';
    echo '<option value="sv"' . selected($language, 'sv', false) . '>Svenska</option>';
    echo '</select>';

    submit_button();
    echo '</form>';
}

function auto_alt_text_register_settings() {
    register_setting(SETTINGS_GROUP, 'auto_alt_text_api_key', 'auto_alt_text_sanitize');
	register_setting(SETTINGS_GROUP, 'language', 'auto_alt_text_sanitize');
}

function auto_alt_text_sanitize($input) {
    // Sanitize and validate the input here
    return sanitize_text_field($input);
}
?>
