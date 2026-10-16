<?php
if (!defined('ICL_SITEPRESS_VERSION')) {
    define('ICL_SITEPRESS_VERSION', '4.9.2');
}

class Auto_Alt_Text_Multilingual_Hooks_Test extends WP_UnitTestCase {
    public function setUp(): void {
        parent::setUp();
        Auto_Alt_Text_Activator::activate();
    }

    public function test_upload_handler_runs_after_multilingual_plugins_assign_language() {
        $processor = WP_Auto_Alt_Text_Plugin::get_instance()->get_component('image_processor');
        // WPML and Polylang assign the attachment language on add_attachment at priority 10.
        $this->assertSame(20, has_action('add_attachment', [$processor, 'handle_new_attachment']));
    }

    public function test_upload_never_assigns_a_wpml_language() {
        $language_manager = new Auto_Alt_Text_Language_Manager(); // detects WPML via the constant above
        $this->assertSame('wpml', $language_manager->get_active_plugin_name());
        $processor = new Auto_Alt_Text_Image_Process(new Auto_Alt_Text_OpenAI($language_manager), $language_manager);

        $assignments = 0;
        add_action('wpml_set_element_language_details', function () use (&$assignments) {
            $assignments++;
        });

        $id = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');
        $processor->handle_new_attachment($id);

        $this->assertSame(0, $assignments);
    }

    public function test_wpml_language_lookup_survives_wp_error() {
        $language_manager = new Auto_Alt_Text_Language_Manager();
        // WPML returns WP_Error for deleted/unknown posts (inc/template-functions.php:803-808).
        add_filter('wpml_post_language_details', function () {
            return new WP_Error('invalid_post', 'Post not found');
        });

        $this->assertSame('en', $language_manager->get_post_language(PHP_INT_MAX));
    }

    public function test_wpml_region_codes_keep_their_language_family() {
        $language_manager = new Auto_Alt_Text_Language_Manager();
        add_filter('wpml_post_language_details', function () {
            return ['language_code' => 'pt-br'];
        });

        // Pins current behaviour until Phase 2 keeps full region codes.
        $this->assertSame('pt', $language_manager->get_post_language(123));
    }
}
