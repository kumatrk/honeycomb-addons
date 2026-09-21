<?php

declare(strict_types=1);

namespace Honeycomb\Whop;

use mysqli;
use SimpleKuma\Honeycomb\BindingStore;
use SimpleKuma\Honeycomb\ConversionExportProvider;
use SimpleKuma\Honeycomb\CredentialStore;

/**
 * Sends qualifying conversions to Whop Events API.
 *
 * @see https://docs.whop.com/developer/ads/events-api
 * @see https://docs.whop.com/manage-your-business/growth-marketing/ads
 */
final class WhopConversionExporter implements ConversionExportProvider
{
    public const SLUG = 'whop-ads';
    private const EVENT_WINDOW_DAYS = 28;

    public function __construct(private mysqli $db)
    {
    }

    public function addonSlug(): string
    {
        return self::SLUG;
    }

    public function label(): string
    {
        return 'Whop Ads (Events API)';
    }

    public function exportConversion(array $conversion): array
    {
        $conversionId = (int) ($conversion['id'] ?? 0);
        $campaignId = (int) ($conversion['campaign_id'] ?? 0);
        if ($conversionId < 1 || $campaignId < 1) {
            return ['ok' => false, 'status' => 'skipped', 'message' => 'Missing conversion or campaign id.'];
        }

        $binding = (new BindingStore($this->db))->getForCampaignAddon($campaignId, self::SLUG);
        if ($binding === null) {
            return ['ok' => true, 'status' => 'skipped', 'message' => 'No Whop binding for campaign.'];
        }
        $extra = is_array($binding['extra'] ?? null) ? $binding['extra'] : [];
        if (empty($extra['conversion_export'])) {
            return ['ok' => true, 'status' => 'skipped', 'message' => 'Whop conversion export disabled for campaign.'];
        }

        $eventName = trim((string) ($extra['event_name'] ?? 'lead'));
        if ($eventName === '') {
            $eventName = 'lead';
        }
        // Never send purchase for affiliate lead value (Whop ROAS is purchase-based).
        if (strtolower($eventName) === 'purchase') {
            $eventName = 'lead';
        }

        $eventId = 'kuma_conversion_' . $conversionId;
        $store = new WhopEventStore($this->db);
        $row = $store->createPending(
            $conversionId,
            isset($conversion['click_id']) ? (string) $conversion['click_id'] : null,
            $campaignId,
            $eventName,
            $eventId
        );

        if (($row['status'] ?? '') === 'delivered') {
            return ['ok' => true, 'status' => 'delivered', 'message' => 'Already delivered (idempotent).'];
        }

        $clickExtra = $conversion['extra_json'] ?? [];
        if (is_string($clickExtra)) {
            $decoded = json_decode($clickExtra, true);
            $clickExtra = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($clickExtra)) {
            $clickExtra = [];
        }

        $eventsUrl = 'https://api.whop.com/api/v1/events';

        $wuid = $this->resolveWuid($clickExtra);
        if ($wuid === null) {
            $reason = 'Missing Whop visitor id (_wuid). Install Whop Pixel and ensure cookie is captured on click.';
            $store->markSkipped((int) $row['id'], $reason);
            return $this->loggableResult(false, 'skipped', $reason, $eventsUrl, 0, [
                'event_name' => $eventName,
                'event_id' => $eventId,
                'error' => $reason,
                'hint' => 'Ad → owned LP with Whop Pixel → CTA must pass _wuid + whop_page_url into Kuma.',
            ], null);
        }

        $landingUrl = $this->resolveLandingUrl($clickExtra, $binding, $conversion);
        if ($landingUrl === null) {
            $reason = 'Missing original landing URL.';
            $store->markSkipped((int) $row['id'], $reason);
            return $this->loggableResult(false, 'skipped', $reason, $eventsUrl, 0, [
                'event_name' => $eventName,
                'event_id' => $eventId,
                'anonymous_id' => $wuid,
                'error' => $reason,
            ], null);
        }

        $eventTime = $this->resolveEventTime($conversion);
        if ($eventTime === null) {
            $reason = 'Conversion older than ' . self::EVENT_WINDOW_DAYS . ' days or invalid timestamp.';
            $store->markSkipped((int) $row['id'], $reason);
            return $this->loggableResult(false, 'expired', 'Event outside Whop acceptance window.', $eventsUrl, 0, [
                'event_name' => $eventName,
                'event_id' => $eventId,
                'url' => $landingUrl,
                'error' => $reason,
            ], null);
        }

        $creds = $this->resolveCredentials($extra, $binding);
        if ($creds === null) {
            $reason = 'Missing Whop API credentials.';
            $store->markFailure((int) $row['id'], 'permanently_failed', 0, $reason, null);
            return $this->loggableResult(false, 'permanently_failed', $reason, $eventsUrl, 0, [
                'event_name' => $eventName,
                'event_id' => $eventId,
                'url' => $landingUrl,
                'error' => $reason,
            ], null);
        }

        $accountId = trim((string) ($creds['account_id'] ?? ''));
        if ($accountId === '' || !str_starts_with($accountId, 'biz_')) {
            $reason = 'Invalid Whop account_id on credential (expected biz_…).';
            $store->markFailure((int) $row['id'], 'permanently_failed', 0, $reason, null);
            return $this->loggableResult(false, 'permanently_failed', 'Invalid account_id on credential.', $eventsUrl, 0, [
                'event_name' => $eventName,
                'event_id' => $eventId,
                'account_id' => $accountId,
                'error' => $reason,
            ], null);
        }

        $apiKey = trim((string) ($creds['api_key'] ?? ''));
        if ($apiKey === '') {
            $reason = 'Missing Whop API key.';
            $store->markFailure((int) $row['id'], 'permanently_failed', 0, $reason, null);
            return $this->loggableResult(false, 'permanently_failed', $reason, $eventsUrl, 0, [
                'account_id' => $accountId,
                'event_name' => $eventName,
                'event_id' => $eventId,
                'error' => $reason,
            ], null);
        }

        $payload = [
            'account_id' => $accountId,
            'event_name' => $eventName,
            'event_id' => $eventId,
            'event_time' => $eventTime,
            'action_source' => 'website',
            'url' => $landingUrl,
            'user' => [
                'anonymous_id' => $wuid,
            ],
        ];

        $value = $this->resolveValue($conversion);
        if ($value !== null) {
            $payload['value'] = $value;
            $currency = strtolower(trim((string) ($conversion['currency'] ?? 'usd')));
            if ($currency === '') {
                $currency = 'usd';
            }
            $payload['currency'] = $currency;
        }

        $context = [];
        $fbclid = $this->resolveFbclid($clickExtra);
        if ($fbclid !== null) {
            $context['fbclid'] = $fbclid;
        }
        foreach (['utm_source', 'utm_medium', 'utm_placement', 'utm_content', 'utm_adset', 'utm_whop'] as $utmKey) {
            $utmVal = $this->resolveParam($clickExtra, $utmKey);
            if ($utmVal !== null) {
                $context[$utmKey] = $utmVal;
            }
        }
        $fbp = $this->resolveCookie($clickExtra, '_fbp');
        if ($fbp !== null) {
            $context['fbp'] = $fbp;
        }
        $fbc = $this->resolveCookie($clickExtra, '_fbc');
        if ($fbc !== null) {
            $context['fbc'] = $fbc;
        }
        $ip = trim((string) ($conversion['ip'] ?? ''));
        if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
            $context['ip_address'] = $ip;
        }
        $ua = trim((string) ($conversion['ua'] ?? ''));
        if ($ua !== '') {
            $context['user_agent'] = substr($ua, 0, 512);
        }
        if ($context !== []) {
            $payload['context'] = $context;
        }

