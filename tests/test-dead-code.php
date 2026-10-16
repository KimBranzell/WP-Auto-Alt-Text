<?php
class Auto_Alt_Text_Dead_Code_Test extends WP_UnitTestCase {
    /**
     * Cache_Manager::init() registered the wp_update_attachment_metadata
     * *filter* as an action pointing at a missing method: wiring it would
     * fatal or make core delete attachment metadata.
     */
    public function test_cache_manager_has_no_hook_registration() {
        $this->assertFalse(method_exists('Auto_Alt_Text_Cache_Manager', 'init'));
    }

    public function test_duplicate_batch_paths_are_gone() {
        $this->assertFalse(class_exists('Auto_Alt_Text_Batch_Processor'));
        $this->assertFalse(method_exists('Auto_Alt_Text_Image_Process', 'process_image_batch'));
        $this->assertFalse(method_exists('Auto_Alt_Text_Admin', 'handle_batch_processing'));
    }
}
