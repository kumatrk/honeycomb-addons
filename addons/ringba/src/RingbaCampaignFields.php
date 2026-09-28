<?php

declare(strict_types=1);

namespace Honeycomb\Ringba;

use mysqli;
use SimpleKuma\Honeycomb\CampaignFieldsProvider;

/**
 * Always-visible Honeycomb campaign panel for Ringba (any traffic source).
 */
final class RingbaCampaignFields implements CampaignFieldsProvider
{
    public const SLUG = 'ringba';

    public function __construct(private mysqli $db)
    {
    }

    public function addonSlug(): string
    {
        return self::SLUG;
    }

    public function providerKey(): string
    {
        return '';
    }

    public function title(): string
    {
        return 'Ringba';
    }

    public function priority(): int
    {
        return 50;
    }

    public function alwaysVisible(): bool
    {
        return true;
    }

    public function normalizeBindingFields(array $fields): array
    {
        $enabled = !empty($fields['enabled']);
        $jsTag = trim((string) ($fields['js_tag_id'] ?? ''));
        $jsTag = preg_replace('/\.js$/i', '', $jsTag) ?? $jsTag;
        $jsTag = preg_replace('#^https?://b-js\.ringba\.com/#i', '', $jsTag) ?? $jsTag;
        $jsTag = trim($jsTag, '/');

        $clickParam = strtolower(trim((string) ($fields['click_param'] ?? 'click_id')));
        if ($clickParam === '' || !preg_match('/^[a-z][a-z0-9_]{0,63}$/', $clickParam)) {
            $clickParam = 'click_id';
        }

        $numberReplace = trim((string) ($fields['number_to_replace'] ?? ''));
        $remoteCampaign = trim((string) ($fields['remote_campaign_id'] ?? ''));
        $remoteCampaignName = trim((string) ($fields['remote_campaign_name'] ?? ''));
        if ($remoteCampaign === '' && $jsTag !== '') {
            $remoteCampaign = $jsTag;
        }

        if (!$enabled) {
            return ['_clear' => true];
        }

        $extra = [
            'enabled' => true,
            'js_tag_id' => $jsTag,
            'click_param' => $clickParam,
            'number_to_replace' => $numberReplace,
        ];
        if ($remoteCampaignName !== '') {
            $extra['remote_campaign_name'] = $remoteCampaignName;
        }

        return [
            'remote_account_id' => 'ringba',
            'remote_campaign_id' => $remoteCampaign !== '' ? $remoteCampaign : 'enabled',
            'extra' => $extra,
        ];
    }

