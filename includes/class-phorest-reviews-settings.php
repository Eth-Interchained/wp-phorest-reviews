<?php
/**
 * Admin settings page.
 *
 * Credentials entered here are AES-256-GCM encrypted before going into
 * wp_options. The key lives in a PHP-guarded file under wp-content/.
 *
 * @package PhorestReviews
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

class Phorest_Reviews_Settings
{
    /**
     * Register hooks.
     */
    public function register(): void
    {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_init', [$this, 'handle_post']);
        add_action('admin_notices', [$this, 'admin_notices']);
    }

    /**
     * Add the settings menu under Settings.
     */
    public function add_menu(): void
    {
        add_options_page(
            'Phorest Reviews',
            'Phorest Reviews',
            'manage_options',
            PHOREST_REVIEWS_SLUG,
            [$this, 'render_page']
        );
    }

    /**
     * Handle form submissions (settings save / refresh / discover branch / regenerate key).
     */
    public function handle_post(): void
    {
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }
        $action = $_POST['phorest_action'] ?? $_GET['phorest_action'] ?? '';
        if ('' === $action) {
            return;
        }
        check_admin_referer('phorest_reviews_admin');

        switch ($action) {
            case 'save_settings':
                $this->handle_save();
                break;
            case 'refresh':
                Phorest_Reviews_Cache::refresh_now();
                add_action('admin_notices', function (): void {
                    echo '<div class="notice notice-success is-dismissible"><p>Reviews cache refreshed.</p></div>';
                });
                break;
            case 'discover_branch':
                $this->handle_discover_branch();
                break;
            case 'regenerate_key':
                Phorest_Reviews_Crypto::delete_key_file();
                try {
                    Phorest_Reviews_Crypto::ensure_key_file();
                    add_action('admin_notices', function (): void {
                        echo '<div class="notice notice-success is-dismissible"><p>Encryption key regenerated. Re-enter credentials — the old ciphertext is now unreadable.</p></div>';
                    });
                } catch (RuntimeException $e) {
                    add_action('admin_notices', function () use ($e): void {
                        echo '<div class="notice notice-error is-dismissible"><p>Key regeneration failed: ' . esc_html($e->getMessage()) . '</p></div>';
                    });
                }
                // Wipe stored creds since they can no longer be decrypted.
                delete_option(PHOREST_REVIEWS_OPTION);
                break;
        }
    }

    /**
     * Persist settings, encrypting the credential fields.
     */
    private function handle_save(): void
    {
        $raw = wp_unslash($_POST['phorest'] ?? []);
        if (!is_array($raw)) {
            return;
        }

        // Sanitize non-credential fields.
        $clean = [
            'base_url'       => esc_url_raw(trim($raw['base_url'] ?? '')),
            'business_id'    => sanitize_text_field(trim($raw['business_id'] ?? '')),
            'branch_id'      => sanitize_text_field(trim($raw['branch_id'] ?? '')),
            'cache_ttl'      => max(60, (int) ($raw['cache_ttl'] ?? 1800)),
            'homepage_count' => max(1, min(12, (int) ($raw['homepage_count'] ?? 4))),
            'min_rating'     => max(1, min(5, (int) ($raw['min_rating'] ?? 4))),
            'enable_jsonld'  => isset($raw['enable_jsonld']) ? 1 : 0,
            'timeout'        => max(5, min(120, (int) ($raw['timeout'] ?? 30))),
        ];

        // Credentials: only re-encrypt if a new value was typed.
        // An empty POST field = "keep existing"; a filled field = "replace".
        $existing = get_option(PHOREST_REVIEWS_OPTION, []);

        try {
            Phorest_Reviews_Crypto::ensure_key_file();

            if (!empty($raw['api_user'])) {
                $clean['api_user'] = Phorest_Reviews_Crypto::encrypt(sanitize_text_field($raw['api_user']));
            } elseif (isset($existing['api_user'])) {
                $clean['api_user'] = $existing['api_user'];
            }

            if (!empty($raw['api_password'])) {
                $clean['api_password'] = Phorest_Reviews_Crypto::encrypt(sanitize_text_field($raw['api_password']));
            } elseif (isset($existing['api_password'])) {
                $clean['api_password'] = $existing['api_password'];
            }
        } catch (RuntimeException $e) {
            add_action('admin_notices', function () use ($e): void {
                echo '<div class="notice notice-error is-dismissible"><p>Could not encrypt credentials: ' . esc_html($e->getMessage()) . '</p></div>';
            });
            return;
        }

        update_option(PHOREST_REVIEWS_OPTION, $clean, false);

        add_action('admin_notices', function (): void {
            echo '<div class="notice notice-success is-dismissible"><p>Settings saved. Credentials encrypted at rest.</p></div>';
        });
    }

    /**
     * Discover branches for the configured business_id + creds.
     */
    private function handle_discover_branch(): void
    {
        $opts = get_option(PHOREST_REVIEWS_OPTION, []);
        try {
            $config = [
                'base_url'     => $_POST['phorest']['base_url'] ?? $opts['base_url'] ?? '',
                'business_id'  => $_POST['phorest']['business_id'] ?? $opts['business_id'] ?? '',
                'branch_id'    => 'unused',
                'api_user'     => $this->resolve_credential($_POST['phorest']['api_user'] ?? '', $opts['api_user'] ?? ''),
                'api_password' => $this->resolve_credential($_POST['phorest']['api_password'] ?? '', $opts['api_password'] ?? ''),
            ];
            $client   = new Phorest_Reviews_Client($config);
            $branches = $client->list_branches();
            $list     = $branches['_embedded']['branches'] ?? [];
            if (is_array($list) && [] !== $list) {
                set_transient('phorest_reviews_branches', $list, 300);
                add_action('admin_notices', function (): void {
                    echo '<div class="notice notice-success is-dismissible"><p>Branches found — pick one below.</p></div>';
                });
            } else {
                add_action('admin_notices', function (): void {
                    echo '<div class="notice notice-warning is-dismissible"><p>No branches returned for that business ID.</p></div>';
                });
            }
        } catch (RuntimeException $e) {
            add_action('admin_notices', function () use ($e): void {
                echo '<div class="notice notice-error is-dismissible"><p>Branch discovery failed: ' . esc_html($e->getMessage()) . '</p></div>';
            });
        }
    }

    /**
     * Resolve a credential: POST plaintext if present, else decrypt the stored one.
     *
     * @param string $post_val  Raw POST value (may be empty = keep existing).
     * @param string $stored    Stored ciphertext.
     * @return string
     */
    private function resolve_credential(string $post_val, string $stored): string
    {
        if ('' !== trim($post_val)) {
            return trim($post_val);
        }
        if ('' !== $stored) {
            try {
                return Phorest_Reviews_Crypto::decrypt($stored);
            } catch (RuntimeException $e) {
                return '';
            }
        }
        return '';
    }

    /**
     * Admin notices for key-file health.
     */
    public function admin_notices(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        if (!Phorest_Reviews_Crypto::is_healthy()) {
            echo '<div class="notice notice-warning is-dismissible"><p>Phorest Reviews: encryption key file is missing or OpenSSL is unavailable. Credentials cannot be stored securely. Visit <a href="' . esc_url(admin_url('options-general.php?page=' . PHOREST_REVIEWS_SLUG)) . '">Settings → Phorest Reviews</a>.</p></div>';
        }
        if (!phorest_reviews_is_configured()) {
            // Only nag on our own settings page to avoid spamming every admin screen.
            $screen = function_exists('get_current_screen') ? get_current_screen() : null;
            if ($screen && false !== stripos($screen->id, PHOREST_REVIEWS_SLUG)) {
                echo '<div class="notice notice-info"><p>Phorest Reviews is not configured yet — enter your Phorest API credentials below.</p></div>';
            }
        }
    }

    /**
     * Render the settings page.
     */
    public function render_page(): void
    {
        $opts = get_option(PHOREST_REVIEWS_OPTION, []);
        $val  = function (string $key, string $default = '') use ($opts): string {
            return (string) ($opts[$key] ?? $default);
        };
        // Never echo decrypted creds back into the form; show placeholder dots.
        $cred_placeholder = '********';
        $branches = get_transient('phorest_reviews_branches');
        ?>
        <div class="wrap">
            <h1>Phorest Reviews</h1>
            <p>Stream live client reviews from your Phorest salon POS into WordPress.</p>

            <h2 class="title">Encryption health</h2>
            <p>
                <?php if (Phorest_Reviews_Crypto::is_healthy()): ?>
                    <span class="dashicons dashicons-yes-alt" style="color:#46b450"></span>
                    AES-256-GCM enabled. Key file: <code><?php echo esc_html(Phorest_Reviews_Crypto::key_file_path()); ?></code>
                <?php else: ?>
                    <span class="dashicons dashicons-warning" style="color:#d63638"></span>
                    Encryption not ready — credentials cannot be stored.
                <?php endif; ?>
            </p>

            <form method="post" action="">
                <?php wp_nonce_field('phorest_reviews_admin'); ?>
                <input type="hidden" name="phorest_action" value="save_settings">

                <h2 class="title">Phorest API credentials</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="pr_base_url">API base URL</label></th>
                        <td>
                            <select name="phorest[base_url]" id="pr_base_url" class="regular-text">
                                <option value="https://api-gateway-us.phorest.com/third-party-api-server" <?php selected($val('base_url'), 'https://api-gateway-us.phorest.com/third-party-api-server'); ?>>US / Canada / Australia (api-gateway-us)</option>
                                <option value="https://api-gateway-eu.phorest.com/third-party-api-server" <?php selected($val('base_url'), 'https://api-gateway-eu.phorest.com/third-party-api-server'); ?>>Europe (api-gateway-eu)</option>
                            </select>
                            <p class="description">Mint on the Avenue = US.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="pr_business_id">Business ID</label></th>
                        <td><input name="phorest[business_id]" id="pr_business_id" type="text" class="regular-text" value="<?php echo esc_attr($val('business_id')); ?>" placeholder="e.g. TFIibeAEzdrUIwJKIhHkTw"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="pr_branch_id">Branch ID</label></th>
                        <td>
                            <input name="phorest[branch_id]" id="pr_branch_id" type="text" class="regular-text" value="<?php echo esc_attr($val('branch_id')); ?>" placeholder="e.g. A5QoE8pDuIPPBJOYtbj1rw">
                            <p class="description">Don't know it? Enter Business ID + creds above, then click "Discover branches".</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="pr_api_user">API username</label></th>
                        <td><input name="phorest[api_user]" id="pr_api_user" type="text" class="regular-text" value="<?php echo esc_attr($val('api_user') ? $cred_placeholder : ''); ?>" placeholder="global/you@salon.com" autocomplete="off"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="pr_api_password">API password</label></th>
                        <td><input name="phorest[api_password]" id="pr_api_password" type="password" class="regular-text" value="<?php echo esc_attr($val('api_password') ? $cred_placeholder : ''); ?>" autocomplete="new-password"></td>
                    </tr>
                </table>

                <?php if (is_array($branches) && [] !== $branches): ?>
                    <h2 class="title">Discovered branches</h2>
                    <table class="widefat striped">
                        <thead><tr><th>Branch ID</th><th>Name</th><th>Address</th><th>Action</th></tr></thead>
                        <tbody>
                        <?php foreach ($branches as $b): ?>
                            <tr>
                                <td><code><?php echo esc_html($b['branchId'] ?? ''); ?></code></td>
                                <td><?php echo esc_html($b['name'] ?? ''); ?></td>
                                <td><?php echo esc_html(sprintf('%s, %s %s', $b['city'] ?? '', $b['state'] ?? '', $b['postalCode'] ?? '')); ?></td>
                                <td><button type="button" class="button" onclick="document.getElementById('pr_branch_id').value='<?php echo esc_attr($b['branchId'] ?? ''); ?>';">Use this</button></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

                <h2 class="title">Display</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="pr_homepage_count">Homepage strip count</label></th>
                        <td><input name="phorest[homepage_count]" id="pr_homepage_count" type="number" min="1" max="12" value="<?php echo esc_attr((string) ($opts['homepage_count'] ?? 4)); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="pr_min_rating">Minimum rating (homepage)</label></th>
                        <td>
                            <select name="phorest[min_rating]" id="pr_min_rating">
                                <?php for ($r = 5; $r >= 1; $r--): ?>
                                    <option value="<?php echo $r; ?>" <?php selected((int) ($opts['min_rating'] ?? 4), $r); ?>><?php echo $r; ?> star & up</option>
                                <?php endfor; ?>
                            </select>
                            <p class="description">Mint's reviews are 906×5★, 3×4★, 3×3★, 2×2★ — 4★ default hides the two 2★.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="pr_cache_ttl">Cache TTL (seconds)</label></th>
                        <td><input name="phorest[cache_ttl]" id="pr_cache_ttl" type="number" min="60" value="<?php echo esc_attr((string) ($opts['cache_ttl'] ?? 1800)); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row">Schema.org JSON-LD</th>
                        <td><label><input type="checkbox" name="phorest[enable_jsonld]" value="1" <?php checked((int) ($opts['enable_jsonld'] ?? 1), 1); ?>> Emit honest Review + AggregateRating markup (computed from cached Phorest reviews — no fabricated Google stars)</label></td>
                    </tr>
                </table>

                <?php submit_button('Save settings'); ?>
            </form>

            <h2 class="title">Tools</h2>
            <form method="post" action="" style="display:inline-block;margin-right:1em">
                <?php wp_nonce_field('phorest_reviews_admin'); ?>
                <input type="hidden" name="phorest_action" value="refresh">
                <button type="submit" class="button button-secondary">Refresh reviews cache now</button>
            </form>
            <form method="post" action="" style="display:inline-block;margin-right:1em">
                <?php wp_nonce_field('phorest_reviews_admin'); ?>
                <input type="hidden" name="phorest_action" value="discover_branch">
                <button type="submit" class="button button-secondary">Discover branches</button>
            </form>
            <form method="post" action="" style="display:inline-block" onsubmit="return confirm('Regenerate the encryption key? All stored credentials become unreadable and you must re-enter them.');">
                <?php wp_nonce_field('phorest_reviews_admin'); ?>
                <input type="hidden" name="phorest_action" value="regenerate_key">
                <button type="submit" class="button button-link-delete">Regenerate encryption key</button>
            </form>

            <h2 class="title">Shortcodes</h2>
            <p>Place on any page/post:</p>
            <ul>
                <li><code>[phorest_reviews_home]</code> — homepage strip (4★+, newest 4). Accepts <code>count</code> and <code>min_rating</code>.</li>
                <li><code>[phorest_reviews_page]</code> — full reviews page with stylist/rating filters.</li>
            </ul>
        </div>
        <?php
    }
}
