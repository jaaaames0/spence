# SPENCE | Engineering Roadmap

## Current Milestone: v1.6.0
**Goal:** Completed v1.6 Mobile Pass — SPENCE is now fully usable on mobile without touching desktop layouts. All core surfaces responsive.

---

## ✅ COMPLETED

### 🍱 Recipe & Blueprint Engine
- **Ghost Recipe Engine:** Automatic historical forking for substituted ingredients. Fingerprinted to prevent duplicate ghosts.
- **On-the-Fly Cook Forking:** Inline substitutions and custom quantity overrides in the cook page. Auto-forks to ghost if anything deviates from canonical. 10g tolerance prevents blocks.
- **Live Cook Macros + Cost:** kJ, protein, and $/serve badges update in real time as quantities change or substitutions are selected.
- **Blueprint Logic:** Full database migration to `yield_serves` terminology.
- **Unit Sovereignty:** Locked recipe units to Product Master `base_unit` to prevent schema drift.
- **Dynamic Tagging:** Self-healing tag cloud extraction from active recipes.
- **Ghost Recipe Suppression:** Fork entries hidden from all user-facing surfaces.
- **RecipeDB Modal Redesign:** 4-row layout with persistent macro preview box (2×3 grid), inline spice requirements, and flex-based ingredient rows that never wrap.

### 📦 Inventory & Ingest
- **Synchronous Receipt Ingest:** Retired `watcher.sh` async pipeline. Full scan completes in a single HTTP request (`receipt_api.php`). PDF path also retired.
- **Semantic Deduplication v2:** Jaccard-based fuzzy matching with Category Locking and "The Mince Paradox" resolution.
- **Dedupe: Preserve Winner Macros on Merge / Dismiss False Positives.**
- **Unit/Price Consistency Audit:** Unit changes cascade to inventory; ingest normalises to canonical unit; stale unit fields self-heal on re-scan.
- **Spice Rack:** Binary on/off system for pantry staples. 18 canonical spices pre-seeded. Receipt AI routes `Spice/Herb` items directly to `spice_rack`. Use counter increments on each cook; `restock_flagged` at 40 uses. Integrated across Stock (modal), RecipeDB (inline checkboxes), and Cook (status dots).

### 🥗 Eat & Quick Eat
- **Quick Eat:** Single dropdown button (Camera / Existing Product). Android camera access fixed. Direct log bypass.
- **RecipeDB Custom Autocomplete:** Stock-on-hand indicator per suggestion, "Use All Stock" pre-fill.

### 🎨 Platform & UX
- **HMAC Cookie Auth:** 1-year token replaces PHP session auth. Survives session GC wipe.
- **PWA Packaging:** `site.webmanifest`, SVG favicon, mobile-web-app-capable meta tags. Installable as home screen app.
- **Column Settings fix:** `col-hidden` CSS rule added; cache-busted; Apply button reconnected.
- **Cook page styling regression fixed:** Correct hover border, badge colour, and header alignment.
- **One Dark Industrial Design System:** High-density 38px alignment, unified search bar aesthetic, standardised colour identity.

### 🏠 Dashboard
- **Intelligence Dashboard:** Replaced static hero grid with live 4×2 metrics dashboard:
  - Today's kJ and Protein vs. goals (progress bars)
  - Pantry Value (live inventory sum) and Monthly Spend Projection
  - 7-day kJ Trend (Chart.js bar chart, goal-aware colouring)
  - Top 3 Energy Sources and Top 3 Protein Sources (30d, ranked)
  - Favourite Meal (30d, canonical name, forks resolved to parent)
  - Expiring Soon placeholder (commented, awaiting expiry tracking)

### 📱 Mobile Pass
- **Nav:** Icon-above-label single-line mobile nav, no hamburger/toggle.
- **Section Headers:** 3-layer stack (heading / search / actions) on all pages; log pages keep heading+date inline; weekly page drops toggle below date row.
- **Stock Table:** Per-row expand chevron reveals macros + category. Config button hidden on mobile.
- **Log Cards:** Prot ROI removed. 6 cards go 2×3 portrait on mobile.
- **Weekly Cards:** 6 stat cards go 2×3 portrait on mobile.
- **Log Table:** Macro columns hidden; values shown inline below item name.
- **RecipeDB Table:** SERVES/FAT/CARBS columns hidden on mobile.
- **Settings:** Macro targets 2×2 on mobile; phantom search gap fixed.

---

## 🚀 PHASE 4: v2.0 — Predictive Intelligence
*Focus: Proactive agency and metabolic insights.*

- [ ] **Expiry Tracking:** Wire up `inventory.expiry_date` column (already in schema). Expiring Soon card on dashboard goes live. Stock page expiry column and sort. Alert badge when items are within 3 days.
- [ ] **Adaptive Daily Targets:** "Training Day" vs "Rest Day" macro toggle.
- [ ] **Adaptive Signal Ingest:** Scan nutritional labels directly (Photo → Genesis Product) without a receipt.
- [ ] **Shopping List Predictor:** Auto-generated list from rolling consumption averages vs current stock.
- [ ] **AI Chef:** Dynamic meal recommendations using "Ingredients on Hand" vs "Macro Targets for Today".
- [ ] **Recipe Discovery:** Import recipes from external sources. Auto-create products for missing ingredients. Spice requirements auto-populated from ingredient list.

---

## 🌐 PHASE 5: v2.1 — Distribution
*Focus: Make SPENCE deployable by others.*

- [ ] **SPENCE Installer:** One-shot `setup.sh` to configure Nginx, SQLite, and permissions.
- [ ] **Configuration Wizard:** Web-based setup for API keys and user goal initialisation.
- [ ] **The "Wipe" Protocol:** Purge personal data while preserving canonical product master for sharing.
- [ ] **Export Engine:** One-click CSV/JSON export for external analysis.
