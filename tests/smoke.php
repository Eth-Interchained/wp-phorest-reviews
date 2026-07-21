<?php
/**
 * Self-contained smoke tests — no WordPress install or Composer required.
 * Runs on PHP 7.4, 8.1 and 8.3 in CI.
 */

declare(strict_types=1);

$failures = [];
function check($condition, string $message): void {
    global $failures;
    if (!$condition) {
        $failures[] = $message;
        fwrite(STDERR, "FAIL: {$message}\n");
    } else {
        fwrite(STDOUT, "PASS: {$message}\n");
    }
}

// ─── Minimal WordPress runtime stubs ─────────────────────────────
define('ABSPATH', __DIR__ . '/');
define('MINUTE_IN_SECONDS', 60);
$testContent = sys_get_temp_dir() . '/phorest-reviews-smoke-' . bin2hex(random_bytes(4));
mkdir($testContent, 0700, true);
define('WP_CONTENT_DIR', $testContent);
define('PHOREST_REVIEWS_KEYFILE_OPTION', 'phorest_reviews_keyfile_path');
define('PHOREST_REVIEWS_OPTION', 'phorest_reviews_settings');
define('PHOREST_REVIEWS_TRANSIENT', 'phorest_reviews_cache');
define('PHOREST_REVIEWS_LASTGOOD_OPTION', 'phorest_reviews_last_good');

$GLOBALS['wp_options'] = ['date_format' => 'F j, Y'];
$GLOBALS['wp_transients'] = [];
$GLOBALS['remote_pages'] = [];
$GLOBALS['remote_fail'] = false;
$GLOBALS['enqueued_styles'] = [];
$GLOBALS['phorest_test_settings'] = [
    'base_url' => 'https://api-gateway-us.phorest.test/third-party-api-server',
    'business_id' => 'business',
    'branch_id' => 'branch',
    'api_user' => 'global/test@example.com',
    'api_password' => 'secret',
    'cache_ttl' => 1800,
    'homepage_count' => 3,
    'min_rating' => 4,
    'enable_jsonld' => false,
    'hidden_artist_names' => [],
];

class WP_Error {
    private $message;
    public function __construct(string $code = '', string $message = '') { $this->message = $message; }
    public function get_error_message(): string { return $this->message; }
}
class WP_Post { public $ID = 99; public $post_content = ''; }

