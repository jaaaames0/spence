# Architecture

SPENCE is plain PHP with no framework and no build step. Pages render server-side and call small JSON endpoints with `fetch()`. Everything persists to one SQLite file per tenant.

```
Receipt photo ─▶ vision model ─▶ ingest ─▶ inventory ─┬─▶ Eat ──────────┐
                                                      └─▶ Cook ─▶ fork? ─┤
Quick Eat photo / description ───────────────────────────────────────────┤
                                                                         ▼
                              dashboard ◀── consumption_log ──▶ daily / weekly / progress
                                                 ▲
                         nutrition plan ◀── energy calibration ◀── weight trend (+ FORGE)
```

## Layout

```
index.php              Dashboard
stock/  eat/  cook/  recipedb/  log/  settings/
                       One directory per page; each index.php holds its HTML, inline JS and PHP
cook/recipe/           Single-recipe cook view
log/                   index.php (daily), weekly.php, progress.php, leaderboard.php
settings/              index.php (profile & plan), products.php (product master), dedupe.php

core/
  auth.php             Access-key login, HMAC cookie, tenant resolution; included by every page
  runtime_config.php   Environment/credential helpers, tenancy (spenceTenant, spenceNanoGptKey)
  db_helper.php        PDO connection, schema migrations, SQL helper functions, costing, recipe→product sync
  api.php              Inventory, product, recipe, merge and spice-rack actions
  raid_api.php         Eating, cooking (with forks), log edits and deletes
  receipt_api.php      Receipt scan: upload → vision → ingest → merge suggestions
  receipt_ingest.php   Maps vision JSON to inserts; AI catalogue matching
  quick_eat_api.php    Quick Eat scan / describe / log
  quick_eat.php        Quick Eat catalogue-match resolution
  nanogpt_client.php   NanoGPT chat-completions client
  matching.php         Levenshtein + Jaccard fallback matching; substitute finder
  recipes.php          RecipeDB save (versioning)
  energy_calibration.php  Observed-maintenance estimator
  food_sources.php     Dashboard sources and the food leaderboard
  forge.php            Read-only FORGE export reader
  export.php           JSON backup and CSV exports
  user_api.php         Profile, vitals, plan and calibration settings
  ingredients_api.php  Shopping-list handoff
  spence.js / spence.css  Shared UI helpers and the mobile-first stylesheet

database/              schema.sql and spence_empty.db (generated), live spence.db (gitignored)
ops/                   Schema rebuild, tenant provisioning, FORGE export publisher, sample server config
tests/                 Standalone PHP test scripts (php tests/test_*.php)
```

All endpoints are POST-only and return JSON. Front-end code parses replies with `.then(spenceJson)` from `spence.js`. A non-JSON reply, such as a PHP fatal error or a proxy error page, then surfaces as a server error instead of a connection error.

## Database

`get_db_connection()` in `core/db_helper.php` is the source of truth for the schema. It applies versioned migrations on connect and seeds the canonical spice rack. `ops/rebuild-schema.php` regenerates `database/schema.sql` and `database/spence_empty.db` from those migrations, and `tests/test_schema.php` checks the two haven't drifted.

Core tables:

| Table | Holds |
|---|---|
| `products` | Product master: name, category, base unit, macros per 100 g (or per item), weight per item, last unit cost, `merges_into` |
| `inventory` | Physical stock lots: product, quantity, total `price_paid`, location (`Pantry`/`Fridge`/`Freezer`), expiry |
| `recipes`, `recipe_ingredients` | Recipe versions and their ingredients; each version links to its own `products` row |
| `consumption_log` | Everything eaten, with a macro and `unit_cost` snapshot at log time |
| `purchase_log`, `waste_log` | Spend and wastage per item |
| `product_aliases` | Receipt wordings that resolve to a product |
| `spice_rack`, `recipe_spices`, `spice_aliases` | Spice rack state, recipe requirements, learned spice names |
| `user_profiles`, `user_vitals_history`, `user_goals_history` | Profile, weigh-ins, and the plan in force over time |

### Time

Timestamps are stored in UTC. The app timezone is `Australia/Sydney`, hardcoded in `db_helper.php`. Because the UTC offset changes with daylight saving, code never applies a fixed offset:

