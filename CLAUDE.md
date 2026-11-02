# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What This Is

SPENCE is a single-user PHP/SQLite kitchen inventory, recipe, and nutritional logging app. It uses NanoGPT (`google/gemini-3.7-flash`, via `core/nanogpt_client.php`) to parse grocery receipts and Quick Eat food photos (JPG/PNG/WebP/HEIC) with vision AI, estimate macros from a text description (Quick Eat → New Food (Describe)), and auto-populate inventory. Designed for local-first personal use — not hardened for public internet exposure.

## Running the App

No build step. Serve the directory root with PHP-FPM 8.2+ via Nginx/Apache.

**Local dev:** `devserve up spence` serves this working tree at `http://127.0.0.1:8700/spence/` (access key `dev`) against the gitignored `database/spence.db` snapshot, with scanning and the live Ingredients list disabled. See `~/src/devserve/README.md`.

**Database init:**
```bash
cp database/spence_empty.db database/spence.db
chmod 666 database/spence.db
```

**Receipt ingest** is synchronous — scanning a receipt completes the full pipeline (upload → NanoGPT vision → ingest → merge suggestions) in a single HTTP request via `core/receipt_api.php`; `ingestReceiptItems()` in `core/receipt_ingest.php` maps the JSON to DB inserts. No background service required. `core/bridge_ingest.php` is a legacy CLI bridge for completed `jobs` rows.

**Credentials** are read from files by `spenceReadCredential()` (`core/runtime_config.php`): the access key from `SPENCE_CREDENTIAL_FILE` (default `/srv/secrets/spence_credentials.env`, production `/etc/spence/access-key`) and the NanoGPT key from `NANOGPT_CREDENTIAL_FILE`. The `SPENCE_ACCESS_KEY` / `NANOGPT_API_KEY` env vars are a local-development fallback only. See `DEPLOYMENT.md`.

