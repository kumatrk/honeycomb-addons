<?php

declare(strict_types=1);

namespace Honeycomb\Whop;

use RuntimeException;

/**
 * Whop API client: Events (conversions) + Ad Reports (spend).
 *
 * @see https://docs.whop.com/developer/ads/events-api
 * @see https://docs.whop.com/api-reference/ad-reports/retrieve-ad-report
 */
final class WhopClient
{
    private const API_BASE = 'https://api.whop.com/api/v1';
    private const EVENTS_URL = self::API_BASE . '/events';
    private const AD_REPORTS_URL = self::API_BASE . '/ad_reports';

    public function __construct(private string $apiKey)
    {
        if (trim($apiKey) === '') {
            throw new RuntimeException('Whop API key is required.');
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{ok: bool, http_status: int, remote_id: ?string, error: ?string, body: string}
     */
    public function createEvent(array $payload): array
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return [
                'ok' => false,
                'http_status' => 0,
                'remote_id' => null,
                'error' => 'Failed to encode JSON payload.',
                'body' => '',
            ];
        }

        $result = $this->request('POST', self::EVENTS_URL, $json, true);
        $decoded = $result['decoded'];
        $remoteId = null;
        if (is_array($decoded) && isset($decoded['id']) && is_scalar($decoded['id'])) {
            $remoteId = (string) $decoded['id'];
        }

        return [
            'ok' => $result['ok'],
            'http_status' => $result['http_status'],
            'remote_id' => $remoteId,
            'error' => $result['error'],
            'body' => $result['body'],
        ];
    }

    /**
     * Daily (or hourly) spend report for one Whop ad campaign.
     *
     * @return list<array{date: string, spend: float, currency: ?string}>
     */
    public function campaignSpendByDay(
        string $adCampaignId,
        string $fromUtc,
        string $toUtc,
        string $granularity = 'daily'
    ): array {
        $adCampaignId = trim($adCampaignId);
        if ($adCampaignId === '') {
            throw new RuntimeException('Whop ad campaign id is required.');
        }

        $query = http_build_query([
            'from' => $fromUtc,
            'to' => $toUtc,
            'granularity' => $granularity,
        ]);
        $url = self::AD_REPORTS_URL . '?' . $query . '&ad_campaign_ids=' . rawurlencode($adCampaignId);

        $result = $this->request('GET', $url, null, false);
        if (!$result['ok']) {
            throw new RuntimeException($result['error'] ?? ('Whop ad_reports HTTP ' . $result['http_status']));
        }

        $decoded = $result['decoded'];
        if (!is_array($decoded)) {
            throw new RuntimeException('Whop ad_reports returned invalid JSON.');
        }

        return $this->parseGranularitySpend($decoded);
    }

    /**
     * @param array<string, mixed> $decoded
     * @return list<array{date: string, spend: float, currency: ?string}>
     */
    private function parseGranularitySpend(array $decoded): array
    {
        $series = $decoded['granularity'] ?? null;
        if (!is_array($series) || $series === []) {
            return [];
        }

        $out = [];
        foreach ($series as $row) {
            if (!is_array($row)) {
                continue;
            }
            $date = $this->bucketDate($row);
            if ($date === null) {
                continue;
            }
            $spend = (float) ($row['spend'] ?? 0);
            $currency = isset($row['spend_currency']) && is_scalar($row['spend_currency'])
                ? (string) $row['spend_currency']
                : null;
            $out[] = [
                'date' => $date,
                'spend' => $spend,
                'currency' => $currency !== '' ? $currency : null,
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function bucketDate(array $row): ?string
    {
        foreach (['stat_date', 'bucket_start', 'date'] as $key) {
            if (empty($row[$key]) || !is_scalar($row[$key])) {
                continue;
            }
            $raw = (string) $row[$key];
            if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $raw, $m) === 1) {
                return $m[1];
            }
        }
        return null;
    }

    /**
     * @return array{ok: bool, http_status: int, error: ?string, body: string, decoded: mixed}
     */
    private function request(string $method, string $url, ?string $jsonBody, bool $isJsonPost): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return [
                'ok' => false,
                'http_status' => 0,
                'error' => 'curl_init failed.',
                'body' => '',
                'decoded' => null,
            ];
        }

        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Accept: application/json',
        ];
        if ($isJsonPost) {
            $headers[] = 'Content-Type: application/json';
        }

        $opts = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_CONNECTTIMEOUT => 10,
        ];
        if ($jsonBody !== null) {
            $opts[CURLOPT_POSTFIELDS] = $jsonBody;
        }
        curl_setopt_array($ch, $opts);

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            return [
                'ok' => false,
                'http_status' => $status,
                'error' => 'Network error: ' . $err,
                'body' => is_string($body) ? self::sanitizeBody($body) : '',
                'decoded' => null,
            ];
        }

        $bodyStr = is_string($body) ? $body : '';
        $decoded = json_decode($bodyStr, true);
        $ok = $status >= 200 && $status < 300;
        $error = null;
        if (!$ok) {
            $error = self::extractErrorMessage($decoded, $status);
        }

        return [
            'ok' => $ok,
            'http_status' => $status,
            'error' => $error,
            'body' => self::sanitizeBody($bodyStr),
            'decoded' => $decoded,
        ];
    }

    /**
     * @param mixed $decoded
     */
    private static function extractErrorMessage($decoded, int $status): string
    {
        if (is_array($decoded)) {
            foreach (['message', 'error', 'detail'] as $key) {
                if (!empty($decoded[$key]) && is_scalar($decoded[$key])) {
                    return self::limit((string) $decoded[$key], 400);
                }
            }
        }
        return 'Whop API HTTP ' . $status;
    }

    private static function sanitizeBody(string $body): string
    {
        return self::limit(preg_replace('/Bearer\s+[A-Za-z0-9._\-]+/i', 'Bearer [REDACTED]', $body) ?? $body, 1000);
    }

    private static function limit(string $value, int $max): string
    {
        if (strlen($value) <= $max) {
            return $value;
        }
        return substr($value, 0, $max);
    }
}
