<?php
require_once ABSPATH . WPINC . '/class-wp-image-editor.php';
require_once ABSPATH . WPINC . '/class-wp-image-editor-gd.php';

class Auto_Alt_Text_Image_Source_Test extends WP_UnitTestCase {
    private $temp_baseline = 0;

    public function setUp(): void {
        parent::setUp();
        $this->temp_baseline = $this->temp_count();
    }

    private function temp_count() {
        return count(glob(get_temp_dir() . 'aat-image*') ?: []);
    }

    private function upload($file) {
        return self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/' . $file);
    }

    private static function data_url_parts($url) {
        if (!preg_match('#^data:(image/[a-z]+);base64,(.+)$#', $url, $m)) {
            return null;
        }
        $info = getimagesizefromstring(base64_decode($m[2]));
        return ['mime' => $m[1], 'width' => $info[0], 'height' => $info[1]];
    }

    // Adopted from the WIP tests/test-openai.php.
    public function test_resolve_image_detail_is_auto_for_batch_runs() {
        $this->assertSame('auto', Auto_Alt_Text_Image_Source::resolve_image_detail(123, 'batch', false));
    }

    // Adopted from the WIP tests/test-openai.php.
    public function test_resolve_image_detail_keeps_manual_runs_at_auto() {
        $this->assertSame('auto', Auto_Alt_Text_Image_Source::resolve_image_detail(123, 'manual', false));
    }

    public function test_every_generation_type_is_auto() {
        foreach (['upload', 'woocommerce', 'woocommerce_variation', 'woocommerce_bulk', 'batch', 'cli', 'api_batch', 'wpmf_folder'] as $type) {
            $this->assertSame('auto', Auto_Alt_Text_Image_Source::resolve_image_detail(1, $type), $type);
        }
        $this->assertSame('auto', Auto_Alt_Text_Image_Source::resolve_image_detail(1, 'api'));
        $this->assertSame('auto', Auto_Alt_Text_Image_Source::resolve_image_detail(1, 'batch', true));
    }

    public function test_detail_filter_can_still_return_low_for_a_background_type() {
        add_filter('auto_alt_text_image_detail', function () {
            return 'low';
        });
        $this->assertSame('low', Auto_Alt_Text_Image_Source::resolve_image_detail(1, 'batch'));
    }

    public function test_detail_filter_is_validated() {
        add_filter('auto_alt_text_image_detail', function () {
            return 'high';
        });
        $this->assertSame('high', Auto_Alt_Text_Image_Source::resolve_image_detail(1, 'batch'));

        add_filter('auto_alt_text_image_detail', function () {
            return 'ultra';
        }, 20);
        $this->assertSame('auto', Auto_Alt_Text_Image_Source::resolve_image_detail(1, 'batch'));
    }

    // Adopted from the WIP tests/test-openai.php.
    public function test_can_use_remote_image_source_rejects_local_and_unsupported_images() {
        $this->assertTrue(Auto_Alt_Text_Image_Source::can_use_remote_image_source('https://cdn.example.com/image.jpg', 'image/jpeg'));
        $this->assertFalse(Auto_Alt_Text_Image_Source::can_use_remote_image_source('https://wordpress-test.test/image.jpg', 'image/jpeg'));
        $this->assertFalse(Auto_Alt_Text_Image_Source::can_use_remote_image_source('https://cdn.example.com/image.avif', 'image/avif'));
    }

    public function test_remote_source_rejects_private_hosts_and_other_schemes() {
        foreach ([
            'http://localhost/a.jpg',
            'https://10.0.0.5/a.jpg',
            'https://192.168.1.2/a.jpg',
            'https://127.0.0.1/a.jpg',
            'https://media.local/a.jpg',
            'https://shop.internal/a.jpg',
            'ftp://cdn.example.com/a.jpg',
            'https://intranet/a.jpg',
        ] as $url) {
            $this->assertFalse(Auto_Alt_Text_Image_Source::can_use_remote_image_source($url, 'image/jpeg'), $url);
        }
        $this->assertTrue(Auto_Alt_Text_Image_Source::can_use_remote_image_source('http://93.184.216.34/a.webp', 'image/webp'));
    }

