<?php
class Auto_Alt_Text_Format_Checker {
    public static function get_avif_status() {
        $status = [
            'server_support' => function_exists('imageavif'),
            'gd_support' => defined('IMAGETYPE_AVIF'),
            'wordpress_support' => wp_image_editor_supports(['mime_type' => 'image/avif']),
        ];

        return $status;
    }
}