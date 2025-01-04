<?php
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
      self::assertSame(15, count($results1));

      // Second run - should hit cache
      $results2 = $this->batch_processor->process_batch($attachment_ids);
      self::assertSame($results1, $results2);

      // Verify rate limiting
      self::assertTrue($this->rate_limiter->can_make_request());
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

