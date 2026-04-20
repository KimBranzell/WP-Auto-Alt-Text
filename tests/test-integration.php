<?php

use PHPUnit\Framework\Assert;

class IntegrationTest extends WP_UnitTestCase {
  private $batch_processor;
  private $openai;
  private $rate_limiter;

  public function setUp(): void {
      parent::setUp();
      $this->openai = new Auto_Alt_Text_OpenAI();
      $this->rate_limiter = new Auto_Alt_Text_Rate_Limiter();
      $this->batch_processor = new Auto_Alt_Text_Batch_Processor($this->openai, 10);
  }

  public function test_full_workflow() {
      // Test batch processing with caching and rate limiting
      $attachment_ids = $this->create_test_attachments(15);

      // First run - should hit API
      $results1 = $this->batch_processor->process_batch($attachment_ids);
            Assert::assertSame(15, count($results1));

      // Second run - should hit cache
      $results2 = $this->batch_processor->process_batch($attachment_ids);
            Assert::assertSame($results1, $results2);

      // Verify rate limiting
            Assert::assertTrue($this->rate_limiter->can_make_request());
  }

  private function create_test_attachments($count) {
      $attachment_ids = [];

      for ($i = 0; $i < $count; $i++) {
          $attachment_ids[] = wp_insert_attachment(array(
              'post_title' => 'Test Image ' . $i,
              'post_type' => 'attachment',
              'post_mime_type' => 'image/jpeg'
          ));
      }

      return $attachment_ids;
  }
}

class LanguageManagerWpmlTest extends WP_UnitTestCase {
  private $translations = [];
  private $languages = [];

  public function setUp(): void {
      parent::setUp();

      if (!defined('ICL_SITEPRESS_VERSION')) {
          define('ICL_SITEPRESS_VERSION', '4.7.0');
      }

      $this->register_wpml_filters();
  }

  public function tearDown(): void {
      remove_all_filters('wpml_default_language');
      remove_all_filters('wpml_current_language');
      remove_all_filters('wpml_post_language_details');
      remove_all_filters('wpml_element_trid');
      remove_all_filters('wpml_get_element_translations');
      parent::tearDown();
  }

  public function test_wpml_alt_text_uses_translated_attachment_for_requested_language() {
      $english_attachment = wp_insert_attachment([
          'post_title' => 'English attachment',
          'post_type' => 'attachment',
          'post_mime_type' => 'image/jpeg',
      ]);

      $swedish_attachment = wp_insert_attachment([
          'post_title' => 'Swedish attachment',
          'post_type' => 'attachment',
          'post_mime_type' => 'image/jpeg',
      ]);

      $this->translations = [
          'en' => $english_attachment,
          'sv' => $swedish_attachment,
      ];

      $this->languages = [
          $english_attachment => 'en',
          $swedish_attachment => 'sv',
      ];

      update_post_meta($english_attachment, '_wp_attachment_image_alt', 'English alt text');
      update_post_meta($swedish_attachment, '_wp_attachment_image_alt', 'Svensk alt-text');

      $language_manager = new Auto_Alt_Text_Language_Manager();

            Assert::assertSame('sv', $language_manager->get_current_language());
            Assert::assertSame('Svensk alt-text', $language_manager->get_alt_text($english_attachment, 'sv'));
            Assert::assertSame('English alt text', $language_manager->get_alt_text($swedish_attachment, 'en'));
  }

  public function test_sync_alt_text_only_updates_current_wpml_attachment_language() {
      $english_attachment = wp_insert_attachment([
          'post_title' => 'English attachment',
          'post_type' => 'attachment',
          'post_mime_type' => 'image/jpeg',
      ]);

      $swedish_attachment = wp_insert_attachment([
          'post_title' => 'Swedish attachment',
          'post_type' => 'attachment',
          'post_mime_type' => 'image/jpeg',
      ]);

      $this->translations = [
          'en' => $english_attachment,
          'sv' => $swedish_attachment,
      ];

      $this->languages = [
          $english_attachment => 'en',
          $swedish_attachment => 'sv',
      ];

      update_post_meta($english_attachment, '_wp_attachment_image_alt', 'Existing English alt');

      $language_manager = new Auto_Alt_Text_Language_Manager();
      $language_manager->sync_alt_text($swedish_attachment, 'Ny svensk alt-text');

            Assert::assertSame('Existing English alt', get_post_meta($english_attachment, '_wp_attachment_image_alt', true));
            Assert::assertSame('Ny svensk alt-text', get_post_meta($swedish_attachment, '_wp_attachment_image_alt', true));
  }

  private function register_wpml_filters() {
      add_filter('wpml_default_language', function() {
          return 'en';
      });

      add_filter('wpml_current_language', function() {
          return 'sv';
      });

      add_filter('wpml_post_language_details', function($details, $post_id) {
          return [
              'language_code' => $this->languages[$post_id] ?? 'en',
          ];
      }, 10, 2);

      add_filter('wpml_element_trid', function($trid, $post_id) {
          return isset($this->languages[$post_id]) ? 999 : null;
      }, 10, 2);

      add_filter('wpml_get_element_translations', function($translations) {
          $result = [];

          foreach ($this->translations as $language => $attachment_id) {
              $result[$language] = (object) [
                  'element_id' => $attachment_id,
              ];
          }

          return $result;
      });
  }
}

