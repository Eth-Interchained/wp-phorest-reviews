<?php
/**
 * Review cache + last-good resilience.
 *
 * Two layers:
 *   1. Transient (object-cache-backed when available) — short TTL, the hot path.
 *   2. wp_options "last good" snapshot — only written on a successful pull,
 *      served when the transient is cold AND Phorest is unreachable. This is
 *      what keeps the homepage reviews populated during a Phorest outage.
 *
 * The site NEVER blanks because of an upstream outage. If we have nothing
 * cached at all and Phorest is down, we render an empty-but-graceful state.
 *
 * @package PhorestReviews
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

class Phorest_Reviews_Cache
{
    /**
     * Register hooks (manual refresh endpoint, scheduled poll if enabled).
     */
    public function register_hooks(): void
    {
        // Optional: a WP cron job to warm the cache off the request path.
        add_action('phorest_reviews_refresh', [self::class, 'refresh_now']);
    }

    /**
     * Get reviews, hitting transient first, then last-good, then a live pull.
     *
     * @param bool $force_refresh Bypass the transient.
     * @return array {reviews: array, meta: array, source: 'transient'|'last_good'|'live'|'empty'}
     */
    public static function get_reviews(bool $force_refresh = false): array
    {
        if (!phorest_reviews_is_configured()) {
            return ['reviews' => [], 'meta' => [], 'source' => 'empty'];
        }

        // 1. Transient.
        if (!$force_refresh) {
            $cached = get_transient(PHOREST_REVIEWS_TRANSIENT);
            if (is_array($cached) && isset($cached['reviews'])) {
                $cached['source'] = 'transient';
                return $cached;
            }
        }

        // 2. Live pull.
        try {
            $data = self::pull_live();
            self::store($data);
            $data['source'] = 'live';
            return $data;
        } catch (RuntimeException $e) {
            if (function_exists('error_log')) {
                error_log('[phorest-reviews] live pull failed: ' . $e->getMessage());
            }
        }

        // 3. Last good.
        $last_good = get_option(PHOREST_REVIEWS_LASTGOOD_OPTION, null);
        if (is_array($last_good) && isset($last_good['reviews']) && [] !== $last_good['reviews']) {
            $last_good['source'] = 'last_good';
            return $last_good;
        }

        // 4. Nothing yet.
        return ['reviews' => [], 'meta' => [], 'source' => 'empty'];
    }

    /**
     * Pull every review from Phorest and assemble the cached shape.
     *
     * @return array {reviews: array, meta: array}
     * @throws RuntimeException On any Phorest error.
     */
    private static function pull_live(): array
    {
        $client = new Phorest_Reviews_Client(Phorest_Reviews_Client::from_settings());

        $reviews = [];
        $total   = 0;
        $pages   = 0;

        // First page to learn totalElements/totalPages.
        $first = $client->list_reviews(['page' => 0, 'size' => 100]);
        $batch = $first['_embedded']['reviews'] ?? [];
        if (is_array($batch)) {
            foreach ($batch as $r) {
                $reviews[] = self::normalize($r);
            }
        }
        $total = (int) ($first['page']['totalElements'] ?? count($reviews));
        $pages = (int) ($first['page']['totalPages'] ?? 0);

        // Remaining pages.
        for ($p = 1; $p < $pages; $p++) {
            $env = $client->list_reviews(['page' => $p, 'size' => 100]);
            $b   = $env['_embedded']['reviews'] ?? [];
            if (!is_array($b) || [] === $b) {
                break;
            }
            foreach ($b as $r) {
                $reviews[] = self::normalize($r);
            }
        }

        // Sort newest reviewDate first (Phorest order is not guaranteed across pages).
        usort($reviews, function (array $a, array $b): int {
            return strcmp($b['reviewDate'] ?? '', $a['reviewDate'] ?? '');
        });

        return [
            'reviews' => $reviews,
            'meta'    => [
                'total'      => $total,
                'pages'      => $pages,
                'pulled_at'  => current_time('mysql', true),
                'branch_id'  => phorest_reviews_get_setting('branch_id'),
            ],
        ];
    }

    /**
     * Normalize a raw Phorest review into a stable render shape.
     *
     * @param array $r Raw Phorest review.
     * @return array
     */
    private static function normalize(array $r): array
    {
        return [
            'reviewId'        => (string) ($r['reviewId'] ?? ''),
            'clientId'        => (string) ($r['clientId'] ?? ''),
            'clientFirstName' => (string) ($r['clientFirstName'] ?? ''),
            'clientLastName'  => (string) ($r['clientLastName'] ?? ''),
            'reviewDate'      => (string) ($r['reviewDate'] ?? ''),
            'visitDate'       => (string) ($r['visitDate'] ?? ''),
            'staffId'         => (string) ($r['staffId'] ?? ''),
            'staffFirstName'  => (string) ($r['staffFirstName'] ?? ''),
            'staffLastName'   => (string) ($r['staffLastName'] ?? ''),
            'text'            => (string) ($r['text'] ?? ''),
            'rating'          => (int)    ($r['rating'] ?? 0),
            'facebookReview'  => (bool)   ($r['facebookReview'] ?? false),
            'twitterReview'   => (bool)   ($r['twitterReview'] ?? false),
        ];
    }

    /**
     * Store a successfully-pulled dataset into both transient + last-good.
     *
     * @param array $data {reviews, meta}
     */
    private static function store(array $data): void
    {
        $ttl = (int) phorest_reviews_get_setting('cache_ttl', 1800); // 30 min default
        $ttl = max(60, $ttl); // never below 1 min

        set_transient(PHOREST_REVIEWS_TRANSIENT, $data, $ttl);
        update_option(PHOREST_REVIEWS_LASTGOOD_OPTION, $data, false);
    }

    /**
     * Force a refresh (used by the cron hook + a settings-page button).
     */
    public static function refresh_now(): void
    {
        try {
            $data = self::pull_live();
            self::store($data);
        } catch (RuntimeException $e) {
            if (function_exists('error_log')) {
                error_log('[phorest-reviews] refresh failed: ' . $e->getMessage());
            }
        }
    }
}
