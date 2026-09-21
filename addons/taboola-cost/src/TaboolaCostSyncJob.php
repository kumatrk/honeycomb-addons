<?php

declare(strict_types=1);

namespace Honeycomb\Taboola;

use mysqli;
use SimpleKuma\Honeycomb\BindingStore;
use SimpleKuma\Honeycomb\CostSyncJob;
use SimpleKuma\Honeycomb\CredentialStore;
use SimpleKuma\Honeycomb\HourlyCostStore;
use Throwable;

/**
 * Hourly Taboola spend sync → honeycomb_campaign_hourly_costs (day buckets + today realtime).
 */
final class TaboolaCostSyncJob implements CostSyncJob
{
    public const SLUG = 'taboola-cost';

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
            return ['ok' => true, 'message' => 'No Taboola credentials configured.'];
        }

        $allBindings = $bindings->listByAddon(self::SLUG);
        if ($allBindings === []) {
            return ['ok' => true, 'message' => 'No Taboola campaign bindings yet.'];
        }

        $lookbackDays = 7;
        $today = gmdate('Y-m-d');
        $startDay = gmdate('Y-m-d', strtotime('-' . $lookbackDays . ' days'));
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
            $clientId = trim((string) ($payload['client_id'] ?? ''));
            $clientSecret = trim((string) ($payload['client_secret'] ?? ''));
            $defaultAccount = trim((string) ($payload['account_id'] ?? ''));
            if ($clientId === '' || $clientSecret === '') {
                $errors[] = 'Credential #' . $credId . ' missing client_id/secret.';
                continue;
            }

            try {
                $client = new TaboolaClient($clientId, $clientSecret);
                if ($defaultAccount === '') {
                    $account = $client->getCurrentAccount();
                    $defaultAccount = trim((string) ($account['account_id'] ?? ''));
                }
            } catch (Throwable $e) {
                $errors[] = 'Credential #' . $credId . ': ' . $e->getMessage();
                continue;
            }

            $credBindings = array_values(array_filter(
                $allBindings,
                static function (array $b) use ($credId, $payload): bool {
                    $extra = [];
                    if (isset($b['extra_json']) && is_string($b['extra_json'])) {
                        $decoded = json_decode($b['extra_json'], true);
                        $extra = is_array($decoded) ? $decoded : [];
                    }
                    $boundCred = (int) ($extra['credential_id'] ?? 0);
                    // If binding does not pin a credential, apply to all active creds that share account.
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
                    $errors[] = 'Campaign ' . $kumaCampaignId . ': missing Taboola account_id.';
                    continue;
                }

                try {
                    $rows = $client->campaignSummaryByDay(
                        $remoteAccount,
                        $startDay,
                        $today,
                        $remoteCampaign
                    );
                    foreach ($rows as $row) {
                        $dateRaw = (string) ($row['date'] ?? '');
                        $date = substr($dateRaw, 0, 10);
                        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                            continue;
                        }
                        $spent = (float) ($row['spent'] ?? 0);
                        $currency = isset($row['currency']) ? (string) $row['currency'] : null;
                        // Day bucket stored at hour=0; delta_spend = that day's spent.
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
                            'taboola_api'
                        );
                        $synced++;
                    }

                    // Refresh "today" from realtime report (near real-time spent).
                    $dayStart = $today . 'T00:00:00';
                    $dayEnd = $today . 'T23:59:59';
                    $rt = $client->realtimeByCampaign($remoteAccount, $dayStart, $dayEnd, $remoteCampaign);
                    foreach ($rt as $row) {
                        $cid = (string) ($row['campaign_id'] ?? '');
                        if ($cid !== '' && $cid !== $remoteCampaign) {
                            continue;
                        }
                        $spent = (float) ($row['spent'] ?? 0);
                        $currency = isset($row['currency']) ? (string) $row['currency'] : null;
                        $hourly->upsertBucket(
                            self::SLUG,
                            $remoteAccount,
                            $remoteCampaign,
                            $today,
                            0,
                            $spent,
                            $spent,
                            $kumaCampaignId > 0 ? $kumaCampaignId : null,
                            $credId,
                            $currency,
                            'taboola_realtime'
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

        return ['ok' => true, 'message' => 'Synced ' . $synced . ' Taboola cost buckets.'];
    }
}
