# Honeycomb addons

Catalog of Simple Kuma Honeycomb addons. Kuma reads this repo from the Honeycomb page.

Kuma core updates stay on [`kumatrk/initialrelease`](https://github.com/kumatrk/initialrelease). Addons (traffic sources first, later other integrations) are published here so they can be imported and updated **without** a full Kuma zip.

## How Kuma uses this repo

1. The Honeycomb page fetches [`catalog.json`](catalog.json) over HTTPS (JSON only).
2. The user clicks **Import** on an addon they want.
3. Kuma downloads that addon’s zip, checks `sha256`, and extracts into `honeycomb/addons/` on the install.

Nothing in this repo is executed until it is installed locally on a Kuma server.

## Catalog format

```json
{
  "schema_version": 1,
  "updated_at": "2026-09-08T00:00:00Z",
  "addons": [
    {
      "slug": "example-cost",
      "name": "Example Cost API",
      "version": "1.0.0",
      "type": "traffic_source",
      "provider_key": "example",
      "min_kuma": "1.1.6",
      "summary": "Pull spend from Example.",
      "zip_url": "https://github.com/kumatrk/honeycomb-addons/releases/download/example-cost-1.0.0/example-cost.zip",
      "sha256": "64-char-hex"
    }
  ]
}
```

`addons` is empty until the first addon ships.

## Addon layout (when we add one)

```
addons/example-cost/
  honeycomb.json
  src/
  migrations/
```

Each addon zip must contain `honeycomb.json` at the root (or one wrapping folder).
