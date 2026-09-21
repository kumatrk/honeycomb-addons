<?php

declare(strict_types=1);

namespace Honeycomb\Taboola;

use mysqli;
use SimpleKuma\Auth\Csrf;
use SimpleKuma\Entity\TrafficSource;
use SimpleKuma\Honeycomb\CredentialStore;
use SimpleKuma\Honeycomb\SettingsPanelProvider;
use SimpleKuma\TrafficSource\TrafficSourceTemplates;
use Throwable;

/**
 * Options page panel: Taboola credentials + which Kuma traffic source this addon owns.
 */
final class TaboolaSettingsPanel implements SettingsPanelProvider
{
    private const INPUT_STYLE = 'width:100%;padding:10px;border:2px solid #ddd;border-radius:4px;font-size:14px;box-sizing:border-box;';

    public function __construct(private mysqli $db)
    {
    }

    public function addonSlug(): string
    {
        return TaboolaCostSyncJob::SLUG;
    }

    public function title(): string
    {
        return 'API credentials';
    }

    public function renderHtml(): string
    {
        $store = new CredentialStore($this->db);
        $rows = $store->listByAddon($this->addonSlug());
        $csrf = Csrf::field();
        $base = defined('APP_BASE_URL') ? APP_BASE_URL : '';
        $formAction = htmlspecialchars(
            $base . '/index.php?page=honeycomb-addon&slug=' . rawurlencode($this->addonSlug()),
            ENT_QUOTES,
            'UTF-8'
        );
        $inputStyle = self::INPUT_STYLE;
        $docs = 'https://developers.taboola.com/backstage-api/reference/client-credentials-flow';

        $trafficSources = (new TrafficSource($this->db))->getAll();
        $linkedTsId = $this->resolveLinkedTrafficSourceId($rows, $trafficSources);
        $tsOptions = $this->buildTrafficSourceOptionsHtml($trafficSources, $linkedTsId);

        $list = '';
        if ($rows === []) {
            $list = '<p class="honeycomb-muted" style="margin-bottom:20px;">No credentials yet. Add your Taboola Backstage API '
                . 'client ID and secret (from Taboola support / your account manager).</p>';
        } else {
            $list = '<div style="margin-bottom:24px;">';
            $list .= '<div style="font-weight:600;margin-bottom:10px;color:#333;">Saved credentials</div>';
            foreach ($rows as $row) {
                $id = (int) $row['id'];
                $label = htmlspecialchars((string) ($row['label'] ?? 'Taboola'), ENT_QUOTES, 'UTF-8');
                $status = htmlspecialchars((string) ($row['status'] ?? 'active'), ENT_QUOTES, 'UTF-8');
                $list .= '<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;'
                    . 'padding:12px 14px;margin-bottom:8px;background:#f8f9fa;border:1px solid #e0e0e0;border-radius:6px;">'
                    . '<div><strong>' . $label . '</strong> '
                    . '<span class="honeycomb-pill">' . $status . '</span></div>'
                    . '<form method="POST" action="' . $formAction . '" style="margin:0;" '
                    . 'onsubmit="return confirm(\'Delete this Taboola credential?\');">'
                    . $csrf
                    . '<input type="hidden" name="action" value="honeycomb_taboola_delete_cred">'
                    . '<input type="hidden" name="credential_id" value="' . $id . '">'
                    . '<button type="submit" class="btn btn-secondary btn-sm">Delete</button>'
                    . '</form></div>';
            }
            $list .= '</div>';
        }

        return <<<HTML
<p class="honeycomb-muted" style="margin-bottom:20px;">
    Uses Taboola <strong>client credentials</strong> OAuth (no refresh token).
    See <a href="{$docs}" target="_blank" rel="noopener noreferrer">Backstage API docs</a>.
    Pick which Kuma traffic source this addon owns, then bind each campaign’s Taboola IDs on the campaign edit form.
    Hourly cron: <code>kuma-traffic-api-cron.php</code>.
</p>
{$list}
<form method="POST" action="{$formAction}" style="max-width:600px;">
    {$csrf}
    <input type="hidden" name="action" value="honeycomb_taboola_save_cred">

    <div style="margin-bottom:20px;">
        <label style="display:block;font-weight:600;margin-bottom:8px;color:#333;">Traffic source <span style="color:#d32f2f;">*</span></label>
        <select name="traffic_source_id" required style="{$inputStyle}">
            {$tsOptions}
        </select>
        <div style="font-size:12px;color:#666;margin-top:4px;">
            Use an existing source, or create one from Kuma’s built-in <strong>Taboola</strong> template
            (publisher/site tokens, campaign id, click id, etc.). That source is then marked for Honeycomb API cost.
        </div>
    </div>

    <div style="margin-bottom:20px;">
        <label style="display:block;font-weight:600;margin-bottom:8px;color:#333;">Label</label>
        <input type="text" name="label" value="Taboola" required style="{$inputStyle}">
        <div style="font-size:12px;color:#666;margin-top:4px;">Friendly name for this credential set</div>
    </div>

    <div style="margin-bottom:20px;">
        <label style="display:block;font-weight:600;margin-bottom:8px;color:#333;">Client ID <span style="color:#d32f2f;">*</span></label>
        <input type="text" name="client_id" required autocomplete="off" style="{$inputStyle}font-family:monospace;">
    </div>

    <div style="margin-bottom:20px;">
        <label style="display:block;font-weight:600;margin-bottom:8px;color:#333;">Client secret <span style="color:#d32f2f;">*</span></label>
        <input type="password" name="client_secret" required autocomplete="new-password" style="{$inputStyle}font-family:monospace;">
    </div>

    <div style="margin-bottom:24px;">
        <label style="display:block;font-weight:600;margin-bottom:8px;color:#333;">Account ID</label>
        <input type="text" name="account_id" placeholder="taboola-demo-advertiser" autocomplete="off" style="{$inputStyle}font-family:monospace;">
        <div style="font-size:12px;color:#666;margin-top:4px;">Alphabetic Taboola account ID (optional — auto-detected from the API if blank)</div>
    </div>

    <button type="submit" class="btn btn-primary">Save Taboola credential</button>
</form>
HTML;
    }

