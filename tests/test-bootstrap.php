<?php
class Auto_Alt_Text_Bootstrap_Test extends WP_UnitTestCase {
    public function test_plugin_initialises_on_plugins_loaded() {
        $plugin = WP_Auto_Alt_Text_Plugin::get_instance();
        // Priority 0: after every plugin file is included, before handle_plugins_loaded (10).
        $this->assertSame(0, has_action('plugins_loaded', [$plugin, 'init']));
    }

    public function test_init_is_idempotent() {
        $plugin = WP_Auto_Alt_Text_Plugin::get_instance();
        $processor = $plugin->get_component('image_processor');
        $this->assertInstanceOf(Auto_Alt_Text_Image_Process::class, $processor);

        $plugin->init();

        $this->assertSame($processor, $plugin->get_component('image_processor'));
    }
}
