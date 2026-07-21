<?php
/**
 * Artist visibility rules.
 *
 * Former artists are hidden by case-insensitive exact full-name matching.
 * Filtering is presentation-only: raw Phorest reviews stay intact in the
 * private cache/last-good snapshot so the history is never destroyed.
 *
 * @package PhorestReviews
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

class Phorest_Reviews_Visibility
{
    /** Normalize whitespace/case for exact name matching. */
    public static function normalize(string $name): string
    {
        $name = preg_replace('/\s+/', ' ', trim(wp_strip_all_tags($name)));
        return function_exists('mb_strtolower')
            ? mb_strtolower((string) $name, 'UTF-8')
            : strtolower((string) $name);
    }

    /** @return string[] Normalized artist names hidden in settings. */
    public static function hidden_names(): array
    {
        $names = phorest_reviews_get_setting('hidden_artist_names', []);
        if (!is_array($names)) {
            return [];
        }
        return array_values(array_unique(array_filter(array_map([self::class, 'normalize'], $names))));
    }

    /** Case-insensitive exact match against the configured full names. */
    public static function is_hidden(string $first, string $last): bool
    {
        $full = self::normalize(trim($first . ' ' . $last));
        return '' !== $full && in_array($full, self::hidden_names(), true);
    }
}
