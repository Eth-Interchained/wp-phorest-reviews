<?php
/**
 * Uninstall — remove all plugin artifacts.
 *
 * Deletes:
 *   - The AES key file under wp-content/
 *   - All wp_options rows (settings, last-good cache, keyfile path)
 *   - The transient
 *
 * Reviews data is NOT destroyed (it lives in Phorest, not WordPress).
 *
 * @package PhorestReviews
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// uninstall.php is loaded directly, so bootstrap the constants normally
// defined by the main plugin file before loading the crypto class.
if (!defined('PHOREST_REVIEWS_KEYFILE_OPTION')) {
    define('PHOREST_REVIEWS_KEYFILE_OPTION', 'phorest_reviews_keyfile_path');
}

// Delete the encryption key file.
if (class_exists('Phorest_Reviews_Crypto')) {
    Phorest_Reviews_Crypto::delete_key_file();
} else {
    // Bootstrap the class file directly (uninstall runs in a partial scope).
    require_once __DIR__ . '/includes/class-phorest-reviews-crypto.php';
    Phorest_Reviews_Crypto::delete_key_file();
}

// Options.
delete_option('phorest_reviews_settings');
delete_option('phorest_reviews_last_good');
delete_option('phorest_reviews_keyfile_path');
delete_transient('phorest_reviews_cache');
delete_transient('phorest_reviews_branches');

// Clear any scheduled cron.
$ts = wp_next_scheduled('phorest_reviews_refresh');
if ($ts) {
    wp_unschedule_event($ts, 'phorest_reviews_refresh');
}
wp_clear_scheduled_hook('phorest_reviews_refresh');
