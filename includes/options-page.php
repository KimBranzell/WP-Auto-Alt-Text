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

    include 'options_page_html.php';
}

function auto_alt_text_register_settings() {
    register_setting(SETTINGS_GROUP, 'auto_alt_text_api_key', 'auto_alt_text_sanitize');
}

function auto_alt_text_sanitize($input) {
    // Sanitize and validate the input here
    return sanitize_text_field($input);
}
?>