    public function test_target_size_caps_area_and_long_side() {
        $this->assertSame([1182, 886], Auto_Alt_Text_Image_Source::target_size(3000, 2250, 'auto'));
        $this->assertSame([512, 384], Auto_Alt_Text_Image_Source::target_size(3000, 2250, 'low'));
        $this->assertSame([640, 480], Auto_Alt_Text_Image_Source::target_size(640, 480, 'auto'), 'Never upscale');
        $this->assertSame([512, 384], Auto_Alt_Text_Image_Source::target_size(640, 480, 'low'));
    }

    public function test_tall_image_keeps_its_long_side_at_2048() {
        // The eval's 1421×7802 lamp: the area cap alone would give 437×2399; the long-side cap wins.
        $this->assertSame([373, 2048], Auto_Alt_Text_Image_Source::target_size(1421, 7802, 'auto'));
    }

    public function test_tall_image_round_trip_through_the_editor_keeps_its_long_side_at_2048() {
        $file = wp_tempnam('aat-tall') . '.png';
        $im = imagecreatetruecolor(400, 3000);
        imagefill($im, 0, 0, imagecolorallocate($im, 200, 120, 40));
        imagepng($im, $file);
        imagedestroy($im);

        $id = self::factory()->attachment->create_upload_object($file);
        $source = Auto_Alt_Text_Image_Source::for_attachment($id, 'auto');
        $this->assertNotWPError($source);
        $parts = self::data_url_parts($source->input_image()['image_url']);
        $source->cleanup();
        wp_delete_file($file);

        $this->assertSame(2048, $parts['height']);
        $this->assertEqualsWithDelta(273, $parts['width'], 1);
        $this->assertEqualsWithDelta(400 / 3000, $parts['width'] / $parts['height'], 0.001);
    }

    public function test_large_jpeg_is_resized_and_sent_as_base64() {
        $source = Auto_Alt_Text_Image_Source::for_attachment($this->upload('test-image-large.jpg'), 'auto');

        $image = $source->input_image();
        $parts = self::data_url_parts($image['image_url']);
        $source->cleanup();

        $this->assertSame('input_image', $image['type']);
        $this->assertSame('auto', $image['detail']);
        $this->assertSame('image/jpeg', $parts['mime']);
        $this->assertLessThanOrEqual(1048576, $parts['width'] * $parts['height']);
        $this->assertGreaterThan(1000, $parts['width']);
        $this->assertSame($this->temp_baseline, $this->temp_count(), 'cleanup() removes every temp file');
    }

    public function test_low_detail_fits_512() {
        $source = Auto_Alt_Text_Image_Source::for_attachment($this->upload('test-image-large.jpg'), 'low');
        $parts = self::data_url_parts($source->input_image()['image_url']);
        $source->cleanup();

        $this->assertSame([512, 384], [$parts['width'], $parts['height']]);
    }

    public function test_transparent_sources_are_sent_as_webp() {
        foreach (['transparent.png', 'webp-transparent.webp'] as $file) {
            $source = Auto_Alt_Text_Image_Source::for_attachment($this->upload($file), 'auto');
            $parts = self::data_url_parts($source->input_image()['image_url']);
            $source->cleanup();

            $this->assertSame('image/webp', $parts['mime'], $file);
        }
    }

    public function test_transparent_sources_fall_back_to_png_without_webp_support() {
        add_filter('wp_image_editors', function () {
            return ['Auto_Alt_Text_Test_No_Webp_Editor'];
        });
        $source = Auto_Alt_Text_Image_Source::for_attachment($this->upload('transparent.png'), 'auto');
        $parts = self::data_url_parts($source->input_image()['image_url']);
        $source->cleanup();

        $this->assertSame('image/png', $parts['mime']);
    }

