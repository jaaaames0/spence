# COMPANION.md

## Why This File Exists

This is the operational manual and technical textbook for SPENCE. It explains the why behind the major systems — Ghost Recipe Engine, receipt ingest pipeline, deduplication, the HMAC auth model — and provides debugging guidance. A developer dropping in should understand the system in an afternoon.

---

## Mental Model

SPENCE is a nutritional tracking system built from three interlocking loops:

```
Receipt → [AI Parse] → Inventory
                            ↓
Recipe ← [Product Sync] ← [syncRecipeToProduct()]
    ↓
Cook → [Inventory Deduction] → Consumption Log
    ↓
Dashboard ← [Consumption Aggregation]
```

Receipts populate inventory. Inventory feeds recipes. Recipes are cooked and deducted from inventory. Consumption is logged and aggregated on the dashboard.

The Ghost Recipe Engine is the historical archive layer: any deviation from the canonical recipe during cooking creates an inactive fork. Forks are accurate records of what was actually eaten; the active recipe is the template for next time.

---

## Core Concepts

### Ghost Recipe Engine

The Ghost Recipe Engine preserves accurate macro and cost history when you cook with substitutions or custom quantities.

**Trigger conditions** (any deviation creates a ghost):
1. A substitution is selected for any ingredient (different product chosen)
2. The quantity entered differs from the canonical recipe amount by more than 10g (tolerance prevents ghost spam from rounding)

**Fingerprint:** The ghost is fingerprinted using a hash of `recipe_id + sorted(substitutions) + sorted(quantities)`. Re-cooking with identical deviations hits the same fingerprint and does not create a duplicate ghost — it appends to the existing fork's consumption log.

**Data model:**
```php
// Canonical recipe
recipes(id=1, name="Chili Con Carne", is_active=1, parent_recipe_id=NULL, product_id=10)

// Ghost fork (created when cooked with substitution)
recipes(id=99, name="Chili Con Carne", is_active=0, parent_recipe_id=1, product_id=NULL, version=NULL)
```

The ghost has no `product_id` — it's not a product in the master. It only exists to record what was consumed.

**Browsing surfaces** (`recipedb/index.php`, `cook/index.php`) filter `WHERE is_active = 1`. Ghosts are invisible unless you query directly.

**Consumption log resolution:** When displaying log entries, ghost `recipe_id` is JOINed to find `parent_recipe_id` and the canonical name is shown.

### Receipt Ingest Pipeline

Receipt scanning is a single synchronous HTTP request:

```
User uploads image → receipt_api.php
    → validate image (JPEG/PNG/HEIC, <10MB)
    → base64 encode
    → POST to OpenRouter (Gemini Flash) with structured output prompt
    → receive JSON array of {name, quantity, unit, price, macros_per_100g, category}
    → for each item:
        → lookup in product_aliases (raw_name → canonical_product_id)
        → if no alias match: run Jaccard similarity against existing products in same category
        → if Jaccard ≥ threshold: flag as potential duplicate for user confirmation
        → if no match: insert new product
        → insert inventory record
    → return merge suggestions to user
```

**OpenRouter prompt:** The prompt instructs the model to output structured JSON with specific fields. The model must be configured for JSON mode (or tool-use for OpenAI-compatible endpoints).

**Bridge ingest (`bridge_ingest.php`):** Maps the provider-agnostic JSON output to SPENCE's schema. Normalises units (e.g., "kg", "g", "1kg" all become grams for storage). Routes `Spice/Herb` category items to `spice_rack` instead of inventory.

### Jaccard Similarity Deduplication

Jaccard similarity measures overlap between two sets of character n-grams. For product names:

```
"Full Cream Milk" → {"fu", "ul", "ll", "l ", " Cr", "Cr", ...}
"Whole Milk"      → {"wh", "ho", "ol", "le", "l M", "Mi", ...}
Jaccard = intersection / union
```

**Category locking:** Jaccard is run only against products in the same `category`. This prevents "Milk" and "Bread" from false-matching.

**Threshold:** Items with Jaccard ≥ 0.6 are flagged as potential duplicates. Below 0.6 are treated as new products.

