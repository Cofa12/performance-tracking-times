<?php

namespace Cofa\PerformanceTracking;

/**
 * Parses the "request received" stamp a web server adds to the request.
 *
 * Accepted formats: "t=1728712345.123" (nginx $msec, seconds),
 * "t=1728712345123456" (Apache %t, microseconds) and bare milliseconds
 * ("1728712345123", Heroku style).
 */
final class RequestStartHeader
{
    /**
     * @return float|null Epoch seconds.
     */
    public static function parse(?string $value): ?float
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if (str_starts_with($value, 't=')) {
            $value = substr($value, 2);
        }

        if ($value === '' || ! is_numeric($value)) {
            return null;
        }

        $number = (float) $value;

        if ($number <= 0) {
            return null;
        }

        return match (true) {
            $number > 1e15 => $number / 1e6, // microseconds
            $number > 1e12 => $number / 1e3, // milliseconds
            default => $number,              // seconds
        };
    }
}
