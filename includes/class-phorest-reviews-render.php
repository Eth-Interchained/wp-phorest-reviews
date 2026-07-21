<?php
/**
 * Render helpers + shortcodes.
 *
 * Two shortcodes:
 *   [phorest_reviews_home count="4" min_rating="4"]  — homepage strip
 *   [phorest_reviews_page]                            — full /reviews listing
 *
 * Honesty rules (carry over from salon-platform):
 *   - schema.org Review is fine for genuine Phorest reviews.
 *   - AggregateRating is computed from the actual cached reviews — never a
 *     fabricated Google star count. Mint's facebookReview/twitterReview are
 *     all false (no Online Reputation social auto-boost), so we do NOT claim
 *     any Google/FB cross-posting.
 *   - Staff names come title-cased for display (raw preserved for attribution)
 *     since Phorest stores some as ALL CAPS ("ASHLEY MARTINEZ").
 *
 * @package PhorestReviews
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

class Phorest_Reviews_Render
{
    /**
     * Homepage strip shortcode.
     *
     * @param array $atts {count?, min_rating?}
     * @return string HTML.
     */
    public static function shortcode_homepage_strip(array $atts): string
    {
        $atts = shortcode_atts([
            'count'      => (int) phorest_reviews_get_setting('homepage_count', 4),
            'min_rating' => (int) phorest_reviews_get_setting('min_rating', 4),
        ], $atts, 'phorest_reviews_home');

        $count      = max(1, (int) $atts['count']);
        $min_rating = max(1, min(5, (int) $atts['min_rating']));

        $data = Phorest_Reviews_Cache::get_reviews();
        $pool = array_values(array_filter($data['reviews'], function (array $r) use ($min_rating): bool {
            return $r['rating'] >= $min_rating && '' !== trim($r['text']);
        }));

        // Take the newest $count.
        $shown = array_slice($pool, 0, $count);

        if ([] === $shown) {
            return self::empty_state($data['source']);
        }

        wp_enqueue_style('phorest-reviews');

        ob_start();
        echo '<section class="phorest-reviews-home" aria-label="Client reviews">';
        echo '<h2 class="phorest-reviews-home__title">What our clients say</h2>';
        if ($data['meta']['total'] ?? 0) {
            $agg = self::aggregate($data['reviews']);
            printf(
                '<p class="phorest-reviews-home__subtitle">%1$s verified client reviews — %2$s average</p>',
                esc_html(number_format_i18n((int) $data['meta']['total'])),
                esc_html(number_format_i18n((float) $agg['average'], 1))
            );
        }
        echo '<div class="phorest-reviews-home__grid">';
        foreach ($shown as $review) {
            self::render_card($review);
        }
        echo '</div>';
        echo '<p class="phorest-reviews-home__cta"><a href="' . esc_url(self::reviews_page_url()) . '">Read all reviews →</a></p>';
        echo '</section>';

        return (string) ob_get_clean();
    }

    /**
     * Full /reviews page shortcode.
     *
     * @param array $atts
     * @return string HTML.
     */
    public static function shortcode_reviews_page(array $atts = []): string
    {
        $data = Phorest_Reviews_Cache::get_reviews();
        $reviews = $data['reviews'];

        if ([] === $reviews) {
            return self::empty_state($data['source']);
        }

        wp_enqueue_style('phorest-reviews');
        wp_enqueue_script('phorest-reviews');

        $staff_filter = isset($_GET['pr_staff']) ? sanitize_text_field(wp_unslash($_GET['pr_staff'])) : '';
        $rating_filter = isset($_GET['pr_rating']) ? (int) $_GET['pr_rating'] : 0;

        $filtered = array_values(array_filter($reviews, function (array $r) use ($staff_filter, $rating_filter): bool {
            if ($staff_filter && $r['staffId'] !== $staff_filter) {
                return false;
            }
            if ($rating_filter && $r['rating'] !== $rating_filter) {
                return false;
            }
            return true;
        }));

        $staff_list = self::derive_staff($reviews);
        $agg        = self::aggregate($reviews);

        ob_start();
        echo '<div class="phorest-reviews-page" data-total="' . esc_attr((string) count($reviews)) . '">';

        // Header + honest aggregate.
        echo '<header class="phorest-reviews-page__header">';
        printf('<h1>Client Reviews</h1>');
        printf(
            '<p class="phorest-reviews-page__aggregate"><span class="phorest-stars" aria-hidden="true">%s</span> %s average from %s verified Phorest reviews</p>',
            self::stars_html((int) round($agg['average'])),
            esc_html(number_format_i18n((float) $agg['average'], 1)),
            esc_html(number_format_i18n((int) $agg['count']))
        );
        echo '</header>';

        // Filters.
        echo '<form class="phorest-reviews-page__filters" method="get">';
        echo '<select name="pr_staff">';
        echo '<option value="">All stylists</option>';
        foreach ($staff_list as $s) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr($s['staffId']),
                selected($staff_filter, $s['staffId'], false),
                esc_html($s['display'])
            );
        }
        echo '</select>';
        echo '<select name="pr_rating">';
        echo '<option value="">All ratings</option>';
        for ($r = 5; $r >= 1; $r--) {
            printf(
                '<option value="%d"%s>%d star</option>',
                $r,
                selected($rating_filter, $r, false),
                $r
            );
        }
        echo '</select>';
        echo '<button type="submit">Filter</button>';
        echo '</form>';

        // Listing.
        echo '<div class="phorest-reviews-page__list">';
        if ([] === $filtered) {
            echo '<p class="phorest-reviews-page__none">No reviews match those filters.</p>';
        } else {
            foreach ($filtered as $review) {
                self::render_card($review, true);
            }
        }
        echo '</div>';

        // Honest JSON-LD: schema.org Review per visible review (capped to avoid
        // bloating the page — first 20 only). AggregateRating is computed from
        // the FULL cached set, not the filtered subset.
        if ((bool) phorest_reviews_get_setting('enable_jsonld', true)) {
            self::render_jsonld($reviews, $agg);
        }

        echo '</div>';
        return (string) ob_get_clean();
    }

    // ---------------------------------------------------------------

    /**
     * Render a single review card.
     *
     * @param array $review  Normalized review.
     * @param bool  $detailed Show full text + visit date (reviews page).
     */
    private static function render_card(array $review, bool $detailed = false): void
    {
        $stars    = self::stars_html((int) $review['rating']);
        $name     = self::display_name($review['clientFirstName'], $review['clientLastName']);
        $staff    = self::display_staff($review['staffFirstName'], $review['staffLastName']);
        $text     = $review['text'];
        $text_esc = esc_html($text);
        // Preserve paragraphs (\r\n\r\n) in detailed view.
        if ($detailed) {
            $text_esc = str_replace(["\r\n\r\n", "\n\n"], '</p><p>', $text_esc);
            $text_esc = '<p>' . $text_esc . '</p>';
        } else {
            // Strip homepage — show a snippet.
            $snippet = function_exists('mb_substr')
                ? mb_substr($text, 0, 180)
                : substr($text, 0, 180);
            if (mb_strlen($text) > 180) {
                $snippet .= '…';
            }
            $text_esc = esc_html($snippet);
        }

        $date = self::format_date($review['reviewDate']);

        echo '<article class="phorest-review" data-rating="' . esc_attr((string) $review['rating']) . '">';
        echo '<div class="phorest-review__stars" aria-label="' . esc_attr((string) $review['rating']) . ' star">' . $stars . '</div>';
        echo '<blockquote class="phorest-review__text">' . $text_esc . '</blockquote>';
        echo '<footer class="phorest-review__meta">';
        echo '<span class="phorest-review__author">' . esc_html($name) . '</span>';
        if ($staff) {
            echo '<span class="phorest-review__staff">with ' . esc_html($staff) . '</span>';
        }
        if ($date) {
            echo '<span class="phorest-review__date">' . esc_html($date) . '</span>';
        }
        echo '</footer>';
        echo '</article>';
    }

    /**
     * Honest schema.org JSON-LD.
     *
     * AggregateRating is computed from the actual cached reviews. Each
     * visible Review entry cites the real author (first name + last initial)
     * and reviewBody. No fabricated Google star count, no platform cross-claim.
     *
     * @param array $reviews Full cached set.
     * @param array $agg     Aggregate {average, count}.
     */
    private static function render_jsonld(array $reviews, array $agg): void
    {
        $items = [];
        $capped = array_slice($reviews, 0, 20);
        foreach ($capped as $r) {
            if (empty($r['text']) || $r['rating'] < 1) {
                continue;
            }
            $items[] = [
                '@type'         => 'Review',
                'author'        => [
                    '@type' => 'Person',
                    'name'  => self::display_name($r['clientFirstName'], $r['clientLastName']),
                ],
                'reviewRating'  => [
                    '@type'       => 'Rating',
                    'ratingValue' => (string) $r['rating'],
                    'bestRating'  => '5',
                    'worstRating' => '1',
                ],
                'reviewBody'    => $r['text'],
                'datePublished' => $r['reviewDate'],
            ];
        }
        $payload = [
            '@context'         => 'https://schema.org',
            '@type'            => 'Product',
            'name'             => get_bloginfo('name') . ' salon services',
            'aggregateRating'  => [
                '@type'       => 'AggregateRating',
                'ratingValue' => number_format((float) $agg['average'], 1, '.', ''),
                'reviewCount' => (string) $agg['count'],
                'bestRating'  => '5',
                'worstRating' => '1',
            ],
            'review'           => $items,
        ];
        echo '<script type="application/ld+json" class="phorest-reviews-jsonld">'
           . wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
           . '</script>';
    }

    // ---------------------------------------------------------------

    /**
     * Compute average + count from a review set.
     *
     * @param array $reviews
     * @return array {average: float, count: int}
     */
    public static function aggregate(array $reviews): array
    {
        $count = 0;
        $sum   = 0;
        foreach ($reviews as $r) {
            $rating = (int) ($r['rating'] ?? 0);
            if ($rating > 0) {
                $sum += $rating;
                $count++;
            }
        }
        return [
            'average' => $count > 0 ? ($sum / $count) : 0.0,
            'count'   => $count,
        ];
    }

    /**
     * Derive a de-duplicated staff list from reviews (no separate API call).
     *
     * @param array $reviews
     * @return array [{staffId, display}]
     */
    private static function derive_staff(array $reviews): array
    {
        $seen = [];
        $out  = [];
        foreach ($reviews as $r) {
            $id = $r['staffId'] ?? '';
            if ('' === $id || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $out[] = [
                'staffId' => $id,
                'display' => self::display_staff($r['staffFirstName'], $r['staffLastName']) ?: 'Stylist',
            ];
        }
        return $out;
    }

    /**
     * Title-case a name if Phorest stored it ALL CAPS. Preserve mixed-case as-is.
     *
     * @param string $first
     * @param string $last
     * @return string
     */
    private static function display_staff(string $first, string $last): string
    {
        $f = self::smart_case($first);
        $l = self::smart_case($last);
        $name = trim($f . ' ' . $l);
        return '' !== $name ? $name : '';
    }

    /**
     * "JOHN" → "John", "John" → "John", "McDonald" → "McDonald" (preserved).
     *
     * @param string $s
     * @return string
     */
    private static function smart_case(string $s): string
    {
        $s = trim($s);
        if ('' === $s) {
            return '';
        }
        // Only title-case if the original is all uppercase (no lowercase letters).
        if (strtoupper($s) === $s && preg_match('/[A-Z]/', $s)) {
            // Unicode-safe title case, then restore likely apostrophes.
            $t = mb_convert_case($s, MB_CASE_TITLE, 'UTF-8');
            return $t;
        }
        return $s;
    }

    /**
     * Author display: first name + last initial (privacy-friendly).
     * "Lizzette Rivera" → "Lizzette R."
     *
     * @param string $first
     * @param string $last
     * @return string
     */
    private static function display_name(string $first, string $last): string
    {
        $first = self::smart_case($first);
        $last  = self::smart_case($last);
        if ('' === $first && '' === $last) {
            return 'Verified client';
        }
        if ('' === $last) {
            return $first;
        }
        return $first . ' ' . mb_substr($last, 0, 1) . '.';
    }

    /**
     * Format a yyyy-MM-dd date for display.
     *
     * @param string $ymd
     * @return string
     */
    private static function format_date(string $ymd): string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) {
            return '';
        }
        $dt = DateTime::createFromFormat('Y-m-d', $ymd);
        if (!$dt) {
            return '';
        }
        return wp_date(get_option('date_format'), $dt->getTimestamp());
    }

    /**
     * Star glyphs for a 1-5 rating.
     *
     * @param int $rating
     * @return string
     */
    private static function stars_html(int $rating): string
    {
        $rating = max(0, min(5, $rating));
        $out = '';
        for ($i = 1; $i <= 5; $i++) {
            $out .= $i <= $rating ? '★' : '☆';
        }
        return $out;
    }

    /**
     * Graceful empty state.
     *
     * @param string $source Cache source.
     * @return string
     */
    private static function empty_state(string $source): string
    {
        // Logged-in admins see a hint; visitors see nothing (no broken UI).
        if (!current_user_can('manage_options')) {
            return '';
        }
        $hint = 'live' === $source ? 'No reviews returned.' : 'Reviews unavailable — check Phorest credentials in Settings → Phorest Reviews.';
        return '<div class="phorest-reviews-empty"><p>' . esc_html($hint) . '</p></div>';
    }

    /**
     * Best-guess URL for the /reviews page (first page that contains the
     * [phorest_reviews_page] shortcode; falls back to home).
     *
     * @return string
     */
    private static function reviews_page_url(): string
    {
        static $url = null;
        if (null !== $url) {
            return $url;
        }
        $pages = get_pages();
        if ($pages) {
            foreach ($pages as $page) {
                if (false !== stripos($page->post_content ?? '', 'phorest_reviews_page')) {
                    $url = get_permalink($page->ID);
                    return $url ?: home_url('/');
                }
            }
        }
        $url = home_url('/');
        return $url;
    }
}
