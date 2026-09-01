# SPECS.md

## Scope

SPENCE tracks nutrition from receipt to inventory to consumption log. It manages recipes with live macro costing, executes cooks with inventory deduction, tracks body weight and body fat, and projects monthly food spend. It does not prescribe meals, track exercise (beyond what FORGE handles), or support multiple users.

## Functional Requirements

### FR-1: Receipt Ingestion
The system SHALL accept a receipt image (camera or upload), send it to OpenRouter vision AI, and parse structured JSON containing product names, quantities, prices, estimated macros per 100g, and category. Potential duplicates against the existing product master shall be flagged for user confirmation. Confirmed duplicates shall be merged with averaged cost basis.

### FR-2: Inventory Management
The system SHALL track all products in stock with current quantity, unit, price paid, and location (Pantry/Fridge/Freezer). Products can be edited inline (name, macros, category, unit, weight per each). Inventory deduction happens automatically on cook execution.

### FR-3: Recipe Builder
The system SHALL allow creation of named recipes with ordered ingredients drawn from the product master, per-serve yield, and instructions. Each save recalculates per-serve macros (kJ, protein, fat, carb) and cost per serve via `syncRecipeToProduct()`. Tags and spice requirements can be attached.

### FR-4: Ghost Recipe Engine
When a recipe is cooked with any substitution or quantity deviation (>10g tolerance), the system SHALL create an inactive fork (`is_active=0`) linked to the parent recipe via `parent_recipe_id`. Fork is fingerprinted to prevent duplicate ghosts from repeated identical deviations. Fork names do not appear in RecipeDB or Cook browsing surfaces; consumption log resolves them to the canonical parent name.

### FR-5: Cook Execution
The system SHALL execute a recipe, deduct ingredient quantities from inventory, log consumption with macros and unit cost at time of consumption, and create a consumption_log entry. The cook modal SHALL display live-updating per-serve macro and cost totals as substitutions or quantities change.

### FR-6: Quick Eat
The system SHALL allow direct consumption logging from inventory by selecting a product and entering a weight or unit count. Quick Eat bypasses the recipe system for single-product consumption.

### FR-7: Spice Rack
The system SHALL track binary stocked/depleted state for 18 canonical pantry spices. Each spice has a uses-since-restock counter. A restock flag is set at 40 uses. Spice state is surfaced in Stock (modal), RecipeDB (inline checkboxes), and Cook (status dots). Receipt AI routes Spice/Herb items to the spice rack instead of inventory.

### FR-8: Consumption Logging
The system SHALL maintain a chronological log of all consumed items (from Quick Eat or Cook), recording product/recipe reference, amount, unit, macros, unit cost, and timestamp. Ghost fork recipe IDs resolve to canonical parent names in all log views.

### FR-9: Intelligence Dashboard
The system SHALL display a live 4×2 dashboard: today's kJ and protein vs goals (progress bars), pantry value (sum of inventory), monthly spend projection (rolling 30-day average extrapolated), 7-day kJ trend (Chart.js bar chart with goal-aware colouring), top 3 energy sources and top 3 protein sources (30-day), and most-cooked recipe (30-day).

### FR-10: Macro Targets
The system SHALL compute TDEE using the Katch-McArdle formula from stored lean mass (derived from weight and body fat %). Macro targets (kJ, protein, fat, carbs) are configurable. Targets are compared against daily/weekly log totals in the Log view.

### FR-11: Body Composition
The system SHALL accept weight and body fat % measurements and store them in `user_vitals_history`. Trend charts display progress over time.

### FR-12: PWA Install
The system SHALL include a service worker manifest and SVG favicon enabling Add to Home Screen on Android Chrome and iOS Safari. App runs in standalone mode without browser chrome.

### FR-13: Mobile-First UI
All surfaces SHALL be fully usable on mobile (375px viewport) without desktop layout. Navigation uses a permanent single-line icon nav bar. Tables collapse secondary columns behind per-row expand. Cards stack in 2×3 portrait layout.

## Non-Functional Requirements

### NFR-1: Single-Request Receipt Pipeline
Receipt scan completes the full pipeline (upload → vision → parse → ingest → merge suggestions) in one synchronous HTTP request. No background service, no polling.

### NFR-2: Self-Hosted
No external services required at runtime except OpenRouter API for vision parsing. SQLite is the only database dependency.

### NFR-3: Auth Survival
HMAC cookie auth SHALL survive PHP session garbage collection. Token lifetime is 1 year minimum. No server-side session storage required.

### NFR-4: No jQuery
All frontend JS is vanilla. No frameworks, no reactive libraries.

### NFR-5: Schema Migration on Boot
`spice_rack` and `recipe_spices` tables are created via `CREATE TABLE IF NOT EXISTS` in `get_db_connection()` on every page load. No explicit migration step required.

## Constraints

- PHP 8.2+ with `pdo_sqlite` extension
- Nginx or Apache with PHP-FPM
- OpenRouter API key required for receipt scanning (or any OpenAI-compatible vision endpoint)
- Receipt images must be JPEG, PNG, or HEIC
- BLE HR data does not flow into SPENCE — FORGE handles biometric data
- `inventory.expiry_date` column exists in schema but expiry tracking is not yet wired to the UI (v2.0)

## Acceptance Criteria

- A user can scan a receipt, confirm merge suggestions, and see items appear in inventory within one HTTP request
- Executing a cook with substitutions creates an inactive ghost recipe fork and deducts correct quantities from inventory
- Ghost recipe forks do not appear in RecipeDB browse or Cook browse surfaces; log entries resolve to parent name
- Spice Rack restock flag appears at 40 uses and is visible in Stock, RecipeDB, and Cook surfaces
- Dashboard updates live: pantry value reflects current inventory sum; 7-day kJ chart reflects actual log data
- HMAC cookie survives PHP-FPM restart without re-authentication
- App is installable on Android Chrome via Add to Home Screen and runs without browser chrome
- All pages render correctly at 375px mobile width

---

*Last updated: 2026-04-27*