    public function handlePost(array $post): ?array
    {
        $action = (string) ($post['action'] ?? '');
        if ($action === 'honeycomb_taboola_save_cred') {
            return $this->saveCredential($post);
        }
        if ($action === 'honeycomb_taboola_delete_cred') {
            return $this->deleteCredential($post);
        }
        return null;
    }

    /**
     * @param array<string, mixed> $post
     * @return array{ok: bool, message: string}
     */
    private function saveCredential(array $post): array
    {
        $label = trim((string) ($post['label'] ?? 'Taboola'));
        $clientId = trim((string) ($post['client_id'] ?? ''));
        $clientSecret = trim((string) ($post['client_secret'] ?? ''));
        $accountId = trim((string) ($post['account_id'] ?? ''));
        $tsChoice = trim((string) ($post['traffic_source_id'] ?? ''));
        if ($clientId === '' || $clientSecret === '') {
            return ['ok' => false, 'message' => 'Client ID and secret are required.'];
        }
        if ($tsChoice === '') {
            return ['ok' => false, 'message' => 'Select a traffic source (or create a new Taboola one).'];
        }

        try {
            $client = new TaboolaClient($clientId, $clientSecret);
            $account = $client->getCurrentAccount();
            if ($accountId === '') {
                $accountId = trim((string) ($account['account_id'] ?? ''));
            }
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Could not authenticate with Taboola: ' . $e->getMessage()];
        }

        try {
            $trafficSourceId = $this->linkTrafficSource($tsChoice);
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Traffic source: ' . $e->getMessage()];
        }

        $store = new CredentialStore($this->db);
        $store->create($this->addonSlug(), $label !== '' ? $label : 'Taboola', [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'account_id' => $accountId,
            'traffic_source_id' => $trafficSourceId,
        ]);

        return [
            'ok' => true,
            'message' => 'Taboola credential saved'
                . ($accountId !== '' ? ' (account ' . $accountId . ')' : '')
                . ', linked to traffic source #' . $trafficSourceId . '.',
        ];
    }

