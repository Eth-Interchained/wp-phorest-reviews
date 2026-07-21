<?php
/**
 * Phorest 3rd-party API client (PHP, read-only).
 *
 * Basic auth, US/EU server via base_url, business/branch scoped, paginated.
 * Reads _embedded.reviews[] + page.{totalPages,totalElements} — the envelope
 * verified live against Mint on the Avenue (914 reviews, 2026-07-20).
 *
 * @package PhorestReviews
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

class Phorest_Reviews_Client
{
    private const MAX_PAGE_SIZE = 100;
    private const DEFAULT_TIMEOUT = 30;

    /** @var string */
    private $base_url;
    /** @var string */
    private $business_id;
    /** @var string */
    private $branch_id;
    /** @var string */
    private $auth_header;
    /** @var int */
    private $timeout;

    /**
     * @param array $config {base_url, business_id, branch_id, api_user, api_password, timeout?}
     * @throws InvalidArgumentException If required fields are missing.
     */
    public function __construct(array $config)
    {
        foreach (['base_url', 'business_id', 'branch_id', 'api_user', 'api_password'] as $req) {
            if (empty($config[$req])) {
                throw new InvalidArgumentException("Phorest client missing config: {$req}");
            }
        }
        $this->base_url    = rtrim((string) $config['base_url'], '/');
        $this->business_id = (string) $config['business_id'];
        $this->branch_id   = (string) $config['branch_id'];
        $this->timeout     = (int) ($config['timeout'] ?? self::DEFAULT_TIMEOUT);

        $raw = $config['api_user'] . ':' . $config['api_password'];
        $this->auth_header = 'Basic ' . base64_encode($raw);
    }

    /**
     * Build a config array from the plugin's settings.
     *
     * @return array
     */
    public static function from_settings(): array
    {
        return [
            'base_url'      => phorest_reviews_get_setting('base_url'),
            'business_id'   => phorest_reviews_get_setting('business_id'),
            'branch_id'     => phorest_reviews_get_setting('branch_id'),
            'api_user'      => phorest_reviews_get_setting('api_user'),
            'api_password'  => phorest_reviews_get_setting('api_password'),
            'timeout'       => (int) (phorest_reviews_get_setting('timeout', self::DEFAULT_TIMEOUT)),
        ];
    }

    /**
     * List one page of reviews.
     *
     * @param array $args {page?, size?, client_id?, client_name?, staff_name?, review_date?}
     * @return array Raw envelope: {_embedded: {reviews: [...]}, page: {...}}
     * @throws RuntimeException On HTTP/JSON/network error.
     */
    public function list_reviews(array $args = []): array
    {
        $page = max(0, (int) ($args['page'] ?? 0));
        $size = (int) ($args['size'] ?? 20);
        if ($size > self::MAX_PAGE_SIZE) {
            throw new RuntimeException("size {$size} exceeds Phorest max " . self::MAX_PAGE_SIZE);
        }

        $query = array_filter([
            'page'        => $page,
            'size'        => $size,
            'clientId'    => $args['client_id']    ?? null,
            'clientName'  => $args['client_name']  ?? null,
            'staffName'   => $args['staff_name']   ?? null,
            'reviewDate'  => $args['review_date']  ?? null,
        ], fn($v) => null !== $v && '' !== $v);

        return $this->request('GET', $this->branch_path('/review', $query));
    }

    /**
     * Iterate every review across all pages (size=100 for fewest round-trips).
     *
     * @param int $size Page size (≤100).
     * @return Generator<int, array, void, void>
     */
    public function iter_reviews(int $size = 100): \Generator
    {
        $page = 0;
        while (true) {
            $envelope = $this->list_reviews(['page' => $page, 'size' => $size]);
            $batch    = $envelope['_embedded']['reviews'] ?? ($envelope['_embedded'] ?? []);
            if (!is_array($batch) || [] === $batch) {
                break;
            }
            foreach ($batch as $review) {
                yield $review;
            }
            $total_pages = (int) ($envelope['page']['totalPages'] ?? 0);
            if ($total_pages > 0 && $page + 1 >= $total_pages) {
                break;
            }
            if (count($batch) < $size) {
                break;
            }
            $page++;
        }
    }

    /**
     * Fetch ALL reviews as an array. Use sparingly — 914 reviews at Mint is
     * ~5s and ~10 round-trips. Prefer iter_reviews for streaming.
     *
     * @param int $size Page size.
     * @return array
     */
    public function all_reviews(int $size = 100): array
    {
        $out = [];
        foreach ($this->iter_reviews($size) as $r) {
            $out[] = $r;
        }
        return $out;
    }

    /**
     * Retrieve a single review.
     *
     * @param string $review_id
     * @return array|null Null if 404.
     */
    public function get_review(string $review_id): ?array
    {
        try {
            return $this->request('GET', $this->branch_path('/review/' . rawurlencode($review_id)));
        } catch (RuntimeException $e) {
            if (false !== strpos($e->getMessage(), '404')) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * List branches for the configured business (business-scoped — used to
     * discover the branch ID when the owner doesn't know it).
     *
     * @return array
     */
    public function list_branches(): array
    {
        return $this->request('GET', '/api/business/' . rawurlencode($this->business_id) . '/branch');
    }

    // ------------------------------------------------------------------

    /**
     * Build a branch-scoped path with optional query string.
     *
     * @param string $suffix Path suffix after /branch/{branchId}.
     * @param array  $query  Query params.
     * @return string
     */
    private function branch_path(string $suffix, array $query = []): string
    {
        $path = '/api/business/' . rawurlencode($this->business_id)
              . '/branch/' . rawurlencode($this->branch_id)
              . $suffix;
        if (!empty($query)) {
            $path .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }
        return $path;
    }

    /**
     * Perform an HTTP request via wp_remote_request.
     *
     * @param string $method GET|POST|...
     * @param string $path   Path under base_url (with leading /).
     * @return array Decoded JSON.
     * @throws RuntimeException On non-2xx, network error, or non-JSON.
     */
    private function request(string $method, string $path): array
    {
        $url = $this->base_url . $path;

        $resp = wp_remote_request($url, [
            'method'  => $method,
            'headers' => [
                'Authorization' => $this->auth_header,
                'Accept'        => 'application/json',
            ],
            'timeout' => $this->timeout,
        ]);

        if (is_wp_error($resp)) {
            throw new RuntimeException('Phorest request failed: ' . $resp->get_error_message());
        }

        $code    = (int) wp_remote_retrieve_response_code($resp);
        $body    = (string) wp_remote_retrieve_body($resp);
        $decoded = json_decode($body, true);

        if ($code < 200 || $code >= 300) {
            $snippet = function_exists('mb_substr') ? mb_substr($body, 0, 300) : substr($body, 0, 300);
            throw new RuntimeException("Phorest API {$code} for {$method} {$path} | body={$snippet}");
        }

        if (null === $decoded && '' !== trim($body)) {
            throw new RuntimeException("Phorest API returned non-JSON for {$method} {$path}");
        }
        return is_array($decoded) ? $decoded : [];
    }
}
