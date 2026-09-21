<?php

declare(strict_types=1);

namespace Honeycomb\Taboola;

use RuntimeException;

/**
 * Minimal Taboola Backstage API client (client-credentials OAuth + reports).
 *
 * @see https://developers.taboola.com/backstage-api/reference/welcome
 */
final class TaboolaClient
{
    private const TOKEN_URL = 'https://backstage.taboola.com/backstage/oauth/token';
    private const API_BASE = 'https://backstage.taboola.com/backstage/api/1.0';

    private ?string $accessToken = null;
    private int $tokenExpiresAt = 0;

    public function __construct(
        private string $clientId,
        private string $clientSecret
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getCurrentAccount(): array
    {
        return $this->getJson('/users/current/account');
    }

    /**
     * Campaign summary by day for one campaign (standard report).
     * Data can lag a few hours; figures may adjust retroactively.
     *
     * @return list<array<string, mixed>>
     */
    public function campaignSummaryByDay(
        string $accountId,
        string $startDate,
        string $endDate,
        string $campaignId
    ): array {
        $path = '/' . rawurlencode($accountId)
            . '/reports/campaign-summary/dimensions/day'
            . '?start_date=' . rawurlencode($startDate)
            . '&end_date=' . rawurlencode($endDate)
            . '&campaign=' . rawurlencode($campaignId);
        $body = $this->getJson($path);
        $results = $body['results'] ?? [];
        return is_array($results) ? array_values($results) : [];
    }

    /**
     * Near-real-time spend for today (max 24h window), by campaign.
     *
     * @return list<array<string, mixed>>
     */
    public function realtimeByCampaign(
        string $accountId,
        string $startDateTime,
        string $endDateTime,
        ?string $campaignId = null
    ): array {
        $path = '/' . rawurlencode($accountId)
            . '/reports/realtime-campaign-summary/dimensions/by_campaign'
            . '?start_date=' . rawurlencode($startDateTime)
            . '&end_date=' . rawurlencode($endDateTime);
        if ($campaignId !== null && $campaignId !== '') {
            $path .= '&campaign=' . rawurlencode($campaignId);
        }
        $body = $this->getJson($path);
        $results = $body['results'] ?? [];
        return is_array($results) ? array_values($results) : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listCampaigns(string $accountId, int $page = 1, int $pageSize = 100): array
    {
        $path = '/' . rawurlencode($accountId)
            . '/campaigns/base?page=' . $page . '&page_size=' . $pageSize;
        $body = $this->getJson($path);
        $results = $body['results'] ?? $body;
        if (!is_array($results)) {
            return [];
        }
        // Some responses wrap in results; base may return a list directly.
        if (isset($results[0]) || $results === []) {
            return array_values($results);
        }
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function getJson(string $path): array
    {
        $this->ensureToken();
        $url = self::API_BASE . $path;
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Could not init Taboola HTTP client.');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->accessToken,
                'Accept: application/json',
            ],
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            throw new RuntimeException('Taboola HTTP error: ' . $err);
        }
        $decoded = json_decode($raw, true);
        if ($code >= 400) {
            $msg = is_array($decoded) ? (string) ($decoded['message'] ?? $raw) : $raw;
            throw new RuntimeException('Taboola API ' . $code . ': ' . $msg);
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('Taboola API returned non-JSON.');
        }
        return $decoded;
    }

    private function ensureToken(): void
    {
        if ($this->accessToken !== null && time() < ($this->tokenExpiresAt - 60)) {
            return;
        }
        $ch = curl_init(self::TOKEN_URL);
        if ($ch === false) {
            throw new RuntimeException('Could not init Taboola token request.');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS => http_build_query([
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'grant_type' => 'client_credentials',
            ]),
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            throw new RuntimeException('Taboola token HTTP error: ' . $err);
        }
        $decoded = json_decode($raw, true);
        if ($code >= 400 || !is_array($decoded) || empty($decoded['access_token'])) {
            $msg = is_array($decoded)
                ? (string) ($decoded['error_description'] ?? $decoded['error'] ?? $raw)
                : $raw;
            throw new RuntimeException('Taboola auth failed: ' . $msg);
        }
        $this->accessToken = (string) $decoded['access_token'];
        $expiresIn = (int) ($decoded['expires_in'] ?? 43200);
        $this->tokenExpiresAt = time() + max(60, $expiresIn);
    }
}