        try {
            $client = new WhopClient($apiKey);
            $result = $client->createEvent($payload);
        } catch (\Throwable $e) {
            $store->markFailure((int) $row['id'], 'retrying', 0, $e->getMessage(), date('Y-m-d H:i:s', time() + 300));
            return $this->loggableResult(false, 'retrying', $e->getMessage(), $eventsUrl, 0, $payload, null);
        }

        if ($result['ok']) {
            $store->markDelivered((int) $row['id'], $result['remote_id'], $result['http_status']);
            return $this->loggableResult(
                true,
                'delivered',
                'Sent to Whop Events API.',
                $eventsUrl,
                (int) $result['http_status'],
                $payload,
                (string) ($result['body'] ?? '')
            );
        }

        $http = (int) ($result['http_status'] ?? 0);
        $err = (string) ($result['error'] ?? 'Unknown Whop error');
        $permanent = in_array($http, [400, 401, 403, 404, 422], true);
        if ($permanent) {
            $store->markFailure((int) $row['id'], 'permanently_failed', $http, $err, null);
            return $this->loggableResult(false, 'permanently_failed', $err, $eventsUrl, $http, $payload, (string) ($result['body'] ?? ''));
        }

        $store->markFailure((int) $row['id'], 'retrying', $http, $err, date('Y-m-d H:i:s', time() + 300));
        return $this->loggableResult(false, 'retrying', $err, $eventsUrl, $http, $payload, (string) ($result['body'] ?? ''));
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>
     */
    private function loggableResult(
        bool $ok,
        string $status,
        string $message,
        string $url,
        int $httpStatus,
        ?array $payload,
        ?string $responseBody
    ): array {
        $requestBody = null;
        if ($payload !== null) {
            $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            $requestBody = $encoded !== false ? $encoded : null;
        }
        return [
            'ok' => $ok,
            'status' => $status,
            'message' => $message,
            'url' => $url,
            'http_status' => $httpStatus,
            'request_body' => $requestBody,
            'response_body' => $responseBody !== null && $responseBody !== '' ? $responseBody : null,
        ];
    }

