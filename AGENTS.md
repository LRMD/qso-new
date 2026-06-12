# AGENTS.md

Guidance for AI agents working in this repository (`new/`) — the map-first redesign
of the qrz.lt amateur-radio award site (WAL / LHFA / LYFF). This folder is its own
git repo and the deployable unit; it is mounted at the **`/new`** subpath in
production but is location-independent (see below).

The design/requirements live in `../new.md`, the phased build log in `../plan.md`,
and the legacy business logic in `../qso.md` (all in the parent working tree, not in
this repo). Read those for *why*; read this for *how to work here*.

## Non-negotiable constraints
- **Backend = PHP 7.4, native only.** No Composer, no framework, no third-party PHP
  libraries. Standard library only (`mysqli`, `json_*`, `DateTime`, `parse_ini_file`,
  filesystem, math). Target 7.4 — don't add version-guards for other PHP releases.
- **Frontend = Vue 3 + MapLibre GL JS, vendored, no bundler.** Libraries live in
  `assets/vendor/` (pinned Vue 3.5.13, MapLibre 4.7.1). `app.js` is plain ES5-ish JS
  with the Vue template authored as a **string array** (`template: [...].join('\n')`).
  npm is allowed only to fetch/vendor assets, never at runtime.
- **`qso` table is the only source of truth** for activity. Reference tables
  (`lhfa`, `wal`, `wal_coords`, `lyff`) and `data/lyff.geojson` supply geometry/names
  only. The legacy `*_achievements` / cache tables are **not** used.
- **Read-only DB.** The API never writes.
- **Location-independent.** The mount prefix is auto-derived from
  `$_SERVER['SCRIPT_NAME']` (router.php) and `<base href>` (view.php). Never hardcode
  `/new`; the app must run unchanged under `/new`, `/`, or any folder.

## Layout
```
index.php            front controller (config → router → whitelist → handler → cache → JSON; else SPA shell)
src/
  http.php           json_out / send_json / json_encode_api / fail()
  config.php         load_config() — parses .env.ini ([database]; optional [app].cache_dir)
  db.php             db_connect() — mysqli, charset, read-only
  router.php         route() — splits URI relative to dirname(SCRIPT_NAME)
  programmes.php     PROGRAMMES whitelist (mode → c1/c2 columns + total) — the SQL-injection guard
  cache.php          data_version() = MAX(datetimenow); cached() = file cache
  geometry.php       reference geometry builders (lhfa_features, wal_features, lyff_features) + code parsers
  api.php            handlers: objects, recent, stats, object, activator, meta, nearest
  view.php           shell_html() — SPA HTML shell with <base href> + ?v=APP_BUILD assets
assets/
  app.js  app.css    the SPA (single Vue component)
  vendor/            vue.global.prod.js, maplibre-gl.js/.css
data/
  lyff.geojson       LYFF polygons fetched from OSM (parks/reserves; ~71 features)
  lyff_misses.txt    LYFF objects with no OSM match (list-only)
  lhfa.json/lyff.json  reference data exports (seed the DB tables)
tools/
  fetch_lyff_polygons.php   one-off OSM Nominatim batch (CLI; --retry-misses)
.env.ini             DB creds (gitignored)         .htaccess  rewrite + deny secrets/src/cache/tools
cache/               file-cache dir (gitignored, must be writable)
```

## Request flow (backend)
`index.php` wires everything. For `/api/...`: connect DB, compute
`$version = data_version($db) . '|' . APP_BUILD`, then dispatch:
- `api/meta/last-update`, `api/nearest?lat=&lng=`
- `api/{mode}/objects|stats|recent|object/{code}|activator/{call}` where `{mode}` ∈
  `wal|lhfa|lyff`.

Every response is wrapped in `cached($cacheDir, $key, $version, fn)`. Non-API routes
return the SPA shell.

**SQL safety model:** column names (`c1`/`c2`, e.g. `wal1`/`wal2`) come **only** from
the `PROGRAMMES` constant — never from the request — so the otherwise-dynamic
`` `$c1` `` interpolation is safe. Every user *value* (limit, code, callsign, lat/lng)
is `(int)`/`(float)`-cast or format-validated **and** bound via a prepared statement.

## Frontend architecture
`view.php` emits the shell (with `<base href>` = mount point and
`assets/app.{js,css}?v=APP_BUILD`). `app.js` is one Vue component:
- State refs (mode, stats, recent, selected, search, basemap, geo, …).
- MapLibre map; per-mode layers (WAL/LYFF polygons, LHFA points). Color is **RAG by
  recency**, computed client-side from the `last_ts` property
  (`['interpolate', …, NOW - last_ts]`: ≤1y green → 30y red; no `last_ts` = gray).
- Panels: stats (pie tabs band/mode), recent activations, activator search, object
  detail; mobile geolocation modal.
- Client routing relative to `<base>`: `/{mode}[/{code}]`, `?op=CALLSIGN`, map view in
  `#hash`.

## Critical caveats (these have all bitten us)
- **`APP_BUILD`** (top of `index.php`) is in the API cache key **and** the asset URL
  (`?v=`). **Bump it on every change to API output, geometry, or `app.{js,css}`** —
  otherwise stale server caches and browser-cached JS are served.
