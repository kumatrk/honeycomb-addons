# Changelog — Whop Ads

All notable changes to this Honeycomb addon are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project uses [Semantic Versioning](https://semver.org/).

## [1.1.1] - 2026-09-21

### Added
- `CHANGELOG.md` included in the published zip so operators can read history after import.

## [1.1.0] - 2026-09-21

### Added
- Hourly ad spend sync via Whop [`GET /api/v1/ad_reports`](https://docs.whop.com/api-reference/ad-reports/retrieve-ad-report) (`WhopCostSyncJob`).
- Manifest capability `cost_sync`; campaign bindings accept Whop **ad campaign** ID as `remote_campaign_id`.
- Settings guidance: API key needs `event:create` and `ad_campaign:stats:read`.

### Changed
- Linked / created traffic sources use `cost_tracking_method=integrated_api`.
- Summary text reflects conversion export + spend sync.

### Notes
- Spend is stored at **Whop ad campaign → Kuma campaign** grain (day buckets in `honeycomb_campaign_hourly_costs`).
- Click tokens (`wacid` / `wasid` / `waid`) still power visitor/breakdown labels; cost is not allocated per adset or ad.
- Stats overlay uses core `HoneycombCostAggregator` (summary-first; no per-click cost joins).

## [1.0.0] - 2026-09-21

### Added
- Initial release: Whop Events API conversion export, credentials panel, campaign conversion binding, pixel / CTA helpers (core).