    /**
     * @param array<string, mixed> $clickExtra
     */
    private function resolveWuid(array $clickExtra): ?string
    {
        $candidates = [
            $clickExtra['whop_visitor_id'] ?? null,
            $clickExtra['cookies']['_wuid'] ?? null,
            $clickExtra['all_params']['_wuid'] ?? null,
            $clickExtra['all_params']['whop_visitor_id'] ?? null,
            $clickExtra['traffic_source_tokens']['_wuid'] ?? null,
        ];
        foreach ($candidates as $c) {
            if (!is_scalar($c)) {
                continue;
            }
            $v = trim((string) $c);
            if ($v !== '' && strlen($v) <= 512) {
                return $v;
            }
        }
        return null;
    }

    /**
     * Prefer the owned LP URL (with Whop/Meta query string). Fallbacks keep attribution params.
     *
     * @param array<string, mixed> $clickExtra
     * @param array<string, mixed> $binding
     * @param array<string, mixed> $conversion
     */
    private function resolveLandingUrl(array $clickExtra, array $binding, array $conversion): ?string
    {
        $candidates = [
            $clickExtra['whop_page_url'] ?? null,
            $clickExtra['original_landing_url'] ?? null,
            $clickExtra['all_params']['whop_page_url'] ?? null,
            $clickExtra['all_params']['whop_landing_url'] ?? null,
        ];
        foreach ($candidates as $c) {
            if (!is_scalar($c)) {
                continue;
            }
            $v = trim((string) $c);
            if ($v !== '' && str_starts_with($v, 'http') && strlen($v) <= 2048) {
                return $v;
            }
        }

        $rebuilt = $this->rebuildAttributionUrl($clickExtra, $conversion);
        if ($rebuilt !== null) {
            return $rebuilt;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $clickExtra
     * @param array<string, mixed> $conversion
     */
    private function rebuildAttributionUrl(array $clickExtra, array $conversion): ?string
    {
        $base = null;
        foreach (['landing_page_url', 'offer_url'] as $key) {
            $candidate = trim((string) ($conversion[$key] ?? ''));
            if ($candidate !== '' && str_starts_with($candidate, 'http')) {
                $base = $candidate;
                break;
            }
        }
        if ($base === null) {
            return null;
        }

        $keys = [
            'wacid', 'wasid', 'waid', 'fbclid',
            'utm_meta_ad_id', 'utm_meta_adset_id', 'utm_meta_campaign_id',
            'utm_source', 'utm_medium', 'utm_placement', 'utm_content', 'utm_adset', 'utm_whop',
            'tw_source', 'tw_adid',
        ];
        $query = [];
        foreach ($keys as $key) {
            $val = $this->resolveParam($clickExtra, $key);
            if ($val !== null) {
                $query[$key] = $val;
            }
        }
        if ($query === []) {
            return null;
        }

        $parts = parse_url($base);
        if ($parts === false || empty($parts['host'])) {
            return null;
        }
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'];
        $path = $parts['path'] ?? '/';
        $existing = [];
        if (!empty($parts['query'])) {
            parse_str((string) $parts['query'], $existing);
        }
        $merged = array_merge($existing, $query);
        $url = $scheme . '://' . $host . $path . '?' . http_build_query($merged);
        return strlen($url) <= 2048 ? $url : substr($url, 0, 2048);
    }

    /**
     * @param array<string, mixed> $clickExtra
     */
    private function resolveFbclid(array $clickExtra): ?string
    {
        return $this->resolveParam($clickExtra, 'fbclid');
    }

    /**
     * @param array<string, mixed> $clickExtra
     */
    private function resolveParam(array $clickExtra, string $key): ?string
    {
        $candidates = [
            $clickExtra['traffic_source_tokens'][$key] ?? null,
            $clickExtra['all_params'][$key] ?? null,
            $clickExtra['custom_tokens'][$key]['value'] ?? null,
        ];
        foreach ($candidates as $c) {
            if (!is_scalar($c)) {
                continue;
            }
            $v = trim((string) $c);
            if ($v !== '' && strlen($v) <= 512) {
                return $v;
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $clickExtra
     */
    private function resolveCookie(array $clickExtra, string $key): ?string
    {
        $candidates = [
            $clickExtra['cookies'][$key] ?? null,
            $clickExtra['all_params'][$key] ?? null,
        ];
        foreach ($candidates as $c) {
            if (!is_scalar($c)) {
                continue;
            }
            $v = trim((string) $c);
            if ($v !== '' && strlen($v) <= 512) {
                return $v;
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $conversion
     */
    private function resolveEventTime(array $conversion): ?string
    {
        $raw = $conversion['ts'] ?? $conversion['created_at'] ?? null;
        if (!is_scalar($raw) || trim((string) $raw) === '') {
            return null;
        }
        $ts = strtotime((string) $raw);
        if ($ts === false) {
            return null;
        }
        $ageDays = (time() - $ts) / 86400;
        if ($ageDays > self::EVENT_WINDOW_DAYS) {
            return null;
        }
        return gmdate('Y-m-d\TH:i:s\Z', $ts);
    }

    /**
     * @param array<string, mixed> $conversion
     */
    private function resolveValue(array $conversion): ?float
    {
        foreach (['payout', 'value'] as $key) {
            if (!isset($conversion[$key]) || $conversion[$key] === '' || $conversion[$key] === null) {
                continue;
            }
            if (!is_numeric($conversion[$key])) {
                continue;
            }
            $n = round((float) $conversion[$key], 2);
            if ($n > 0) {
                return $n;
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $extra
     * @param array<string, mixed> $binding
     * @return array<string, mixed>|null
     */
    private function resolveCredentials(array $extra, array $binding): ?array
    {
        $creds = new CredentialStore($this->db);
        $credId = (int) ($extra['credential_id'] ?? 0);
        if ($credId < 1) {
            foreach ($creds->listByAddon(self::SLUG) as $meta) {
                if (($meta['status'] ?? '') === 'active') {
                    $credId = (int) $meta['id'];
                    break;
                }
            }
        }
        if ($credId < 1) {
            return null;
        }
        $full = $creds->getById($credId, true);
        if ($full === null || ($full['status'] ?? '') !== 'active') {
            return null;
        }
        $payload = is_array($full['payload'] ?? null) ? $full['payload'] : [];
        return $payload;
    }
}
