# Honeycomb addons

Catalog of Simple Kuma Honeycomb addons. Kuma reads this repo from the Honeycomb page.

Kuma core updates stay on [`kumatrk/initialrelease`](https://github.com/kumatrk/initialrelease). Addons are published here so they can be imported and updated **without** a full Kuma zip.

## How Kuma uses this repo

1. The Honeycomb page fetches [`catalog.json`](catalog.json) over HTTPS (JSON only).
2. The user clicks **Import** on an addon they want.
3. Kuma downloads that addon zip, checks `sha256`, and extracts into `honeycomb/addons/` on the install.

Nothing in this repo is executed until it is installed locally on a Kuma server.

## Published addons

| Slug | Version | Provider |
|------|---------|----------|
| `taboola-cost` | 1.0.0 | Taboola Backstage cost sync |
| `whop-ads` | 1.0.0 | Whop Ads conversion export |

Source for each addon also lives under `addons/<slug>/`. Installable packages are GitHub Release zip assets referenced from `catalog.json`.

## Requirements

These addons need a Kuma install that already includes the Honeycomb core (kernel, migrations, Honeycomb UI). They will not work on older public zips that do not yet ship Honeycomb.
