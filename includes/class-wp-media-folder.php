<?php

class Auto_Alt_Text_WP_Media_Folder {
    private $openai;
    private $batch_processor;

    public function __construct() {
        $this->openai = new Auto_Alt_Text_OpenAI();
        $this->batch_processor = new Auto_Alt_Text_Batch_Processor($this->openai, 10);

        // Add button to WP Media Folder toolbar
        add_action('admin_footer', [$this, 'add_batch_button_to_media_folder']);

        // Handle AJAX batch processing
        add_action('wp_ajax_wpmf_process_alt_text_batch', [$this, 'process_folder_images']);
    }

    /**
     * Adds a batch processing button to the WP Media Folder interface
     */
    public function add_batch_button_to_media_folder() {
        // Only add on media page
        $screen = get_current_screen();
        if (!$screen || $screen->base !== 'upload') {
            return;
        }

        // Check if WP Media Folder is active
        if (!class_exists('WpMediaFolder')) {
            return;
        }

        ?>
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                // Add button to WP Media Folder toolbar
                function addWPMFButton() {
                    if ($('.wpmf-toolbar-container').length && !$('.aat-wpmf-batch-btn').length) {
                        const batchButton = $('<button>', {
                            'class': 'aat-wpmf-batch-btn button button-primary',
                            'text': '<?php _e('Generate Alt Text for Folder', 'wp-auto-alt-text'); ?>',
                            'style': 'margin-left: 10px;'
                        });

                        $('.wpmf-toolbar-container').append(batchButton);

                        // Add click event
                        batchButton.on('click', function(e) {
                            e.preventDefault();

                            // Get current folder ID
                            const currentFolderId = $('.wpmf-breadcrumb-category.active').data('id') || 0;

                            if (confirm('<?php _e('Generate alt text for all images in this folder?', 'wp-auto-alt-text'); ?>')) {
                                processFolderImages(currentFolderId);
                            }
                        });
                    }
                }

                // Monitor for folder changes
                $(document).on('wpmfsetfolder', function() {
                    setTimeout(addWPMFButton, 500);
                });

                // Initial button add
                setTimeout(addWPMFButton, 1000);

                // Process images in folder
                function processFolderImages(folderId) {
                    const button = $('.aat-wpmf-batch-btn');
                    const originalText = button.text();
                    button.text('<?php _e('Processing...', 'wp-auto-alt-text'); ?>');
                    button.prop('disabled', true);

                    // Create progress bar if not exists
                    if (!$('.aat-wpmf-progress-container').length) {
                        $('<div class="aat-wpmf-progress-container" style="margin-top: 10px; width: 100%;"><div class="aat-wpmf-progress-bar" style="background-color: #2271b1; height: 20px; width: 0%; transition: width 0.3s;"></div><div class="aat-wpmf-progress-text" style="margin-top: 5px;">Processing: 0%</div></div>').insertAfter(button);
                    }

                    // Get attachment IDs in current folder
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'wpmf_process_alt_text_batch',
                            nonce: '<?php echo wp_create_nonce('auto_alt_text_wpmf_nonce'); ?>',
                            folder_id: folderId,
                            step: 'get_ids'
                        },
                        success: function(response) {
                            if (response.success && response.data.ids.length > 0) {
                                processAttachmentBatch(response.data.ids, 0, response.data.ids.length);
                            } else {
                                alert('<?php _e('No images found in this folder or failed to retrieve images.', 'wp-auto-alt-text'); ?>');
                                button.text(originalText);
                                button.prop('disabled', false);
                                $('.aat-wpmf-progress-container').remove();
                            }
                        },
                        error: function() {
                            alert('<?php _e('Failed to retrieve images from folder.', 'wp-auto-alt-text'); ?>');
                            button.text(originalText);
                            button.prop('disabled', false);
                            $('.aat-wpmf-progress-container').remove();
                        }
                    });

                    // Process attachments in batches
                    function processAttachmentBatch(ids, processed, total) {
                        const batchSize = 5;
                        const currentBatch = ids.slice(processed, processed + batchSize);

                        if (currentBatch.length === 0) {
                            // All done
                            $('.aat-wpmf-progress-bar').css('width', '100%');
                            $('.aat-wpmf-progress-text').text('Completed: ' + total + ' images processed');

                            setTimeout(function() {
                                button.text(originalText);
                                button.prop('disabled', false);
                                $('.aat-wpmf-progress-container').remove();
                                alert('<?php _e('Alt text generation complete!', 'wp-auto-alt-text'); ?>');
                            }, 1000);

                            return;
                        }

                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'wpmf_process_alt_text_batch',
                                nonce: '<?php echo wp_create_nonce('auto_alt_text_wpmf_nonce'); ?>',
                                ids: JSON.stringify(currentBatch),
                                step: 'process'
                            },
                            success: function(response) {
                                processed += currentBatch.length;
                                const percentage = Math.min(Math.round((processed / total) * 100), 99);

                                // Update progress
                                $('.aat-wpmf-progress-bar').css('width', percentage + '%');
                                $('.aat-wpmf-progress-text').text('Processing: ' + processed + ' of ' + total + ' images (' + percentage + '%)');

                                // Process next batch
                                setTimeout(function() {
                                    processAttachmentBatch(ids, processed, total);
                                }, 500);
                            },
                            error: function() {
                                alert('<?php _e('Error processing batch. Please try again.', 'wp-auto-alt-text'); ?>');
                                button.text(originalText);
                                button.prop('disabled', false);
                                $('.aat-wpmf-progress-container').remove();
                            }
                        });
                    }
                }
            });
        </script>
        <?php
    }

    /**
     * Processes images in a WP Media Folder
     * Handles both retrieving IDs from folder and processing batches
     */
    public function process_folder_images() {
        check_ajax_referer('auto_alt_text_wpmf_nonce', 'nonce');

        if (!current_user_can('upload_files')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
            return;
        }

        $step = isset($_POST['step']) ? sanitize_text_field($_POST['step']) : '';

        if ($step === 'get_ids') {
            // Get attachment IDs from folder
            $folder_id = isset($_POST['folder_id']) ? intval($_POST['folder_id']) : 0;

            // Query attachments in this folder
            $args = [
                'post_type' => 'attachment',
                'post_status' => 'inherit',
                'posts_per_page' => -1,
                'fields' => 'ids',
                'meta_query' => [
                    [
                        'key' => 'wpmf_folder',
                        'value' => $folder_id,
                        'compare' => '='
                    ]
                ]
            ];

            // Only get images
            $args['post_mime_type'] = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'];

            $attachments = get_posts($args);

            if (!empty($attachments)) {
                wp_send_json_success(['ids' => $attachments]);
            } else {
                wp_send_json_error(['message' => 'No images found in folder']);
            }

        } else if ($step === 'process') {
            // Process batch of attachments
            $ids = isset($_POST['ids']) ? json_decode(stripslashes($_POST['ids'])) : [];

            if (empty($ids) || !is_array($ids)) {
                wp_send_json_error(['message' => 'Invalid attachment IDs']);
                return;
            }

            $results = [];

            foreach ($ids as $id) {
                $image_url = wp_get_attachment_url($id);
                if ($image_url) {
                    $alt_text = $this->openai->generate_alt_text($image_url, $id, 'wpmf_folder');
                    if ($alt_text) {
                        $results[$id] = $alt_text;
                    }
                }
            }

            wp_send_json_success(['results' => $results]);
        } else {
            wp_send_json_error(['message' => 'Invalid step parameter']);
        }
    }
}
