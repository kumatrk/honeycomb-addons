<?php

declare(strict_types=1);

namespace Honeycomb\Whop;

use mysqli;
use SimpleKuma\Honeycomb\BindingStore;
use SimpleKuma\Honeycomb\CostSyncJob;
use SimpleKuma\Honeycomb\CredentialStore;
use SimpleKuma\Honeycomb\HourlyCostStore;
use Throwable;

/**
 * Hourly Whop Ads spend sync → honeycomb_campaign_hourly_costs via Ad Reports API.
 *
 * @see https://docs.whop.com/api-reference/ad-reports/retrieve-ad-report
 */
final class WhopCostSyncJob implements CostSyncJob
{
    public const SLUG = 'whop-ads';

    public function __construct(private mysqli $db)
    {
    }

    public function slug(): string
    {
        return self::SLUG;
    }

    public function run(): array
    {
        $creds = new CredentialStore($this->db);
        $bindings = new BindingStore($this->db);
        $hourly = new HourlyCostStore($this->db);

        if (!$hourly->tableExists()) {
            return ['ok' => false, 'message' => 'honeycomb_campaign_hourly_costs missing (run migration 092).'];
        }

        $credentialRows = $creds->listByAddon(self::SLUG);
        if ($credentialRows === []) {
            return ['ok' => true, 'message' => 'No Whop credentials configured.'];
        }

        $allBindings = $bindings->listByAddon(self::SLUG);
        if ($allBindings === []) {
            return ['ok' => true, 'message' => 'No Whop campaign bindings yet.'];
        }

        $lookbackDays = 7;
        $today = gmdate('Y-m-d');
        $startDay = gmdate('Y-m-d', strtotime('-' . $lookbackDays . ' days'));
        $fromUtc = $startDay . 'T00:00:00.000Z';
        $toUtc = $today . 'T23:59:59.999Z';
        $synced = 0;
        $errors = [];

        foreach ($credentialRows as $credMeta) {
            if (($credMeta['status'] ?? '') !== 'active') {
                continue;
            }
            $credId = (int) $credMeta['id'];
            $full = $creds->getById($credId, true);
            if ($full === null) {
                continue;
            }
            /** @var array<string, mixed> $payload */
            $payload = is_array($full['payload'] ?? null) ? $full['payload'] : [];
            $apiKey = trim((string) ($payload['api_key'] ?? ''));
            $defaultAccount = trim((string) ($payload['account_id'] ?? ''));
            if ($apiKey === '') {
                $errors[] = 'Credential #' . $credId . ' missing api_key.';
                continue;
            }

            try {
                $client = new WhopClient($apiKey);
            } catch (Throwable $e) {
                $errors[] = 'Credential #' . $credId . ': ' . $e->getMessage();
                continue;
            }

            $credBindings = array_values(array_filter(
                $allBindings,
                static function (array $b) use ($credId): bool {
                    $extra = [];
                    if (isset($b['extra_json']) && is_string($b['extra_json'])) {
                        $decoded = json_decode($b['extra_json'], true);
                        $extra = is_array($decoded) ? $decoded : [];
                    } elseif (isset($b['extra']) && is_array($b['extra'])) {
                        $extra = $b['extra'];
                    }
                    $boundCred = (int) ($extra['credential_id'] ?? 0);
                    return $boundCred === 0 || $boundCred === $credId;
                }
            ));

            foreach ($credBindings as $binding) {
                $remoteAccount = trim((string) ($binding['remote_account_id'] ?? ''));
                $remoteCampaign = trim((string) ($binding['remote_campaign_id'] ?? ''));
                $kumaCampaignId = (int) ($binding['campaign_id'] ?? 0);
                if ($remoteCampaign === '') {
                    continue;
                }
                if ($remoteAccount === '') {
                    $remoteAccount = $defaultAccount;
                }
                if ($remoteAccount === '') {
                    $remoteAccount = 'whop';
                }

                try {
                    $rows = $client->campaignSpendByDay($remoteCampaign, $fromUtc, $toUtc, 'daily');
                    foreach ($rows as $row) {
                        $date = (string) ($row['date'] ?? '');
                        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                            continue;
                        }
                        $spent = (float) ($row['spend'] ?? 0);
                        $currency = isset($row['currency']) ? (string) $row['currency'] : null;
                        $hourly->upsertBucket(
                            self::SLUG,
                            $remoteAccount,
                            $remoteCampaign,
                            $date,
                            0,
                            $spent,
                            $spent,
                            $kumaCampaignId > 0 ? $kumaCampaignId : null,
                            $credId,
                            $currency,
                            'whop_ad_reports'
                        );
                        $synced++;
                    }
                } catch (Throwable $e) {
                    $errors[] = 'Campaign ' . $kumaCampaignId . ' / ' . $remoteCampaign . ': ' . $e->getMessage();
                }
            }
        }

        if ($errors !== []) {
            return [
                'ok' => $synced > 0,
                'message' => 'Synced ' . $synced . ' buckets; errors: ' . implode(' | ', array_slice($errors, 0, 5)),
            ];
        }

        return ['ok' => true, 'message' => 'Synced ' . $synced . ' Whop cost buckets.'];
    }
}
