# wp-phorest-reviews

**Stream live client reviews from your Phorest salon POS into WordPress.**

Atelier-themed landing widget + full paginated `/reviews` page, pulled read-only from the Phorest 3rd-party API. Built for [Mint on the Avenue](https://www.phorest.com/salon/mintontheavenue) (Winter Park, FL) and reusable by any Phorest salon.

- **Read-only.** Pulls reviews only — no booking writeback, no payments (Phorest doesn't expose PhorestPay via the API anyway).
- **Resilient.** Fresh cache → last-good snapshot → the Atelier theme's original hardcoded review block. The site never blanks when Phorest is unreachable.
- **Encrypted at rest.** Credentials are AES-256-GCM encrypted in `wp_options`; the key lives in a PHP-guarded data file under `wp-content/` (survives plugin updates, direct HTTP requests exit before the key bytes).
- **Honest SEO.** Optional schema.org ItemList of the visible reviews. No self-serving LocalBusiness AggregateRating rich-result claim.
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
<!-- Atelier landing widget: newest 3 reviews at 4★ or above -->
[phorest_reviews_home]

<!-- Override count + minimum rating -->
[phorest_reviews_home count="6" min_rating="5"]

<!-- Full reviews page with stylist + rating filters -->
[phorest_reviews_page]
```

On any theme, create a WordPress page called "Reviews", use the slug `/reviews`, drop in `[phorest_reviews_page]`, and publish. **With the supplied Atelier v2.9.69 theme, leave the page body blank**: `page-reviews.php` loads the live plugin surface automatically and falls back to the original hardcoded reviews if the plugin is unavailable. The landing widget links to `/reviews` automatically.

For classic widget areas, add **Phorest Reviews — Landing Widget** under **Appearance → Widgets**. Page builders can use `[phorest_reviews_home]` instead; both render the same surface.

## Where credentials live

| Where | What |
|---|---|
| `wp_options` (`phorest_reviews_settings`) | AES-256-GCM ciphertext of `api_user` + `api_password` |
| `wp-content/.phorest-reviews-key.php` | The 32-byte decryption key in a versioned data payload, preceded by `<?php exit; ?>`; the plugin reads it as data and never includes it |

**Threat model (honest):**
- ✅ Defends against **DB-only attackers** (SQL injection, DB backup leaks, plugin exports) — ciphertext in `wp_options` is useless without the key file.
- ❌ Does **not** defend against full-filesystem attackers — they have the key file too. That is impossible to prevent without an external key source (wp-config constant or a KMS), which this plugin does not assume since many salon sites don't have filesystem root access.

To rotate the key: **Settings → Phorest Reviews → Regenerate encryption key**. All stored credentials become unreadable; re-enter them.

## Performance

Phorest's API rate limit is 100 rps — generous. A full pull of Mint's 914 reviews takes ~5s across 10 round-trips. The transient cache (default 30 min TTL) means normal page loads don't hit Phorest at all. Reviews are low-churn; the default cadence is plenty.

## No webhooks

Phorest's API does not support webhooks (per their docs). The plugin schedules a WordPress cron refresh every 30 minutes, also refreshes on cache miss, and offers a manual "Refresh now" button. Failed refreshes preserve and serve the last-good snapshot.

## Honesty rules

Carried over from the [salon-platform](https://github.com/Eth-Interchained/salon-platform):

- **No fabricated ratings.** The visible average is computed from the complete cached Phorest dataset. JSON-LD omits `AggregateRating` because Google disallows self-serving LocalBusiness review rich results.
- **No false platform attribution.** For Mint, the sampled `facebookReview` / `twitterReview` flags are `false` — we do not claim any Google/Facebook cross-posting. If a salon uses Phorest's Online Reputation social auto-boost, verify per-review source before claiming it.
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

BUSL-1.1 — see [LICENSE](LICENSE). Production use is governed by the license terms.

## Related

- [phorest-api](https://github.com/Eth-Interchained/) — Python skill wrapping the same API (for the salon-platform catalog pull-live into NEDB).
- [wp-portal-bridge](https://github.com/Eth-Interchained/wp-portal-bridge) — sibling WordPress plugin (the HMAC tunnel pattern this plugin inherits discipline from).
- [salon-platform](https://github.com/Eth-Interchained/salon-platform) — the Portal app this reviews plugin feeds into.

Built by [Interchained LLC](https://interchained.org).
