# SYSTEM.md

## Purpose

SPENCE is a self-hosted nutritional logging, recipe, and kitchen inventory app. It tracks what you eat, what you cook, what you have in stock, and what you spend. Receipts are scanned via AI vision and auto-populate inventory with macro estimates. It is the nutritional counterpart to FORGE — together they form a sovereign personal fitness operating system.

## Tech Stack

- **Backend:** PHP 8.2+, no framework — files served directly by Nginx + PHP-FPM
- **Database:** SQLite 3 (WAL mode) via PDO — single-file, portable
- **Frontend:** Bootstrap 5.3 dark theme, Bootstrap Icons, Chart.js 4.x, vanilla JS (`fetch()` only)
- **AI Vision:** OpenRouter API (Gemini Flash default; any OpenAI-compatible vision endpoint) via `receipt_api.php`
- **Auth:** HMAC cookie — 1-year token derived from `hash_hmac('sha256', 'spence_auth_v1', $ACCESS_KEY)`, survives session GC
- **PWA:** `site.webmanifest`, SVG favicon, mobile-web-app-capable meta tags
- **Sister app:** FORGE (`/srv/jaaaames.com/forge/`) — same architecture, shared patterns

## Architecture

```
/spence/
  index.php            ← Intelligence Dashboard (4×2 live metrics)
  stock/               ← Inventory management + receipt scan + Spice Rack modal
  eat/                 ← Consume items from inventory by weight or unit
  cook/                ← Browse/execute recipes, live macro preview, substitutions
  recipedb/            ← Recipe builder with live macro + cost preview
  log/                 ← Daily/weekly macro summaries, charts, composition
  settings/            ← User profile, TDEE, macro targets, product master
  core/
    auth.php           ← HMAC cookie auth; ACCESS_KEY constant must be changed
    db_helper.php      ← PDO singleton, WAL, helpers, spice rack auto-migrate
    api.php            ← Inventory, products, jobs CRUD
    raid_api.php        ← Ghost Recipe Engine, cook execution, inventory deduction
    receipt_api.php    ← Receipt scan → OpenRouter vision → ingest pipeline
    bridge_ingest.php  ← Maps OpenRouter JSON output to DB inserts
    matching.php       ← Jaccard similarity + category locking deduplication
    ingredients_api.php ← Recipe ingredient helpers
    user_api.php        ← User profile/goals CRUD
    quick_eat_api.php    ← Quick Eat bypass
    upload.php          ← Receipt image upload handler
    spence.css         ← Mobile-first responsive overrides, One Dark Industrial theme
  database/
    schema.sql         ← Full DDL (committed)
    spence_empty.db    ← Blank template (committed)
    spence.db          ← Live data (gitignored)
```

## Design Goals

- **Receipt-to-pantry in one tap:** No manual entry. Point camera at receipt, AI parses it, inventory updates.
- **Ghost Recipe Engine:** Cook with substitutions without destroying the canonical recipe. History is preserved accurately.
- **Live macro feedback:** Every cook, every eat, every recipe build shows running macro totals in real time.
- **Cost awareness:** Every item has a unit cost. Every cook has a per-serve cost. Monthly spend is projected from consumption data.
- **Spice Rack:** Binary pantry staple tracking. Never run out of black pepper without knowing.
- **Self-hosted sovereignty:** OpenRouter API key is the only external dependency. Everything else runs locally.

## Design Non-Goals

- No social features or meal sharing
- No barcode scanning (receipt scanning covers grocery purchases; barcode is a future label scan feature in v2.0)
- No community recipe sharing
- No exercise integration beyond SPENCE → FORGE data sharing (planned)
- Not hardened for public internet exposure (v2.1 addresses deployment hardening)

## Runtime Topology

- **Deployed:** `/srv/jaaaames.com/spence/` (Nginx vhost, PHP-FPM socket)
- **Database:** `database/spence.db` — gitignored live data
- **Auth key:** `core/auth.php` `ACCESS_KEY` constant — must be changed from default before network exposure
- **OpenRouter key:** `core/openrouter.env` — single line, gitignored
- **Migration target:** OptiPlex (same migration as FORGE) — pending hardware delivery
- **Access:** HTTPS only, HMAC cookie auth, 1-year token lifetime

## Key Design Decisions

| Decision | Chosen Because |
|----------|---------------|
| HMAC cookie over PHP sessions | PHP sessions are wiped by garbage collection; sessions were expiring randomly |
| Receipt AI over manual entry | Minimises friction — grocery shopping is already tracked, just not digitised |
| Ghost Recipe Engine for substitutions | Preserves accurate macro history without branching the canonical recipe |
| Jaccard similarity for deduplication | Handles "Full Cream Milk" vs "Whole Milk" — string matching alone fails here |
| Katch-McArdle for TDEE | Uses actual lean mass rather than body weight; more accurate for trained individuals |
| Synchronous receipt ingest | Single request completes the full pipeline; no background service or queue management needed |
| 18 canonical spices seeded | Enough to cover common pantry without being exhaustive; custom spices can be added |

## Key Design Patterns

- **Ghost Recipe Engine:** Any deviation during cook (substitution selected OR quantity differs from canonical by >10g) creates `is_active=0` fork with `parent_recipe_id` set. Fingerprint prevents duplicate ghosts from repeated identical substitutions.
- **Product ↔ Recipe sync:** `syncRecipeToProduct()` recalculates macros/cost per portion whenever a recipe is saved. Each recipe version gets its own `product_id` to prevent inventory stacking.
- **Spice Rack auto-migrate:** `get_db_connection()` runs `CREATE TABLE IF NOT EXISTS` for `spice_rack` and `recipe_spices` on every page load — schema migration without explicit migration step.
- **HMAC auth:** `hash_hmac('sha256', 'spence_auth_v1', $ACCESS_KEY)` stored as cookie. Server recomputes and compares — no session storage needed.

---

*Last updated: 2026-04-27*
