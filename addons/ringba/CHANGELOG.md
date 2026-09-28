# Changelog — Ringba

All notable changes to this Honeycomb addon are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project uses [Semantic Versioning](https://semver.org/).

## [1.1.1] - 2026-09-28

### Changed
- Clearer campaign-editor copy: “Ringba tag name for Kuma’s click id” — explicitly not traffic-source click ids (Propeller, Meta, etc.).
- Options pixel URLs document that `[tag:User:…]` must match the campaign’s click-id tag name when not `click_id`.

## [1.1.0] - 2026-09-28

### Added
- Optional Ringba API credentials (account ID + API access token) on Honeycomb Options.
- Connection test on save and via Test connection button.
- Campaign editor picker: list live Ringba campaigns and fill script / binding ids (paste fallback remains).
- Best-effort tag fetch for number-to-replace when selecting a campaign.
- Core AJAX endpoint `public/api/honeycomb-ringba.php` (`status`, `campaigns`, `tags`).

## [1.0.0] - 2026-09-28

### Added
- Initial release: utility addon for pay-per-call / Ringba attribution.
- Campaign Honeycomb panel: enable Ringba, paste call-tracking tag id (`CA…`), click_id URL param name, optional number-to-replace.
- Campaign Tracking copy panel (core): Ringba JS + click_id tag glue when enabled.
- Options page: pixel URL builder for Connected / Converted / Payout → Kuma postback.
- Docs for Ringba URL Parameters + Pixels setup (no API required for v1).
