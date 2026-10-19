<?php
class Auto_Alt_Text_Models_Test extends WP_UnitTestCase {
    public function test_default_is_the_recommended_model() {
        $this->assertSame('gpt-6.1-sol', Auto_Alt_Text_Models::default_id());
        $this->assertSame('gpt-6.1-sol', Auto_Alt_Text_Models::current_id());
    }

    public function test_every_entry_is_normalized() {
        foreach (Auto_Alt_Text_Models::all() as $id => $model) {
            $this->assertSame($id, $model['id']);
            $this->assertIsFloat($model['input_price']);
            $this->assertIsFloat($model['output_price']);
            $this->assertGreaterThan(0, $model['max_output_tokens']);
            if (null !== $model['effort']) {
                $this->assertContains($model['effort'], $model['efforts'], "{$id} sends an effort it does not list");
            }
        }
    }

    public function test_saved_model_is_used() {
        update_option(Auto_Alt_Text_Models::OPTION, 'gpt-6-luna');
        $this->assertSame('gpt-6-luna', Auto_Alt_Text_Models::current_id());
        $this->assertSame('none', Auto_Alt_Text_Models::current()['effort']);
    }

    public function test_unknown_saved_model_falls_back_to_the_default() {
        update_option(Auto_Alt_Text_Models::OPTION, 'gpt-retired');
        $this->assertSame('gpt-6.1-sol', Auto_Alt_Text_Models::current_id());
        $this->assertSame('gpt-6.1-sol', Auto_Alt_Text_Models::current()['id']);
    }

    public function test_filter_can_add_a_model_with_defaults() {
        add_filter('auto_alt_text_models', function ($models) {
            $models['custom-vision'] = ['label' => 'Custom', 'input_price' => 1, 'output_price' => 2];
            return $models;
        });
        update_option(Auto_Alt_Text_Models::OPTION, 'custom-vision');

        $model = Auto_Alt_Text_Models::current();

        $this->assertSame('custom-vision', $model['id']);
        $this->assertNull($model['effort']);
        $this->assertSame(300, $model['max_output_tokens']);
        $this->assertSame(1.0, $model['input_price']);
    }

    public function test_model_filter_overrides_the_setting() {
        update_option(Auto_Alt_Text_Models::OPTION, 'gpt-6-luna');
        add_filter('auto_alt_text_model', function () {
            return 'gpt-5.4-mini';
        });
        $this->assertSame('gpt-5.4-mini', Auto_Alt_Text_Models::current_id());
    }

    public function test_sanitize_accepts_only_registry_ids() {
        $this->assertSame('gpt-6-luna', Auto_Alt_Text_Models::sanitize('gpt-6-luna'));
        $this->assertSame('', Auto_Alt_Text_Models::sanitize('gpt-retired'));
        $this->assertSame('', Auto_Alt_Text_Models::sanitize(['gpt-6-luna']));
    }

    public function test_cost_per_1000_images_uses_typical_usage() {
        // 1,300 input × $0.10 + 60 output × $0.50 per 1M tokens = $0.00016 per image.
        $this->assertEqualsWithDelta(0.16, Auto_Alt_Text_Models::cost_per_1000('gpt-6-luna'), 0.0001);
        $this->assertSame(0.0, Auto_Alt_Text_Models::cost_per_1000('gpt-retired'));
    }

    public function test_openai_reports_the_current_model() {
        update_option(Auto_Alt_Text_Models::OPTION, 'gpt-6-luna');
        $this->assertSame('gpt-6-luna', Auto_Alt_Text_OpenAI::get_model_name());
    }
}