**Tenancy**: the original install is the primary tenant; guests are key files in `SPENCE_TENANTS_DIR` with their own DB and uploads under `SPENCE_TENANT_DATA_DIR/<id>/`. `auth.php` resolves the tenant from the cookie (`<id>.<hmac>` for guests, the original token for the primary) and `spenceTenant()` (`core/runtime_config.php`) supplies its paths to `get_db_connection()` and `spenceUploadDir()`. Guests never get Forge (`getForgeDbConnection()` returns null) or the Ingredients list, and scan with their own `<id>.nanogpt` key via `spenceNanoGptKey()` (none means scanning is off, never the primary's key). Provision with `ops/spence-create-tenant.php`, which seeds the raw product catalogue; see `DEPLOYMENT.md`.

**Timezone** is hardcoded to `Australia/Sydney` in `core/db_helper.php`. Timestamps are stored in UTC and the offset changes with DST, so never apply a fixed offset: filter a local day or range with `col >= ? AND col < ?` and `spenceLocalDayBounds()`, and group or display per row with the SQL functions `spence_local_date()` / `spence_local_datetime()` registered by `get_db_connection()`.

## Architecture

Three-layer: frontend PHP pages → API endpoints → PDO SQLite.

**Frontend pages** (one `index.php` per directory): `stock/`, `eat/`, `cook/`, `recipedb/`, `log/`, `settings/`. Each page is self-contained (HTML + inline JS + PHP mixed), uses Bootstrap 5.3 dark theme, and calls backend APIs via vanilla `fetch()`.

**API endpoints** (POST only, return JSON):
- `core/api.php` — CRUD for inventory, products, jobs
- `core/raid_api.php` — consume items, execute recipes (Ghost Recipe Engine)
- `core/user_api.php` — user profile/goals CRUD
- `core/ingredients_api.php` — recipe ingredient helpers

**Core logic files:**
- `core/db_helper.php` — PDO connection, `getUnitCost()`, `syncRecipeToProduct()` (recalculates macros/cost when recipe changes)
- `core/matching.php` — Jaccard similarity deduplication during ingest
- `core/recipes.php` — `saveRecipe()` for the RecipeDB editor: ingredients by posted product id (through `merges_into`); an ingredient change forks a new version and retires the edited lineage
- `core/receipt_ingest.php` — maps the vision model's JSON output to DB inserts, AI catalogue matching

**Database:** migrations in `get_db_connection()` (`core/db_helper.php`) are the source of truth. `database/schema.sql` (the fully migrated schema) and `database/spence_empty.db` (the blank template, all migrations recorded) are generated from them by `ops/rebuild-schema.php`; run it after adding a migration, or `tests/test_schema.php` fails. Live `spence.db` is gitignored.

## Key Domain Concepts

**Ghost Recipe Engine** (`core/raid_api.php`): When a recipe is cooked with ingredient substitutions, an inactive fork (`is_active=0`, `parent_recipe_id` set) is auto-created. This preserves accurate macro/cost history without polluting the active recipe list. Fingerprinting prevents duplicate ghosts.

**Recipe → Product sync** (`syncRecipeToProduct()` in `db_helper.php`): Every saved recipe gets a linked `products` row with calculated macros per portion and cost per serve. Each recipe version forces a new `product_id` to prevent inventory stacking across versions.

**Cost basis tracking**: `inventory.price_paid` is total paid; `products.last_unit_cost` is averaged. `consumption_log` captures `unit_cost` at log time for historical accuracy. Smart edits to qty-only preserve unit cost.

**Deduplication flow**: After the vision call, `matchReceiptItemsWithAi()` (`receipt_ingest.php`) sends receipt items with no exact name/alias match plus the live catalogue to the model in one text call. 'same' verdicts stock straight onto the existing product and save the receipt wording to `product_aliases`; 'similar' verdicts become merge suggestions. If that call fails, ingest falls back to `matching.php` (Levenshtein + Jaccard, category-locked) for new products only. Suggestions carry `productMergePreview()` rows and are reviewed with `mergeReview()` (`spence.js`, used on Stock and Settings → Deduplication): pick which name and macros survive (`executeProductMerge($db, $src, $tgt, $macros, $nameFrom)`); the target keeps its id and unit, and stock converts between each and kg/L via weight per item. Unticked suggestions on Stock are dismissed. `merges_into` always points at the surviving product, never chained; ingest resolves names via `products.name` then `product_aliases`.

**Product naming**: Type first, then a comma and brand/descriptors (`Cheese Slices, Extra Tasty`, `Tim Tam, Arnotts Original`), no brackets, no store brands or pack sizes. The receipt prompt in `receipt_api.php` asks for this format.

## Key Additional Systems

**Spice Rack** (`spice_rack` + `recipe_spices` tables): Binary is_stocked state for pantry staples. 18 canonical spices seeded on boot via `INSERT OR IGNORE` in `get_db_connection()`. Uses counter increments on each cook; `restock_flagged` at 40 uses. Surfaces: Stock modal (bi-fire toolbar button), RecipeDB inline checkboxes, Cook spice check panel.

**Receipt AI routing**: Items classified as `Spice/Herb` are routed to `spice_rack` only if the name matches a rack spice (qualifiers like "Ground" ignored) or a confirmed alias in `spice_aliases`. Unmatched ones (meal kits, pastes) are stocked as `Other` and flagged in a post-scan review on Stock, where moving one to the rack reverses its inventory line and remembers the name.

**Quick Eat catalogue matching** (`core/quick_eat.php`): photo and describe scans send the live raw catalogue with the request, and the model returns a `kind` (packaged/fresh/meal) and a same/similar/new verdict per item in that one call. `resolveQuickEatMatch()` turns it into the review card's chip: 'same' with a valid id links (logs against the product with its macros and real price), an unmatched packaged/fresh item becomes a new product, and meals, 'similar' guesses and invalid ids are logged plainly so the catalogue stays clean. Tapping the chip cycles link → just log → new product. No merge step in Quick Eat. Speed: the request uses `reasoning_effort: minimal` (~4 s vs ~14 s at the default, same answers in a 2026-10-31 benchmark), and `eat/index.php` shrinks photos to 1536 px JPEG before upload (full-size phone photos took ~2.5× as long, and NanoGPT rejects ~6 MB ones with HTTP 413).

**Receipt expiry merging**: When a receipt restocks an existing inventory row, a dated new purchase replaces the row's expiry (restocks happen when stock is low; rotation makes the new date correct quickly). Undated purchases leave an in-stock date alone (`mergeInventoryExpiry()` in `receipt_ingest.php`).

**Spend & wastage** (`purchase_log`, `waste_log`): Every receipt line and manual add is logged per item (rack spices excluded; moving a line to the rack removes it). The Stock "wasted" button (bi-recycle) logs the row's remaining `price_paid` before deleting; the trash button is for corrections and logs nothing. Hand-edits that lower qty log the removed share as waste; raising qty never adds spend. `getWasteSummary()` reports waste % against consumed + wasted (not purchases, which are lumpy) — shown on the weekly cost card and dashboard projection card, deliberately not on the daily log.

**Energy calibration** (`core/energy_calibration.php`): Maintenance = average intake − weight-trend slope × `ENERGY_KJ_PER_KG` over the current phase, after a 14-day transition when Forge supplies the cycle. Confidence is "high" when the estimate's standard error is within `ENERGY_HIGH_CONFIDENCE_KJ` (plus 28+ days, 6+ weigh-ins, 80% coverage). The prior-phase "last stable" estimate is anchored to the current phase start (not today), and is never used across a bulk↔cut flip; targets use the formula instead.

**Food leaderboard** (`log/leaderboard.php`, `core/food_sources.php`): every food eaten, ranked by times eaten, serves, weight, kJ, protein or protein per 1000 kJ, in meal or ingredient mode (meals split across recipe ingredients). Deliberately not in any menu — reached only from the list icon on the dashboard's sources cards, which share its `getFoodSources()` totals. EACH / BY TYPE folds raw products by `productBaseName()` (the name before the comma: "Bacon, Streaky" → Bacon) via `groupFoodSources()`, counting sittings once; recipes and unlinked Quick Eat meals are never folded. Cook's `findSubstitutes()` ranks same-type stock first. Types group and rank; they never merge.

**Shared UI helpers** (`core/spence.js`, loaded in `page_head.php`): `.then(spenceJson)` parses API replies (use it instead of `r => r.json()`): a non-JSON reply (PHP crash, proxy page) throws a `ServerError`, and `requestErrorMessage(err)` words it as a server error rather than a connection problem; `data-autofocus` focuses a field when its modal opens; `attachProductSearch()` is the type-to-search product picker (name-only, empty until typing, ↑/↓/Enter). Use it instead of `<datalist>`/`<select>` for product lists. `handoffFile(file, url)` / `takeHandoffFile()` carry a picked photo across a navigation via IndexedDB (dashboard Scan Receipt → `stock/?scan=1`, camera → `eat/?quick=photo`; `eat/?quick=existing` opens the product picker).

**Mobile layout**: `core/spence.css` contains all responsive overrides. `d-none d-md-table-cell` hides secondary table columns on mobile. `.mob-detail-row` expands per-row detail on mobile (suppressed via CSS at md+). Section headers use Bootstrap `col-6 col-md-*` for inline heading+date on log pages.

## Database Tables

`products`, `inventory`, `recipes`, `recipe_ingredients`, `consumption_log`, `jobs`, `product_aliases`, `user_profiles`, `user_vitals_history`, `user_goals_history`, `spice_rack`, `recipe_spices`, `spice_aliases`, `purchase_log`, `waste_log`

Key enums: `inventory.location` = `'Pantry'|'Fridge'|'Freezer'`; `products.type` = `'raw'|'cooked'`; `jobs.status` = `'pending'|'completed'|'failed'`