function trailingslashit($v) { return rtrim((string) $v, '/\\') . '/'; }
function get_option($k, $d = false) { return array_key_exists($k, $GLOBALS['wp_options']) ? $GLOBALS['wp_options'][$k] : $d; }
function update_option($k, $v, $autoload = null) { $GLOBALS['wp_options'][$k] = $v; return true; }
function delete_option($k) { unset($GLOBALS['wp_options'][$k]); return true; }
function get_transient($k) { return $GLOBALS['wp_transients'][$k] ?? false; }
function set_transient($k, $v, $ttl) { $GLOBALS['wp_transients'][$k] = $v; return true; }
function delete_transient($k) { unset($GLOBALS['wp_transients'][$k]); return true; }
function esc_html($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
function esc_attr($v) { return esc_html($v); }
function esc_url($v) { return (string) $v; }
function sanitize_text_field($v) { return trim(strip_tags((string) $v)); }
function wp_strip_all_tags($v) { return strip_tags((string) $v); }
function sanitize_html_class($v) { return preg_replace('/[^A-Za-z0-9_-]/', '', (string) $v); }
function wp_unslash($v) { return $v; }
function wp_remote_request($url, $args) {
    if ($GLOBALS['remote_fail']) { return new WP_Error('network', 'offline'); }
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
    $page = (int) ($q['page'] ?? 0);
    $body = $GLOBALS['remote_pages'][$page] ?? ['_embedded' => ['reviews' => []], 'page' => ['totalPages' => 0]];
    return ['response' => ['code' => 200], 'body' => json_encode($body)];
}
function is_wp_error($v) { return $v instanceof WP_Error; }
function wp_remote_retrieve_response_code($r) { return $r['response']['code']; }
function wp_remote_retrieve_body($r) { return $r['body']; }
function current_time($type, $gmt = false) { return '2026-07-21 01:00:00'; }
function phorest_reviews_get_setting($key, $default = null) {
    return $GLOBALS['phorest_test_settings'][$key] ?? $default;
}
function phorest_reviews_is_configured() { return true; }
function shortcode_atts($defaults, $atts, $tag = '') { return array_merge($defaults, $atts); }
function wp_enqueue_style($h) { $GLOBALS['enqueued_styles'][] = $h; }
function wp_enqueue_script($h) {}
function number_format_i18n($n, $d = 0) { return number_format((float) $n, $d); }
function selected($a, $b, $echo = true) { $s = ((string) $a === (string) $b) ? ' selected="selected"' : ''; if ($echo) echo $s; return $s; }
function current_user_can($cap) { return true; }
function get_page_by_path($slug) { return 'reviews' === $slug ? new WP_Post() : null; }
function get_permalink($id) { return 'https://example.test/reviews/'; }
function get_pages() { return []; }
function home_url($path = '/') { return 'https://example.test' . $path; }
function get_bloginfo($key) { return 'Mint on the Avenue'; }
function wp_date($format, $timestamp) { return date($format, $timestamp); }
function remove_query_arg($keys, $url = null) { return 'https://example.test/reviews/'; }
function add_query_arg($args, $url) { return $url . '?pr_page=%25%23%25'; }
function paginate_links($args) { return '<ul class="page-numbers"><li><span class="page-numbers current">1</span></li><li><a class="page-numbers" href="#">2</a></li></ul>'; }
function wp_kses_post($v) { return (string) $v; }
function wp_json_encode($v, $flags = 0) { return json_encode($v, $flags); }

require_once dirname(__DIR__) . '/includes/class-phorest-reviews-crypto.php';
require_once dirname(__DIR__) . '/includes/class-phorest-reviews-client.php';
require_once dirname(__DIR__) . '/includes/class-phorest-reviews-cache.php';
require_once dirname(__DIR__) . '/includes/class-phorest-reviews-visibility.php';
require_once dirname(__DIR__) . '/includes/class-phorest-reviews-render.php';

// ─── Crypto round trip + tamper detection ────────────────────────
Phorest_Reviews_Crypto::ensure_key_file();
$keyPath = Phorest_Reviews_Crypto::key_file_path();
$keyRaw = file_get_contents($keyPath);
check(is_file($keyPath), 'key file generated outside plugin directory');
check(strpos($keyRaw, "<?php exit; ?>\nPHOREST-REVIEWS-KEY-V1\n") === 0, 'key file has direct-HTTP PHP exit guard + version marker');
$ciphertext = Phorest_Reviews_Crypto::encrypt('global/test:pässword');
check('global/test:pässword' === Phorest_Reviews_Crypto::decrypt($ciphertext), 'AES-256-GCM round trip preserves UTF-8 credentials');
$tampered = base64_decode($ciphertext, true);
$tampered[strlen($tampered) - 1] = chr(ord($tampered[strlen($tampered) - 1]) ^ 1);
try {
    Phorest_Reviews_Crypto::decrypt(base64_encode($tampered));
    check(false, 'AES-GCM rejects tampered ciphertext');
} catch (RuntimeException $e) {
    check(true, 'AES-GCM rejects tampered ciphertext');
}

// ─── Live envelope shape + pagination ────────────────────────────
function fixture_review(int $i, int $rating = 5): array {
    return [
        'reviewId' => 'r' . $i,
        'clientId' => 'c' . $i,
        'clientFirstName' => 'Guest',
        'clientLastName' => 'Number' . $i,
        'reviewDate' => '2026-07-' . str_pad((string) max(1, 20 - $i), 2, '0', STR_PAD_LEFT),
        'visitDate' => '2026-07-01',
        'staffId' => 0 === $i % 2 ? 's1' : 's2',
        'staffFirstName' => 0 === $i % 2 ? 'MARISA' : 'Maribel',
        'staffLastName' => 0 === $i % 2 ? 'EVANS' : 'Gonzalez',
        'text' => 'Real review number ' . $i . ' with curly apostrophe ’ and useful detail.',
        'rating' => $rating,
        'facebookReview' => false,
        'twitterReview' => false,
    ];
}
$GLOBALS['remote_pages'] = [
    0 => ['_embedded' => ['reviews' => [fixture_review(1), fixture_review(2)]], 'page' => ['size' => 2, 'totalElements' => 3, 'totalPages' => 2, 'number' => 0]],
    1 => ['_embedded' => ['reviews' => [fixture_review(3, 4)]], 'page' => ['size' => 2, 'totalElements' => 3, 'totalPages' => 2, 'number' => 1]],
];
$client = new Phorest_Reviews_Client([
    'base_url' => 'https://api.test', 'business_id' => 'b', 'branch_id' => 'br',
    'api_user' => 'global/test', 'api_password' => 'secret',
]);
$walked = iterator_to_array($client->iter_reviews(2));
check(3 === count($walked), 'client reads _embedded.reviews and stops at page.totalPages');
check('r3' === $walked[2]['reviewId'], 'client preserves real review fields across pages');

// ─── Cache writes + offline last-good fallback ───────────────────
$GLOBALS['remote_fail'] = false;
$live = Phorest_Reviews_Cache::get_reviews(true);
check('live' === $live['source'] && 3 === count($live['reviews']), 'successful pull stores normalized reviews');
check(isset($GLOBALS['wp_options'][PHOREST_REVIEWS_LASTGOOD_OPTION]), 'successful pull persists last-good snapshot');
unset($GLOBALS['wp_transients'][PHOREST_REVIEWS_TRANSIENT]);
$GLOBALS['remote_fail'] = true;
$fallback = Phorest_Reviews_Cache::get_reviews(true);
check('last_good' === $fallback['source'] && 3 === count($fallback['reviews']), 'network failure serves last-good snapshot');

// ─── Atelier widget + full page pagination ───────────────────────
$reviews = [];
for ($i = 1; $i <= 25; $i++) { $reviews[] = fixture_review($i, $i === 25 ? 3 : 5); }
$GLOBALS['wp_transients'][PHOREST_REVIEWS_TRANSIENT] = [
    'reviews' => $reviews,
    'meta' => ['total' => 25, 'pages' => 1, 'pulled_at' => '2026-07-21 01:00:00'],
];
$_GET = [];
$widget = Phorest_Reviews_Render::shortcode_homepage_strip(['count' => 3, 'min_rating' => 4]);
check(strpos($widget, 'phorest-reviews-home--atelier') !== false, 'landing shortcode emits Atelier contract class');
check(3 === substr_count($widget, 'phorest-review--widget'), 'landing widget renders configured review count');
$page = Phorest_Reviews_Render::shortcode_reviews_page(['per_page' => 6]);
check(strpos($page, 'phorest-reviews-page--atelier') !== false, 'reviews page emits Atelier contract class');
check(6 === substr_count($page, 'phorest-review--detailed'), 'reviews page renders only one page of cards');
check(strpos($page, 'phorest-reviews-pagination') !== false, 'reviews page renders pagination for large datasets');
check(strpos($page, 'aggregateRating') === false, 'JSON-LD does not claim self-serving AggregateRating');

// ─── Hidden artist names: case-insensitive, everywhere ───────────
$GLOBALS['phorest_test_settings']['hidden_artist_names'] = ['mArIsA   eVaNs'];
$hiddenWidget = Phorest_Reviews_Render::shortcode_homepage_strip(['count' => 3, 'min_rating' => 4]);
$hiddenPage = Phorest_Reviews_Render::shortcode_reviews_page(['per_page' => 6]);
check(strpos($hiddenWidget, 'Marisa Evans') === false, 'hidden artist is absent from landing widget');
check(3 === substr_count($hiddenWidget, 'phorest-review--widget'), 'landing widget backfills with visible artists');
check(strpos($hiddenPage, 'Marisa Evans') === false, 'hidden artist is absent from reviews page cards and filter');
check(strpos($hiddenPage, 'data-total="13"') !== false, 'counts and aggregates are based only on visible artists');
check(strpos($hiddenPage, 'value="s1"') === false, 'hidden artist is absent from artist filter options');

// Cleanup only the isolated temp files created by this test.
Phorest_Reviews_Crypto::delete_key_file();
@unlink($testContent . '/index.php');
@rmdir($testContent);

if ($failures) {
    fwrite(STDERR, "\n" . count($failures) . " smoke test(s) failed.\n");
    exit(1);
}
fwrite(STDOUT, "\nAll smoke tests passed.\n");
