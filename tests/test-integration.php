<?php

use PHPUnit\Framework\Assert;

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

  public function test_translated_alt_text_is_recorded_as_ai() {
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
      $this->translations = ['en' => $english_attachment, 'sv' => $swedish_attachment];
      $this->languages = [$english_attachment => 'en', $swedish_attachment => 'sv'];
      update_post_meta($english_attachment, '_wp_attachment_image_alt', 'English alt text');
      (new Auto_Alt_Text_OpenAI())->save_api_key('sk-test-not-real');
      add_filter('pre_http_request', function ($pre, $args, $url) {
          if (strpos($url, 'openai.com') === false) {
              return $pre;
          }
          return [
              'headers' => [],
              'body' => wp_json_encode(['output_text' => 'Svensk alt-text']),
              'response' => ['code' => 200, 'message' => 'OK'],
              'cookies' => [],
          ];
      }, 10, 3);

      (new Auto_Alt_Text_Language_Manager())->generate_multilingual_alt_text($english_attachment, 'English alt text');

      Assert::assertSame('Svensk alt-text', get_post_meta($swedish_attachment, '_wp_attachment_image_alt', true));
      Assert::assertSame('ai', Auto_Alt_Text_Provenance::classify($swedish_attachment));
  }
}

