<?php

declare(strict_types=1);

namespace Honeycomb\Ringba;

use mysqli;
use SimpleKuma\Auth\Csrf;
use SimpleKuma\Honeycomb\CredentialStore;
use SimpleKuma\Honeycomb\SettingsPanelProvider;
use Throwable;

/**
 * Honeycomb Options: Ringba API credentials + pixel URL builder.
 */
final class RingbaSettingsPanel implements SettingsPanelProvider
{
    private const INPUT_STYLE = 'width:100%;padding:10px;border:2px solid #ddd;border-radius:4px;font-size:14px;box-sizing:border-box;';

    public function __construct(private mysqli $db)
    {
    }

    public function addonSlug(): string
    {
        return RingbaCampaignFields::SLUG;
    }

    public function title(): string
    {
        return 'Ringba credentials & pixel URLs';
    }

    public function renderHtml(): string
    {
        $store = new CredentialStore($this->db);
        $rows = $store->listByAddon($this->addonSlug());
        $csrf = Csrf::field();
        $base = defined('APP_BASE_URL') ? rtrim((string) APP_BASE_URL, '/') : '';
        $formAction = htmlspecialchars(
            $base . '/index.php?page=honeycomb-addon&slug=' . rawurlencode($this->addonSlug()),
            ENT_QUOTES,
            'UTF-8'
        );
        $inputStyle = self::INPUT_STYLE;
        $docsTokens = 'https://support.ringba.com/hc/en-us/articles/31661837696023-API-Access-Tokens';
        $docsPixels = 'https://support.ringba.com/hc/en-us/articles/17998491616535-Pixels';
        $docsParams = 'https://support.ringba.com/hc/en-us/articles/17998510078103-URL-Parameters';
        $docsPools = 'https://support.ringba.com/hc/en-us/articles/17992305425815-Number-Pools';

        $creds = new RingbaCredentials($this->db);
        $active = $creds->active();
        $hasCreds = $active !== null;

        $list = '';
        if (!$hasCreds) {
            $list = '<p class="honeycomb-muted" style="margin-bottom:20px;">No API token yet. Paste is still available on each campaign; '
                . 'connecting an API token unlocks campaign pickers in the campaign editor.</p>';
        } else {
            $accountEsc = htmlspecialchars($active['account_id'], ENT_QUOTES, 'UTF-8');
            $labelEsc = htmlspecialchars($active['label'], ENT_QUOTES, 'UTF-8');
            $id = (int) $active['credential_id'];
            $list = '<div style="margin-bottom:20px;padding:14px 16px;background:#f0f7fb;border:1px solid #a8c5d8;'
                . 'border-left:4px solid #1565c0;border-radius:6px;">'
                . '<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:10px;">'
                . '<div><strong style="color:#0d47a1;">' . $labelEsc . '</strong> '
                . '<span class="honeycomb-pill">active</span></div>'
                . '<div style="display:flex;gap:8px;flex-wrap:wrap;">'
                . '<form method="POST" action="' . $formAction . '" style="margin:0;">'
                . $csrf
                . '<input type="hidden" name="action" value="honeycomb_ringba_test_cred">'
                . '<button type="submit" class="btn btn-secondary btn-sm">Test connection</button>'
                . '</form>'
                . '<form method="POST" action="' . $formAction . '" style="margin:0;" '
                . 'onsubmit="return confirm(\'Delete this Ringba credential?\');">'
                . $csrf
                . '<input type="hidden" name="action" value="honeycomb_ringba_delete_cred">'
                . '<input type="hidden" name="credential_id" value="' . $id . '">'
                . '<button type="submit" class="btn btn-secondary btn-sm">Delete</button>'
                . '</form></div></div>'
                . '<div style="font-size:13px;color:#555;line-height:1.55;">'
                . '<div><strong>Account ID:</strong> <code>' . $accountEsc . '</code></div>'
                . '<div><strong>API token:</strong> saved (hidden)</div>'
                . '</div>'
                . '<p style="margin:12px 0 0;font-size:12px;color:#555;line-height:1.45;">'
                . 'On each campaign → Honeycomb → Enable Ringba, pick a campaign from the dropdown '
                . '(or paste a <code>CA…</code> id). Tracking copy codes update automatically.'
                . '</p></div>';
        }

        $addForm = '';
        if (!$hasCreds) {
            $addForm = <<<HTML
<div style="margin-bottom:8px;font-weight:600;color:#333;">Connect Ringba API</div>
<form method="POST" action="{$formAction}" style="max-width:600px;margin-bottom:24px;">
    {$csrf}
    <input type="hidden" name="action" value="honeycomb_ringba_save_cred">
    <div style="margin-bottom:16px;">
        <label style="display:block;font-weight:600;margin-bottom:8px;color:#333;">Label</label>
        <input type="text" name="label" value="Ringba" required style="{$inputStyle}">
    </div>
    <div style="margin-bottom:16px;">
        <label style="display:block;font-weight:600;margin-bottom:8px;color:#333;">Account ID <span style="color:#d32f2f;">*</span></label>
        <input type="text" name="account_id" required autocomplete="off" placeholder="RA…" style="{$inputStyle}font-family:monospace;">
        <div style="font-size:12px;color:#666;margin-top:4px;">From Ringba account settings (often starts with <code>RA</code>).</div>
    </div>
    <div style="margin-bottom:16px;">
        <label style="display:block;font-weight:600;margin-bottom:8px;color:#333;">API access token <span style="color:#d32f2f;">*</span></label>
        <input type="password" name="api_token" required autocomplete="new-password" style="{$inputStyle}font-family:monospace;">
        <div style="font-size:12px;color:#666;margin-top:4px;">
            Security → <a href="{$docsTokens}" target="_blank" rel="noopener noreferrer">API Access Tokens</a>.
            Header uses <code>Authorization: Token …</code> (not Bearer).
        </div>
    </div>
    <button type="submit" class="btn btn-primary">Save &amp; test</button>
</form>
HTML;
        }

        $postback = htmlspecialchars($base . '/postback.php', ENT_QUOTES, 'UTF-8');
        $alias = htmlspecialchars($base . '/ringba-postback.php', ENT_QUOTES, 'UTF-8');
        // Template default — campaigns may override via Honeycomb binding click_param.
        $clickTag = 'click_id';
        $clickToken = '[tag:User:' . $clickTag . ']';
        $callId = '[Call:InboundCallId]';
        $payout = '[Call:ConversionPayout]';
        $amount = '[Call:ConversionAmount]';

        $connected = htmlspecialchars(
            $base . '/postback.php?click_id=' . rawurlencode($clickToken)
            . '&txid=' . rawurlencode($callId) . '&et=call_connected&payout=0',
            ENT_QUOTES,
            'UTF-8'
        );
        $converted = htmlspecialchars(
            $base . '/postback.php?click_id=' . rawurlencode($clickToken)
            . '&txid=' . rawurlencode($callId) . '&payout=' . rawurlencode($amount) . '&et=call_converted',
            ENT_QUOTES,
            'UTF-8'
        );
        $paid = htmlspecialchars(
            $base . '/postback.php?click_id=' . rawurlencode($clickToken)
            . '&txid=' . rawurlencode($callId) . '&payout=' . rawurlencode($payout) . '&et=call_payout',
            ENT_QUOTES,
            'UTF-8'
        );

        return <<<HTML
<p class="honeycomb-muted" style="margin-bottom:16px;line-height:1.5;">
    Ringba owns call routing and number pools. Kuma owns ad click attribution, ROI, and CAPI / Whop export.
    API credentials are optional but recommended — they power campaign pickers so you don’t paste <code>CA…</code> ids by hand.
</p>

<div style="margin-bottom:20px;padding:16px 18px;background:#f0f7fb;border:1px solid #a8c5d8;border-radius:8px;font-size:13px;line-height:1.55;color:#333;">
    <div style="font-weight:700;color:#0d47a1;margin-bottom:10px;">Setup checklist</div>
    <ol style="margin:0;padding-left:20px;">
        <li style="margin-bottom:8px;">(Optional) Connect API credentials below for campaign pickers.</li>
        <li style="margin-bottom:8px;">In Ringba: create a <strong>URL Parameter</strong> named <code>click_id</code>
            (unless you changed the tag name on the campaign). This stores <strong>Kuma’s</strong> click id on the call —
            not Propeller/Meta/etc. (<a href="{$docsParams}" target="_blank" rel="noopener noreferrer">URL Parameters</a>).</li>
        <li style="margin-bottom:8px;">Attach a <strong>number pool</strong> + call tracking tag
            (<a href="{$docsPools}" target="_blank" rel="noopener noreferrer">Number Pools</a>).</li>
        <li style="margin-bottom:8px;">In Kuma: campaign → <strong>Honeycomb</strong> → enable Ringba → pick or paste the campaign / script id.</li>
        <li style="margin-bottom:0;">Create <strong>Pixels</strong> (below) for Connected / Converted / Payout
            (<a href="{$docsPixels}" target="_blank" rel="noopener noreferrer">Pixels docs</a>.</li>
    </ol>
</div>

{$list}
{$addForm}

<div style="margin-bottom:20px;padding:16px 18px;background:#f5f8f2;border:1px solid #c5d4b8;border-radius:8px;">
    <div style="font-weight:700;color:#3d5a26;margin-bottom:10px;">Pixel URLs → paste into Ringba Integrations → Pixels</div>
    <p style="font-size:12px;color:#555;margin:0 0 14px;line-height:1.45;">
        Fire Pixel On = <strong>Connected</strong>, <strong>Converted</strong>, or <strong>Payout</strong>.
        Alias endpoint: <code>{$alias}</code>
        Templates use <code>{$clickToken}</code> — if a campaign’s Honeycomb field
        <strong>Ringba tag name for Kuma’s click id</strong> is not <code>click_id</code>, change
        <code>[tag:User:…]</code> in these URLs to match that name.
    </p>
    <div style="margin-bottom:14px;">
        <strong style="font-size:13px;">Connected</strong> <code style="font-size:11px;color:#666;">et=call_connected</code>
        <pre style="margin:6px 0 0;padding:10px;background:#1e1e1e;color:#d4d4d4;border-radius:6px;overflow:auto;font-size:11px;white-space:pre-wrap;">{$connected}</pre>
    </div>
    <div style="margin-bottom:14px;">
        <strong style="font-size:13px;">Converted</strong> <code style="font-size:11px;color:#666;">et=call_converted</code>
        <pre style="margin:6px 0 0;padding:10px;background:#1e1e1e;color:#d4d4d4;border-radius:6px;overflow:auto;font-size:11px;white-space:pre-wrap;">{$converted}</pre>
    </div>
    <div>
        <strong style="font-size:13px;">Payout</strong> <code style="font-size:11px;color:#666;">et=call_payout</code>
        <pre style="margin:6px 0 0;padding:10px;background:#1e1e1e;color:#d4d4d4;border-radius:6px;overflow:auto;font-size:11px;white-space:pre-wrap;">{$paid}</pre>
    </div>
</div>
HTML;
    }

    public function handlePost(array $post): ?array
    {
        $action = (string) ($post['action'] ?? '');
        if ($action === 'honeycomb_ringba_save_cred') {
            return $this->saveCredential($post);
        }
        if ($action === 'honeycomb_ringba_delete_cred') {
            return $this->deleteCredential($post);
        }
        if ($action === 'honeycomb_ringba_test_cred') {
            return $this->testCredential();
        }
        return null;
    }

    /**
     * @param array<string, mixed> $post
     * @return array{ok: bool, message: string}
     */
    private function saveCredential(array $post): array
    {
        $label = trim((string) ($post['label'] ?? 'Ringba'));
        $accountId = trim((string) ($post['account_id'] ?? ''));
        $apiToken = trim((string) ($post['api_token'] ?? ''));

        if ($accountId === '') {
            return ['ok' => false, 'message' => 'Account ID is required.'];
        }
        if ($apiToken === '') {
            return ['ok' => false, 'message' => 'API access token is required.'];
        }
        $existing = (new CredentialStore($this->db))->listByAddon($this->addonSlug());
        if ($existing !== []) {
            return ['ok' => false, 'message' => 'A Ringba credential is already connected. Delete it before adding another.'];
        }

        try {
            $client = new RingbaClient($accountId, $apiToken);
            $test = $client->testConnection();
            if (!$test['ok']) {
                return ['ok' => false, 'message' => 'Could not connect: ' . $test['message']];
            }

            (new CredentialStore($this->db))->create(
                $this->addonSlug(),
                $label !== '' ? $label : 'Ringba',
                [
                    'account_id' => $accountId,
                    'api_token' => $apiToken,
                ],
                'active'
            );

            return [
                'ok' => true,
                'message' => $test['message'] . ' Credential saved. Open a campaign → Honeycomb → Enable Ringba to pick a campaign.',
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * @param array<string, mixed> $post
     * @return array{ok: bool, message: string}
     */
    private function deleteCredential(array $post): array
    {
        $id = (int) ($post['credential_id'] ?? 0);
        if ($id < 1) {
            return ['ok' => false, 'message' => 'Invalid credential.'];
        }
        $store = new CredentialStore($this->db);
        $row = $store->getById($id, false);
        if ($row === null || ($row['addon_slug'] ?? '') !== $this->addonSlug()) {
            return ['ok' => false, 'message' => 'Credential not found.'];
        }
        $store->delete($id);
        return ['ok' => true, 'message' => 'Ringba credential deleted. Paste mode still works on campaigns.'];
    }

    /**
     * @return array{ok: bool, message: string}
     */
    private function testCredential(): array
    {
        $client = (new RingbaCredentials($this->db))->client();
        if ($client === null) {
            return ['ok' => false, 'message' => 'No active Ringba credential.'];
        }
        $test = $client->testConnection();
        return ['ok' => $test['ok'], 'message' => $test['message']];
    }
}
