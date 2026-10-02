# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What This Is

SPENCE is a single-user PHP/SQLite kitchen inventory, recipe, and nutritional logging app. It uses OpenRouter (Gemini Flash) to parse grocery receipts via vision AI and auto-populate inventory. Designed for local-first personal use — not hardened for public internet exposure.

## Running the App

No build step. Serve the directory root with PHP-FPM 8.2+ via Nginx/Apache.

**Database init:**
```bash
cp database/spence_empty.db database/spence.db
chmod 666 database/spence.db
```

**Receipt ingest** is synchronous — scanning a receipt completes the full pipeline (upload → OpenRouter vision → ingest → merge suggestions) in a single HTTP request via `core/receipt_api.php`. No background service required. `core/bridge_ingest.php` handles the JSON-to-DB mapping.

**Auth key** is hardcoded in `core/auth.php` — change `"jaaaames_sovereign_2026"` before exposing to any network.

**Timezone** is hardcoded to `Australia/Sydney` in `core/db_helper.php`.

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
- `core/bridge_ingest.php` — maps OpenRouter JSON output to DB inserts

**Database:** `database/schema.sql` is the canonical schema. `database/spence_empty.db` is the blank template committed to git. Live `spence.db` is gitignored.

## Key Domain Concepts

**Ghost Recipe Engine** (`core/raid_api.php`): When a recipe is cooked with ingredient substitutions, an inactive fork (`is_active=0`, `parent_recipe_id` set) is auto-created. This preserves accurate macro/cost history without polluting the active recipe list. Fingerprinting prevents duplicate ghosts.

**Recipe → Product sync** (`syncRecipeToProduct()` in `db_helper.php`): Every saved recipe gets a linked `products` row with calculated macros per portion and cost per serve. Each recipe version forces a new `product_id` to prevent inventory stacking across versions.

**Cost basis tracking**: `inventory.price_paid` is total paid; `products.last_unit_cost` is averaged. `consumption_log` captures `unit_cost` at log time for historical accuracy. Smart edits to qty-only preserve unit cost.

**Deduplication flow**: New receipt items go through `matching.php` (Jaccard + category locking). Matches are flagged for user confirmation. Confirmed merges set `products.merges_into` FK and populate `product_aliases` (raw_name → canonical_product_id) for future scans.

## Key Additional Systems

**Spice Rack** (`spice_rack` + `recipe_spices` tables): Binary is_stocked state for pantry staples. 18 canonical spices seeded on boot via `INSERT OR IGNORE` in `get_db_connection()`. Uses counter increments on each cook; `restock_flagged` at 40 uses. Surfaces: Stock modal (bi-fire toolbar button), RecipeDB inline checkboxes, Cook spice check panel.

**Receipt AI routing**: Items classified as `Spice/Herb` are routed to `spice_rack` only if the name matches a rack spice (qualifiers like "Ground" ignored) or a confirmed alias in `spice_aliases`. Unmatched ones (meal kits, pastes) are stocked as `Other` and flagged in a post-scan review on Stock, where moving one to the rack reverses its inventory line and remembers the name.

**Receipt expiry merging**: When a receipt restocks an existing inventory row, a dated new purchase replaces the row's expiry (restocks happen when stock is low; rotation makes the new date correct quickly). Undated purchases leave an in-stock date alone (`mergeInventoryExpiry()` in `receipt_ingest.php`).

**Energy calibration** (`core/energy_calibration.php`): Maintenance = average intake − weight-trend slope × `ENERGY_KJ_PER_KG` over the current phase, after a 14-day transition when Forge supplies the cycle. Confidence is "high" when the estimate's standard error is within `ENERGY_HIGH_CONFIDENCE_KJ` (plus 28+ days, 6+ weigh-ins, 80% coverage). The prior-phase "last stable" estimate is anchored to the current phase start (not today), and is never used across a bulk↔cut flip; targets use the formula instead.

**Shared UI helpers** (`core/spence.js`, loaded in `page_head.php`): `data-autofocus` focuses a field when its modal opens; `attachProductSearch()` is the type-to-search product picker (name-only, empty until typing, ↑/↓/Enter). Use it instead of `<datalist>`/`<select>` for product lists.

**Mobile layout**: `core/spence.css` contains all responsive overrides. `d-none d-md-table-cell` hides secondary table columns on mobile. `.mob-detail-row` expands per-row detail on mobile (suppressed via CSS at md+). Section headers use Bootstrap `col-6 col-md-*` for inline heading+date on log pages.

## Database Tables

`products`, `inventory`, `recipes`, `recipe_ingredients`, `consumption_log`, `jobs`, `product_aliases`, `user_profiles`, `user_vitals_history`, `user_goals_history`, `spice_rack`, `recipe_spices`, `spice_aliases`

Key enums: `inventory.location` = `'Pantry'|'Fridge'|'Freezer'`; `products.type` = `'raw'|'cooked'`; `jobs.status` = `'pending'|'completed'|'failed'`
