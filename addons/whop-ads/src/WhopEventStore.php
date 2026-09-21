<?php

declare(strict_types=1);

namespace Honeycomb\Whop;

use mysqli;

/**
 * Durable dispatch rows for Whop Events API deliveries.
 */
final class WhopEventStore
{
    public const SLUG = 'whop-ads';

    public function __construct(private mysqli $db)
    {
    }

    public function ensureTable(): void
    {
        $check = $this->db->query("SHOW TABLES LIKE 'honeycomb_conversion_exports'");
        if ($check && $check->num_rows > 0) {
            return;
        }
        $this->db->query(
            "CREATE TABLE IF NOT EXISTS honeycomb_conversion_exports (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                addon_slug VARCHAR(64) NOT NULL,
                conversion_id BIGINT UNSIGNED NOT NULL,
                click_id VARCHAR(64) NULL,
                campaign_id INT UNSIGNED NULL,
                event_name VARCHAR(64) NOT NULL,
                event_id VARCHAR(191) NOT NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'pending',
                attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
                last_http_status INT NULL,
                last_error VARCHAR(512) NULL,
                remote_event_id VARCHAR(191) NULL,
                delivered_at DATETIME NULL,
                next_attempt_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_honeycomb_export_event (addon_slug, event_name, event_id),
                KEY idx_honeycomb_export_conversion (conversion_id),
                KEY idx_honeycomb_export_status (status, next_attempt_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByEvent(string $eventName, string $eventId): ?array
    {
        $this->ensureTable();
        $slug = self::SLUG;
        $stmt = $this->db->prepare(
            'SELECT * FROM honeycomb_conversion_exports
             WHERE addon_slug = ? AND event_name = ? AND event_id = ?
             LIMIT 1'
        );
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('sss', $slug, $eventName, $eventId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    public function createPending(
        int $conversionId,
        ?string $clickId,
        ?int $campaignId,
        string $eventName,
        string $eventId
    ): array {
        $this->ensureTable();
        $existing = $this->findByEvent($eventName, $eventId);
        if ($existing !== null) {
            return $existing;
        }

        $slug = self::SLUG;
        $status = 'pending';
        $stmt = $this->db->prepare(
            'INSERT INTO honeycomb_conversion_exports
                (addon_slug, conversion_id, click_id, campaign_id, event_name, event_id, status)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        if ($stmt === false) {
            throw new \RuntimeException('Could not prepare Whop export insert.');
        }
        $clickId = $clickId ?? '';
        $campaignIdVal = $campaignId ?? 0;
        $stmt->bind_param(
            'sisisss',
            $slug,
            $conversionId,
            $clickId,
            $campaignIdVal,
            $eventName,
            $eventId,
            $status
        );
        if (!$stmt->execute()) {
            $stmt->close();
            // Race: unique key — reload
            $again = $this->findByEvent($eventName, $eventId);
            if ($again !== null) {
                return $again;
            }
            throw new \RuntimeException('Could not create Whop export row.');
        }
        $id = (int) $stmt->insert_id;
        $stmt->close();
        $row = $this->findByEvent($eventName, $eventId);
        return $row ?? [
            'id' => $id,
            'status' => $status,
            'event_name' => $eventName,
            'event_id' => $eventId,
            'attempt_count' => 0,
        ];
    }

    public function markDelivered(int $id, ?string $remoteId, int $httpStatus): void
    {
        $this->ensureTable();
        $status = 'delivered';
        $stmt = $this->db->prepare(
            'UPDATE honeycomb_conversion_exports
             SET status = ?, attempt_count = attempt_count + 1, last_http_status = ?,
                 remote_event_id = ?, last_error = NULL, delivered_at = NOW(), next_attempt_at = NULL
             WHERE id = ?'
        );
        if ($stmt === false) {
            return;
        }
        $stmt->bind_param('sisi', $status, $httpStatus, $remoteId, $id);
        $stmt->execute();
        $stmt->close();
    }

    public function markFailure(
        int $id,
        string $status,
        int $httpStatus,
        string $error,
        ?string $nextAttemptAt = null
    ): void {
        $this->ensureTable();
        $error = substr($error, 0, 512);
        $stmt = $this->db->prepare(
            'UPDATE honeycomb_conversion_exports
             SET status = ?, attempt_count = attempt_count + 1, last_http_status = ?,
                 last_error = ?, next_attempt_at = ?
             WHERE id = ?'
        );
        if ($stmt === false) {
            return;
        }
        $stmt->bind_param('sissi', $status, $httpStatus, $error, $nextAttemptAt, $id);
        $stmt->execute();
        $stmt->close();
    }

    public function markSkipped(int $id, string $reason): void
    {
        $this->markFailure($id, 'skipped', 0, $reason, null);
    }
}