    public function test_transparent_source_is_png_when_the_loaded_editor_cannot_write_webp_but_another_can() {
        // The loaded editor (first, supports PNG) cannot write WebP while the global check still says yes (GD is second).
        add_filter('wp_image_editors', function () {
            return ['Auto_Alt_Text_Test_No_Webp_Editor', 'WP_Image_Editor_GD'];
        });
        $this->assertTrue(wp_image_editor_supports(['mime_type' => 'image/webp']));

        $source = Auto_Alt_Text_Image_Source::for_attachment($this->upload('transparent.png'), 'auto');
        $parts = self::data_url_parts($source->input_image()['image_url']);
        $source->cleanup();

        $this->assertSame('image/png', $parts['mime']);
    }

    public function test_png_to_webp_conversion_is_saved_at_quality_82() {
        Auto_Alt_Text_Test_Quality_Spy_Editor::$saves = [];
        add_filter('wp_image_editors', function () {
            return ['Auto_Alt_Text_Test_Quality_Spy_Editor'];
        });
        $source = Auto_Alt_Text_Image_Source::for_attachment($this->upload('transparent.png'), 'auto');
        $source->cleanup();

        $this->assertSame([['image/webp', 82]], Auto_Alt_Text_Test_Quality_Spy_Editor::$saves);
    }

    private function assert_no_scoped_filters_left() {
        global $wp_filter;
        foreach (['image_resize_dimensions', 'image_editor_output_format', 'wp_editor_set_quality', 'jpeg_quality'] as $tag) {
            $this->assertFalse(isset($wp_filter[$tag]->callbacks[PHP_INT_MAX]), $tag);
        }
    }

    public function test_exception_during_resize_cleans_up_temp_files_and_scoped_filters() {
        $thrower = function ($null) {
            throw new RuntimeException('boom');
        };
        $id = $this->upload('test-image-large.jpg');
        add_filter('image_resize_dimensions', $thrower, 1);
        try {
            Auto_Alt_Text_Image_Source::for_attachment($id, 'auto');
            $this->fail('Expected exception');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }
        remove_filter('image_resize_dimensions', $thrower, 1);

        $this->assertSame($this->temp_baseline, $this->temp_count());
        $this->assert_no_scoped_filters_left();
    }

    public function test_exception_during_save_cleans_up_temp_files_and_scoped_filters() {
        // Only the save into the plugin's temp file throws, not the earlier loads.
        $thrower = function ($formats, $filename) {
            if (is_string($filename) && false !== strpos(basename($filename), 'aat-image')) {
                throw new RuntimeException('boom');
            }
            return $formats;
        };
        $id = $this->upload('transparent.png');
        add_filter('image_editor_output_format', $thrower, 1, 3);
        try {
            Auto_Alt_Text_Image_Source::for_attachment($id, 'auto');
            $this->fail('Expected exception');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }
        remove_filter('image_editor_output_format', $thrower, 1);

        $this->assertSame($this->temp_baseline, $this->temp_count());
        $this->assert_no_scoped_filters_left();
    }

    public function test_avif_is_converted_to_jpeg() {
        $source = Auto_Alt_Text_Image_Source::for_attachment($this->upload('avif-lossy.avif'), 'auto');
        $parts = self::data_url_parts($source->input_image()['image_url']);
        $source->cleanup();

        $this->assertSame('image/jpeg', $parts['mime']);
    }

    public function test_without_an_editor_a_supported_original_is_sent_as_is() {
        $id = $this->upload('canola.jpg');
        add_filter('wp_image_editors', '__return_empty_array');

        $source = Auto_Alt_Text_Image_Source::for_attachment($id, 'auto');
        $parts = self::data_url_parts($source->input_image()['image_url']);
        $source->cleanup();

        $this->assertSame(['image/jpeg', 640, 480], [$parts['mime'], $parts['width'], $parts['height']]);
    }

    public function test_without_an_editor_avif_is_unsupported() {
        $id = $this->upload('avif-lossy.avif');
        add_filter('wp_image_editors', '__return_empty_array');

        $result = Auto_Alt_Text_Image_Source::for_attachment($id, 'auto');

        $this->assertWPError($result);
        $this->assertSame('unsupported_image', $result->get_error_code());
        $this->assertSame($this->temp_baseline, $this->temp_count());
    }

