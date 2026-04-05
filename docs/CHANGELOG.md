# SPENCE | Changelog

### v1.6.0 (2026-04-05) - "Mobile Pass"
- **Mobile Navigation:** Replaced Bootstrap hamburger with a permanent single-line icon nav on mobile. Each item shows its icon above a centred label — no toggle required. Labels abbreviated (DB, SET) to fit without overflow.
- **Section Headers:** All page headers now stack cleanly on mobile via a 3-layer pattern (heading / search / actions). Log pages exception: heading and date picker remain inline. Weekly page exception: date picker inline with heading, rolling/mon-sun toggle drops to its own right-aligned row.
- **Stock Table:** 9 secondary columns (PRICE hidden — now primary, macros, location, category) hidden on mobile behind a per-row chevron expand. Expanded section shows macro badges with units and category on a single line. Column settings button hidden on mobile.
- **Daily Log Cards:** Prot ROI card removed site-wide. Remaining 6 stat cards go 2×3 portrait on mobile.
- **Weekly Cards:** 6 period-average stat cards go 2×3 portrait on mobile.
- **Log Table:** Macro columns (kJ/P/F/C/Cost) hidden on mobile; values shown as a compact inline line below the item name. Recipe breakdown child rows also hide macro columns on mobile.
- **Log Name Resolution:** Ghost recipe (Fork) names in the consumption log now resolve to the canonical parent recipe name via JOIN.
- **RecipeDB Table:** SERVES, FAT/SERVE, CARBS/SERVE columns hidden on mobile. Serves count shown inline below recipe name. Visible on mobile: kJ/SERVE, PROTEIN/SERVE, $/SERVE.
- **Settings:** Macro target boxes go 2×2 on mobile. Phantom placeholder search bar hidden on mobile to remove the gap between heading and tabs.
- **Eat / Cook:** Badge legend (kJ / Protein / Cost) visible on mobile; verbose unit suffix ("per 100g / per 100mL / each") hidden on mobile only.
- **PWA / CSS:** All mobile styles centralised in `core/spence.css`; `.mob-detail-row` utility suppressed at `md+`.

### v1.5.0 (2026-04-05) - "Intelligence Dashboard"
- **Dashboard Overhaul:** Replaced static hero-link grid with a live 4×2 intelligence dashboard at `index.php`. Standard nav/header now present on landing page.
- **Today's Energy & Protein:** Progress bar cards vs. user goals, whole-number precision in this context.
- **Pantry Value:** Live `SUM(price_paid)` across all stocked inventory with product count.
- **Monthly Spend Projection:** Rolling 30-day consumption average extrapolated to full month length.
- **7-Day kJ Trend:** Chart.js bar chart — bars turn green when daily goal is met, orange otherwise; empty days rendered transparent.
- **Top 3 Energy & Protein Sources (30d):** Ranked lists with name, value, and % share of total 30-day intake. Rank 1 gets accent colour; ranks 2–3 stepped down visually.
- **Favourite Meal (30d):** Most-cooked recipe by serve count; ghost forks resolve to canonical parent name so variants count toward the same total.
- **Expiring Soon:** Placeholder commented out in code, ready to activate when expiry tracking is wired up.

### v1.4.0 (2026-04-05) - "Spice Rack"
- **Synchronous Receipt Ingest:** Retired the `watcher.sh` async pipeline. `receipt_api.php` now completes the full scan (upload → OpenRouter vision → ingest → merge suggestions) in a single HTTP request, matching the Quick Eat pattern. PDF path also retired.
- **Spice Rack tables:** `spice_rack` and `recipe_spices` auto-migrate on every DB connection via `get_db_connection()`.
- **Canonical Spice Seed:** 18 spices pre-seeded with `INSERT OR IGNORE` on boot — Salt, Black Pepper, Paprika, Garlic Powder, Onion Powder first (fixed order), then rest alphabetically. Custom spices can be added via UI.
- **Receipt AI routing:** Items classified as `Spice/Herb` by the vision model are routed to `spice_rack` (insert or restock) instead of inventory. Restock resets `uses_since_restock` and clears `restock_flagged`.
- **Stock page — Spice modal:** Fire icon button in the header toolbar opens a Bootstrap modal with toggle switches (stocked/depleted), amber restock badge, and custom add input. Amber dot on the toolbar button if any spice is flagged.
- **RecipeDB — Spice Requirements:** Inline in the recipe modal alongside Tags (col-md-7), always visible, checkbox grid with canonical ordering. Selection count badge. Saved after `save_recipe` resolves its new ID.
- **Cook page — Spice Check:** Read-only panel below ingredient list. Green dot = stocked, amber = restock flagged, red = not stocked. Loaded lazily; hidden if recipe has no spices assigned.
- **Use counter:** Each `cook` action increments `uses_since_restock` for all spices linked to that recipe. `restock_flagged` flips at 40 uses.
- **Spice ordering:** `get_spices` orders Salt → Black Pepper → Paprika → Garlic Powder → Onion Powder, then alphabetically — applied consistently across all surfaces.

