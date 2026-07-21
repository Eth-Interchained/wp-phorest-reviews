<?php
/**
 * At-rest encryption for Phorest credentials.
 *
 * AES-256-GCM. Key lives in a PHP-guarded file under wp-content/ (NOT inside
 * the plugin dir — survives plugin updates), generated on first run with
 * random_bytes(32). The key file begins with <?php http_response_code(403); die();
 * so a direct HTTP fetch returns 403 and never serves the key as text.
 *
 * THREAT MODEL (honest):
 *   ✓ Defends against DB-only attackers (SQL injection, DB backup leak,
 *     plugin export). Ciphertext in wp_options is useless without the key file.
 *   ✗ Does NOT defend against full-filesystem attackers — they have the key
 *     file too. That is impossible to prevent without an external key source
 *     (e.g. wp-config constant or a KMS), which this plugin does not assume.
 *
 * @package PhorestReviews
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

class Phorest_Reviews_Crypto
{
    private const CIPHER   = 'aes-256-gcm';
    private const KEY_LEN  = 32;   // 256-bit
    private const TAG_LEN  = 16;   // GCM auth tag
    private const IV_LEN   = 12;   // GCM nonce

    /**
     * Resolve the absolute path to the key file.
     *
     * Stored just above the plugin dir under wp-content/, named
     * .phorest-reviews-key.php so it looks like PHP and is served as such
     * (the file's first line is a die() guard). The path is also persisted
     * in an option so relocations are detectable + repairable.
     *
     * @return string Absolute path.
     */
    public static function key_file_path(): string
    {
        $cached = get_option(PHOREST_REVIEWS_KEYFILE_OPTION, '');
        if ($cached && is_string($cached)) {
            return $cached;
        }
        $candidate = trailingslashit(WP_CONTENT_DIR) . '.phorest-reviews-key.php';
        return $candidate;
    }

    /**
     * Generate the key file if missing. Idempotent. Safe to call on activation
     * and on settings save.
     *
     * @throws RuntimeException If the key file cannot be written or OpenSSL
     *                           is unavailable.
     */
    public static function ensure_key_file(): void
    {
        if (!function_exists('openssl_encrypt')) {
            throw new RuntimeException(
                'OpenSSL extension is not available — Phorest Reviews cannot encrypt credentials. Install/enable the PHP OpenSSL extension.'
            );
        }

        $path = self::key_file_path();

        if (is_file($path)) {
            // Re-cache the path in case wp-content moved.
            update_option(PHOREST_REVIEWS_KEYFILE_OPTION, $path, false);
            return;
        }

        $parent = dirname($path);
        if (!is_dir($parent) || !is_writable($parent)) {
            throw new RuntimeException(
                sprintf('Cannot write key file to %s — directory missing or not writable.', esc_html($parent))
            );
        }

        $key   = random_bytes(self::KEY_LEN);
        $key64 = base64_encode($key);

        // PHP guard: a direct HTTP hit executes the file and dies 403.
        // A require/include from this plugin reads the $KEY constant.
        $contents = "<?php http_response_code(403); die('Forbidden');\n"
                  . "// DO NOT EDIT. Regenerate by deleting this file.\n"
                  . "\$KEY = base64_decode('{$key64}');\n";

        // Atomic write: temp file + rename so a crash never leaves a partial.
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
        if (false === file_put_contents($tmp, $contents, LOCK_EX)) {
            throw new RuntimeException('Failed writing key file (temp).');
        }
        // 0600 — owner read/write only.
        @chmod($tmp, 0600);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Failed finalizing key file (rename).');
        }

        update_option(PHOREST_REVIEWS_KEYFILE_OPTION, $path, false);
    }

    /**
     * Load the raw key from the key file.
     *
     * @return string 32-byte key.
     * @throws RuntimeException If the key file is missing or malformed.
     */
    private static function load_key(): string
    {
        $path = self::key_file_path();
        if (!is_file($path)) {
            throw new RuntimeException('Key file missing — re-save settings to regenerate.');
        }
        $KEY = null;
        // Isolate scope: the key file defines $KEY.
        (static function () use ($path, &$KEY): void {
            include $path;
        })();
        if (!is_string($KEY) || self::KEY_LEN !== strlen($KEY)) {
            throw new RuntimeException('Key file present but malformed — delete it and re-save settings.');
        }
        return $KEY;
    }

    /**
     * Encrypt a plaintext string.
     *
     * Returns a base64 payload: base64( iv || tag || ciphertext ).
     * Suitable for storing in wp_options.
     *
     * @param string $plaintext UTF-8 plaintext.
     * @return string Encrypted payload (base64).
     * @throws RuntimeException On crypto failure.
     */
    public static function encrypt(string $plaintext): string
    {
        self::ensure_key_file();
        $key = self::load_key();

        $iv  = random_bytes(self::IV_LEN);
        $tag = '';
        $ct  = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
        if (false === $ct || '' === $tag) {
            throw new RuntimeException('AES-256-GCM encryption failed.');
        }
        return base64_encode($iv . $tag . $ct);
    }

    /**
     * Decrypt an encrypt()-produced payload.
     *
     * @param string $payload base64 payload from encrypt().
     * @return string Plaintext.
     * @throws RuntimeException On crypto failure or tamper.
     */
    public static function decrypt(string $payload): string
    {
        $raw = base64_decode($payload, true);
        if (false === $raw) {
            throw new RuntimeException('Ciphertext payload is not valid base64.');
        }
        if (strlen($raw) < self::IV_LEN + self::TAG_LEN) {
            throw new RuntimeException('Ciphertext payload too short.');
        }
        $key = self::load_key();
        $iv  = substr($raw, 0, self::IV_LEN);
        $tag = substr($raw, self::IV_LEN, self::TAG_LEN);
        $ct  = substr($raw, self::IV_LEN + self::TAG_LEN);

        $pt = openssl_decrypt($ct, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
        if (false === $pt) {
            throw new RuntimeException('AES-256-GCM decryption failed — wrong key or tampered ciphertext.');
        }
        return $pt;
    }

    /**
     * Is the key file present and OpenSSL available?
     *
     * @return bool
     */
    public static function is_healthy(): bool
    {
        return function_exists('openssl_encrypt') && is_file(self::key_file_path());
    }

    /**
     * Delete the key file (used by uninstall + a "regenerate key" button).
     *
     * @return bool
     */
    public static function delete_key_file(): bool
    {
        $path = self::key_file_path();
        if (is_file($path)) {
            @unlink($path);
        }
        return delete_option(PHOREST_REVIEWS_KEYFILE_OPTION);
    }
}
