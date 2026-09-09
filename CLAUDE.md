# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A WordPress plugin (`blk-synchronizer-main`) that one-way syncs products from BaseLinker (a PL order/inventory management SaaS) into WooCommerce: name, price, stock, weight, tax rate, description, images, category, EAN. It runs on a live production site (best-market.pl) as well as locally under MAMP.

## Commands

There is no build step, package manager script, or local test suite — this is a plain PHP WordPress plugin loaded directly by `wp-content/plugins/`.

- **Install PHP deps**: `composer install` (only dependency: `yahnis-elsts/plugin-update-checker`, used for GitHub-based self-updates).
- **Lint (what CI runs)**:
  - PHP syntax: `find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l`
  - JS syntax: `node --check assets/admin-page.js` (and any other file under `assets/*.js`)
- **CI** (`.github/workflows/ci.yml`): lints PHP/JS on every push/PR to `main`. Pushing a `v*` tag auto-creates a GitHub Release (release notes only — WordPress itself always tracks the `main` branch via the update checker, not tags).
- **Trigger a sync manually** (no WP-CLI, no cron needed):
  - HTTP: `GET /?action=blkSynchronizer&method=synchronize` (see `BlkEndpoints::register_endpoints()`) — **unauthenticated**, anyone who knows the URL can trigger it.
  - From the admin UI: BL Synk menu → "Run sync now" button (AJAX `wp_ajax_blk_start_import`, nonce + `manage_options` required).
- `readme.txt` references a `cli/start-synchronize.php` and `cli/delete-all-products.php` — that `cli/` directory does **not exist** in this repo; treat those as stale/aspirational unless you find them elsewhere.

## Architecture

**Bootstrap** (`blk-synchronizer.php`): defines all `BLK_*` constants (paths, tuning knobs, log retention), requires the class files, then wires up three collaborators:
- `BlkSynchronizer` — the sync engine (all business logic).
- `BlkEndpoints` — the unauthenticated HTTP trigger, wraps `$bs`.
- `BlkHooks` — cron scheduling + the authenticated AJAX trigger, wraps `$bs`.

It also defines the plugin-update-checker instance (points at `github.com/afisza/blk-synchronizer`, branch `main` — this is how the live site self-updates) and several free functions for a file-based mutex (`.import-lock`, `.import-stop`) that all sync entry points share.

**Sync pipeline** (`BlkSynchronizer::startSynchronize()`), file-diff based, not incremental via API deltas:
1. `downloadProducts()` — pulls the full BaseLinker catalog via `BaseLinkerHelper` (`getProductsList` for IDs, then `getProducts` for full data in chunks), normalizes it into this plugin's internal shape, and writes it to `new-products.json`.
2. `filterNewProducts()` — drops products whose `category_id`/`sku` are in the admin-configured ignore lists.
3. `prepareOldProducts()` — pages through existing WooCommerce products (`WC_Product_Query`) and writes a comparable snapshot to `old-products.json`.
4. Diff by SKU: `find_added_products` / `find_removed_products` / `find_updated_products` (`has_product_changed` compares name/price/weight/quantity/description/images).
5. Execute: `remove_products` (hard-deletes via `wp_delete_post`), `update_products`, `add_new_products` (`createProduct` upserts a `WC_Product_Simple`).

Every step in `startSynchronize()` writes `.sync-progress.json` (`step`/`percent`/`message`) — this is what the admin dashboard's progress bar and countdown poll via `blk_sync_status`/`blk_sync_progress` AJAX. `new-products.json`/`old-products.json`/`.sync-progress.json`/`.import-lock`/`.import-stop` are all runtime state written to the plugin directory itself (not the uploads dir) and are gitignored — don't try to commit or "fix" their contents.

Sync can be interrupted mid-run: `add_new_products`/`remove_products`/`update_products` each check `blk_is_stop_import()` on every iteration and bail out (clearing the lock) if the admin clicked "Stop".

**Locking**: a single sync run is guarded by a `.import-lock` file with a max age (`BLK_LOCK_MAX_AGE`, 10 min) after which it's considered stale and ignored — this is what allows the cron-based sync to self-recover if a previous run crashed without cleaning up (see git log for "Not scheduled" / cron self-heal history in `BlkHooks::maybe_schedule_sync()`, called on every `init`).

**WPML**: after every create/update, `sync_wpml_translations()` propagates price/stock/weight/tax and (if present in BaseLinker's `text_fields`) the translated name/description to every linked translation via `$sitepress->get_element_translations()`. This only runs if WPML (`icl_object_id`) is active.

**Price/stock/name resolution**: BaseLinker returns `prices`/`stock` as maps keyed by price-group-id/warehouse-id, and `text_fields` as a flat map with `|<lang>` suffixed keys (e.g. `name|de`). `extract_price`/`extract_stock`/`extract_name` in `BlkSynchronizer` resolve these against the admin-configured `blk_price_group_id`/`blk_warehouse_id`/`blk_default_language`, falling back to "first available" if unconfigured. These IDs can also be auto-detected from BaseLinker's default inventory (`load_inventory_config()`) when left blank in settings.

**Settings/admin UI** (`admin.php`, `BlkSettingsPage`): one settings group (`blk_settings`, sanitized centrally in `sanitize_settings`) rendered as a single tabbed admin page (Dashboard / Log / Settings tabs, all in one `settings_page()` render — no separate templates). Frontend behavior (progress polling, countdown, log viewer) lives in `assets/admin-page.js`, talking to the AJAX actions registered in `blk-synchronizer.php`/`BlkHooks.php`, all gated by `check_ajax_referer( BLK_NONCE_ACTION )` + `current_user_can('manage_options')`.

**Logging** (`helper.php`): three independent log streams (`blk_debug_log`, `blk_synk_log`, `blk_error_log`) written to `wp-content/uploads/blk-logs/` as `debug-<date>.log` / `synchronizer-<run-timestamp>.log` / `errors-<date>.log`. `BlkHooks::delete_old_logs()` prunes each stream to `BLK_LOG_MAX_FILES` (10) on every scheduled run.

## BaseLinker API

Docs: **https://api.baselinker.com**

All API access is centralized in `classes/BaseLinkerHelper.php` — this is the only place that should ever call BaseLinker directly. Everything is a single JSON-RPC-style endpoint:

- `POST https://api.baselinker.com/connector.php`, auth via `X-BLToken` header (token from `blk_settings['blk_api_token']`), body is `method` + `parameters` (JSON-encoded) as `application/x-www-form-urlencoded`.
- `BaseLinkerHelper::exec()` is the single low-level call wrapper: retries up to 3x on network errors or HTTP 429/5xx (backoff `attempt * 2` seconds), throws on `status === 'ERROR'`, and sleeps `BLK_API_DELAY_MICROSECONDS` (500ms) after every successful call to stay under rate limits.
- Methods currently used: `getInventories`, `getInventoryProductsList` (paginated, 1000/page), `getInventoryProductsData` (batched by `BLK_API_CHUNK_SIZE`, 1000 ids/call), `getInventoryCategories`. Note the code comments explicitly call out that these replace older/obsolete `getProductsList`/`getProductsData` methods — check the docs' changelog before "fixing" a method name back to the old ones.
