<?php

class Auto_Alt_Text_Rate_Limiter {
    private const STATE_KEY = 'auto_alt_text_rate_limit_state';
    private const DEFAULT_REQUESTS_PER_MINUTE = 60;
    private const DEFAULT_WINDOW_SECONDS = 60;
    private const DEFAULT_COOLDOWN_SECONDS = 2.0;
    private const STATE_TTL = DAY_IN_SECONDS;

    /**
     * Checks whether the next request can be made immediately.
     *
     * @return bool True if the request can be made, false otherwise.
     */
    public function can_make_request($estimated_tokens = 0): bool {
        return $this->get_recommended_delay($estimated_tokens) <= 0;
    }

    /**
     * Returns the recommended delay, in seconds, before the next request.
     *
     * @param int $estimated_tokens Conservative token estimate for the pending request.
     * @return float
     */
    public function get_recommended_delay($estimated_tokens = 0): float {
        $state = $this->get_state();
        $now = microtime(true);
        $delays = [];

        if ($state['cooldown_until'] > $now) {
            $delays[] = $state['cooldown_until'] - $now;
        }

        if (null !== $state['request_remaining'] && $state['request_remaining'] <= 0 && $state['request_reset_at'] > $now) {
            $delays[] = $state['request_reset_at'] - $now;
        }

        if ($estimated_tokens > 0 && null !== $state['token_remaining'] && $state['token_remaining'] < $estimated_tokens && $state['token_reset_at'] > $now) {
            $delays[] = $state['token_reset_at'] - $now;
        }

        $fallback_count = count($state['fallback_timestamps']);
        if ($fallback_count >= self::DEFAULT_REQUESTS_PER_MINUTE) {
            $oldest_timestamp = (float) $state['fallback_timestamps'][0];
            $fallback_delay = ($oldest_timestamp + self::DEFAULT_WINDOW_SECONDS) - $now;

            if ($fallback_delay > 0) {
                $delays[] = $fallback_delay;
            }
        }

        if (empty($delays)) {
            return 0.0;
        }

        return max(0.0, max($delays));
    }

    /**
     * Sleeps until the limiter indicates capacity is available.
     *
     * @param int $estimated_tokens Conservative token estimate for the pending request.
     * @return void
     */
    public function wait_for_capacity($estimated_tokens = 0): void {
        $delay = $this->get_recommended_delay($estimated_tokens);

        if ($delay <= 0) {
            return;
        }

        usleep((int) ceil($delay * 1000000));
    }

    /**
     * Records that a request is about to be sent.
     *
     * @param int $estimated_tokens Conservative token estimate for the pending request.
     * @return void
     */
    public function record_request($estimated_tokens = 0): void {
        $state = $this->get_state();
        $state['fallback_timestamps'][] = microtime(true);

        if (null !== $state['request_remaining']) {
            $state['request_remaining'] = max(0, (int) $state['request_remaining'] - 1);
        }

        if ($estimated_tokens > 0 && null !== $state['token_remaining']) {
            $state['token_remaining'] = max(0, (int) $state['token_remaining'] - (int) $estimated_tokens);
        }

        $this->save_state($state);
    }

    /**
     * Records rate-limit metadata from response headers.
     *
     * @param array $headers Normalized response headers.
     * @return void
     */
    public function record_response_headers($headers): void {
        if (!is_array($headers) || empty($headers)) {
            return;
        }

        $state = $this->get_state();
        $now = microtime(true);

        $request_limit = $this->normalize_positive_integer($headers['x-ratelimit-limit-requests'] ?? null);
        $request_remaining = $this->normalize_non_negative_integer($headers['x-ratelimit-remaining-requests'] ?? null);
        $request_reset_seconds = $this->parse_reset_interval_seconds($headers['x-ratelimit-reset-requests'] ?? null);

        $token_limit = $this->normalize_positive_integer($headers['x-ratelimit-limit-tokens'] ?? null);
        $token_remaining = $this->normalize_non_negative_integer($headers['x-ratelimit-remaining-tokens'] ?? null);
        $token_reset_seconds = $this->parse_reset_interval_seconds($headers['x-ratelimit-reset-tokens'] ?? null);

        if (null !== $request_limit) {
            $state['request_limit'] = $request_limit;
        }

        if (null !== $request_remaining) {
            $state['request_remaining'] = $request_remaining;
        }

        if ($request_reset_seconds > 0) {
            $state['request_reset_at'] = $now + $request_reset_seconds;
        }

        if (null !== $token_limit) {
            $state['token_limit'] = $token_limit;
        }

        if (null !== $token_remaining) {
            $state['token_remaining'] = $token_remaining;
        }

        if ($token_reset_seconds > 0) {
            $state['token_reset_at'] = $now + $token_reset_seconds;
        }

        if ((null !== $request_remaining && $request_remaining > 0) || (null !== $token_remaining && $token_remaining > 0)) {
            $state['cooldown_until'] = 0.0;
        }

        $this->save_state($state);
    }

