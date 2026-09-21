# Honeycomb addons (source)

Addon packages for [kumatrk/honeycomb-addons](https://github.com/kumatrk/honeycomb-addons).

These folders are **not** shipped inside the Kuma production zip. Users import published zips from the catalog into `honeycomb/addons/` on their server.

Each addon keeps its own **`CHANGELOG.md`** (Keep a Changelog). Bump `honeycomb.json` `version` and add a changelog section in the same PR / publish.

## Cost grain & stats fast path

Honeycomb cost addons write **campaign-level** spend into `honeycomb_campaign_hourly_costs`, keyed by the remote campaign ID bound on the Kuma campaign. Core `HoneycombCostAggregator` overlays that onto campaign KPI / chart totals alongside Facebook and Google — **summary-first**, no per-click cost joins.

Click tokens (adset / ad / creative names) remain available for breakdowns and visitor logs. Per-adset or per-ad **cost allocation** for Honeycomb networks is not in v1 (Facebook has a separate Meta hourly overlay for those dims).

## Taboola Cost API (`addons/taboola-cost`)

Uses [Taboola Backstage API](https://developers.taboola.com/backstage-api/reference/welcome):

1. **Auth** — client credentials (`client_id` + `client_secret`) → bearer token (~12h)
2. **Account** — alphabetic `account_id` from `users/current/account` (or network/sub-account)
3. **Cost** — campaign-summary `day` dimension + realtime `by_campaign` for today → `spent`
4. **Storage** — core table `honeycomb_campaign_hourly_costs` (day buckets at hour `0`)
5. **Stats** — core `HoneycombCostAggregator` overlays spend via `campaign_addon_bindings` (no click scans)

### Local install (dev)

```powershell
php scripts/run-migration-090.php
# ensure migration 092 has also run via Settings → Update database

php scripts/install-local-honeycomb-addon.php honeycomb-addons/addons/taboola-cost
```

Then open **Honeycomb** → save Taboola credentials → edit a campaign with a Taboola traffic source → set remote account + campaign IDs.

## Whop Ads (`addons/whop-ads`)

Uses [Whop Events API](https://docs.whop.com/developer/ads/events-api), [Ad Reports](https://docs.whop.com/api-reference/ad-reports/retrieve-ad-report), and [Whop Pixel](https://docs.whop.com/developer/guides/pixel) for [Whop Ads](https://docs.whop.com/manage-your-business/growth-marketing/ads):

1. **Traffic source** — create from **Whop Ads** template (`wacid` / `wasid` / `waid` / Meta UTMs / `fbclid`); `integrated_api` cost method
2. **Credentials** — `biz_…` account + API key with `event:create` **and** `ad_campaign:stats:read` (Honeycomb Options)
3. **Click capture** — core stores `_wuid` + `original_landing_url` in `clicks.extra_json`
4. **Export** — on conversion, Honeycomb `conversion_export` posts `lead` (default) to Whop with stable `kuma_conversion_{id}`
5. **Cost** — `WhopCostSyncJob` calls `GET /ad_reports` per bound Whop ad campaign id → `honeycomb_campaign_hourly_costs`
6. **Outbox** — `honeycomb_conversion_exports` (migration 093)

Requires Kuma core with Honeycomb (`conversion_export` + `cost_sync` hooks). Future event/cost networks reuse those hooks without another core change.

### Local install (dev)

```powershell
# ensure migration 093 has run via Settings → Update database
php scripts/install-local-honeycomb-addon.php honeycomb-addons/addons/whop-ads
```

Then **Honeycomb → Whop Ads** → save credentials → edit campaign → enable **Send conversions to Whop Ads** and set **Remote campaign ID** (Whop ad campaign id) for spend.

### Publish (catalog)

Zip the addon folder (so `honeycomb.json` is at zip root), compute sha256, attach a GitHub Release asset on `kumatrk/honeycomb-addons`, and add an entry to `catalog.json`.
