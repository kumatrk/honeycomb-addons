<?php

declare(strict_types=1);

namespace Honeycomb\Whop;

use mysqli;
use SimpleKuma\Auth\Csrf;
use SimpleKuma\Entity\TrafficSource;
use SimpleKuma\Honeycomb\CredentialStore;
use SimpleKuma\Honeycomb\SettingsPanelProvider;
use SimpleKuma\TrafficSource\TrafficSourceTemplates;
use Throwable;

/**
 * Honeycomb Options: Whop API key + biz account + linked traffic source.
 */
final class WhopSettingsPanel implements SettingsPanelProvider
{
    private const INPUT_STYLE = 'width:100%;padding:10px;border:2px solid #ddd;border-radius:4px;font-size:14px;box-sizing:border-box;';

    public function __construct(private mysqli $db)
    {
    }

    public function addonSlug(): string
    {
        return WhopConversionExporter::SLUG;
    }

    public function title(): string
    {
        return 'Whop Ads credentials';
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
        $docsAds = 'https://docs.whop.com/manage-your-business/growth-marketing/ads';
        $docsEvents = 'https://docs.whop.com/developer/ads/events-api';
        $docsPixel = 'https://docs.whop.com/developer/guides/pixel';

        $trafficSources = (new TrafficSource($this->db))->getAll();
        $tsById = [];
        foreach ($trafficSources as $ts) {
            $tsById[(int) ($ts['id'] ?? 0)] = $ts;
        }
        $linkedTsId = $this->resolveLinkedTrafficSourceId($rows, $trafficSources);
        $tsOptions = $this->buildTrafficSourceOptionsHtml($trafficSources, $linkedTsId);
        $hasCreds = $rows !== [];

        $list = '';
        if (!$hasCreds) {
            $list = '<p class="honeycomb-muted" style="margin-bottom:20px;">No credentials yet. Create an API key with '
                . '<code>event:create</code> under Whop Account API Keys, then connect below.</p>';
        } else {
            $list = '<div style="margin-bottom:20px;">';
            $list .= '<div style="font-weight:600;margin-bottom:10px;color:#333;">Connected</div>';
            foreach ($rows as $row) {
                $id = (int) $row['id'];
                $label = htmlspecialchars((string) ($row['label'] ?? 'Whop Ads'), ENT_QUOTES, 'UTF-8');
                $status = htmlspecialchars((string) ($row['status'] ?? 'active'), ENT_QUOTES, 'UTF-8');
                $full = $store->getById($id, true);
                $payload = is_array($full['payload'] ?? null) ? $full['payload'] : [];
                $biz = trim((string) ($payload['account_id'] ?? ''));
                $bizDisplay = $biz !== ''
                    ? htmlspecialchars($biz, ENT_QUOTES, 'UTF-8')
                    : '<span style="color:#999;">No biz_ id</span>';
                $tsId = (int) ($payload['traffic_source_id'] ?? 0);
                $tsName = isset($tsById[$tsId])
                    ? htmlspecialchars((string) ($tsById[$tsId]['name'] ?? 'Traffic source'), ENT_QUOTES, 'UTF-8')
                    : 'Not linked';
                $eventDefault = htmlspecialchars((string) ($payload['default_event_name'] ?? 'lead'), ENT_QUOTES, 'UTF-8');

                $list .= '<div style="padding:14px 16px;margin-bottom:10px;background:#f5f8f2;border:1px solid #c5d4b8;'
                    . 'border-left:4px solid #3d5a26;border-radius:6px;">'
                    . '<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:10px;">'
                    . '<div><strong style="color:#3d5a26;">' . $label . '</strong> '
                    . '<span class="honeycomb-pill">' . $status . '</span></div>'
                    . '<form method="POST" action="' . $formAction . '" style="margin:0;" '
                    . 'onsubmit="return confirm(\'Delete this Whop credential?\');">'
                    . $csrf
                    . '<input type="hidden" name="action" value="honeycomb_whop_delete_cred">'
                    . '<input type="hidden" name="credential_id" value="' . $id . '">'
                    . '<button type="submit" class="btn btn-secondary btn-sm">Delete</button>'
                    . '</form></div>'
                    . '<div style="font-size:13px;color:#555;line-height:1.55;">'
                    . '<div><strong>Account:</strong> <code>' . $bizDisplay . '</code></div>'
                    . '<div><strong>API key:</strong> saved (hidden)</div>'
                    . '<div><strong>Traffic source:</strong> ' . $tsName . '</div>'
                    . '<div><strong>Default event:</strong> ' . $eventDefault . '</div>'
                    . '</div></div>';
            }
            $list .= '<p class="honeycomb-muted" style="margin:0;font-size:13px;">You\'re set. Enable “Send conversions to Whop Ads” on each campaign that uses this traffic source.</p>';
            $list .= '</div>';
        }

        $addFormInner = <<<FORM
    {$csrf}
    <input type="hidden" name="action" value="honeycomb_whop_save_cred">

    <div style="margin-bottom:20px;">
        <label style="display:block;font-weight:600;margin-bottom:8px;color:#333;">Traffic source <span style="color:#d32f2f;">*</span></label>
        <select name="traffic_source_id" required style="{$inputStyle}">
            {$tsOptions}
        </select>
        <div style="font-size:12px;color:#666;margin-top:4px;">
            Use an existing source, or create one from Kuma’s built-in <strong>Whop Ads</strong> template
            (wacid / wasid / waid / Meta UTMs / tw_source / tw_adid).
        </div>
    </div>

    <div style="margin-bottom:20px;">
        <label style="display:block;font-weight:600;margin-bottom:8px;color:#333;">Label</label>
        <input type="text" name="label" value="Whop Ads" required style="{$inputStyle}">
    </div>

    <div style="margin-bottom:20px;">
        <label style="display:block;font-weight:600;margin-bottom:8px;color:#333;">Whop account ID (biz_…) <span style="color:#d32f2f;">*</span></label>
        <input type="text" name="account_id" required autocomplete="off" placeholder="biz_xxxxxxxxxxxxx" style="{$inputStyle}font-family:monospace;">
        <div style="font-size:12px;color:#666;margin-top:4px;">From your Whop dashboard URL after <code>dashboard/</code>.</div>
    </div>

    <div style="margin-bottom:20px;">
        <label style="display:block;font-weight:600;margin-bottom:8px;color:#333;">API key <span style="color:#d32f2f;">*</span></label>
        <input type="password" name="api_key" required autocomplete="new-password" style="{$inputStyle}font-family:monospace;">
        <div style="font-size:12px;color:#666;margin-top:4px;">Server-side only. Needs <code>event:create</code>. Never put this in browser code.</div>
    </div>

    <div style="margin-bottom:24px;">
        <label style="display:block;font-weight:600;margin-bottom:8px;color:#333;">Default event name</label>
        <select name="default_event_name" style="{$inputStyle}">
            <option value="lead" selected>lead</option>
            <option value="schedule">schedule</option>
            <option value="contact">contact</option>
            <option value="complete_registration">complete_registration</option>
            <option value="submit_application">submit_application</option>
        </select>
        <div style="font-size:12px;color:#666;margin-top:4px;">
            Affiliate conversions are reported as leads by default — not <code>purchase</code>
            (Whop purchase ROAS is for Whop checkout only).
        </div>
    </div>

    <button type="submit" class="btn btn-primary">Save Whop credential</button>
FORM;

        if ($hasCreds) {
            // One Whop business per tracker. A second key would be ambiguous
            // because campaigns use the first active credential.
            $credentialForm = '<p class="honeycomb-muted" style="margin:8px 0 0;font-size:13px;">To switch accounts, delete this credential and connect the new one.</p>';
        } else {
            $credentialForm = <<<HTML
<div style="margin-bottom:8px;font-weight:600;color:#333;">Connect Whop Ads</div>
<form method="POST" action="{$formAction}" style="max-width:600px;">
{$addFormInner}
</form>
HTML;
        }

        return <<<HTML
<p class="honeycomb-muted" style="margin-bottom:16px;line-height:1.5;">
    Connect your Whop account here once. Then open each Whop campaign to copy the Pixel + CTA codes,
    and turn on <strong>Send conversions to Whop Ads</strong>.
</p>
<div style="margin-bottom:20px;padding:16px 18px;background:#f5f8f2;border:1px solid #c5d4b8;border-radius:8px;font-size:13px;line-height:1.55;color:#333;">
    <div style="font-weight:700;color:#3d5a26;margin-bottom:10px;">How Whop Ads works in Kuma</div>
    <ol style="margin:0;padding-left:20px;">
        <li style="margin-bottom:8px;"><strong>Connect credentials</strong> below (<code>biz_…</code> account + API key with event create access).</li>
        <li style="margin-bottom:8px;"><strong>Create / use a campaign</strong> with the <strong>Whop Ads</strong> traffic source.</li>
        <li style="margin-bottom:8px;"><strong>On that campaign page</strong>, copy the ready-made codes:
            Whop Pixel for your landing page <code>&lt;head&gt;</code>, plus either the Kuma CTA (ad → LP → Kuma link)
            or Redirectless JavaScript (ad → LP directly).</li>
        <li style="margin-bottom:8px;"><strong>Enable</strong> “Send conversions to Whop Ads” on the campaign so leads post back to Whop’s Events API.</li>
        <li style="margin-bottom:0;">When a lead converts, Kuma sends the event to Whop using the visitor id from your LP Pixel — so Whop can attribute the ad.</li>
    </ol>
    <p style="margin:12px 0 0;font-size:12px;color:#555;">
        Docs:
        <a href="{$docsAds}" target="_blank" rel="noopener noreferrer">Whop Ads</a> ·
        <a href="{$docsPixel}" target="_blank" rel="noopener noreferrer">Whop Pixel</a> ·
        <a href="{$docsEvents}" target="_blank" rel="noopener noreferrer">Events API</a>
    </p>
</div>
{$list}
{$credentialForm}
HTML;
    }

