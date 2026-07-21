<?php
/**
 * Plugin Name:       Phorest Reviews
 * Plugin URI:        https://github.com/Eth-Interchained/wp-phorest-reviews
 * Description:       Stream live client reviews from your Phorest salon POS into WordPress — homepage strip + full /reviews page. Read-only, AES-256-GCM encrypted credentials, last-good cache so the site never blanks when Phorest is unreachable.
 * Version:           0.1.0
 * Requires at least: 5.6
 * Requires PHP:      7.4
 * Author:            Interchained LLC
 * Author URI:        https://interchained.org
 * License:           BUSL-1.1
 * License URI:       https://spdx.org/licenses/BUSL-1.1.html
 *
 * @package PhorestReviews
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

define('PHOREST_REVIEWS_VERSION', '0.1.0');
define('PHOREST_REVIEWS_SLUG', 'phorest-reviews');
define('PHOREST_REVIEWS_OPTION', 'phorest_reviews_settings');
define('PHOREST_REVIEWS_KEYFILE_OPTION', 'phorest_reviews_keyfile_path');
define('PHOREST_REVIEWS_TRANSIENT', 'phorest_reviews_cache');
define('PHOREST_REVIEWS_LASTGOOD_OPTION', 'phorest_reviews_last_good');

require_once __DIR__ . '/includes/class-phorest-reviews-crypto.php';
require_once __DIR__ . '/includes/class-phorest-reviews-client.php';
require_once __DIR__ . '/includes/class-phorest-reviews-cache.php';
require_once __DIR__ . '/includes/class-phorest-reviews-render.php';
require_once __DIR__ . '/includes/class-phorest-reviews-settings.php';

/**
 * Helper: get a single setting with default + constant override support.
 *
 * Reads in order: filter (programmatic) → wp-config constant (PHOREST_REVIEWS_*)
 * → stored option → default. Constants let ops override without touching the DB.
 *
 * @param string $key     Setting key.
 * @param mixed  $default Fallback value.
 * @return mixed
 */
function phorest_reviews_get_setting(string $key, $default = null)
{
    $constants = [
        'base_url'      => 'PHOREST_REVIEWS_BASE_URL',
        'business_id'   => 'PHOREST_REVIEWS_BUSINESS_ID',
        'branch_id'     => 'PHOREST_REVIEWS_BRANCH_ID',
        'api_user'      => 'PHOREST_REVIEWS_API_USER',
        'api_password'  => 'PHOREST_REVIEWS_API_PASSWORD',
        'cache_ttl'     => 'PHOREST_REVIEWS_CACHE_TTL',
        'homepage_count'=> 'PHOREST_REVIEWS_HOMEPAGE_COUNT',
        'min_rating'    => 'PHOREST_REVIEWS_MIN_RATING',
        'enable_jsonld' => 'PHOREST_REVIEWS_ENABLE_JSONLD',
    ];

    if (isset($constants[$key]) && defined($constants[$key])) {
        return constant($constants[$key]);
    }

    /**
     * Programmatic override filter.
     *
     * @param mixed  $current Current resolved value.
     * @param string $key     Setting key.
     */
    $filtered = apply_filters('phorest_reviews_setting', null, $key);
    if (null !== $filtered) {
        return $filtered;
    }

    $options = get_option(PHOREST_REVIEWS_OPTION, []);
    return $options[$key] ?? $default;
}

/**
 * Helper: is the plugin fully configured to pull from Phorest?
 *
 * @return bool
 */
function phorest_reviews_is_configured(): bool
{
    return (bool) (
        phorest_reviews_get_setting('base_url')
        && phorest_reviews_get_setting('business_id')
        && phorest_reviews_get_setting('branch_id')
        && phorest_reviews_get_setting('api_user')
        && phorest_reviews_get_setting('api_password')
    );
}

( new Phorest_Reviews_Settings() )->register();
( new Phorest_Reviews_Cache() )->register_hooks();

// Shortcodes register on init.
add_action('init', function (): void {
    add_shortcode('phorest_reviews_home', [Phorest_Reviews_Render::class, 'shortcode_homepage_strip']);
    add_shortcode('phorest_reviews_page', [Phorest_Reviews_Render::class, 'shortcode_reviews_page']);
});

// Assets register on wp_enqueue_scripts (enqueued lazily by shortcodes).
add_action('wp_enqueue_scripts', function (): void {
    wp_register_style(
        'phorest-reviews',
        plugins_url('assets/css/phorest-reviews.css', __FILE__),
        [],
        PHOREST_REVIEWS_VERSION
    );
    wp_register_script(
        'phorest-reviews',
        plugins_url('assets/js/phorest-reviews.js', __FILE__),
        [],
        PHOREST_REVIEWS_VERSION,
        true
    );
});

/**
 * Activation: ensure the key file is provisioned so the settings page can
 * store encrypted creds from minute one. Non-fatal — settings page re-attempts
 * if missing.
 */
register_activation_hook(__FILE__, function (): void {
    try {
        Phorest_Reviews_Crypto::ensure_key_file();
    } catch (RuntimeException $e) {
        // Log but do not block activation; settings page surfaces the error.
        if (function_exists('error_log')) {
            error_log('[phorest-reviews] activation key-file warning: ' . $e->getMessage());
        }
    }
});
