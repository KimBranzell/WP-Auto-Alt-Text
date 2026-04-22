<?php

use PHPUnit\Framework\Assert;

class Auto_Alt_Text_Rate_Limiter_Test extends WP_UnitTestCase {
    private $rate_limiter;

    public function setUp(): void {
        parent::setUp();
        $this->rate_limiter = new Auto_Alt_Text_Rate_Limiter();
        $this->rate_limiter->reset();
    }

    public function tearDown(): void {
        $this->rate_limiter->reset();
        parent::tearDown();
    }

    public function test_request_headers_block_until_reset_window() {
        $this->rate_limiter->record_response_headers([
            'x-ratelimit-limit-requests' => '120',
            'x-ratelimit-remaining-requests' => '0',
            'x-ratelimit-reset-requests' => '2s',
        ]);

        Assert::assertFalse($this->rate_limiter->can_make_request());
        Assert::assertGreaterThan(1.5, $this->rate_limiter->get_recommended_delay());
    }

    public function test_token_remaining_is_checked_against_estimate() {
        $this->rate_limiter->record_response_headers([
            'x-ratelimit-limit-requests' => '120',
            'x-ratelimit-remaining-requests' => '100',
            'x-ratelimit-reset-requests' => '1s',
            'x-ratelimit-limit-tokens' => '1000',
            'x-ratelimit-remaining-tokens' => '80',
            'x-ratelimit-reset-tokens' => '3s',
        ]);

        Assert::assertTrue($this->rate_limiter->can_make_request(50));
        Assert::assertFalse($this->rate_limiter->can_make_request(120));
        Assert::assertGreaterThan(2.5, $this->rate_limiter->get_recommended_delay(120));
    }

    public function test_rate_limit_error_sets_cooldown() {
        $this->rate_limiter->record_rate_limit_error([
            'x-ratelimit-reset-requests' => '1s',
        ], 1);

        Assert::assertFalse($this->rate_limiter->can_make_request());
        Assert::assertGreaterThanOrEqual(1.0, $this->rate_limiter->get_recommended_delay());
    }

    public function test_reset_clears_persisted_state() {
        $this->rate_limiter->record_response_headers([
            'x-ratelimit-limit-requests' => '60',
            'x-ratelimit-remaining-requests' => '0',
            'x-ratelimit-reset-requests' => '5s',
        ]);

        $this->rate_limiter->reset();

        Assert::assertTrue($this->rate_limiter->can_make_request());
        Assert::assertSame(0.0, $this->rate_limiter->get_recommended_delay());
    }
}
