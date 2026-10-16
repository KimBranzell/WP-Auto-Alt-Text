<?php
class Auto_Alt_Text_Frontend_Render_Test extends WP_UnitTestCase {
    /**
     * Rendering must never trigger generation: these filters run on
     * anonymous front-end page views.
     */
    public function test_no_render_time_page_builder_hooks_are_registered() {
        $hooks = [
            'et_pb_image_alt',
            'et_pb_gallery_image_alt',
            'fl_builder_photo_data',
            'vc_single_image_html',
            'elementor/image_carousel/get_image_caption',
            'elementor/images/before_insert_attachment',
        ];
        foreach ($hooks as $hook) {
            $this->assertFalse(has_filter($hook), "$hook must not be hooked");
        }
    }
}
