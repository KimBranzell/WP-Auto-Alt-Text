<?php

class Auto_Alt_Text_WooCommerce {
    private $openai;
    private $batch_processor;
    private $statistics;

    public function __construct() {
        $this->openai = new Auto_Alt_Text_OpenAI();
        $this->batch_processor = new Auto_Alt_Text_Batch_Processor();
        $this->statistics = new Auto_Alt_Text_Statistics();

        // Product creation and updates
        add_action('woocommerce_new_product', [$this, 'process_product_images']);
        add_action('woocommerce_update_product', [$this, 'process_product_images']);

        // Variation handling
        add_action('woocommerce_save_product_variation', [$this, 'process_variation_image'], 10, 2);

        // Bulk processing for existing products
        add_action('admin_post_process_wc_product_images', [$this, 'bulk_process_products']);

        // Add bulk action option
        add_filter('bulk_actions-edit-product', [$this, 'add_bulk_actions']);

        // Main product image
        add_filter('wp_get_attachment_image_attributes', [$this, 'enhance_image_attributes'], 10, 2);

        // Product gallery
        add_filter('woocommerce_product_get_image', [$this, 'enhance_product_image'], 10, 2);

        // Variable products
        add_filter('woocommerce_available_variation', [$this, 'enhance_variation_image'], 10, 3);

        // Main product image
        add_filter('woocommerce_single_product_image_html', [$this, 'enhance_main_product_image'], 10, 2);
    }

    public function enhance_image_attributes($attr, $attachment) {
      $alt_text = get_post_meta($attachment->ID, '_wp_attachment_image_alt', true);
      if ($alt_text) {
          $attr['alt'] = $alt_text;
      }
      return $attr;
    }

    public function enhance_main_product_image($html, $product_id) {
      $product = wc_get_product($product_id);
      $image_id = $product->get_image_id();
      $alt_text = get_post_meta($image_id, '_wp_attachment_image_alt', true);

      if ($alt_text) {
          $html = str_replace('alt=""', sprintf('alt="%s"', esc_attr($alt_text)), $html);
      }

      return $html;
    }

    public function enhance_product_image($image, $product) {
      $attachment_id = $product->get_image_id();
      $alt_text = get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
      if ($alt_text) {
          $image = str_replace('alt=""', sprintf('alt="%s"', esc_attr($alt_text)), $image);
      }
      return $image;
    }

    public function enhance_variation_image($variation_data, $product, $variation) {
      if (!empty($variation_data['image']['alt'])) {
        return $variation_data;
      }

      $image_id = $variation->get_image_id();
      $alt_text = get_post_meta($image_id, '_wp_attachment_image_alt', true);
      if ($alt_text) {
        $variation_data['image']['alt'] = $alt_text;
      }
      return $variation_data;
    }

    public function process_product_images($product_id) {
        $product = wc_get_product($product_id);

        // Collect all image IDs
        $image_ids = [];

        // Main product image
        $main_image_id = $product->get_image_id();
        if ($main_image_id) {
            $image_ids[] = $main_image_id;
        }

        // Gallery images
        $gallery_ids = $product->get_gallery_image_ids();
        if (!empty($gallery_ids)) {
            $image_ids = array_merge($image_ids, $gallery_ids);
        }

        // Process variation images for variable products
        if ($product->is_type('variable')) {
            $variations = $product->get_children();
            foreach ($variations as $variation_id) {
                $variation = wc_get_product($variation_id);
                $variation_image_id = $variation->get_image_id();
                if ($variation_image_id) {
                    $image_ids[] = $variation_image_id;
                }
            }
        }

        // Process unique image IDs
        $unique_ids = array_unique(array_filter($image_ids));
        foreach ($unique_ids as $attachment_id) {
            $image_url = wp_get_attachment_url($attachment_id);
            $this->openai->generate_alt_text($image_url, $attachment_id, 'woocommerce');
        }
    }

    public function process_variation_image($variation_id, $loop) {
        $variation = wc_get_product($variation_id);
        $image_id = $variation->get_image_id();

        if ($image_id) {
            $image_url = wp_get_attachment_url($image_id);
            $this->openai->generate_alt_text($image_url, $image_id, 'woocommerce_variation');
        }
    }

    public function add_bulk_actions($bulk_actions) {
        $bulk_actions['generate_alt_texts'] = __('Generate Alt Texts', 'auto-alt-text');
        return $bulk_actions;
    }

    public function bulk_process_products() {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $products = wc_get_products([
            'limit' => -1,
            'status' => 'publish'
        ]);

        foreach ($products as $product) {
            $this->process_product_images($product->get_id());
        }

        wp_redirect(admin_url('edit.php?post_type=product&alt_texts_generated=1'));
        exit;
    }
}