    public function handlePost(array $post): ?array
    {
        $action = (string) ($post['action'] ?? '');
        if ($action === 'honeycomb_whop_save_cred') {
            return $this->saveCredential($post);
        }
        if ($action === 'honeycomb_whop_delete_cred') {
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
        $label = trim((string) ($post['label'] ?? 'Whop Ads'));
        $accountId = trim((string) ($post['account_id'] ?? ''));
        $apiKey = trim((string) ($post['api_key'] ?? ''));
        $defaultEvent = trim((string) ($post['default_event_name'] ?? 'lead'));
        $tsChoice = trim((string) ($post['traffic_source_id'] ?? ''));

        if ($accountId === '' || !str_starts_with($accountId, 'biz_')) {
            return ['ok' => false, 'message' => 'Account ID must start with biz_.'];
        }
        $existing = (new CredentialStore($this->db))->listByAddon($this->addonSlug());
        if ($existing !== []) {
            return ['ok' => false, 'message' => 'A Whop credential is already connected. Delete it before adding another.'];
        }
        if ($apiKey === '') {
            return ['ok' => false, 'message' => 'API key is required.'];
        }
        if ($tsChoice === '') {
            return ['ok' => false, 'message' => 'Select a traffic source (or create from the Whop Ads template).'];
        }

        $allowedEvents = ['lead', 'schedule', 'contact', 'complete_registration', 'submit_application'];
        if (!in_array($defaultEvent, $allowedEvents, true)) {
            $defaultEvent = 'lead';
        }

        try {
            $trafficSourceId = $this->linkTrafficSource($tsChoice);
            $store = new CredentialStore($this->db);
            $store->create($this->addonSlug(), $label !== '' ? $label : 'Whop Ads', [
                'account_id' => $accountId,
                'api_key' => $apiKey,
                'default_event_name' => $defaultEvent,
                'traffic_source_id' => $trafficSourceId,
            ], 'active');
            return [
                'ok' => true,
                'message' => 'Whop credential saved, linked to traffic source #' . $trafficSourceId
                    . '. Enable “Send conversions to Whop” on each campaign.',
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
        return ['ok' => true, 'message' => 'Whop credential deleted.'];
    }

    /**
     * @param list<array<string, mixed>> $credRows
     * @param list<array<string, mixed>> $trafficSources
     */
    private function resolveLinkedTrafficSourceId(array $credRows, array $trafficSources): int
    {
        $store = new CredentialStore($this->db);
        foreach ($credRows as $meta) {
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
            if ($key === 'whop' || str_contains($name, 'whop')) {
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
            . 'Create from Whop Ads template (recommended)</option>';
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
        $template = TrafficSourceTemplates::all()['whop'] ?? null;
        $templateTokens = is_array($template['tokens'] ?? null) ? $template['tokens'] : [];

        if ($choice === '__create__') {
            return $ts->create([
                'name' => (string) ($template['name'] ?? 'Whop Ads'),
                'provider_key' => 'whop',
                'cost_tracking_method' => 'manual_token',
                'cost_param_key' => '',
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
            'name' => $existing['name'] ?? (string) ($template['name'] ?? 'Whop Ads'),
            'provider_key' => 'whop',
            'cost_tracking_method' => $existing['cost_tracking_method'] ?? 'manual_token',
            'tokens' => $existingTokens,
            'postback_template' => $existing['postback_template'] ?? null,
            'cost_param_key' => $existing['cost_param_key'] ?? '',
            'cost_currency' => $existing['cost_currency'] ?? ($template['cost_currency'] ?? 'USD'),
        ]);

        return $id;
    }
}
