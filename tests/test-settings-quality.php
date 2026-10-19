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

    private function render_settings_page(): string {
        require_once ABSPATH . 'wp-admin/includes/template.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $GLOBALS['title'] = 'Auto Alt Text';

        ob_start();
        auto_alt_text_options();
        return ob_get_clean();
    }

    /** @return array<string,array{selected:bool,label:string}> The model select's options, in order. */
    private function model_options(string $html): array {
        $this->assertSame(1, preg_match('#<select id="model"[^>]*>(.*?)</select>#s', $html, $select));
        preg_match_all('#<option value="([^"]*)"([^>]*)>(.*?)</option>#s', $select[1], $matches, PREG_SET_ORDER);
        $options = [];
        foreach ($matches as $match) {
            $options[$match[1]] = [
                'selected' => false !== strpos($match[2], 'selected'),
                'label'    => trim(html_entity_decode($match[3], ENT_QUOTES, 'UTF-8')),
            ];
        }
        return $options;
    }

    private static function selected_values(array $options): array {
        return array_keys(array_filter($options, function ($option) {
            return $option['selected'];
        }));
    }

    public function test_model_select_follows_the_default_when_nothing_is_saved() {
        delete_option('auto_alt_text_model');

        $options = $this->model_options($this->render_settings_page());

        $this->assertSame('', array_key_first($options), 'The default choice comes first');
        $this->assertSame([''], self::selected_values($options), 'No explicit model is pinned');
        $default = Auto_Alt_Text_Models::get(Auto_Alt_Text_Models::default_id());
        $this->assertStringStartsWith('Standard: ' . $default['label'] . ' – ca ', $options['']['label']);
        $this->assertStringContainsString('USD per 1 000 bilder', $options['']['label']);
        $this->assertStringContainsString('(rekommenderad)', $options[$default['id']]['label']);
        $this->assertStringContainsString('USD per 1 000 bilder', $options['gpt-6-luna']['label']);
    }

    public function test_default_choice_label_is_built_from_the_registry() {
        delete_option('auto_alt_text_model');
        add_filter('auto_alt_text_models', function ($models) {
            foreach ($models as $id => $model) {
                $models[$id]['recommended'] = 'gpt-6-luna' === $id;
            }
            return $models;
        });

        $options = $this->model_options($this->render_settings_page());

        $this->assertStringStartsWith('Standard: GPT-6 Luna – ca ', $options['']['label']);
    }

    public function test_model_select_shows_a_saved_choice() {
        update_option('auto_alt_text_model', 'gpt-6-luna');

        $options = $this->model_options($this->render_settings_page());

        $this->assertSame(['gpt-6-luna'], self::selected_values($options));
    }

    public function test_model_select_falls_back_to_the_default_choice_for_a_removed_model() {
        update_option('auto_alt_text_model', 'gpt-6-luna');
        add_filter('auto_alt_text_models', function ($models) {
            unset($models['gpt-6-luna']);
            return $models;
        });

        $options = $this->model_options($this->render_settings_page());

        $this->assertSame([''], self::selected_values($options));
    }

    public function test_saving_the_default_choice_keeps_it_empty() {
        $this->mock_model_status(200);
        update_option('auto_alt_text_model', 'gpt-6-luna');

        update_option('auto_alt_text_model', '');

        $this->assertSame('', get_option('auto_alt_text_model'));
    }

    public function test_settings_page_shows_the_new_fields() {
        update_option('auto_alt_text_naming', 'product');

        $html = $this->render_settings_page();

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
