<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Prepares an attachment's image for the API (spec §5). A copy is resized to
 * what the model will use (at most ~1 MP, long side ≤ 2048; 512 px for
 * `low`) and sent as base64. Offloaded media on a public host is sent by URL.
 * The original file is never modified. Callers must call cleanup().
 */
class Auto_Alt_Text_Image_Source {
    public const DEFAULT_MAX_PIXELS = 1048576;
    private const MAX_LONG_SIDE = 2048;
    private const LOW_DETAIL_BOX = 512;
    private const QUALITY = 82;
    public const QUALITY_LEVEL = self::QUALITY;
    private const ORIGINAL_MAX_BYTES = 20971520;
    private const DOWNLOAD_TIMEOUT = 30;
    private const API_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    private const ALPHA_MIMES = ['image/png', 'image/gif', 'image/webp'];
    private const DETAILS = ['low', 'high', 'auto', 'original'];
    private const LOCAL_HOST_SUFFIXES = ['.test', '.local', '.localhost', '.internal', '.invalid'];

    private $image_url = '';
    private $detail = 'auto';
    private $temp_files = [];

    /** `auto` for every generation type: `low` cut packaging text recall by 8-24 % in the evaluation (spec §5, §7.4). */
    public static function resolve_image_detail($attachment_id, $generation_type, $preview_mode = false) {
        $detail = 'auto';
        $detail = apply_filters('auto_alt_text_image_detail', $detail, (int) $attachment_id, (string) $generation_type);
        return in_array($detail, self::DETAILS, true) ? $detail : 'auto';
    }

    /** Whether OpenAI can fetch this URL itself: a public host and a format it accepts. */
    public static function can_use_remote_image_source($url, $mime) {
        if (!in_array($mime, self::API_MIMES, true)) {
            return false;
        }
        $parts = wp_parse_url((string) $url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
        if (!in_array($scheme, ['http', 'https'], true) || '' === $host || 'localhost' === $host) {
            return false;
        }
        foreach (self::LOCAL_HOST_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return false;
            }
        }
        if (false !== filter_var($host, FILTER_VALIDATE_IP)) {
            return false !== filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }
        return str_contains($host, '.');
    }

    /** Output dimensions: fit 512 px for `low`, else cap the area and the long side. Never upscales. */
    public static function target_size($width, $height, $detail, $max_pixels = self::DEFAULT_MAX_PIXELS) {
        $width = (int) $width;
        $height = (int) $height;
        if ($width <= 0 || $height <= 0) {
            return [$width, $height];
        }
        if ('low' === $detail) {
            $scale = min(1, self::LOW_DETAIL_BOX / max($width, $height));
        } else {
            $scale = min(1, sqrt(max(1, (int) $max_pixels) / ($width * $height)), self::MAX_LONG_SIDE / max($width, $height));
        }
        return [max(1, (int) floor($width * $scale + 1e-6)), max(1, (int) floor($height * $scale + 1e-6))];
    }

    /** What identifies the source image for the cache, without reading the file. */
    public static function fingerprint($attachment_id) {
        $path = (string) get_attached_file((int) $attachment_id);
        if ('' !== $path && file_exists($path)) {
            return ['size' => (int) filesize($path), 'mtime' => (int) filemtime($path)];
        }
        return ['url' => (string) wp_get_attachment_url((int) $attachment_id)];
    }

    /** @return self|WP_Error */
    public static function for_attachment($attachment_id, $detail) {
        $source = new self();
        try {
            return $source->build((int) $attachment_id, $detail);
        } catch (\Throwable $e) {
            $source->cleanup();
            throw $e;
        }
    }

    /** @return self|WP_Error */
    private function build($attachment_id, $detail) {
        $source = $this;
        $source->detail = in_array($detail, self::DETAILS, true) ? $detail : 'auto';
        $path = (string) get_attached_file($attachment_id);

        if ('' === $path || !file_exists($path)) {
            $url = (string) wp_get_attachment_url($attachment_id);
            if ('' === $url) {
                return new WP_Error('image_missing', __('The image file could not be found.', 'WP-Auto-Alt-Text'));
            }
            if (self::can_use_remote_image_source($url, (string) get_post_mime_type($attachment_id))) {
                $source->image_url = $url;
                return $source;
            }
            $path = $source->download($url);
            if (is_wp_error($path)) {
                $source->cleanup();
                return $path;
            }
        }

        $prepared = $source->prepare($path);
        if (is_wp_error($prepared)) {
            $source->cleanup();
            return $prepared;
        }
        return $source;
    }

    /** @return array{type:string,image_url:string,detail:string} */
    public function input_image() {
        return ['type' => 'input_image', 'image_url' => $this->image_url, 'detail' => $this->detail];
    }