- **mysqli charset must be `utf8`** (not `utf8mb3`) — some client libs reject the
  `utf8mb3` alias, silently fall back, and Lithuanian names come back as invalid
  UTF-8, making `json_encode` return `false` → 500. `json_encode_api()` also uses
  `JSON_INVALID_UTF8_SUBSTITUTE` as a backstop.
- **WAL polygons:** `wal_coords` stores corners in raster order (NW, NE, SW, SE);
  using them directly makes a self-intersecting **bowtie** that MapLibre drops.
  `wal_coords_polygon()` builds an axis-aligned **bbox rectangle** instead.
- **LHFA points are NOT natively clustered** — MapLibre clustering produced 0 tile
  features on the real data. They render as plain circles.
- **DB is MariaDB 10.6**; write queries that are **`ONLY_FULL_GROUP_BY`-safe**.
- **LYFF geometry** exists only for parks/reserves (in `data/lyff.geojson`); the ~255
  *draustiniai* have no polygon and are list-only.
- **Geolocation / "my location" modal is touch-devices only** (gated on
  `ontouchstart`/`maxTouchPoints`).
- `?before=` on `recent` is read into the cache key but unused (8.1 dropped the time
  window) — harmless leftover.

## Dev / test / debug — everything via podman
Per project convention, **never use host `php`/`node`/`npm`** — run them in
containers. On this Fedora host, bind mounts need the SELinux relabel flag **`:z`**.
Commands below assume the working dir is the **parent** tree (`qso/`), so paths are
`new/...`; adjust if you run from inside `new/`.

**PHP lint (7.4):**
```bash
podman run --rm -v "$PWD":/app:z -w /app php:7.4 \
  sh -c 'for f in new/index.php new/src/*.php new/tools/*.php; do php -l "$f"; done'
```

**JS syntax check:**
```bash
podman run --rm -v "$PWD":/app:z -w /app node:20-alpine node --check new/assets/app.js
```

**SQL validation (throwaway MariaDB 10.6, matches prod):** create the relevant
table(s) + a few rows, set `SET SESSION sql_mode='ONLY_FULL_GROUP_BY,…'`, and run the
query. Used to validate `api_recent`, `api_nearest`, etc. before deploy.
```bash
podman run -d --name maria -e MARIADB_ROOT_PASSWORD=x -e MARIADB_DATABASE=test mariadb:10.6
podman exec maria sh -c 'until mariadb -uroot -px -e "SELECT 1" >/dev/null 2>&1; do sleep 1; done'
podman exec -i maria mariadb -uroot -px test < script.sql ; podman rm -f maria
```

**Browser / E2E + screenshots (headless Chromium):** the stock playwright image has
browsers but not the npm package, so install it into `/work` and point `NODE_PATH`
at it. The proven pattern: a tiny Node static server serves a harness dir containing
the real `assets/` + **API response fixtures as files** (e.g. `api/wal/objects`,
`api/nearest`), then drive Chromium and screenshot. For mobile/geolocation tests use
`newContext({ hasTouch:true, isMobile:true, geolocation:{…}, permissions:['geolocation'] })`.
```bash
podman run --rm -v "$PWD":/app:z -w /app \
  -e PLAYWRIGHT_BROWSERS_PATH=/ms-playwright -e PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1 \
  -e NODE_PATH=/work/node_modules \
  mcr.microsoft.com/playwright:v1.48.0-jammy \
  bash -lc 'mkdir -p /work && cd /work && npm i playwright@1.48.0 --no-fund --no-audit >/dev/null; node /app/runner.js'
```
Read PNGs the runner writes (e.g. `_shot.png`) to verify rendering. **Clean up**
temp harness dirs, runner scripts, screenshots, and any `maria`/`play` containers
when done.

**The `php:7.4` image has NO `mysqli`** — so the API/geometry **cannot be run against
a real DB locally**. Verify SQL on MariaDB (above) and lint/logic-check the PHP; the
live API is tested on the real server. (Or build an image: `FROM php:7.4` +
`docker-php-ext-install mysqli`.)

**Local run (static + SPA shell only; API needs a DB):**
```bash
podman run --rm -v "$PWD":/app:z -w /app/new php:7.4 php -S 0.0.0.0:8080 index.php
```
`index.php` has a `PHP_SAPI === 'cli-server'` passthrough so `php -S` serves static
assets directly.

## Deploy
Upload the changed files. Because `APP_BUILD` is part of both cache keys, bumping it
invalidates the server's file cache and the browsers' asset cache automatically — no
manual cache clearing needed (`cache/*.json` can also be deleted). `.env.ini` (DB
creds) and `cache/` are gitignored; `cache/` must be writable on the host.

The LYFF polygon dataset is regenerated offline with
`tools/fetch_lyff_polygons.php` (respects Nominatim's 1 req/s policy; `--retry-misses`
re-queries gaps with simplified names). Run it via the `php:7.4` podman image (it
uses `file_get_contents` only, no mysqli).
