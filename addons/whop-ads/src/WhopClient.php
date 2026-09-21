<?php

declare(strict_types=1);

namespace Honeycomb\Whop;

use RuntimeException;

/**
 * Whop Events API client (server-side conversion reporting).
 *
 * @see https://docs.whop.com/developer/ads/events-api
 */
final class WhopClient
{
    private const EVENTS_URL = 'https://api.whop.com/api/v1/events';

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

        $ch = curl_init(self::EVENTS_URL);
        if ($ch === false) {
            return [
                'ok' => false,
                'http_status' => 0,
                'remote_id' => null,
                'error' => 'curl_init failed.',
                'body' => '',
            ];
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
        ]);

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            return [
                'ok' => false,
                'http_status' => $status,
                'remote_id' => null,
                'error' => 'Network error: ' . $err,
                'body' => is_string($body) ? self::sanitizeBody($body) : '',
            ];
        }

        $bodyStr = is_string($body) ? $body : '';
        $decoded = json_decode($bodyStr, true);
        $remoteId = null;
        if (is_array($decoded) && isset($decoded['id']) && is_scalar($decoded['id'])) {
            $remoteId = (string) $decoded['id'];
        }

        $ok = $status >= 200 && $status < 300;
        $error = null;
        if (!$ok) {
            $error = self::extractErrorMessage($decoded, $status);
        }

        return [
            'ok' => $ok,
            'http_status' => $status,
            'remote_id' => $remoteId,
            'error' => $error,
            'body' => self::sanitizeBody($bodyStr),
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
