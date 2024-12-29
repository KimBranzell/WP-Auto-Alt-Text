<?php

class Auto_Alt_Text_Rate_Limiter {
    private const RATE_LIMIT_KEY = 'auto_alt_text_api_calls';
    private const MAX_CALLS_PER_MINUTE = 50;
    private const WINDOW_SECONDS = 60;

    /**
     * Checks if the current request can be made based on the rate limit.
     *
     * This method retrieves the list of recent API calls, removes any expired timestamps,
     * and then checks if the number of calls within the rate limit window is less than
     * the maximum allowed calls per minute.
     *
     * @return bool True if the request can be made, false otherwise.
     */
    public function can_make_request(): bool {
        $calls = get_transient(self::RATE_LIMIT_KEY) ?: [];
        $now = time();

        // Remove expired timestamps
        $calls = array_filter($calls, function($timestamp) use ($now) {
            return $timestamp > ($now - self::WINDOW_SECONDS);
        });

        return count($calls) < self::MAX_CALLS_PER_MINUTE;
    }

    /**
     * Records a request in the rate limit tracking.
     *
     * This method retrieves the list of recent API calls, adds the current timestamp to the list,
     * and then stores the updated list of calls in the transient.
     */
    public function record_request(): void {
        $calls = get_transient(self::RATE_LIMIT_KEY) ?: [];
        $calls[] = time();
        set_transient(self::RATE_LIMIT_KEY, $calls, self::WINDOW_SECONDS);
    }
}