    /**
     * @param array<string, mixed> $post
     * @return array{ok: bool, message: string}
     */
    private function deleteCredential(array $post): array
    {
        $id = (int) ($post['credential_id'] ?? 0);
        $store = new CredentialStore($this->db);
        $row = $store->getById($id, false);
        if ($row === null || ($row['addon_slug'] ?? '') !== $this->addonSlug()) {
            return ['ok' => false, 'message' => 'Credential not found.'];
        }
        $store->delete($id);
        return ['ok' => true, 'message' => 'Taboola credential deleted.'];
    }

    /**
     * @param list<array<string, mixed>> $credentialRows
     * @param list<array<string, mixed>> $trafficSources
     */
    private function resolveLinkedTrafficSourceId(array $credentialRows, array $trafficSources): int
    {
        $store = new CredentialStore($this->db);
        foreach ($credentialRows as $meta) {
            $full = $store->getById((int) ($meta['id'] ?? 0), true);
            $payload = is_array($full['payload'] ?? null) ? $full['payload'] : [];
            $id = (int) ($payload['traffic_source_id'] ?? 0);
            if ($id > 0) {
                return $id;
            }
        }
        foreach ($trafficSources as $ts) {
            $key = trim((string) ($ts['provider_key'] ?? ''));
            $name = strtolower((string) ($ts['name'] ?? ''));
            if ($key === 'taboola' || str_contains($name, 'taboola')) {
                return (int) ($ts['id'] ?? 0);
            }
        }
        return 0;
    }

    /**
     * @param list<array<string, mixed>> $trafficSources
     */
    private function buildTrafficSourceOptionsHtml(array $trafficSources, int $selectedId): string
    {
        $html = '<option value="">Select a traffic source…</option>';
        $createSelected = $selectedId === 0 ? ' selected' : '';
        $html .= '<option value="__create__"' . $createSelected . '>'
            . 'Create from Taboola template (recommended)</option>';
        foreach ($trafficSources as $ts) {
            $id = (int) ($ts['id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $name = htmlspecialchars((string) ($ts['name'] ?? 'Untitled'), ENT_QUOTES, 'UTF-8');
            $key = trim((string) ($ts['provider_key'] ?? ''));
            $suffix = $key !== '' ? ' (' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . ')' : '';
            $sel = $id === $selectedId ? ' selected' : '';
            $html .= '<option value="' . $id . '"' . $sel . '>' . $name . $suffix . '</option>';
        }
        return $html;
    }

    private function linkTrafficSource(string $choice): int
    {
        $ts = new TrafficSource($this->db);
        $template = TrafficSourceTemplates::all()['taboola'] ?? null;
        $templateTokens = is_array($template['tokens'] ?? null) ? $template['tokens'] : [];

        if ($choice === '__create__') {
            return $ts->create([
                'name' => (string) ($template['name'] ?? 'Taboola'),
                'provider_key' => 'taboola',
                'cost_tracking_method' => 'integrated_api',
                'cost_param_key' => (string) ($template['cost_param_key'] ?? 'cost'),
                'cost_currency' => (string) ($template['cost_currency'] ?? 'USD'),
                'tokens' => $templateTokens,
                'postback_template' => null,
            ]);
        }

        $id = (int) $choice;
        $existing = $ts->getById($id);
        if ($existing === null) {
            throw new \InvalidArgumentException('Selected traffic source was not found.');
        }

        $existingTokens = $existing['tokens_json'] ?? [];
        if (!is_array($existingTokens) || $existingTokens === []) {
            $existingTokens = $templateTokens;
        }

        $ts->update($id, [
            'name' => $existing['name'] ?? (string) ($template['name'] ?? 'Taboola'),
            'provider_key' => 'taboola',
            'cost_tracking_method' => 'integrated_api',
            'tokens' => $existingTokens,
            'postback_template' => $existing['postback_template'] ?? null,
            'cost_param_key' => $existing['cost_param_key'] ?? ($template['cost_param_key'] ?? 'cost'),
            'cost_currency' => $existing['cost_currency'] ?? ($template['cost_currency'] ?? 'USD'),
        ]);

        return $id;
    }
}
