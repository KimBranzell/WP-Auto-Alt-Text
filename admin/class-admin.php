<?php
if ( ! defined( 'ABSPATH' ) ) exit;
class Auto_Alt_Text_Admin {
    private const SCRIPT_HANDLE = 'auto-alt-text-admin-js';
    private const SCRIPT_PATH = 'js/app.js';
    private const STYLE_HANDLE = 'auto_alt_text_css';

    /** Core screens that host the media modal or the Media Library. */
    private const MEDIA_SCREENS = ['upload.php', 'post.php', 'post-new.php'];

    /**
     * Whether the plugin's admin assets belong on this screen: the media
     * screens above plus the plugin's own pages.
     */
    public static function should_load_on($hook_suffix) {
        $hook_suffix = (string) $hook_suffix;
        return in_array($hook_suffix, self::MEDIA_SCREENS, true)
            || false !== strpos($hook_suffix, 'auto-alt-text');
    }

    /**
     * Constructor for the admin class.
    */
    public function __construct() {
        add_action('bulk_actions-upload', [$this, 'add_bulk_actions']);
        add_filter('handle_bulk_actions-upload', [$this, 'handle_bulk_actions'], 10, 3);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAutoAltTextScript']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueStyles']);
    }

    /**
     * Adds a 'Generate Alt Text' bulk action to the media library.
     *
     * @param array $bulk_actions The existing bulk actions.
     * @return array The updated bulk actions array.
     */
    public function add_bulk_actions($bulk_actions) {
        $bulk_actions['generate_alt_text'] = __('Generate Alt Text', 'WP-Auto-Alt-Text');
        return $bulk_actions;
    }

    /**
     * Handles the bulk action for generating alt text for selected media items.
     *
     * This method is called when the 'Generate Alt Text' bulk action is selected in the media library.
     * It checks if the selected action is 'generate_alt_text', enqueues the necessary script, and
     * returns a redirect URL with query parameters to indicate the number of items to be processed
     * and the IDs of the selected media items.
     *
     * @param string $redirect_to The original redirect URL.
     * @param string $doaction The selected bulk action.
     * @param array $post_ids The IDs of the selected media items.
     * @return string The updated redirect URL with query parameters.
     */
    public function handle_bulk_actions($redirect_to, $doaction, $post_ids) {
        if ($doaction !== 'generate_alt_text') {
            return $redirect_to;
        }

        return add_query_arg([
            'bulk_generate_alt_text' => count($post_ids),
            'bulk_ids' => implode(',', $post_ids),
            'processed' => 0,
            'failed' => 0
        ], $redirect_to);
    }

    /**
     * Enqueues the plugin's CSS styles for the admin area.
     *
     * This method is responsible for registering and enqueuing the plugin's CSS styles,
     * which are used to style the admin interface. It sets the handle and the URL of the
     * CSS file to be loaded.
     */
    public function enqueueStyles($hook_suffix = ''): void {
        if (!self::should_load_on($hook_suffix)) {
            return;
        }
        wp_enqueue_style(
            self::STYLE_HANDLE,
            plugin_dir_url(dirname(__FILE__)) . 'css/style.css',
            [],
            AUTO_ALT_TEXT_VERSION
        );
    }

    /**
     * Enqueues the Auto Alt Text script for the admin area.
     *
     * This method is responsible for registering and enqueuing the plugin's JavaScript
     * script, which is used for handling the batch processing of images for automatic
     * alt text generation. It sets up the necessary script dependencies, version, and
     * module type. It also localizes the script with AJAX-related data, such as the
     * AJAX URL and a nonce for security.
     */
    public function enqueueAutoAltTextScript($hook_suffix = ''): void {
        if (!self::should_load_on($hook_suffix)) {
            return;
        }
        $scriptUrl = plugin_dir_url(__FILE__) . self::SCRIPT_PATH;
        $dependencies = [];

        if ($this->is_block_editor_screen()) {
            $dependencies = [
                'wp-block-editor',
                'wp-components',
                'wp-element',
                'wp-hooks',
            ];
        }

        wp_enqueue_script(
            self::SCRIPT_HANDLE,
            $scriptUrl,
            $dependencies,
            AUTO_ALT_TEXT_VERSION,
            true
        );

        wp_script_add_data(self::SCRIPT_HANDLE, 'type', 'module');

        wp_localize_script(self::SCRIPT_HANDLE, 'autoAltTextData', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('auto_alt_text_batch_nonce'),
			'actionNonce' => wp_create_nonce('auto_alt_text_nonce'),
			'brandTonalityEnabled' => (bool) get_option('wp_auto_alt_text_enable_brand_tonality', false),
        ]);
    }

    /**
     * Checks whether the current admin screen is using the block editor.
     *
     * @return bool True when the current screen is the block editor.
     */
    private function is_block_editor_screen(): bool {
        if (!function_exists('get_current_screen')) {
            return false;
        }

        $screen = get_current_screen();

        return $screen && method_exists($screen, 'is_block_editor') && $screen->is_block_editor();
    }
}