    public function detail() {
        return $this->detail;
    }

    public function cleanup() {
        foreach ($this->temp_files as $file) {
            if (is_string($file) && file_exists($file)) {
                wp_delete_file($file);
            }
        }
        $this->temp_files = [];
    }

    /** The site's own media URL: trusted, so wp_remote_get (not the "safe" variant) may reach a private host. */
    private function download($url) {
        $target = wp_tempnam('aat-image');
        $this->temp_files[] = $target;
        $response = wp_remote_get($url, ['timeout' => self::DOWNLOAD_TIMEOUT, 'stream' => true, 'filename' => $target]);
        if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response) || !filesize($target)) {
            return new WP_Error('image_download_failed', __('The image could not be downloaded. Please try again.', 'WP-Auto-Alt-Text'));
        }
        return $target;
    }

    /** @return true|WP_Error */
    private function prepare($path) {
        $mime = (string) wp_get_image_mime($path);
        $editor = wp_get_image_editor($path);

        if (!is_wp_error($editor)) {
            $size = $editor->get_size();
            [$width, $height] = self::target_size(
                (int) $size['width'],
                (int) $size['height'],
                $this->detail,
                (int) apply_filters('auto_alt_text_image_max_pixels', self::DEFAULT_MAX_PIXELS)
            );
            $resized = true;
            if ($width < (int) $size['width'] || $height < (int) $size['height']) {
                // WordPress fits the size into a bounding box and rounds, which can leave the long side
                // a few px short of target_size() (e.g. 2042 instead of 2048). Use the exact size.
                $src_w = (int) $size['width'];
                $src_h = (int) $size['height'];
                $exact = static function ($null, $orig_w, $orig_h, $dest_w, $dest_h, $crop) use ($width, $height, $src_w, $src_h) {
                    if ($crop || (int) $orig_w !== $src_w || (int) $orig_h !== $src_h) {
                        return $null;
                    }
                    return [0, 0, 0, 0, $width, $height, $src_w, $src_h];
                };
                add_filter('image_resize_dimensions', $exact, PHP_INT_MAX, 6);
                try {
                    $resized = $editor->resize($width, $height, false);
                } finally {
                    remove_filter('image_resize_dimensions', $exact, PHP_INT_MAX);
                }
            }

            if (!is_wp_error($resized)) {
                $editor->set_quality(self::QUALITY);
                $target = wp_tempnam('aat-image');
                $this->temp_files[] = $target;

                // core's save() resets the quality when the output mime differs from the source's,
                // and the site may map formats (e.g. JPEG → AVIF); the API needs the ones asked for.
                $quality = static function () {
                    return Auto_Alt_Text_Image_Source::QUALITY_LEVEL;
                };
                add_filter('image_editor_output_format', '__return_empty_array', PHP_INT_MAX);
                add_filter('wp_editor_set_quality', $quality, PHP_INT_MAX);
                add_filter('jpeg_quality', $quality, PHP_INT_MAX);
                try {
                    $saved = $editor->save($target, self::output_mime($mime, $editor));
                } finally {
                    remove_filter('image_editor_output_format', '__return_empty_array', PHP_INT_MAX);
                    remove_filter('wp_editor_set_quality', $quality, PHP_INT_MAX);
                    remove_filter('jpeg_quality', $quality, PHP_INT_MAX);
                }

                if (!is_wp_error($saved) && !empty($saved['path'])) {
                    if ($saved['path'] !== $target) {
                        $this->temp_files[] = $saved['path'];
                    }
                    if (in_array($saved['mime-type'], self::API_MIMES, true) && file_exists($saved['path'])) {
                        $this->image_url = self::data_url($saved['path'], $saved['mime-type']);
                        return true;
                    }
                }
            }
        }

        // No usable editor: send the original if the API accepts it as it is.
        if (in_array($mime, self::API_MIMES, true) && filesize($path) <= self::ORIGINAL_MAX_BYTES) {
            $this->image_url = self::data_url($path, $mime);
            return true;
        }
        return new WP_Error('unsupported_image', __('Bildformatet stöds inte.', 'WP-Auto-Alt-Text'));
    }

    /** WebP keeps transparency and stays small; PNG when the loaded editor cannot write WebP; JPEG otherwise. */
    private static function output_mime($source_mime, $editor) {
        if (in_array($source_mime, self::ALPHA_MIMES, true)) {
            return call_user_func([get_class($editor), 'supports_mime_type'], 'image/webp') ? 'image/webp' : 'image/png';
        }
        return 'image/jpeg';
    }

    private static function data_url($path, $mime) {
        return 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($path));
    }
}