### v1.3.0 (2026-04-05) - "Platform Foundation"
- **HMAC Cookie Auth:** Replaced PHP session-based auth (susceptible to session GC wipe) with a 1-year HMAC cookie. Token derived via `hash_hmac('sha256', 'spence_auth_v1', $ACCESS_KEY)`. Login once, stay in for a year.
- **PWA Packaging:** Added `site.webmanifest`, SVG favicon (bold S in `#00A3FF` on `#121212`), and mobile-web-app-capable meta tags. SPENCE is now installable as a home screen app. Status bar keeps `#121212` background.
- **Column Settings fix:** `col-hidden { display: none !important }` was missing from `spence.css` — the JS was toggling the class but nothing was defined. Added the rule; CSS cache busted with `?v=2` on the link tag. Apply button now explicitly calls `applyColumnSettings()`.
- **Cook page styling regression fixed:** Restored cook-red (`#f44336`) hover border (full thin border, not thick left-only), correct `badge-tag` accent via `[data-context="cook"]` CSS selector, and `tag-header` alignment matching `eat/index.php`.
- **RecipeDB modal redesign:** 4-row layout — Name + Servings / Ingredients + Macro box / Tags + Spices / Instructions. Macro box is a persistent 2×3 grid (Per Serve + kJ / P + F / C + $) that shows dashes until data is entered. Ingredient rows use pure flexbox (`d-flex flex-nowrap`) to prevent wrapping. Stock button reduced to icon-only (`bi-arrow-up-circle`), hover-only colour for both stock and trash buttons.

### v1.2.0 (2026-04-05) - "Fluid Cook"
- **On-the-Fly Recipe Forking**: Cook page now supports live ingredient substitution and custom quantities without modifying the master recipe. Any deviation (sub selected or qty differs from canonical) auto-creates a Ghost Recipe fork with full fingerprinting to prevent duplicate ghosts.
- **Ingredient Substitution UX**: Ingredient name row doubles as dropdown trigger (chevron hint, no extra button). State icon toggles between `›` (open picker) and `↺` (reset to canonical) based on modification state. Outside-click closes picker.
- **Custom Amounts with Tolerance**: Recipe deduction accepts up to 10g undershoot ("use what you have"). Ghost fork records base-equivalent amounts (actual ÷ multiplier) for per-batch consistency.
- **Live Cook Macros + Cost**: kJ, protein, and `$`/serve badges in the cook header update in real time as quantities change or substitutions are selected.
- **Live RecipeDB Macros + Cost**: Build/Edit recipe modal now shows a live per-serve macro bar (kJ / P / F / C / `$`) that updates as ingredients and serving count change.
- **RecipeDB Custom Autocomplete**: Replaced `<datalist>` with a custom dropdown showing stock-on-hand alongside each suggestion. "Use All Stock" button pre-fills the amount field.
- **Quick Eat UX**: Consolidated to a single dropdown button (Camera / Existing Product). Fixed Android camera access (`accept="image/*;capture=camera"` + form wrapper). Removed dead JS references to retired modal elements.

### v1.0.0 (2026-02-22) - "The Sovereign Release"
- **Terminology Refactor**: Completed the full-stack migration from "Portions" to **"Serves"**. Updated database schema (`yield_serves`), API logic, and every front-end dashboard for consistency.
- **RecipeDB Polish**: 
    - Fixed the clickable **Tag Cloud** in the Build/Edit modal by implementing a dynamic, self-healing tag extraction engine.
    - Locked ingredient units to the Product Master `base_unit` (readonly) to prevent schema drift during recipe creation.
    - Simplified the Recipe Table by moving tags into the expanded detail view, prioritizing name legibility.