**Dismissed pairs:** User can dismiss a merge suggestion permanently. `dedupe_dismissed` table stores `(product_id_a, product_id_b)` pairs.

### Product ↔ Recipe Sync

Every recipe is linked to a `products` row via `recipes.product_id`. When a recipe is saved, `syncRecipeToProduct()` recalculates the linked product's macros per serve and cost per serve:

```php
function syncRecipeToProduct(PDO $db, int $recipe_id): void {
    // 1. Sum ingredient costs (inventory unit_cost × amount)
    // 2. Sum ingredient macros (kj_protein, fat, carbs per 100g × amount)
    // 3. Divide by yield_serves
    // 4. UPDATE products SET ... WHERE id = (SELECT product_id FROM recipes WHERE id = $recipe_id)
}
```

Each recipe version gets its own `product_id`. This prevents inventory stacking: if you change a recipe's ingredient list, the old product inventory deduction is preserved for historical log entries that reference it.

### Spice Rack

18 canonical spices are seeded on first boot via `INSERT OR IGNORE` in `get_db_connection()`:

Salt, Black Pepper, Paprika, Garlic Powder, Onion Powder, Cayenne Pepper, Cumin, Oregano, Basil, Thyme, Rosemary, Dill, Cinnamon, Nutmeg, Ginger, Turmeric, Chili Flakes, Coriander.

`spice_rack` table: `spice_name`, `is_stocked`, `uses_since_restock`, `restock_flagged` (boolean).

**Use counter:** Each `cook` action increments `uses_since_restock` for all spices linked to that recipe. `restock_flagged` flips to `1` when `uses_since_restock >= 40`.

**Receipt routing:** The vision AI classifies items as `Spice/Herb`. These are routed to `spice_rack` (insert new or restock existing) instead of inventory.

**Surfaces:**
- Stock page: fire icon toolbar button → modal with toggle switches and restock badge
- RecipeDB: inline checkbox grid in recipe modal
- Cook page: read-only status dots (green = stocked, amber = restock flagged, red = not stocked)

### HMAC Cookie Auth

PHP sessions expire when `session.gc_maxlifetime` fires or PHP-FPM restarts cause session GC to run. This was causing random logouts.

HMAC cookie solution:

```php
// Login — set cookie
$token = hash_hmac('sha256', 'spence_auth_v1', $ACCESS_KEY);
setcookie('spence_auth', $token, [
    'expires' => time() + 86400 * 365,
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Strict',
]);

// Every page load — validate
$ ACCESS_KEY from constant or credentials.env
if (!hash_equals(hash_hmac('sha256', 'spence_auth_v1', $ACCESS_KEY), $_COOKIE['spence_auth'] ?? '')) {
    // render login
}
```

No server-side session storage. The cookie IS the session. It survives PHP-FPM restarts and session GC.

### Katch-McArdle TDEE

```
BMR = 370 + (21.6 × lean_mass_kg)
TDEE = BMR × activity_multiplier
```

Lean mass = `weight_kg × (1 − body_fat_pct / 100)`

Activity multiplier from `user_profiles.activity_rate`: 1.2 (sedentary) to 1.9 (heavy training).

Macro targets: protein 2g/kg lean mass, fat 1g/kg, remaining kJ from carbs.

---

## Architecture Tour

### `core/auth.php`

Every page includes this first. Validates HMAC cookie. Renders login form if invalid. Sets `ACCESS_KEY` from constant — must be changed from default.

### `core/db_helper.php`

PDO singleton with WAL mode. Also runs spice rack and `recipe_spices` table auto-migrate on every connection. Key helper: `getUnitCost()`, `syncRecipeToProduct()`.

### `core/receipt_api.php`

Entry point for receipt scan. Validates image, calls OpenRouter, calls `bridge_ingest.php`, returns merge suggestions.

### `core/bridge_ingest.php`

Maps OpenRouter JSON to DB inserts. Normalises units. Routes spices to `spice_rack`. Calls `matching.php` for deduplication.

### `core/matching.php`

Jaccard similarity. `find_duplicates(product_name, category)` returns ranked list of potential duplicates with Jaccard scores.

### `core/raid_api.php`

