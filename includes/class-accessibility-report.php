<?php
/**
 * Accessibility report: lists images missing alt text with thumbnails and links to edit/generate.
 */
class Auto_Alt_Text_Accessibility_Report {
    /**
     * Registers the submenu and page.
     */
    public function __construct() {
        add_action('admin_menu', [$this, 'register_page'], 20);
    }

    public function register_page() {
        add_submenu_page(
            'auto-alt-text',
            __('Accessibility report', 'wp-auto-alt-text'),
            __('Accessibility report', 'wp-auto-alt-text'),
            'manage_options',
            'auto-alt-text-accessibility',
            [$this, 'render_page']
        );
    }

    /**
     * Returns attachment IDs for image attachments that have no alt text.
     *
     * @param int $limit  Max number to return.
     * @param int $offset Offset for pagination.
     * @return array [ 'ids' => int[], 'total' => int ]
     */
    private function get_images_missing_alt($limit = 20, $offset = 0) {
        global $wpdb;
        $post_type = 'attachment';
        $mime_like = $wpdb->esc_like('image/') . '%';
        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_wp_attachment_image_alt'
            WHERE p.post_type = %s AND p.post_mime_type LIKE %s
            AND (pm.meta_value IS NULL OR TRIM(pm.meta_value) = '')",
            $post_type,
            $mime_like
        ));
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT p.ID FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_wp_attachment_image_alt'
            WHERE p.post_type = %s AND p.post_mime_type LIKE %s
            AND (pm.meta_value IS NULL OR TRIM(pm.meta_value) = '')
            ORDER BY p.ID DESC LIMIT %d OFFSET %d",
            $post_type,
            $mime_like,
            $limit,
            $offset
        ));
        return ['ids' => array_map('intval', $ids), 'total' => $total];
    }

    public function render_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'wp-auto-alt-text'));
        }

        $per_page = 20;
        $current_page = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
        $offset = ($current_page - 1) * $per_page;

        $result = $this->get_images_missing_alt($per_page, $offset);
        $ids = $result['ids'];
        $total = $result['total'];
        $total_pages = $total > 0 ? (int) ceil($total / $per_page) : 1;

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Accessibility report', 'wp-auto-alt-text'); ?></h1>
            <p><?php echo esc_html(sprintf(__('Images in the Media Library that are missing alt text: %d total.', 'wp-auto-alt-text'), $total)); ?></p>

            <?php if (empty($ids)) : ?>
                <p><?php esc_html_e('No images are missing alt text.', 'wp-auto-alt-text'); ?></p>
            <?php else : ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th class="column-thumb"><?php esc_html_e('Thumbnail', 'wp-auto-alt-text'); ?></th>
                            <th><?php esc_html_e('Title', 'wp-auto-alt-text'); ?></th>
                            <th><?php esc_html_e('Date', 'wp-auto-alt-text'); ?></th>
                            <th><?php esc_html_e('Actions', 'wp-auto-alt-text'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($ids as $id) :
                            $post = get_post($id);
                            if (!$post) {
                                continue;
                            }
                            $edit_url = admin_url('post.php?post=' . $id . '&action=edit');
                            ?>
                            <tr>
                                <td class="column-thumb"><?php echo wp_get_attachment_image($id, [60, 60]); ?></td>
                                <td><?php echo esc_html($post->post_title ?: __('(no title)', 'wp-auto-alt-text')); ?></td>
                                <td><?php echo esc_html(get_the_date('', $post)); ?></td>
                                <td>
                                    <a href="<?php echo esc_url($edit_url); ?>" class="button button-small"><?php esc_html_e('Edit / Generate', 'wp-auto-alt-text'); ?></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if ($total_pages > 1) : ?>
                    <div class="tablenav">
                        <div class="tablenav-pages">
                            <?php
                            echo wp_kses_post(paginate_links([
                                'base'      => add_query_arg('paged', '%#%'),
                                'format'    => '',
                                'current'   => $current_page,
                                'total'     => $total_pages,
                                'prev_text' => __('&laquo; Previous', 'wp-auto-alt-text'),
                                'next_text' => __('Next &raquo;', 'wp-auto-alt-text'),
                                'type'      => 'plain',
                            ]));
                            ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }
}
