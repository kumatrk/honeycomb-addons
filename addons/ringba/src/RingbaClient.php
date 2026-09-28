<?php

declare(strict_types=1);

namespace Honeycomb\Ringba;

use RuntimeException;

/**
 * Ringba REST client (v2). Auth: Authorization: Token {apiAccessToken}
 *
 * @see https://support.ringba.com/hc/en-us/articles/31661837696023-API-Access-Tokens
 */
final class RingbaClient
{
    private const API_BASE = 'https://api.ringba.com/v2';

    public function __construct(
        private string $accountId,
        private string $apiToken,
    ) {
        $this->accountId = trim($accountId);
        $this->apiToken = trim($apiToken);
        if ($this->accountId === '' || $this->apiToken === '') {
            throw new RuntimeException('Ringba account ID and API token are required.');
        }
    }

    /**
     * @return array{ok: bool, message: string, campaign_count: int}
     */
    public function testConnection(): array
    {
        try {
            $campaigns = $this->listCampaigns();
            $n = count($campaigns);
            return [
                'ok' => true,
                'message' => $n === 0
                    ? 'Connected. No campaigns returned yet.'
                    : ('Connected. Found ' . $n . ' campaign' . ($n === 1 ? '' : 's') . '.'),
                'campaign_count' => $n,
            ];
        } catch (RuntimeException $e) {
            return [
                'ok' => false,
                'message' => $e->getMessage(),
                'campaign_count' => 0,
            ];
        }
    }

    /**
     * @return list<array{id: string, name: string, enabled: bool, raw: array<string, mixed>}>
     */
    public function listCampaigns(): array
    {
        $decoded = $this->requestJson('GET', '/' . rawurlencode($this->accountId) . '/campaigns');
        $rows = $this->extractList($decoded, ['campaigns', 'Campaigns', 'items', 'data', 'result']);

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = $this->scalarString($row['id'] ?? $row['Id'] ?? $row['campaignId'] ?? $row['CampaignId'] ?? null);
            if ($id === '') {
                continue;
            }
            $name = $this->scalarString(
                $row['name'] ?? $row['Name'] ?? $row['campaignName'] ?? $row['CampaignName'] ?? $id
            );
            $enabled = true;
            if (array_key_exists('enabled', $row)) {
                $enabled = (bool) $row['enabled'];
            } elseif (array_key_exists('Enabled', $row)) {
                $enabled = (bool) $row['Enabled'];
            } elseif (isset($row['status']) && is_string($row['status'])) {
                $enabled = !in_array(strtolower($row['status']), ['paused', 'disabled', 'inactive', 'archived'], true);
            }
            $out[] = [
                'id' => $id,
                'name' => $name !== '' ? $name : $id,
                'enabled' => $enabled,
                'raw' => $row,
            ];
        }

        usort(
            $out,
            static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name'])
        );

