<?php

class Auto_Alt_Text_Rate_Limiter {
  private const RATE_LIMIT_KEY = 'auto_alt_text_api_calls';
  private const MAX_CALLS_PER_MINUTE = 50;
  private const WINDOW_SECONDS = 60;

  public function can_make_request(): bool {
      $calls = get_transient(self::RATE_LIMIT_KEY) ?: [];
      $now = time();

      // Remove expired timestamps
      $calls = array_filter($calls, function($timestamp) use ($now) {
          return $timestamp > ($now - self::WINDOW_SECONDS);
      });

      return count($calls) < self::MAX_CALLS_PER_MINUTE;
  }

  public function record_request(): void {
      $calls = get_transient(self::RATE_LIMIT_KEY) ?: [];
      $calls[] = time();
      set_transient(self::RATE_LIMIT_KEY, $calls, self::WINDOW_SECONDS);
  }
}