- To filter a local day or date range, use `spenceLocalDayBounds()` and `col >= ? AND col < ?`.
- To group or display per row, use the SQL functions `spence_local_date()` / `spence_local_datetime()`, which `get_db_connection()` registers.

## Receipt ingest

A scan is one synchronous request to `core/receipt_api.php`:

1. **Shrink in the browser.** The photo is re-encoded to a 2048 px JPEG (`shrinkPhoto()`), keeping it under NanoGPT's request size limit.
2. **Vision call.** The model returns line items with name, quantity, unit, price, category, macros per 100 g, weight per item and an expiry estimate for perishables, plus the receipt's printed total. Names follow a fixed convention: type first, then a comma and descriptors (`Cheese Slices, Extra Tasty`), with no store brands or pack sizes.
3. **Catalogue matching** (`matchReceiptItemsWithAi()`). Items without an exact name or alias match are sent with the live catalogue to the model in one text call. Each product in the catalogue is listed with its size, because different sizes of the same bar are different products.
   - *Same* verdicts restock the existing product and save the receipt wording as an alias.
   - *Similar* verdicts become merge suggestions.
   - If the call fails, `matching.php` (Levenshtein + Jaccard, within the same category) flags new products instead.
4. **Ingest** (`ingestReceiptItems()`) inserts products, inventory lots and purchase-log rows in one transaction. `Spice/Herb` items whose name matches a rack spice or a learned alias go to the spice rack. Other `Spice/Herb` items, such as pastes and meal kits, are stocked as `Other` and flagged for review.
5. **Response.** The reply carries merge suggestions with side-by-side previews, the spice review, and a warning if the lines don't add up to the printed total.

Both model calls use `reasoning_effort: minimal`, which keeps a scan to roughly 20 seconds.

When a receipt restocks an existing lot, a dated new purchase replaces the lot's expiry, and an undated one leaves it alone.

## Merging and deduplication

Merges are reviewed with `mergeReview()` (`spence.js`), on Stock after a scan and under Settings → Deduplication. You pick which name and which macros survive. The target keeps its id and unit, and stock converts between units through weight per item.

`merges_into` always points directly at the surviving product, never through a chain. Ingest resolves names through `products.name`, then `product_aliases`. Suggestions you dismiss are remembered.

## Quick Eat

A Quick Eat photo (shrunk to 1536 px) or text description is sent to the model along with the live raw catalogue. In that one call the model returns each item's macros and estimated cost, a `kind` (packaged, fresh or meal) and a same/similar/new verdict. `resolveQuickEatMatch()` turns the verdict into a chip on the review card:

- **Link.** A *same* verdict with a valid id logs against the existing product with its real macros and price.
- **New product.** An unmatched packaged or fresh item becomes a new catalogue product.
- **Just log.** Meals, *similar* guesses and invalid ids are logged without touching the catalogue.

Tapping the chip cycles between the three. Quick Eat log entries keep their own nutrition snapshot and can be corrected from the daily log.

## Cooking and recipe forks

Each saved recipe has a linked `products` row, kept current by `syncRecipeToProduct()`, carrying macros and cost per serve. Editing a recipe's ingredients forks a new version with a new product id and retires the old one. Old log entries and leftover Meal Prep therefore keep the numbers they were made with.

Cooking (`raid_api.php`, action `cook`):

1. **Resolve ingredients.** Each ingredient takes the substitute you picked, or the amount you typed, or the recipe amount times the number of batches.
2. **Fork on any change.** If anything differs, the cook is recorded against an inactive fork (`is_active = 0`, `parent_recipe_id` set, named "… (Fork)"). Forks are matched by an ingredient fingerprint, so repeating the same variation reuses the same fork. Forks never appear in RecipeDB or Cook, and the log shows them under the parent recipe's name.
3. **Deduct stock.** Each ingredient is deducted lot by lot at that lot's own cost basis, which also gives the exact cost per serve.
4. **Log and store.** Serves eaten now are logged; the rest go to inventory as Meal Prep.
5. **Count spices.** Spice use counters go up, and a spice is flagged for restock at 40 uses.

`findSubstitutes()` (`matching.php`) suggests in-stock alternatives, ranking products of the same type first.

