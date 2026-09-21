# Changelog — Taboola Cost API

All notable changes to this Honeycomb addon are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project uses [Semantic Versioning](https://semver.org/).

## [1.0.1] - 2026-09-21

### Added
- `CHANGELOG.md` included in the published zip so operators can read history after import.

## [1.0.0] - 2026-09-21

### Added
- Initial release: Taboola Backstage client-credentials auth, campaign day + realtime spend sync (`TaboolaCostSyncJob`).
- Settings panel (credentials + traffic source link); campaign remote account / campaign ID bindings.
- Manifest capabilities: `cost_sync`, `cost_overlay`, `settings_panel`, `campaign_fields`.

### Notes
- Spend is stored at **Taboola campaign → Kuma campaign** grain (day buckets in `honeycomb_campaign_hourly_costs`).
- Stats overlay uses core `HoneycombCostAggregator` (summary-first; do not also register a duplicate `CostOverlayProvider` for the same spend).