    public function renderHtml(array $ctx): string
    {
        $binding = is_array($ctx['binding'] ?? null) ? $ctx['binding'] : null;
        $post = is_array($ctx['post'] ?? null) ? $ctx['post'] : [];
        $extra = is_array($binding['extra'] ?? null) ? $binding['extra'] : [];

        $enabled = !empty($extra['enabled']);
        if (array_key_exists('enabled', $post)) {
            $enabled = !empty($post['enabled']);
        }

        $jsTag = (string) ($post['js_tag_id'] ?? $extra['js_tag_id'] ?? '');
        $clickParam = (string) ($post['click_param'] ?? $extra['click_param'] ?? 'click_id');
        $numberReplace = (string) ($post['number_to_replace'] ?? $extra['number_to_replace'] ?? '');
        $remoteCampaign = (string) ($post['remote_campaign_id'] ?? $binding['remote_campaign_id'] ?? '');
        $remoteName = (string) ($post['remote_campaign_name'] ?? $extra['remote_campaign_name'] ?? '');

        $apiConnected = (new RingbaCredentials($this->db))->active() !== null;

        $slug = self::SLUG;
        $jsEsc = htmlspecialchars($jsTag, ENT_QUOTES, 'UTF-8');
        $clickEsc = htmlspecialchars($clickParam, ENT_QUOTES, 'UTF-8');
        $numEsc = htmlspecialchars($numberReplace, ENT_QUOTES, 'UTF-8');
        $campEsc = htmlspecialchars($remoteCampaign, ENT_QUOTES, 'UTF-8');
        $nameEsc = htmlspecialchars($remoteName, ENT_QUOTES, 'UTF-8');
        $base = defined('APP_BASE_URL') ? rtrim((string) APP_BASE_URL, '/') : '';
        $optsUrl = htmlspecialchars($base . '/index.php?page=honeycomb-addon&slug=ringba', ENT_QUOTES, 'UTF-8');
        $apiUrl = htmlspecialchars($base . '/api/honeycomb-ringba.php', ENT_QUOTES, 'UTF-8');

        $apiBadge = $apiConnected
            ? '<span style="display:inline-block;margin-left:8px;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;background:#e3f2fd;color:#0d47a1;border:1px solid #90caf9;">API connected</span>'
            : '<span style="display:inline-block;margin-left:8px;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;background:#fff8e1;color:#f57f17;border:1px solid #ffe082;">Paste mode</span>';

        $pickerBlock = '';
        if ($apiConnected) {
            $pickerBlock = '<div style="margin-bottom:14px;padding:12px;background:#fff;border:1px solid #90caf9;border-radius:6px;">'
                . '<div style="display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:8px;flex-wrap:wrap;">'
                . '<label style="display:block;font-weight:600;margin:0;">Ringba campaign</label>'
                . '<button type="button" id="ringba_refresh_campaigns_btn" onclick="ringbaLoadCampaigns(true)" '
                . 'style="padding:4px 10px;font-size:12px;background:#1565c0;color:#fff;border:none;border-radius:4px;cursor:pointer;font-weight:600;">Refresh list</button>'
                . '</div>'
                . '<select id="ringba_campaign_picker" '
                . 'style="width:100%;padding:10px;border:2px solid #ddd;border-radius:4px;margin-bottom:6px;" '
                . 'onchange="ringbaApplyCampaignPicker()">'
                . '<option value="">Loading campaigns…</option>'
                . '</select>'
                . '<div id="ringba_picker_status" style="font-size:12px;color:#666;line-height:1.4;">Loads from your connected Ringba API.</div>'
                . '</div>';
        } else {
            $pickerBlock = '<p style="font-size:12px;color:#666;margin:0 0 12px;line-height:1.45;">'
                . 'Connect an API token under <a href="' . $optsUrl . '" style="color:#0d47a1;font-weight:600;">Honeycomb → Ringba</a> '
                . 'to pick campaigns from a dropdown. Until then, paste the <code>CA…</code> id below.'
                . '</p>';
        }

        return '<div class="honeycomb-binding-panel" data-provider-key="" data-always-visible="1" data-addon-slug="ringba"'
            . ' data-ringba-api-url="' . $apiUrl . '"'
            . ' data-ringba-api-connected="' . ($apiConnected ? '1' : '0') . '"'
            . ' data-ringba-selected-campaign="' . $campEsc . '"'
            . ' style="display:none;margin-bottom:16px;padding:14px;background:#f0f7fb;border:1px solid #a8c5d8;'
            . 'border-left:4px solid #1565c0;border-radius:6px;">'
            . '<strong style="color:#0d47a1;">Ringba (Honeycomb)</strong>' . $apiBadge
            . '<p style="font-size:12px;color:#555;margin:8px 0 12px;line-height:1.45;">'
            . 'Pay-per-call destination helper. Works with any traffic source. '
            . 'Pixel URLs: <a href="' . $optsUrl . '" style="color:#0d47a1;font-weight:600;">Honeycomb → Ringba</a>.'
            . '</p>'
            . '<label style="display:flex;align-items:center;gap:10px;margin-bottom:14px;cursor:pointer;">'
            . '<input type="hidden" name="honeycomb_binding[' . $slug . '][enabled]" value="0">'
            . '<input type="checkbox" id="ringba_binding_enabled" name="honeycomb_binding[' . $slug . '][enabled]" value="1"'
            . ($enabled ? ' checked' : '')
            . ' style="width:18px;height:18px;" onchange="if(typeof toggleRingbaLpCodes===\'function\')toggleRingbaLpCodes();'
            . 'if(this.checked&&typeof ringbaLoadCampaigns===\'function\')ringbaLoadCampaigns(false);">'
            . '<span style="font-weight:600;color:#333;">Enable Ringba on this campaign</span>'
            . '</label>'
            . '<div id="ringba_binding_details" style="' . ($enabled ? '' : 'display:none;') . '">'
            . $pickerBlock
            . '<input type="hidden" id="ringba_remote_campaign_name" name="honeycomb_binding[' . $slug . '][remote_campaign_name]" value="' . $nameEsc . '">'
            . '<label style="display:block;font-weight:600;margin-bottom:6px;">Call tracking / script ID</label>'
            . '<input type="text" id="ringba_js_tag_id" name="honeycomb_binding[' . $slug . '][js_tag_id]"'
            . ' value="' . $jsEsc . '" placeholder="CAxxxxxxxxxxxxxxxx"'
            . ' style="width:100%;padding:10px;border:2px solid #ddd;border-radius:4px;margin-bottom:4px;font-family:monospace;"'
            . ' oninput="if(typeof updateRingbaLpCodes===\'function\')updateRingbaLpCodes();">'
            . '<div style="font-size:12px;color:#666;margin-bottom:12px;line-height:1.4;">'
            . 'Used in <code>//b-js.ringba.com/<strong>CA…</strong>.js</code> (Ringba campaign id). '
            . 'Filled from the picker when API is connected; you can still paste manually.'
            . '</div>'
            . '<label style="display:block;font-weight:600;margin-bottom:6px;">Ringba tag name for <em>Kuma’s</em> click id</label>'
            . '<input type="text" id="ringba_click_param" name="honeycomb_binding[' . $slug . '][click_param]"'
            . ' value="' . $clickEsc . '" placeholder="click_id"'
            . ' style="width:100%;padding:10px;border:2px solid #ddd;border-radius:4px;margin-bottom:4px;font-family:monospace;"'
            . ' oninput="if(typeof updateRingbaLpCodes===\'function\')updateRingbaLpCodes();">'
            . '<div style="font-size:12px;color:#666;margin-bottom:12px;line-height:1.45;">'
            . '<strong>Not</strong> your traffic-source click id (Propeller, Meta, Taboola, etc.). '
            . 'This is only the <strong>label</strong> Ringba uses to store <strong>Kuma’s</strong> click id on the call '
            . 'so payout pixels can send it back. Leave as <code>click_id</code> unless you already created a different '
            . 'URL Parameter in Ringba. Then create that same name under Ringba → Integrations → URL Parameters '
            . 'and use <code>[tag:User:' . $clickEsc . ']</code> in your pixels.'
            . '</div>'
            . '<label style="display:block;font-weight:600;margin-bottom:6px;">Number to replace (optional)</label>'
            . '<input type="text" id="ringba_number_to_replace" name="honeycomb_binding[' . $slug . '][number_to_replace]"'
            . ' value="' . $numEsc . '" placeholder="(888) 555-0100"'
            . ' style="width:100%;padding:10px;border:2px solid #ddd;border-radius:4px;margin-bottom:4px;"'
            . ' oninput="if(typeof updateRingbaLpCodes===\'function\')updateRingbaLpCodes();">'
            . '<div style="font-size:12px;color:#666;margin-bottom:12px;line-height:1.4;">'
            . 'Must match the default number on your LP that Ringba’s script swaps (number pool DNI).'
            . '</div>'
            . '<label style="display:block;font-weight:600;margin-bottom:6px;">Ringba campaign ID (Kuma binding)</label>'
            . '<input type="text" id="ringba_remote_campaign_id" name="honeycomb_binding[' . $slug . '][remote_campaign_id]"'
            . ' value="' . $campEsc . '" placeholder="Usually same CA… as above"'
            . ' style="width:100%;padding:10px;border:2px solid #ddd;border-radius:4px;margin-bottom:4px;">'
            . '<div style="font-size:12px;color:#666;margin-bottom:4px;line-height:1.45;">'
            . 'Kuma’s internal link between <em>this</em> campaign and a Ringba campaign (stored in Honeycomb bindings). '
            . 'It is <strong>not</strong> an extra script to paste — the script above uses <strong>Call tracking / script ID</strong>. '
            . 'When you pick from the dropdown, both fill automatically. If you paste a <code>CA…</code> by hand, leave this blank '
            . 'and Kuma copies the script ID on save. Only change this if Ringba’s campaign id and script id ever differ.'
            . '</div>'
            . '</div>'
            . '</div>'
            . '<script>(function(){var cb=document.getElementById("ringba_binding_enabled");'
            . 'var det=document.getElementById("ringba_binding_details");'
            . 'if(cb&&det){cb.addEventListener("change",function(){det.style.display=cb.checked?"block":"none";});}'
            . 'if(cb&&cb.checked&&typeof ringbaLoadCampaigns==="function"){setTimeout(function(){ringbaLoadCampaigns(false);},0);}'
            . '})();</script>';
    }
}