    /**
     * Records that the API responded with a rate-limit error.
     *
     * @param array $headers Normalized response headers.
     * @param int   $attempt Current retry attempt.
     * @return void
     */
    public function record_rate_limit_error($headers = [], $attempt = 1): void {
        $state = $this->get_state();
        $now = microtime(true);
        $request_reset_seconds = $this->parse_reset_interval_seconds($headers['x-ratelimit-reset-requests'] ?? null);
        $token_reset_seconds = $this->parse_reset_interval_seconds($headers['x-ratelimit-reset-tokens'] ?? null);
        $cooldown_seconds = max(
            self::DEFAULT_COOLDOWN_SECONDS,
            min(15.0, (float) pow(2, max(0, (int) $attempt - 1))),
            $request_reset_seconds,
            $token_reset_seconds
        );

        $state['request_remaining'] = 0;
        $state['cooldown_until'] = $now + $cooldown_seconds;

        if ($request_reset_seconds > 0) {
            $state['request_reset_at'] = $now + $request_reset_seconds;
        }

        if ($token_reset_seconds > 0) {
            $state['token_reset_at'] = $now + $token_reset_seconds;
        }

        $this->save_state($state);
    }

    /**
     * Clears all persisted limiter state.
     *
     * @return void
     */
    public function reset(): void {
        delete_transient(self::STATE_KEY);
    }

    /**
     * Retrieves the normalized limiter state.
     *
     * @return array
     */
    private function get_state(): array {
        $state = get_transient(self::STATE_KEY);

        if (!is_array($state)) {
            $state = [];
        }

        $state = wp_parse_args($state, [
            'request_limit' => self::DEFAULT_REQUESTS_PER_MINUTE,
            'request_remaining' => null,
            'request_reset_at' => 0.0,
            'token_limit' => null,
            'token_remaining' => null,
            'token_reset_at' => 0.0,
            'cooldown_until' => 0.0,
            'fallback_timestamps' => [],
        ]);

        $now = microtime(true);
        $state['fallback_timestamps'] = $this->filter_recent_timestamps($state['fallback_timestamps'], $now);

        if ($state['request_reset_at'] > 0 && $state['request_reset_at'] <= $now && !empty($state['request_limit'])) {
            $state['request_remaining'] = (int) $state['request_limit'];
            $state['request_reset_at'] = 0.0;
        }

        if ($state['token_reset_at'] > 0 && $state['token_reset_at'] <= $now && !empty($state['token_limit'])) {
            $state['token_remaining'] = (int) $state['token_limit'];
            $state['token_reset_at'] = 0.0;
        }

        if ($state['cooldown_until'] <= $now) {
            $state['cooldown_until'] = 0.0;
        }

        return $state;
    }

    /**
     * Persists the limiter state.
     *
     * @param array $state Limiter state.
     * @return void
     */
    private function save_state($state): void {
        set_transient(self::STATE_KEY, $state, self::STATE_TTL);
    }

    /**
     * Removes expired fallback timestamps from the request window.
     *
     * @param array $timestamps Tracked timestamps.
     * @param float $now Current timestamp.
     * @return array
     */
    private function filter_recent_timestamps($timestamps, $now): array {
        if (!is_array($timestamps)) {
            return [];
        }

        $window_start = $now - self::DEFAULT_WINDOW_SECONDS;

        return array_values(array_filter($timestamps, static function($timestamp) use ($window_start) {
            return is_numeric($timestamp) && (float) $timestamp > $window_start;
        }));
    }

    /**
     * Parses a reset interval header value into seconds.
     *
     * @param mixed $value Header value.
     * @return float
     */
    private function parse_reset_interval_seconds($value): float {
        if (is_numeric($value)) {
            return max(0.0, (float) $value);
        }

        if (!is_string($value) || '' === trim($value)) {
            return 0.0;
        }

        $normalized_value = strtolower(trim($value));

        if (is_numeric($normalized_value)) {
            return max(0.0, (float) $normalized_value);
        }

        preg_match_all('/(\d+(?:\.\d+)?)(ms|s|m|h|d)/', $normalized_value, $matches, PREG_SET_ORDER);

        if (empty($matches)) {
            return 0.0;
        }

        $seconds = 0.0;

        foreach ($matches as $match) {
            $value = (float) $match[1];
            $unit = $match[2];

            switch ($unit) {
                case 'd':
                    $seconds += $value * DAY_IN_SECONDS;
                    break;
                case 'h':
                    $seconds += $value * HOUR_IN_SECONDS;
                    break;
                case 'm':
                    $seconds += $value * MINUTE_IN_SECONDS;
                    break;
                case 's':
                    $seconds += $value;
                    break;
                case 'ms':
                    $seconds += $value / 1000;
                    break;
            }
        }

        return max(0.0, $seconds);
    }

    /**
     * Normalizes a positive integer header value.
     *
     * @param mixed $value Header value.
     * @return int|null
     */
    private function normalize_positive_integer($value): ?int {
        if (is_numeric($value)) {
            $value = (int) $value;
            return $value > 0 ? $value : null;
        }

        return null;
    }

    /**
     * Normalizes a non-negative integer header value.
     *
     * @param mixed $value Header value.
     * @return int|null
     */
    private function normalize_non_negative_integer($value): ?int {
        if (is_numeric($value)) {
            $value = (int) $value;
            return $value >= 0 ? $value : null;
        }

        return null;
    }
}