Ghost Recipe Engine: cook execution, inventory deduction, ghost fork creation, fingerprint deduplication. Also `consume_recipe()` and `consume_product()`.

### `core/api.php`

CRUD for inventory, products, jobs (receipt processing jobs).

### `core/user_api.php`

User profile and goals CRUD. TDEE recalculation on profile save.

### `stock/index.php`

Inventory list, inline edit, receipt scan trigger, Spice Rack modal.

### `cook/index.php`

Recipe browser, cook execution, live substitution UI, ghost fork creation.

### `recipedb/index.php`

Recipe builder, live macro preview, ingredient autocomplete.

### `eat/index.php`

Quick eat — camera or existing product consumption logging.

### `log/index.php`

Daily and weekly macro summaries, Chart.js charts, consumption history.

---

## Debug Checklist

### Receipt scan fails
1. Is `core/openrouter.env` present and has a valid key?
2. Is the image format supported? (JPEG, PNG, HEIC)
3. Is the image size < 10MB?
4. Check `jobs` table for the last failed job: `SELECT * FROM jobs ORDER BY id DESC LIMIT 5`
5. Check `result_json` for OpenRouter error response

### HMAC cookie rejected on every page load
1. Has `ACCESS_KEY` in `core/auth.php` been changed from default?
2. Is the cookie present? Browser devtools → Application → Cookies
3. Is HTTPS enforced? Cookie has `secure` flag — won't send over HTTP
4. Clear cookies and re-login

### Ghost recipe not being created on cook
1. Was there an actual deviation? Check: substitution selected OR quantity differs by >10g from canonical
2. Is the fingerprint unique? Check `SELECT * FROM recipes WHERE is_active=0 ORDER BY id DESC LIMIT 5`
3. Was the cook completed (not cancelled)?

### Inventory not deducting on cook
1. Was `raid_api.php` called successfully? Check `consumption_log` for entries with today's date
2. Does the recipe have the correct `product_id`? Null `product_id` means no product-level record
3. Check `inventory` table before and after a cook: `SELECT product_id, current_qty FROM inventory ORDER BY id DESC LIMIT 10`

### Spice Rack count wrong after cook
1. Does the recipe have spices assigned in `recipe_spices`?
2. Check: `SELECT * FROM recipe_spices WHERE recipe_id = ?`
3. Was `uses_since_restock` incremented? Check `spice_rack`

### Jaccard deduplication not catching a duplicate
1. Check the threshold — it's 0.6. "Milk" vs "Milk 1L" might score lower than expected
2. Was the product manually renamed after ingestion? Names must be similar for Jaccard to match
3. Check `product_aliases` — if an alias exists, the lookup bypasses Jaccard

### Dashboard numbers don't match log
1. Is the date range correct? Dashboard uses today; log uses selected date
2. Has the recipe been re-saved since the cook? `syncRecipeToProduct()` updates the product, not historical log entries
3. Check `consumption_log` directly: `SELECT SUM(kj) FROM consumption_log WHERE consumed_at >= date('now')`

---

## Key Technical Decisions

### Why OpenRouter for vision?

Receipt OCR is a commodity task. OpenRouter gives access to Gemini Flash (fast, cheap, good at structured output) with an OpenAI-compatible API. The prompt is provider-agnostic — switching providers requires only changing the endpoint URL and API key.

### Why Jaccard and not Levenshtein distance?

Levenshtein counts character edits. Jaccard handles multi-word product names better because it captures shared substrings regardless of position. "Full Cream Milk" and "Cream Full Milk" would score 1.0 with Jaccard but poorly with Levenshtein. Category locking prevents cross-category false matches.

### Why sync product ↔ recipe?

Nutrition logging needs to answer: "what did I eat today and what were its macros?" If a recipe changes, historical log entries must not change. By giving each recipe version its own `product_id`, the inventory deduction for log entries from older cooks is preserved.

### Why 10g tolerance for ghost creation?

Without a tolerance, minor quantity rounding during cooking (e.g., "I used 185g instead of 180g") would create ghost recipes on every cook. The tolerance prevents ghost spam for negligible deviations.

---

*Last updated: 2026-04-27*