- **One Dark Industrial Lockdown**: 
    - Standardized all search bars, input group spans, and card headers to use `#1a1a1a` backgrounds and `#333` borders.
    - Removed all remaining Bootstrap "blue-grey" remnants sitewide.
- **GitHub Prep**: 
    - Created comprehensive system documentation (`README.md`, `ROADMAP.md`, `MFP_COMPARISON.md`).
    - Implemented a privacy shield with `.gitignore`, `schema.sql`, and an empty `spence_empty.db` for distribution.
    - Added the MIT License.

### v0.12-RC1 (2026-02-22)
- **Ghost Recipe Engine**: Implemented automatic historical forking for recipes with ingredient substitutes. Maintains macro/cost integrity for "one-off" cooks without cluttering the master RecipeDB.
- **Relaxed Substitute Logic**: Added `findSubstitutes()` to `matching.php` for proactive ingredient alternatives during the Cook flow.
- **Metric Normalization**: Standardized context-aware metrics (Stock raw $/Unit vs Eat normalized 100g/ea density).
- **Sovereign Rebranding**: Renamed `pantry.db` -> `spence.db` across the entire codebase and filesystem.
- **Concept-Aware Matching**: Refined deduplication logic to share 2+ tokens (1+ meaningful) to catch specific variants (e.g., "Free Range Eggs").
- **UI Anchor Standard**: Normalized search bar layouts and grid column widths across all dashboard modules.

### v0.11.5 (2026-02-21)
- **Intelligence Strike (Dedupe v2)**: Rebuilt matching engine with Category Locking, Jaccard Similarity (60%), and unique Group-Root concept matching.
- **Sovereign Normalization**: Vision model now strips weights/volumes from names; alphabetical token sorting fixes "Red Onion" vs "Onion Red".
- **Proactive UX**: Integrated "Cleanup Suggestions" modal in Stock dashboard; implemented directional flippable merges and real-time conflict detection.
- **Dropped Protocol**: Added `is_dropped` flag to Product Master to permanently ignore high-frequency noise (e.g., Paper Bags) from future ingests.
- **Raid Integrity**: Refactored `raid_api.php` to sync recipe macros before logging "Eat Now" portions, resolving the 0-macro leftover bug.
- **Hardened Ingest**: Switched to case-insensitive name matching (`LOWER()`) in ingest bridge to prevent duplicate name drift.

### v0.10.2 (2026-02-20)
- **Identity Architecture**: Launched User Profile system with `user_profiles`, `user_vitals_history`, and `user_goals_history`.
- **Katch-McArdle Engine**: Integrated TDEE/BMR calculations based on LBM (Lean Body Mass).
- **Macro Precision**: Standardized 1g protein per lb of LBM; implemented proportional macro rebalancer sliders with 50/30/20 defaults.
- **Economic Logic**: Automated 2-decimal daily ceilings from weekly discretionary budgets.

### v0.8.6 (2026-02-20)
- **Warehouse Customization**: Added localStorage-persistent column visibility to Stock dashboard.
- **Sort Hardening**: Implemented canonical category sorting (Meal Prep -> Other) and alphabetical sub-sorting.
- **Log Heroes**: Added Fat/Carb heroes to daily log; converted Composition to interactive Pie chart.

### v0.8.5 (2026-02-19)
- **Immutable Genesis Versioning**: Overhauled RecipeDB to treat recipes as versioned entities. Edits archive the old version and spawn new blueprint/product pairs to protect historical log integrity.
- **Portal Symmetry**: Rebuilt homepage as 3x2 high-density grid; consolidated brand logo as root navigator.
- **Dedupe v1.0**: Implemented first hybrid matching engine with synonym maps and alias persistence.

### v0.5.0 — v0.6.1 (2026-02-12/13)
- **Intake Engine**: Initial launch of the Consumption Log and aggregate macro tracking.
- **Persistent Unit Costing**: `last_unit_cost` preserves price memory when stock hits zero.
- **Price-Ratio Locking**: Proportional price reduction enforced during consumption.

### v0.4.0 (2026-02-11)
- **Unified Product Engine**: Core refactor splitting data into `products` (master nutrition) and `inventory` (physical stock).
- **The James Constant**: Implemented `weight_per_ea` for accurate macro derivation from unit-based products.
- **Cook Engine v1.0**: Initial launch of the multiplier logic and atomic stock deduction.

### v0.1.0 (2026-02-09)
- **Genesis**: Initial receipt parsing engine and inventory tracking.