        return $out;
    }

    /**
     * Call-tracking / JS tags for one campaign (best-effort; script src is usually the campaign id).
     *
     * @return list<array{id: string, label: string, script_id: string, number_to_replace: string}>
     */
    public function listCampaignTags(string $campaignId): array
    {
        $campaignId = trim($campaignId);
        if ($campaignId === '') {
            return [];
        }

        $decoded = $this->requestJson(
            'GET',
            '/' . rawurlencode($this->accountId) . '/campaigns/' . rawurlencode($campaignId) . '/Tags'
        );
        $rows = $this->extractList($decoded, ['tags', 'Tags', 'items', 'data', 'result', 'jsTags', 'JsTags']);

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = $this->scalarString(
                $row['id'] ?? $row['Id'] ?? $row['jsTagId'] ?? $row['JsTagId'] ?? $row['tagId'] ?? null
            );
            $scriptId = $this->extractScriptId($row) ?: $campaignId;
            $number = $this->scalarString(
                $row['numberToReplace'] ?? $row['NumberToReplace'] ?? $row['number'] ?? $row['Number'] ?? null
            );
            $label = $this->scalarString(
                $row['name'] ?? $row['Name'] ?? $row['publisherName'] ?? $row['PublisherName'] ?? $scriptId
            );
            if ($id === '' && $scriptId === '') {
                continue;
            }
            $out[] = [
                'id' => $id !== '' ? $id : $scriptId,
                'label' => $label !== '' ? $label : $scriptId,
                'script_id' => $scriptId,
                'number_to_replace' => $number,
            ];
        }

        // Always offer the campaign id itself (Ringba install snippet uses campaign id).
        $hasCampaignScript = false;
        foreach ($out as $t) {
            if (strcasecmp($t['script_id'], $campaignId) === 0) {
                $hasCampaignScript = true;
                break;
            }
        }
        if (!$hasCampaignScript) {
            array_unshift($out, [
                'id' => $campaignId,
                'label' => 'Campaign script (' . $campaignId . ')',
                'script_id' => $campaignId,
                'number_to_replace' => '',
            ]);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function extractScriptId(array $row): string
    {
        foreach (['scriptSrc', 'ScriptSrc', 'scriptUrl', 'ScriptUrl', 'src', 'Src', 'jsUrl', 'JsUrl'] as $key) {
            $v = $this->scalarString($row[$key] ?? null);
            if ($v === '') {
                continue;
            }
            if (preg_match('#b-js\.ringba\.com/([A-Za-z0-9_-]+)#', $v, $m)) {
                return $m[1];
            }
        }
        foreach (['campaignId', 'CampaignId', 'jsTagId', 'JsTagId', 'id', 'Id'] as $key) {
            $v = $this->scalarString($row[$key] ?? null);
            if ($v !== '' && preg_match('/^CA[A-Za-z0-9_-]+/', $v)) {
                return $v;
            }
        }
        return '';
    }

    /**
     * @param array<string, mixed> $decoded
     * @param list<string> $listKeys
     * @return list<mixed>
     */
    private function extractList(array $decoded, array $listKeys): array
    {
        // Common Ringba envelope: { isSuccessful, …, campaigns: [...] }
        foreach ($listKeys as $key) {
            if (isset($decoded[$key]) && is_array($decoded[$key])) {
                $candidate = $decoded[$key];
                if ($this->isList($candidate)) {
                    return $candidate;
                }
                // Nested { campaigns: { items: [...] } }
                foreach (['items', 'Items', 'data', 'Data', 'list', 'List'] as $inner) {
                    if (isset($candidate[$inner]) && is_array($candidate[$inner]) && $this->isList($candidate[$inner])) {
                        return $candidate[$inner];
                    }
                }
            }
        }

        if ($this->isList($decoded)) {
            return $decoded;
        }

        // Sometimes the payload is under "result"
        if (isset($decoded['result']) && is_array($decoded['result'])) {
            return $this->extractList($decoded['result'], $listKeys);
        }

        return [];
    }

    /**
     * @param array<mixed> $arr
     */
    private function isList(array $arr): bool
    {
        if ($arr === []) {
            return true;
        }
        return array_keys($arr) === range(0, count($arr) - 1);
    }

    private function scalarString(mixed $v): string
    {
        if ($v === null || is_array($v) || is_object($v)) {
            return '';
        }
        return trim((string) $v);
    }

    /**
     * @return array<string, mixed>
     */
    private function requestJson(string $method, string $path): array
    {
        $url = self::API_BASE . $path;
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => [
                'Authorization: Token ' . $this->apiToken,
                'Accept: application/json',
                'Content-Type: application/json',
                'User-Agent: SimpleKuma-Honeycomb-Ringba/1.0',
            ],
        ]);

        $body = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err !== '') {
            throw new RuntimeException('Ringba network error: ' . $err);
        }
        if (!is_string($body)) {
            throw new RuntimeException('Ringba returned an empty response (HTTP ' . $http . ').');
        }
        if ($http === 401 || $http === 403) {
            throw new RuntimeException('Ringba auth failed (HTTP ' . $http . '). Check account ID and API access token.');
        }
        if ($http < 200 || $http >= 300) {
            $snip = substr(preg_replace('/\s+/', ' ', $body) ?? $body, 0, 180);
            throw new RuntimeException('Ringba HTTP ' . $http . ($snip !== '' ? (': ' . $snip) : ''));
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Ringba returned non-JSON.');
        }

        if (isset($decoded['isSuccessful']) && $decoded['isSuccessful'] === false) {
            $msg = $this->scalarString($decoded['message'] ?? $decoded['Message'] ?? 'Ringba request failed.');
            throw new RuntimeException($msg !== '' ? $msg : 'Ringba request failed.');
        }

        return $decoded;
    }
}