    public function test_output_format_mapping_filters_are_ignored() {
        // A site mapping JPEG to AVIF must not make the plugin send AVIF.
        add_filter('image_editor_output_format', function ($formats) {
            $formats['image/jpeg'] = 'image/avif';
            return $formats;
        });
        $source = Auto_Alt_Text_Image_Source::for_attachment($this->upload('test-image-large.jpg'), 'auto');
        $parts = self::data_url_parts($source->input_image()['image_url']);
        $source->cleanup();

        $this->assertSame('image/jpeg', $parts['mime']);
    }

    private function offload($id, $url) {
        wp_delete_file(get_attached_file($id));
        add_filter('wp_get_attachment_url', function ($original, $post_id) use ($id, $url) {
            return $post_id === $id ? $url : $original;
        }, 10, 2);
    }

    public function test_offloaded_image_on_a_public_host_is_sent_by_url() {
        $id = $this->upload('canola.jpg');
        $this->offload($id, 'https://cdn.example.com/canola.jpg');

        $source = Auto_Alt_Text_Image_Source::for_attachment($id, 'low');

        $this->assertSame('https://cdn.example.com/canola.jpg', $source->input_image()['image_url']);
        $this->assertSame(['url' => 'https://cdn.example.com/canola.jpg'], Auto_Alt_Text_Image_Source::fingerprint($id));
    }

    public function test_offloaded_image_on_a_private_host_is_downloaded() {
        $id = $this->upload('canola.jpg');
        $bytes = file_get_contents(DIR_TESTDATA . '/images/canola.jpg');
        $this->offload($id, 'https://media.shop.test/canola.jpg');
        add_filter('pre_http_request', function ($pre, $args, $url) use ($bytes) {
            if ('https://media.shop.test/canola.jpg' !== $url) {
                return $pre;
            }
            file_put_contents($args['filename'], $bytes);
            return ['headers' => [], 'body' => '', 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => $args['filename']];
        }, 10, 3);

        $source = Auto_Alt_Text_Image_Source::for_attachment($id, 'auto');
        $parts = self::data_url_parts($source->input_image()['image_url']);
        $source->cleanup();

        $this->assertSame(['image/jpeg', 640, 480], [$parts['mime'], $parts['width'], $parts['height']]);
        $this->assertSame($this->temp_baseline, $this->temp_count());
    }

    public function test_failed_download_is_a_transient_error() {
        $id = $this->upload('canola.jpg');
        $this->offload($id, 'https://media.shop.test/canola.jpg');
        add_filter('pre_http_request', function ($pre, $args, $url) {
            return 'https://media.shop.test/canola.jpg' === $url
                ? ['headers' => [], 'body' => '', 'response' => ['code' => 404, 'message' => 'Not Found'], 'cookies' => []]
                : $pre;
        }, 10, 3);

        $result = Auto_Alt_Text_Image_Source::for_attachment($id, 'auto');

        $this->assertWPError($result);
        $this->assertSame('image_download_failed', $result->get_error_code());
        $this->assertSame($this->temp_baseline, $this->temp_count());
    }

    public function test_local_fingerprint_uses_size_and_mtime_not_contents() {
        $id = $this->upload('canola.jpg');
        $path = get_attached_file($id);

        $this->assertSame(['size' => filesize($path), 'mtime' => filemtime($path)], Auto_Alt_Text_Image_Source::fingerprint($id));
    }
}

/** An image editor that cannot write WebP, to exercise the PNG fallback. */
class Auto_Alt_Text_Test_No_Webp_Editor extends WP_Image_Editor_GD {
    public static function supports_mime_type($mime_type) {
        return 'image/webp' !== $mime_type && parent::supports_mime_type($mime_type);
    }
}

/** Records the mime type and quality each save is made with. */
class Auto_Alt_Text_Test_Quality_Spy_Editor extends WP_Image_Editor_GD {
    public static $saves = [];

    protected function _save($image, $filename = null, $mime_type = null) {
        self::$saves[] = [$mime_type, $this->get_quality()];
        return parent::_save($image, $filename, $mime_type);
    }
}
