<?php
if (!defined('AUTH_SALT')) {
    define('AUTH_SALT', 'test-auth-salt-0123456789abcdef');
}

class Auto_Alt_Text_Settings_Quality_Test extends WP_UnitTestCase {
    public function setUp(): void {
        parent::setUp();
        $GLOBALS['wp_settings_errors'] = [];
        auto_alt_text_register_settings();
    }

    public function test_migration_maps_brand_tonality_on_to_product_brand() {
        delete_option('auto_alt_text_naming');
        update_option('wp_auto_alt_text_enable_brand_tonality', '1');
        update_option('alt_text_prompt_template', 'old template');

        Auto_Alt_Text_Activator::activate();

        $this->assertSame('product_brand', get_option('auto_alt_text_naming'));
        $this->assertFalse(get_option('wp_auto_alt_text_enable_brand_tonality'));
        $this->assertFalse(get_option('alt_text_prompt_template'));
    }

    public function test_migration_maps_brand_tonality_off_or_missing_to_visible() {
        delete_option('auto_alt_text_naming');
        update_option('wp_auto_alt_text_enable_brand_tonality', '');
        Auto_Alt_Text_Activator::activate();
        $this->assertSame('visible', get_option('auto_alt_text_naming'));

        delete_option('auto_alt_text_naming');
        delete_option('wp_auto_alt_text_enable_brand_tonality');
        Auto_Alt_Text_Activator::activate();
        $this->assertSame('visible', get_option('auto_alt_text_naming'));
    }

    public function test_migration_never_overrides_a_chosen_level() {
        update_option('auto_alt_text_naming', 'product');
        update_option('wp_auto_alt_text_enable_brand_tonality', '1');

        Auto_Alt_Text_Activator::activate();

        $this->assertSame('product', get_option('auto_alt_text_naming'));
    }

    public function test_schema_1_3_adds_the_stats_columns() {
        global $wpdb;
        Auto_Alt_Text_Activator::activate();

        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$wpdb->prefix}auto_alt_text_stats");

