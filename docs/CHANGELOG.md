# SPENCE | Changelog

### v1.0-RC1 (2026-02-22) - "The Sovereign Release"
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
- **Signal Migration**: Successfully moved system alerts and communications to a sovereign Signal channel (+61431112973).
- **Purge Protocol**: Wiped operational data (inventory, logs, products) for clean Release Candidate zero-state.

### v0.11.5 (2026-02-21)
- **Intelligence Strike (Dedupe v2)**: Rebuilt matching engine with Category Locking, Jaccard Similarity (60%), and unique Group-Root concept matching.
- **Sovereign Normalization**: Vision model now strips weights/volumes from names; alphabetical token sorting fixes "Red Onion" vs "Onion Red".
- **Proactive UX**: Integrated "Cleanup Suggestions" modal in Stock dashboard; implemented directional flippable merges and real-time conflict detection.
- **Dropped Protocol**: Added `is_dropped` flag to Product Master to permanently ignore high-frequency noise (e.g., Paper Bags) from future ingests.
- **UX Theater**: Implemented staged feedback loop for receipt uploads to smooth over Gemini/Ingest latency spikes.
- **Raid Integrity**: Refactored `raid_api.php` to sync recipe macros before logging "Eat Now" portions, resolving the 0-macro leftover bug.
- **Hardened Ingest**: Switched to case-insensitive name matching (`LOWER()`) in ingest bridge to prevent duplicate name drift.
- **Security Audit**: Verified SSH "PasswordAuthentication no" status; hardened web root with 2775/664 ownership recursively under `www-data`.

### v0.10.2 (2026-02-20)
- **Identity Architecture**: Launched User Profile system with `user_profiles`, `user_vitals_history`, and `user_goals_history`.
- **Katch-McArdle Engine**: Integrated TDEE/BMR calculations based on LBM (Lean Body Mass).
- **Macro Precision**: Standardized 1g protein per lb of LBM; implemented proportional macro rebalancer sliders with 50/50 Fat/Carb defaults.
- **Economic Logic**: Automated 2-decimal daily ceilings from weekly discretionary budgets.
- **Theme Polish**: Established "Settings Grey" (#6c757d) for config isolation; replaced Warning Amber with kJ Warning Orange (#ff9800) for EAT context.
- **Log UI Harmonization**: Updated Daily Log headers and rows to use canonical macro colors (kJ=Orange, P=Blue, F=Red, C=Purple, Cost=Green).

### v0.8.6 (2026-02-20)
- **Warehouse Customization**: Added localStorage-persistent column visibility to Stock dashboard.
- **Sort Hardening**: Implemented canonical category sorting (Meal Prep -> Other) and alphabetical sub-sorting.
- **Log Heroes**: Added Fat/Carb heroes to daily log; converted Composition to interactive Pie chart with hover-grow effect.

### v0.8.5 (2026-02-19)
- **Immutable Genesis Versioning**: Overhauled RecipeDB to treat recipes as versioned entities. Edits archive the old version and spawn new blueprint/product pairs to protect historical log integrity.
- **Portal Symmetry**: Rebuilt homepage as 3x2 high-density grid; consolidated brand logo as root navigator.
- **Warehouse Customization**: Added localStorage-persistent column visibility and canonical category sorting.
- **Dedupe v1.0**: Implemented first hybrid matching engine with synonym maps (Mince/Ground/Burger) and alias persistence.

### v0.5.8 (2023-02-13)
- **Economic Durability**: Implemented Persistent Unit Costing (`last_unit_cost`) to prevent data loss on 0-stock refunds.
- **Flat Log Parity**: Nuked nested tables in Log for flat <tr> integration and perfect alignment.

### v0.5.0 (2023-02-12)
- **Intake Engine**: Initial launch of the Consumption Log and aggregate macro tracking.

### v0.6.1 (2026-02-13)
- **Visual Stabilization**: Introduced "The Spacer Protocol" (38px alignment) and Danger Red (#f44336) Cook accent.
- **Persistent Unit Costing**: Added `last_unit_cost` to preserve price memory even when stock hits zero.
- **Flat Log Parity**: Nuked nested tables in Log for flat <tr> integration and perfect alignment.
- **Price-Ratio Locking**: Refactored API to enforce proportional price reduction during consumption (qty consumption scales price_paid).

### v0.4.0 (2026-02-11)
- **Unified Product Engine**: core refactor splitting data into `products` (master nutrition) and `inventory` (physical stock).
- **The James Constant**: Implemented `weight_per_ea` for accurate macro derivation from unit-based products.
- **Dynamic Precision**: Automated decimal scaling (0 for ea, 3 for kg/L) across the UI.
- **Cook Engine v1.0**: Initial launch of the multiplier logic and atomic stock deduction.

# SPENCE: System Architecture (v0.3.2)

## Overview
SPENCE (formerly PantryOS) is a Gemini-native kitchen intelligence platform. The name derives from the Middle English "spence" (a larder or storeroom), rooted in the Latin "dispendere"╬ô├ç├╢to weigh out and dispense. It has evolved from a simple inventory tracker into a high-precision macro-extraction engine...

## Directory Structure
- `index.php`: Main Landing (v3.0). Choice between Stock and Raid.
- `stock/index.php`: Inventory Dashboard (formerly main index).
- `raid/index.php`: Raid Entry.
- `raid/eat/index.php`: Single item consumption.
- `raid/cook/index.php`: Recipe engine (Pending).
- `core/`: Execution engine.
    - `watcher.sh`: Vision Watcher (v3.0). Gemini 3 Flash / SKU Recall / Sticker Fusion.
    - `bridge_ingest.php`: Ingests Vision results. Gemini-first nutrition.
    - `db_helper.php`: Shared PDO database logic.
    - `api.php`: Central AJAX API for Stock management.
    - `raid_api.php`: API for consumption logging.
    - `batch_nutrition.php`: Batched Gemini enrichment for user-corrected items.
    - `upload.php`: Receipt upload handler with Job ID tracking.
- `database/`: Persistent storage (`spence.db`).
- `docs/`: System documentation and roadmaps.
- `uploads/`: Raw receipt images/PDFs.

## The Ingestion Loop (v0.3.1)
1. **Upload:** User uploads image -> `upload.php` creates job -> Redirect to `stock/index.php?active_job=ID`.
2. **Reactive UI:** `stock/index.php` polls `api.php?action=check_job` every 2s.

## The Raid Loop (v0.3.0)
1. **Selection:** User selects item in `eat/`.
2. **Consumption:** User inputs amount -> `raid_api.php` updates inventory and logs to `consumption_log`.
3. **Macros:** Macros are calculated and frozen at the point of consumption in the log.

## Database Schema (`spence.db`)
- `inventory`: `id, name, current_qty, unit, price_paid, location, updated_at`.
- `jobs`: `id, file_path, status, result_json, message, created_at`.
- `master_nutrition`: `name_key, kj_per_100, protein_per_100, fat_per_100, carb_per_100`.
- `consumption_log`: `id, date, item_name, qty_consumed, unit, kj, protein, fat, carbs`.

## Maintenance
- **Watcher Status:** `ps aux | grep watcher.sh`
- **Logs:** `docs/watcher.log` (daemonized output).

### v0.1.0 (2026-02-09)
- **Genesis**: Initial receipt parsing engine and inventory tracking.
