# SPENCE
### AI-Powered Kitchen Inventory, Recipe Database & Nutritional Logger

SPENCE harnesses AI vision to parse grocery receipts — automatically sorting, categorising, and tracking your nutrition from shopping cart to pantry, fridge or freezer, to stovetop, and onwards as you use it to fuel your daily targets.

> ⚠️ **Caution:** Designed for local-first, single-user personal use. Not hardened for public internet exposure. Change the auth key in `core/auth.php` before putting this anywhere near a network.

---

## Features

- **One-tap receipt ingestion** — AI vision parses your grocery receipt and extracts clean product names, quantities, prices, and macronutrient profiles. Runs synchronously in a single HTTP request; no background service required.
- **Spice Rack** — Binary on/off pantry staple tracking for 18 canonical spices. Usage counter flags restocks at 40 uses. Integrated across Stock, RecipeDB, and Cook.
- **Smart deduplication** — Jaccard similarity + category locking detects and merges duplicate or near-duplicate products (e.g. "Full Cream Milk" vs "Whole Milk") with averaged cost basis tracking.
- **Ghost Recipe Engine** — Cook with substitutions or custom quantities without touching the master recipe. Forks are fingerprinted, stored silently as inactive ghosts, and resolved back to the canonical name in logs and analytics.
- **Live Cook macros** — kJ, protein, and $/serve badges update in real time as quantities and substitutions change during a cook.
- **Recipe builder** — Build recipes from inventory with per-serve energy, protein, fat, carb and cost breakdowns. Live macro preview as you add ingredients.
- **Cook & consume workflow** — Execute a recipe and SPENCE automatically deducts ingredients from stock, logs consumed servings, and stores leftovers as Meal Prep.
- **Intelligence Dashboard** — Live 4×2 home screen: today's energy/protein vs goals, pantry value, monthly spend projection, 7-day kJ trend (Chart.js), top 3 energy and protein sources (30d), and favourite meal (30d).
- **Nutritional logging** — Daily and weekly macro tracking with charts, compared against personalised targets. Ghost fork names resolve to canonical parent recipes.
- **Personalised targets** — Katch-McArdle TDEE calculation from lean body mass; proportional macro rebalancer; training/rest mode coming in v2.0.
- **Weight & body fat tracking** — Graphical progress view over time.
- **Product master** — Edit names, categories, macros and per-unit weights; deduplicate your product list; add products manually for use in recipes.
- **PWA** — Installable as a home screen app. HMAC cookie auth survives session GC wipes (1-year token).
- **Mobile-first UI** — Fully usable on phone: icon nav bar, collapsible table rows, responsive card grids, touch-friendly section headers.

---

## Tech Stack

| Layer | Technology |
|---|---|
| Backend | PHP 8.2+ |
| Database | SQLite3 |
| Frontend | HTML, CSS, JavaScript |
| UI Framework | Bootstrap 5.3 dark theme + Bootstrap Icons |
| Charts | Chart.js 4.x |
| AI Vision | OpenRouter (Gemini Flash default; any OpenAI-compatible vision endpoint) |
| Web Server | Nginx or Apache with PHP-FPM |

---

## Requirements

- Nginx or Apache with PHP-FPM 8.2+
- SQLite3
- An [OpenRouter](https://openrouter.ai) API key (or any OpenAI-compatible vision endpoint)

---

## Installation

### 1. Clone the repository

```bash
git clone https://github.com/yourusername/spence.git
cd spence
```

### 2. Configure your web server

Point your Nginx or Apache virtual host document root at the project directory. Ensure PHP-FPM 8.2+ is enabled and the web server process has read/write access to the `database/` directory.

### 3. Initialize the database

```bash
cp database/spence_empty.db database/spence.db
chmod 666 database/spence.db
```

### 4. Set your API key

Create `core/openrouter.env` containing your OpenRouter API key on a single line:

```
sk-or-v1-your-key-here
```

### 5. Set your auth key

Open `core/auth.php` and change the `ACCESS_KEY` constant from the default before exposing SPENCE to any network.

### 6. Visit the app

Navigate to your configured hostname. On first visit, go to **Settings** to initialise your user profile and macro targets.

---

## Usage Overview

| Page | What it does |
|---|---|
| **Home** | Live intelligence dashboard — today's macros vs goals, pantry value, spend projection, 7-day trend, top sources, favourite meal |
| **Stock** | View and manage full inventory; scan receipts; inline edit quantities and prices; Spice Rack modal |
| **Eat** | Consume items from inventory by weight or unit; Quick Eat for camera or existing product |
| **Cook** | Browse and execute recipes; live macro + cost preview; on-the-fly substitutions; spice check |
| **RecipeDB** | Build and manage recipes with live macro preview, ingredient autocomplete, tags, spices, and instructions |
| **Log** | Daily and weekly macro summaries, charts and cost analysis; body composition progress |
| **Settings** | User profile, TDEE, macro targets, product master, and deduplication tools |

---

## AI Vision & the Ingest Pipeline

When you scan a receipt, the image is sent directly to the configured vision model (synchronous — no background process). The model returns structured JSON including:

- Clean, title-cased product names
- Quantity and unit information
- Price paid per line item
- Estimated macronutrient profiles (energy, protein, fat, carbs per 100g)
- A best-guess `weight_per_ea` for unit-based products
- Category classification (including `Spice/Herb` routing to the Spice Rack)

Potential duplicates against your existing product master are flagged for review, giving you the option to merge and consolidate stock.

---

## Planned Features (v2.0+)

- [ ] **Expiry Tracking** — Wire up `inventory.expiry_date`; expiring soon card on dashboard; alert badge within 3 days
- [ ] **Adaptive Daily Targets** — Training Day vs Rest Day macro toggle
- [ ] **Label Scan** — Photo → Genesis Product without a receipt
- [ ] **Shopping List Predictor** — Auto-generated from rolling consumption vs current stock
- [ ] **AI Chef** — Dynamic meal recommendations from ingredients on hand vs today's macro targets
- [ ] **Recipe Discovery** — Import recipes from external sources; auto-create missing products
- [ ] **SPENCE Installer** — One-shot `setup.sh` for Nginx, SQLite, and permissions
- [ ] **Export Engine** — One-click CSV/JSON export for external analysis

---

## Licence

MIT