        $this->assertContains('model', $columns);
        $this->assertContains('input_tokens', $columns);
        $this->assertContains('output_tokens', $columns);
        $this->assertSame('1.3', get_option(Auto_Alt_Text_Activator::DB_VERSION_OPTION));
    }

    public function test_track_generation_records_model_and_tokens() {
        global $wpdb;
        Auto_Alt_Text_Activator::activate();

        (new Auto_Alt_Text_Statistics())->track_generation(4242, 'Text', 130, 'manual', false, false, null, 'gpt-6-luna', 110, 20);

        $row = $wpdb->get_row("SELECT model, input_tokens, output_tokens FROM {$wpdb->prefix}auto_alt_text_stats WHERE image_id = 4242", ARRAY_A);
        $this->assertSame(['model' => 'gpt-6-luna', 'input_tokens' => '110', 'output_tokens' => '20'], $row);
    }

    public function test_track_generation_without_usage_stores_nulls() {
        global $wpdb;
        Auto_Alt_Text_Activator::activate();

        (new Auto_Alt_Text_Statistics())->track_generation(4343, 'Text', 0, 'manual');

        $row = $wpdb->get_row("SELECT model, input_tokens, output_tokens FROM {$wpdb->prefix}auto_alt_text_stats WHERE image_id = 4343", ARRAY_A);
        $this->assertSame(['model' => null, 'input_tokens' => null, 'output_tokens' => null], $row);
    }

    public function test_new_settings_are_registered_and_the_old_switch_is_not() {
        $settings = get_registered_settings();
        foreach (['auto_alt_text_naming', 'auto_alt_text_extra_instructions', 'auto_alt_text_model'] as $name) {
            $this->assertArrayHasKey($name, $settings);
        }
        $this->assertArrayNotHasKey('wp_auto_alt_text_enable_brand_tonality', $settings);
    }

    public function test_naming_and_extra_instructions_are_sanitized() {
        update_option('auto_alt_text_naming', 'everything');
        $this->assertSame('visible', get_option('auto_alt_text_naming'));

        update_option('auto_alt_text_extra_instructions', '<script>x</script>Nämn färgen. ' . str_repeat('a', 1200));
        $saved = get_option('auto_alt_text_extra_instructions');
        $this->assertStringStartsWith('Nämn färgen.', $saved);
        $this->assertSame(1000, mb_strlen($saved));
    }

    public function test_unknown_model_is_not_saved() {
        update_option('auto_alt_text_model', 'gpt-retired');
        $this->assertSame('', get_option('auto_alt_text_model'));
    }

    public function test_choosing_a_model_the_key_cannot_use_shows_a_notice() {
        (new Auto_Alt_Text_OpenAI())->save_api_key('sk-proj-' . str_repeat('a', 80));
        add_filter('pre_http_request', function ($pre, $args, $url) {
            if (strpos($url, 'api.openai.com/v1/models/') === false) {
                return $pre;
            }
            return ['headers' => [], 'body' => '{}', 'response' => ['code' => 404, 'message' => 'Not Found'], 'cookies' => []];
        }, 10, 3);

        update_option('auto_alt_text_model', 'gpt-6-luna');

        $this->assertSame('gpt-6-luna', get_option('auto_alt_text_model'), 'The choice is saved; the notice explains the problem');
        $this->assertContains('model_unavailable', wp_list_pluck(get_settings_errors('auto_alt_text_model'), 'code'));
    }

    private function mock_model_status(int $code): void {
        (new Auto_Alt_Text_OpenAI())->save_api_key('sk-proj-' . str_repeat('a', 80));
        add_filter('pre_http_request', function ($pre, $args, $url) use ($code) {
            if (strpos($url, 'openai.com') === false) {
                return $pre;
            }
            return ['headers' => [], 'body' => '{}', 'response' => ['code' => $code, 'message' => 'x'], 'cookies' => []];
        }, 10, 3);
    }

    public function test_saving_the_default_choice_checks_the_default_model() {
        $this->mock_model_status(404);

        update_option('auto_alt_text_model', 'gpt-6-luna');
        $GLOBALS['wp_settings_errors'] = [];
        update_option('auto_alt_text_model', '');

        $errors = array_values(array_filter(
            get_settings_errors('auto_alt_text_model'),
            function ($e) { return 'model_unavailable' === $e['code']; }
        ));
        $this->assertCount(1, $errors);
        $this->assertStringContainsString(Auto_Alt_Text_Models::default_id(), $errors[0]['message']);
    }

    public function test_saving_the_default_choice_with_an_available_model_adds_no_notice() {
        $this->mock_model_status(200);

        update_option('auto_alt_text_model', 'gpt-6-luna');
        $GLOBALS['wp_settings_errors'] = [];
        update_option('auto_alt_text_model', '');

        $this->assertNotContains('model_unavailable', wp_list_pluck(get_settings_errors('auto_alt_text_model'), 'code'));
    }

    public function test_settings_page_shows_the_new_fields() {
        require_once ABSPATH . 'wp-admin/includes/template.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $GLOBALS['title'] = 'Auto Alt Text';
        update_option('auto_alt_text_naming', 'product');

        ob_start();
        auto_alt_text_options();
        $html = ob_get_clean();

        $this->assertStringContainsString('name="auto_alt_text_naming"', $html);
        $this->assertMatchesRegularExpression('/value="product"\s+selected=/', $html);
        $this->assertStringContainsString('name="auto_alt_text_extra_instructions"', $html);
        $this->assertStringContainsString('name="auto_alt_text_model"', $html);
        $this->assertStringContainsString('GPT-6 Luna', $html);
        $this->assertStringContainsString('Text provenance', $html);
        $this->assertStringNotContainsString('wp_auto_alt_text_enable_brand_tonality', $html);
        $this->assertStringNotContainsString('alt_text_prompt_template', $html);
    }
}
