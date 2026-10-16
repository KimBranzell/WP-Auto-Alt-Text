<?php
class Auto_Alt_Text_Admin_Assets_Test extends WP_UnitTestCase {
    public function setUp(): void {
        parent::setUp();
        // WooCommerce's admin_enqueue_scripts listener reads get_current_screen()->id.
        set_current_screen('dashboard');
    }

    public function tearDown(): void {
        wp_dequeue_script('auto-alt-text-admin-js');
        wp_dequeue_style('auto_alt_text_css');
        // Localized data accumulates on the registered handle; start each test clean.
        wp_deregister_script('auto-alt-text-admin-js');
        wp_deregister_style('auto_alt_text_css');
        parent::tearDown();
    }

    public function test_assets_load_only_on_media_editor_and_plugin_screens() {
        foreach (['upload.php', 'post.php', 'post-new.php', 'toplevel_page_auto-alt-text', 'auto-alt-text_page_auto-alt-text-stats'] as $hook) {
            $this->assertTrue(Auto_Alt_Text_Admin::should_load_on($hook), $hook);
        }
        foreach (['index.php', 'edit.php', 'plugins.php', 'woocommerce_page_wc-settings', 'woocommerce_page_wc-orders'] as $hook) {
            $this->assertFalse(Auto_Alt_Text_Admin::should_load_on($hook), $hook);
        }
    }

    public function test_dashboard_does_not_enqueue_plugin_assets() {
        do_action('admin_enqueue_scripts', 'index.php');
        $this->assertFalse(wp_script_is('auto-alt-text-admin-js', 'enqueued'));
        $this->assertFalse(wp_style_is('auto_alt_text_css', 'enqueued'));
    }

    public function test_media_library_enqueues_versioned_assets() {
        do_action('admin_enqueue_scripts', 'upload.php');
        $this->assertTrue(wp_script_is('auto-alt-text-admin-js', 'enqueued'));
        $this->assertSame(AUTO_ALT_TEXT_VERSION, wp_scripts()->registered['auto-alt-text-admin-js']->ver);
    }

    public function test_media_modal_on_other_admin_screens_enqueues_assets() {
        // attachment_fields_to_edit renders the Generate button in every wp.media
        // modal, e.g. the site editor, widgets and customizer.
        set_current_screen('site-editor');
        do_action('wp_enqueue_media');
        $this->assertTrue(wp_script_is('auto-alt-text-admin-js', 'enqueued'));
        $this->assertTrue(wp_style_is('auto_alt_text_css', 'enqueued'));
        $this->assertStringContainsString('autoAltTextData', (string) wp_scripts()->get_data('auto-alt-text-admin-js', 'data'));
    }

    public function test_media_modal_assets_are_localized_once_when_both_hooks_fire() {
        // upload.php and the editors call wp_enqueue_media() and then fire admin_enqueue_scripts.
        set_current_screen('upload');
        do_action('wp_enqueue_media');
        do_action('admin_enqueue_scripts', 'upload.php');
        $this->assertSame(1, substr_count((string) wp_scripts()->get_data('auto-alt-text-admin-js', 'data'), 'var autoAltTextData'));
    }

    public function test_media_modal_outside_admin_enqueues_nothing() {
        set_current_screen('front');
        do_action('wp_enqueue_media');
        $this->assertFalse(wp_script_is('auto-alt-text-admin-js', 'enqueued'));
        $this->assertFalse(wp_style_is('auto_alt_text_css', 'enqueued'));
    }

    public function test_list_table_pagination_rule_is_scoped_to_the_stats_page() {
        $css = file_get_contents(dirname(__DIR__) . '/css/style.css');
        $this->assertDoesNotMatchRegularExpression('/(^|\})\s*\.tablenav \.tablenav-pages\s*\{/m', $css);
        $this->assertStringContainsString('.aat-stats-page .tablenav .tablenav-pages', $css);
    }
}
