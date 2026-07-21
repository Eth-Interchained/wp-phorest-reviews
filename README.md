# wp-phorest-reviews

**Stream live client reviews from your Phorest salon POS into WordPress.**

Homepage reviews strip + full `/reviews` page, pulled read-only from the Phorest 3rd-party API. Built for [Mint on the Avenue](https://www.phorest.com/salon/mintontheavenue) (Winter Park, FL) and reusable by any Phorest salon.

- **Read-only.** Pulls reviews only — no booking writeback, no payments (Phorest doesn't expose PhorestPay via the API anyway).
- **Resilient.** Transient cache + last-good snapshot. The site never blanks when Phorest is unreachable.
- **Encrypted at rest.** Credentials are AES-256-GCM encrypted in `wp_options`; the key lives in a PHP-guarded file under `wp-content/` (survives plugin updates, can't be fetched over HTTP).
- **Honest SEO.** schema.org `Review` + `AggregateRating` computed from your actual Phorest reviews — never a fabricated Google star count.
- **PHP 7.4 / 8.1 / 8.3.** No Composer, no Python, no external runtime — pure WordPress.

## Install

1. Clone or download into `wp-content/plugins/wp-phorest-reviews/`.
2. Activate in **Plugins**.
3. Go to **Settings → Phorest Reviews**.
4. Pick your Phorest server (US/Canada/Australia or Europe), enter your **Business ID**, **API username** (`global/you@salon.com`) and **API password** (issued by Phorest Support — request via api-requests@phorest.com, CC the salon owner).
5. Click **Discover branches** to auto-find your Branch ID, or paste it if you know it.
6. Click **Save settings** — credentials are encrypted before storage.
7. Click **Refresh reviews cache now** to pull immediately (otherwise the first page load with a shortcode triggers a pull).

## Usage

Place shortcodes on any page or post:

```html
<!-- Homepage strip: newest 4 reviews at 4★ or above -->
[phorest_reviews_home]

<!-- Override count + minimum rating -->
[phorest_reviews_home count="6" min_rating="5"]

<!-- Full reviews page with stylist + rating filters -->
[phorest_reviews_page]
```

Create a WordPress page called "Reviews", drop in `[phorest_reviews_page]`, publish. The homepage shortcode links to it automatically.

## Where credentials live

| Where | What |
|---|---|
| `wp_options` (`phorest_reviews_settings`) | AES-256-GCM ciphertext of `api_user` + `api_password` |
| `wp-content/.phorest-reviews-key.php` | The 32-byte decryption key, guarded by `<?php die(403)` so a direct HTTP hit returns 403 |

**Threat model (honest):**
- ✅ Defends against **DB-only attackers** (SQL injection, DB backup leaks, plugin exports) — ciphertext in `wp_options` is useless without the key file.
- ❌ Does **not** defend against full-filesystem attackers — they have the key file too. That is impossible to prevent without an external key source (wp-config constant or a KMS), which this plugin does not assume since many salon sites don't have filesystem root access.

To rotate the key: **Settings → Phorest Reviews → Regenerate encryption key**. All stored credentials become unreadable; re-enter them.

## Performance

Phorest's API rate limit is 100 rps — generous. A full pull of Mint's 914 reviews takes ~5s across 10 round-trips. The transient cache (default 30 min TTL) means normal page loads don't hit Phorest at all. Reviews are low-churn; the default cadence is plenty.

## No webhooks

Phorest's API does not support webhooks (per their docs). The plugin polls on cache expiry + offers a manual "Refresh now" button. A WP cron warm is available via the `phorest_reviews_refresh` hook if you want to keep the cache hot off the request path.

## Honesty rules

Carried over from the [salon-platform](https://github.com/Eth-Interchained/salon-platform):

- **No fabricated ratings.** `AggregateRating` is computed from your real cached Phorest reviews.
- **No false platform attribution.** For Mint, the `facebookReview` / `twitterReview` flags are all `false` — we do not claim any Google/Facebook cross-posting in JSON-LD. If your salon uses Phorest's Online Reputation social auto-boost, verify per-review source before claiming it.
- **Real review text only.** Never invented or paraphrased.
- **Privacy-friendly author display.** First name + last initial ("Lizzette R.").

## Development

```bash
# Lint all PHP files
find . -name '*.php' -exec php -l {} \;

# phpcs with WordPress standards (optional)
composer install
vendor/bin/phpcs --standard=WordPress .
```

### CI

GitHub Actions lints PHP 7.4 / 8.1 / 8.3 on every push and PR.

## License

BUSL-1.1 — see [LICENSE](LICENSE). Source-available; production use permitted.

## Related

- [phorest-api](https://github.com/Eth-Interchained/) — Python skill wrapping the same API (for the salon-platform catalog pull-live into NEDB).
- [wp-portal-bridge](https://github.com/Eth-Interchained/wp-portal-bridge) — sibling WordPress plugin (the HMAC tunnel pattern this plugin inherits discipline from).
- [salon-platform](https://github.com/Eth-Interchained/salon-platform) — the Portal app this reviews plugin feeds into.

Built by [Interchained LLC](https://interchained.org).