## Costing

- **Lots.** `inventory.price_paid` is the total paid for a lot, so unit cost is `price_paid / current_qty`.
- **Products.** `products.last_unit_cost` remembers a product's cost once it is out of stock.
- **Log entries.** `consumption_log.unit_cost` freezes the cost at the time of eating.
- **Edits.** Editing only a quantity keeps the unit cost. Lowering a quantity by hand logs the removed share as waste; raising it is treated as a correction.
- **Waste.** The Stock *wasted* action logs the lot's remaining value and deletes the lot. *Delete* is for corrections and logs nothing.
- **Waste %.** `getWasteSummary()` reports waste against consumed + wasted, not against purchases, which are too lumpy to compare with.

## Nutrition plan and energy calibration

The formula baseline is Katch-McArdle, `BMR = 370 + 21.6 × lean mass (kg)`, times an activity rate. The plan is maintenance + daily energy change + an optional training-day addition, split into protein (1 g per lb of lean mass by default), fat and carbs. Each saved plan is kept in `user_goals_history`, so past days are judged against the plan in force at the time.

`core/energy_calibration.php` estimates observed maintenance as average intake − weight-trend slope × `ENERGY_KJ_PER_KG` over the current phase:

- **Phases.** The current phase is a cut or a bulk, detected locally or supplied by a FORGE training cycle. When FORGE supplies the cycle, the first 14 days after a phase change are skipped as water and glycogen shift.
- **Confidence.** The estimate is "high" when its standard error is within `ENERGY_HIGH_CONFIDENCE_KJ` and it has at least 28 days, 6 weigh-ins and 80% logging coverage.
- **Fallback.** Until the new phase reaches high confidence, the previous phase's last stable estimate can stand in, but never across a cut↔bulk flip.

You can exclude individual days from calibration when logging was incomplete.

## Food sources and leaderboard

`getFoodSources()` (`food_sources.php`) feeds the dashboard's source cards and the leaderboard (`log/leaderboard.php`). The leaderboard is reached from the list icon on those cards. It ranks every food by times eaten, serves, weight, kJ, protein, or protein per 1000 kJ. It runs in meal mode, or in ingredient mode, which splits recipes into their ingredients.

The *by type* view folds products by the name before the comma ("Bacon, Streaky" → Bacon) and counts each sitting once. Recipes and unlinked Quick Eat meals are never folded.

## Auth and tenancy

- **Login.** You log in with an access key. The cookie holds an HMAC of a fixed string under that key, so there is no server-side session to expire. The cookie is scoped to `/spence/` and is `Secure`, `HttpOnly` and `SameSite=Lax` (Strict cookies are withheld when an installed PWA launches), with a one-year lifetime that renews on each page load.
- **Tenants.** The original install is the *primary* tenant. Guests are `<id>.key` files in `SPENCE_TENANTS_DIR`, and their cookies are `<id>.<hmac>`. `spenceTenant()` gives the current tenant's database and upload paths to `get_db_connection()` and `spenceUploadDir()`.
- **Guest limits.** Guests never get FORGE (`getForgeDbConnection()` returns null) or the shopping-list handoff. Guests scan only with their own `<id>.nanogpt` key.
- **Provisioning.** Guests are created with `ops/spence-create-tenant.php`; see [DEPLOYMENT.md](../DEPLOYMENT.md#guest-tenants).

## Front end

Every page loads Bootstrap 5.3 (dark), Bootstrap Icons, `core/spence.css` and `core/spence.js` through `core/page_head.php`. Helpers worth knowing:

| Helper | What it does |
|---|---|
| `attachProductSearch()` | Type-to-search product picker; use it instead of `<select>` / `<datalist>` |
| `shrinkPhoto(file, maxEdge)` | Re-encodes a photo as a smaller JPEG before upload |
| `handoffFile()` / `takeHandoffFile()` | Carry a picked photo across a page navigation (IndexedDB) |
| `spenceJson`, `requestErrorMessage()` | Parse API replies and word errors |
| `data-autofocus` | Focuses a field when its modal opens |

On mobile, secondary table columns are hidden with `d-none d-md-table-cell` and expanded per row via `.mob-detail-row`. All responsive overrides live in `spence.css`.
