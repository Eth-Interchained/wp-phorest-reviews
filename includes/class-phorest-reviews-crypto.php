<?php
/**
 * At-rest encryption for Phorest credentials.
 *
 * AES-256-GCM. Key lives in a PHP-guarded data file under wp-content/ (NOT
 * inside the plugin dir — survives plugin updates), generated on first run
 * with random_bytes(32). A direct HTTP request executes `<?php exit; ?>` and
 * returns nothing. The plugin reads the file as data — it never includes or
 * executes it — then parses the versioned base64 payload after the guard.
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
     * .phorest-reviews-key.php. The path is persisted in an option so
     * relocations are detectable + repairable. It has a PHP exit guard but is
     * always parsed as data by this plugin.
     *
     * @return string Absolute path.
     */
    public static function key_file_path(): string
    {
        $cached = get_option(PHOREST_REVIEWS_KEYFILE_OPTION, '');
        if ($cached && is_string($cached) && is_file($cached)) {
            return $cached;
        }
        // A site move can leave an obsolete absolute path in wp_options.
        // Fall back to the current wp-content directory and recache on ensure.
        return trailingslashit(WP_CONTENT_DIR) . '.phorest-reviews-key.php';
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

        // Guarded data format. A web request exits; this class parses the raw
        // bytes with file_get_contents() and never includes the file.
        $contents = "<?php exit; ?>\nPHOREST-REVIEWS-KEY-V1\n{$key64}\n";

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

        // Defense in depth for hosts that expose wp-content directly.
        $guard = trailingslashit($parent) . 'index.php';
        if (!is_file($guard) && is_writable($parent)) {
            @file_put_contents($guard, "<?php\n// Silence is golden.\n", LOCK_EX);
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
        $raw = @file_get_contents($path);
        if (false === $raw) {
            throw new RuntimeException('Key file exists but could not be read.');
        }
        $lines = preg_split('/\R/', trim($raw));
        if (!is_array($lines) || count($lines) < 3 || '<?php exit; ?>' !== $lines[0] || 'PHOREST-REVIEWS-KEY-V1' !== $lines[1]) {
            throw new RuntimeException('Key file present but malformed — delete it and re-save settings.');
        }
        $key = base64_decode(trim($lines[2]), true);
        if (!is_string($key) || self::KEY_LEN !== strlen($key)) {
            throw new RuntimeException('Key file present but malformed — delete it and re-save settings.');
        }
        return $key;
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
