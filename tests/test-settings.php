<?php
// The test config defines no AUTH_SALT; without it encryption is a no-op and
// the double-sanitize bug is invisible.
if (!defined('AUTH_SALT')) {
    define('AUTH_SALT', 'test-auth-salt-0123456789abcdef');
}

class Auto_Alt_Text_Settings_Test extends WP_UnitTestCase {
    private function valid_key() {
        return 'sk-proj-' . str_repeat('a', 80);
    }

    public function setUp(): void {
        parent::setUp();
        auto_alt_text_register_settings();
        delete_option('auto_alt_text_api_key');
    }

    public function tearDown(): void {
        unset($_POST['option_page']);
        parent::tearDown();
    }

    public function test_first_save_of_api_key_stores_a_decryptable_key() {
        // First save goes update_option() -> add_option(): sanitized twice.
        update_option('auto_alt_text_api_key', $this->valid_key());
        $openai = new Auto_Alt_Text_OpenAI();
        $this->assertSame($this->valid_key(), $openai->decrypt_api_key(get_option('auto_alt_text_api_key')));
    }

    public function test_empty_submission_keeps_existing_key() {
        update_option('auto_alt_text_api_key', $this->valid_key());
        $stored = get_option('auto_alt_text_api_key');
        update_option('auto_alt_text_api_key', '');
        $this->assertSame($stored, get_option('auto_alt_text_api_key'));
    }

    public function test_settings_page_never_prints_the_key() {
        require_once ABSPATH . 'wp-admin/includes/template.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        update_option('auto_alt_text_api_key', $this->valid_key());
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $GLOBALS['title'] = 'Auto Alt Text';

        ob_start();
        auto_alt_text_options();
        $html = ob_get_clean();

        $this->assertStringNotContainsString($this->valid_key(), $html);
        $this->assertStringContainsString('type="password"', $html);
    }

    public function test_invalid_language_falls_back_to_english() {
        $_POST['option_page'] = AUTO_ALT_TEXT_SETTINGS_GROUP;
        update_option('language', 'xx');
        unset($_POST['option_page']);
        $this->assertSame('en', get_option('language'));
    }

    public function test_non_string_language_from_the_settings_form_falls_back_to_english() {
        $_POST['option_page'] = AUTO_ALT_TEXT_SETTINGS_GROUP;
        update_option('language', ['sv']);
        unset($_POST['option_page']);
        $this->assertSame('en', get_option('language'));
    }

    public function test_language_option_written_outside_the_settings_form_is_stored_unchanged() {
        // 'language' is a generic option name other plugins also use.
        update_option('language', 'sv_SE');
        $this->assertSame('sv_SE', get_option('language'));
        update_option('language', ['code' => 'sv']);
        $this->assertSame(['code' => 'sv'], get_option('language'));
    }

    public function test_feedback_toggle_is_a_registered_setting() {
        $this->assertArrayHasKey('auto_alt_text_enable_feedback', get_registered_settings());
    }

    public function test_global_helpers_are_prefixed() {
        $this->assertFalse(function_exists('get_supported_languages'));
        $this->assertFalse(function_exists('add_format_compatibility_section'));
        $this->assertFalse(defined('SETTINGS_GROUP'));
        $this->assertTrue(function_exists('auto_alt_text_get_supported_languages'));
        $this->assertSame('auto-alt-text-settings-group', AUTO_ALT_TEXT_SETTINGS_GROUP);
    }

    public function test_overwrite_policy_setting_is_registered_and_sanitized() {
        $this->assertArrayHasKey(Auto_Alt_Text_Provenance::POLICY_OPTION, get_registered_settings());

        update_option(Auto_Alt_Text_Provenance::POLICY_OPTION, 'everything');
        $this->assertSame('missing', get_option(Auto_Alt_Text_Provenance::POLICY_OPTION));

        update_option(Auto_Alt_Text_Provenance::POLICY_OPTION, 'ai');
        $this->assertSame('ai', get_option(Auto_Alt_Text_Provenance::POLICY_OPTION));
    }

    public function test_settings_page_offers_the_overwrite_policy() {
        require_once ABSPATH . 'wp-admin/includes/template.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $GLOBALS['title'] = 'Auto Alt Text';

        ob_start();
        auto_alt_text_options();
        $html = ob_get_clean();

        $this->assertStringContainsString('name="auto_alt_text_overwrite_policy"', $html);
    }
}
