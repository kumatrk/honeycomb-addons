<?php

declare(strict_types=1);

namespace Honeycomb\Ringba;

use mysqli;
use SimpleKuma\Honeycomb\CredentialStore;

/**
 * Resolve the active Ringba API credential (one per install).
 */
final class RingbaCredentials
{
    public function __construct(private mysqli $db)
    {
    }

    /**
     * @return array{credential_id: int, account_id: string, api_token: string, label: string}|null
     */
    public function active(): ?array
    {
        $store = new CredentialStore($this->db);
        foreach ($store->listByAddon(RingbaCampaignFields::SLUG) as $meta) {
            if (($meta['status'] ?? '') !== 'active') {
                continue;
            }
            $full = $store->getById((int) ($meta['id'] ?? 0), true);
            if ($full === null) {
                continue;
            }
            $payload = is_array($full['payload'] ?? null) ? $full['payload'] : [];
            $accountId = trim((string) ($payload['account_id'] ?? ''));
            $token = trim((string) ($payload['api_token'] ?? ''));
            if ($accountId === '' || $token === '') {
                continue;
            }
            return [
                'credential_id' => (int) ($meta['id'] ?? 0),
                'account_id' => $accountId,
                'api_token' => $token,
                'label' => (string) ($meta['label'] ?? 'Ringba'),
            ];
        }
        return null;
    }

    public function client(): ?RingbaClient
    {
        $active = $this->active();
        if ($active === null) {
            return null;
        }
        return new RingbaClient($active['account_id'], $active['api_token']);
    }
}
