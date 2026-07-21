<?php
/**
 * Render helpers + shortcodes.
 *
 * Two shortcodes:
 *   [phorest_reviews_home count="3" min_rating="4"]  — landing-page widget
 *   [phorest_reviews_page]                            — full /reviews listing
 *
 * Honesty rules (carry over from salon-platform):
 *   - schema.org Review is fine for genuine Phorest reviews.
 *   - The visible aggregate is computed from actual cached reviews. JSON-LD
 *     intentionally omits AggregateRating because self-serving LocalBusiness
 *     review rich results are not eligible. No fabricated Google star count.
 *   - Mint's facebookReview/twitterReview flags do not justify claiming any
 *     Google/Facebook cross-posting.
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
            'count'      => (int) phorest_reviews_get_setting('homepage_count', 3),
            'min_rating' => (int) phorest_reviews_get_setting('min_rating', 4),
        ], $atts, 'phorest_reviews_home');

        $count      = max(1, min(6, (int) $atts['count']));
        $min_rating = max(1, min(5, (int) $atts['min_rating']));

        $data = Phorest_Reviews_Cache::get_reviews();
        $pool = array_values(array_filter($data['reviews'], function (array $r) use ($min_rating): bool {
            return $r['rating'] >= $min_rating && '' !== trim($r['text']);
        }));
        $shown = array_slice($pool, 0, $count);

        if ([] === $shown) {
            return self::empty_state($data['source']);
        }

        $agg = self::aggregate($data['reviews']);
        wp_enqueue_style('phorest-reviews');

        ob_start();
        echo '<section class="phorest-reviews-home phorest-reviews-home--atelier" aria-label="Client reviews">';
        echo '<div class="phorest-reviews-home__inner">';
        echo '<header class="phorest-reviews-home__header">';
        echo '<div>';
        echo '<p class="phorest-reviews-eyebrow">Verified guest reviews</p>';
        echo '<h2 class="phorest-reviews-home__title">What they’re <em>saying.</em></h2>';
        echo '</div>';
        printf(
            '<div class="phorest-reviews-home__score" aria-label="%1$s out of 5 from %2$s reviews"><strong>%1$s</strong><span>%2$s client reviews via Phorest</span></div>',
            esc_html(number_format_i18n((float) $agg['average'], 2)),
            esc_html(number_format_i18n((int) $agg['count']))
        );
        echo '</header>';
        echo '<div class="phorest-reviews-home__grid">';
        foreach ($shown as $review) {
            self::render_card($review);
        }
        echo '</div>';
        echo '<footer class="phorest-reviews-home__footer">';
        echo '<p>Real feedback, shared after real visits to Mint on the Avenue.</p>';
        echo '<a class="phorest-reviews-button" href="' . esc_url(self::reviews_page_url()) . '">Read all reviews <span aria-hidden="true">→</span></a>';
        echo '</footer>';
        echo '</div>';
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
        $atts = shortcode_atts([
            'per_page' => 18,
        ], $atts, 'phorest_reviews_page');

        $data    = Phorest_Reviews_Cache::get_reviews();
        $reviews = $data['reviews'];

        if ([] === $reviews) {
            return self::empty_state($data['source']);
        }

        wp_enqueue_style('phorest-reviews');

        $staff_filter  = isset($_GET['pr_staff']) ? sanitize_text_field(wp_unslash($_GET['pr_staff'])) : '';
        $rating_filter = isset($_GET['pr_rating']) ? max(0, min(5, (int) $_GET['pr_rating'])) : 0;
        $current_page  = isset($_GET['pr_page']) ? max(1, (int) $_GET['pr_page']) : 1;
        $per_page      = max(6, min(48, (int) $atts['per_page']));

        $filtered = array_values(array_filter($reviews, function (array $r) use ($staff_filter, $rating_filter): bool {
            if ($staff_filter && $r['staffId'] !== $staff_filter) {
                return false;
            }
            if ($rating_filter && $r['rating'] !== $rating_filter) {
                return false;
            }
            return '' !== trim($r['text']);
        }));

        $filtered_count = count($filtered);
        $total_pages    = max(1, (int) ceil($filtered_count / $per_page));
        $current_page   = min($current_page, $total_pages);
        $offset         = ($current_page - 1) * $per_page;
        $visible        = array_slice($filtered, $offset, $per_page);
        $first_result   = $filtered_count > 0 ? $offset + 1 : 0;
        $last_result    = min($offset + $per_page, $filtered_count);

        $staff_list = self::derive_staff($reviews);
        $agg        = self::aggregate($reviews);

        ob_start();
        echo '<section class="phorest-reviews-page phorest-reviews-page--atelier" data-total="' . esc_attr((string) count($reviews)) . '">';
        echo '<header class="phorest-reviews-page__hero">';
        echo '<div class="phorest-reviews-page__hero-inner">';
        echo '<p class="phorest-reviews-eyebrow">Certified guest reviews · Mint on the Avenue</p>';
        echo '<h1>Trust, earned <em>one visit at a time.</em></h1>';
        echo '<p class="phorest-reviews-page__lede">It’s never “just” hair. Read what our guests shared after sitting in the chair, meeting their artist, and experiencing Mint.</p>';
        printf(
            '<div class="phorest-reviews-page__summary"><strong>%1$s</strong><span class="phorest-review__stars" aria-hidden="true">%2$s</span><span>Average from %3$s verified Phorest reviews</span></div>',
            esc_html(number_format_i18n((float) $agg['average'], 2)),
            self::stars_html((int) round($agg['average'])),
            esc_html(number_format_i18n((int) $agg['count']))
        );
        echo '</div>';
        echo '</header>';

        echo '<div class="phorest-reviews-page__body">';
        echo '<form class="phorest-reviews-page__filters" method="get">';
        echo '<label><span>Artist</span><select name="pr_staff">';
        echo '<option value="">All artists</option>';
        foreach ($staff_list as $s) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr($s['staffId']),
                selected($staff_filter, $s['staffId'], false),
                esc_html($s['display'])
            );
        }
        echo '</select></label>';
        echo '<label><span>Rating</span><select name="pr_rating">';
        echo '<option value="">All ratings</option>';
        for ($r = 5; $r >= 1; $r--) {
            printf(
                '<option value="%d"%s>%d star%s</option>',
                $r,
                selected($rating_filter, $r, false),
                $r,
                1 === $r ? '' : 's'
            );
        }
        echo '</select></label>';
        echo '<button class="phorest-reviews-button" type="submit">Apply filters</button>';
        if ($staff_filter || $rating_filter) {
            echo '<a class="phorest-reviews-filter-reset" href="' . esc_url(remove_query_arg(['pr_staff', 'pr_rating', 'pr_page'])) . '">Clear</a>';
        }
        echo '</form>';

        printf(
            '<p class="phorest-reviews-page__count">Showing %1$s–%2$s of %3$s reviews</p>',
            esc_html(number_format_i18n($first_result)),
            esc_html(number_format_i18n($last_result)),
            esc_html(number_format_i18n($filtered_count))
        );

        echo '<div class="phorest-reviews-page__list">';
        if ([] === $visible) {
            echo '<p class="phorest-reviews-page__none">No reviews match those filters.</p>';
        } else {
            foreach ($visible as $review) {
                self::render_card($review, true);
            }
        }
        echo '</div>';

        if ($total_pages > 1) {
            $base = add_query_arg([
                'pr_page'   => '%#%',
                'pr_staff'  => $staff_filter ?: false,
                'pr_rating' => $rating_filter ?: false,
            ], remove_query_arg('pr_page'));
            $base = str_replace('%25%23%25', '%#%', $base);
            $links = paginate_links([
                'base'      => $base,
                'format'    => '',
                'current'   => $current_page,
                'total'     => $total_pages,
                'mid_size'  => 2,
                'prev_text' => '← Previous',
                'next_text' => 'Next →',
                'type'      => 'list',
            ]);
            if ($links) {
                echo '<nav class="phorest-reviews-pagination" aria-label="Reviews pages">' . wp_kses_post($links) . '</nav>';
            }
        }

        if ((bool) phorest_reviews_get_setting('enable_jsonld', false)) {
            self::render_jsonld($visible);
        }

        echo '</div>';
        echo '</section>';
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
        $stars      = self::stars_html((int) $review['rating']);
        $name       = self::display_name($review['clientFirstName'], $review['clientLastName']);
        $staff      = self::display_staff($review['staffFirstName'], $review['staffLastName']);
        $text       = trim((string) $review['text']);
        $review_date = self::format_date($review['reviewDate']);
        $visit_date  = self::format_date($review['visitDate']);
        $review_id   = sanitize_html_class((string) $review['reviewId']);

        if ($detailed) {
            $paragraphs = preg_split('/(?:\r\n|\r|\n){2,}/', $text);
            $safe       = [];
            foreach (is_array($paragraphs) ? $paragraphs : [$text] as $paragraph) {
                $safe[] = '<p>' . nl2br(esc_html(trim($paragraph))) . '</p>';
            }
            $text_html = implode('', $safe);
        } else {
            $snippet = self::text_substr($text, 0, 210);
            if (self::text_length($text) > 210) {
                $snippet .= '…';
            }
            $text_html = esc_html($snippet);
        }

        echo '<article id="review-' . esc_attr($review_id) . '" class="phorest-review' . ($detailed ? ' phorest-review--detailed' : ' phorest-review--widget') . '" data-rating="' . esc_attr((string) $review['rating']) . '">';
        echo '<header class="phorest-review__header">';
        echo '<div class="phorest-review__stars" aria-label="' . esc_attr((string) $review['rating']) . ' out of 5 stars">' . $stars . '</div>';
        if ($visit_date) {
            echo '<span class="phorest-review__verified">Verified visit</span>';
        }
        echo '</header>';
        echo '<blockquote class="phorest-review__text">' . $text_html . '</blockquote>';
        echo '<footer class="phorest-review__meta">';
        echo '<span class="phorest-review__author">' . esc_html($name) . '</span>';
        if ($staff) {
            echo '<span class="phorest-review__staff">Appointment with ' . esc_html($staff) . '</span>';
        }
        if ($review_date) {
            echo '<time class="phorest-review__date" datetime="' . esc_attr($review['reviewDate']) . '">' . esc_html($review_date) . '</time>';
        }
        echo '</footer>';
        echo '</article>';
    }

    /**
     * Honest schema.org JSON-LD for visible review content.
     *
     * This intentionally emits an ItemList of real Review nodes and omits
     * AggregateRating. Google does not show self-serving review rich results
     * for LocalBusiness/HairSalon pages; publishing a synthetic Product solely
     * to attach stars would be misleading. The on-page aggregate remains
     * visible to humans and is computed from the complete Phorest dataset.
     *
     * @param array $reviews Reviews visible on the current page.
     */
    private static function render_jsonld(array $reviews): void
    {
        $items    = [];
        $position = 1;
        foreach (array_slice($reviews, 0, 20) as $r) {
            if (empty($r['text']) || $r['rating'] < 1) {
                continue;
            }
            $items[] = [
                '@type'    => 'ListItem',
                'position' => $position++,
                'item'     => [
                    '@type'        => 'Review',
                    'itemReviewed' => [
                        '@type' => 'HairSalon',
                        'name'  => get_bloginfo('name'),
                        'url'   => home_url('/'),
                    ],
                    'author'       => [
                        '@type' => 'Person',
                        'name'  => self::display_name($r['clientFirstName'], $r['clientLastName']),
                    ],
                    'reviewRating' => [
                        '@type'       => 'Rating',
                        'ratingValue' => (string) $r['rating'],
                        'bestRating'  => '5',
                        'worstRating' => '1',
                    ],
                    'reviewBody'    => $r['text'],
                    'datePublished' => $r['reviewDate'],
                ],
            ];
        }
        if ([] === $items) {
            return;
        }
        $payload = [
            '@context'        => 'https://schema.org',
            '@type'           => 'ItemList',
            'name'            => 'Client reviews for ' . get_bloginfo('name'),
            'itemListElement' => $items,
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
        usort($out, function (array $a, array $b): int {
            return strcasecmp($a['display'], $b['display']);
        });
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
            return function_exists('mb_convert_case')
                ? mb_convert_case($s, MB_CASE_TITLE, 'UTF-8')
                : ucwords(strtolower($s));
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
        return $first . ' ' . self::text_substr($last, 0, 1) . '.';
    }

    /**
     * UTF-8 aware string length with a core-PHP fallback.
     *
     * @param string $text
     * @return int
     */
    private static function text_length(string $text): int
    {
        return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    }

    /**
     * UTF-8 aware substring with a core-PHP fallback.
     *
     * @param string $text
     * @param int    $start
     * @param int    $length
     * @return string
     */
    private static function text_substr(string $text, int $start, int $length): string
    {
        return function_exists('mb_substr')
            ? mb_substr($text, $start, $length, 'UTF-8')
            : substr($text, $start, $length);
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
        $slug_page = get_page_by_path('reviews');
        if ($slug_page instanceof WP_Post) {
            $url = get_permalink($slug_page->ID);
            return $url ?: home_url('/reviews/');
        }

        $pages = get_pages();
        if ($pages) {
            foreach ($pages as $page) {
                if (false !== stripos($page->post_content ?? '', 'phorest_reviews_page')) {
                    $url = get_permalink($page->ID);
                    return $url ?: home_url('/reviews/');
                }
            }
        }
        $url = home_url('/reviews/');
        return $url;
    }
}
