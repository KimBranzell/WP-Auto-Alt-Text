<?php

class Auto_Alt_Text_Rate_Limiter {
    private const RATE_LIMIT_KEY = 'auto_alt_text_api_calls';
    private const RATE_LIMIT_DAY_KEY = 'auto_alt_text_api_calls_day';
    private const WINDOW_SECONDS = 60;
    private const DEFAULT_MAX_PER_MINUTE = 50;
    private const DEFAULT_MAX_PER_DAY = 0;

    /**
     * Maximum API calls allowed per minute (from options).
     */
    private function get_max_per_minute(): int {
        $v = get_option('aat_rate_limit_per_minute', self::DEFAULT_MAX_PER_MINUTE);
        return max(1, (int) $v);
    }

    /**
     * Maximum API calls per day; 0 means no daily cap.
     */
    private function get_max_per_day(): int {
        $v = get_option('aat_rate_limit_per_day', self::DEFAULT_MAX_PER_DAY);
        return max(0, (int) $v);
    }

    /**
     * Checks if the current request can be made based on the rate limit.
     *
     * @return bool True if the request can be made, false otherwise.
     */
    public function can_make_request(): bool {
        $calls = get_transient(self::RATE_LIMIT_KEY) ?: [];
        $now = time();
        $window = self::WINDOW_SECONDS;
        $calls = array_filter($calls, function($timestamp) use ($now, $window) {
            return $timestamp > ($now - $window);
        });
        $max_per_minute = $this->get_max_per_minute();
        if (count($calls) >= $max_per_minute) {
            return false;
        }
        $max_per_day = $this->get_max_per_day();
        if ($max_per_day > 0) {
            $day_data = get_transient(self::RATE_LIMIT_DAY_KEY);
            $day_start = strtotime('today midnight');
            if ($day_data === false || ($day_data['day_start'] ?? 0) !== $day_start) {
                $day_data = ['day_start' => $day_start, 'count' => 0];
            }
            if ($day_data['count'] >= $max_per_day) {
                return false;
            }
        }
        return true;
    }

    /**
     * Records a request in the rate limit tracking.
     */
    public function record_request(): void {
        $calls = get_transient(self::RATE_LIMIT_KEY) ?: [];
        $calls[] = time();
        set_transient(self::RATE_LIMIT_KEY, $calls, self::WINDOW_SECONDS);

        $max_per_day = $this->get_max_per_day();
        if ($max_per_day > 0) {
            $day_data = get_transient(self::RATE_LIMIT_DAY_KEY);
            $day_start = strtotime('today midnight');
            if ($day_data === false || ($day_data['day_start'] ?? 0) !== $day_start) {
                $day_data = ['day_start' => $day_start, 'count' => 0];
            }
            $day_data['count'] = ($day_data['count'] ?? 0) + 1;
            set_transient(self::RATE_LIMIT_DAY_KEY, $day_data, DAY_IN_SECONDS);
        }
    }
}