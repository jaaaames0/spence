# SPENCE
### AI-Powered Kitchen Inventory, Recipe Database & Nutritional Logger

SPENCE harnesses AI vision and LLMs to analyze grocery receipts — automatically sorting, categorising, and tracking your nutrition from shopping cart to pantry, fridge or freezer, to stovetop, and onwards as you use it to fuel your journey.

> ⚠️ **Caution:** This project was vibe-coded using Gemini Flash. It could be an absolute dumpster fire and security nightmare — use at your own risk and do not expose it to the public internet without hardening it first.

---

## Features

- **One-click receipt ingestion** — AI vision parses your grocery receipt and extracts clean product names, quantities, prices, and macronutrient profiles
- **Smart inventory management** — automatic detection and merging of duplicate or similar products (e.g. "Full Cream Milk" vs "Whole Milk"), with averaged cost basis tracking
- **Recipe builder** — create recipes from your inventory with per-serve energy, protein, fat, carb and cost breakdowns
- **Cook & consume workflow** — execute a cook and SPENCE automatically deducts ingredients from stock, adds consumed servings to your daily log, and stores leftovers as Meal Prep in your fridge
- **Nutritional logging** — daily and weekly macro tracking with charts, compared against your personalised targets
- **Personalised diet plans** — enter your stats to receive a recommended macro split for weight gain, maintenance or loss, with full manual override
- **Weight & body fat tracking** — graphical progress view over time
- **Product master control** — edit names, categories, macros and per-unit weights; deduplicate your product list; add products manually for use in recipes

---

## Tech Stack

| Layer | Technology |
|---|---|
| Backend | PHP 8.2+ |
| Database | SQLite3 |
| Frontend | HTML, CSS, JavaScript |
| UI Framework | Bootstrap (styles & icons) |
| Ingest Pipeline | Bash (`core/watcher.sh`) |
| AI Vision | Any vision-capable LLM (bring your own API key) |
| Web Server | Nginx or Apache with PHP-FPM |

---

## Requirements

- Nginx or Apache with PHP-FPM 8.2+
- SQLite3
- An API key for a vision-capable LLM (tested with Google Gemini 3 Pro/Flash and GPT-4o/GPT-5; any OpenAI-compatible vision endpoint should work)

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

SPENCE ships with a pre-configured schema. To initialize your local database:

```bash
cp database/spence_empty.db database/spence.db
chmod 666 database/spence.db
```

Alternatively, you can rebuild the schema from scratch using the provided SQL:

```bash
sqlite3 database/spence.db < database/schema.sql
```

### 4. Configure the ingest watcher

Open `core/watcher.sh` and set your API key and preferred vision model endpoint. The watcher monitors an ingest directory for receipt images and sends them to your configured AI provider.

To run the watcher as a persistent service, create a systemd unit:

```ini
[Unit]
Description=SPENCE Receipt Watcher
After=network.target

[Service]
ExecStart=/bin/bash /var/www/spence/core/watcher.sh
Restart=always

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl enable spence-watcher
sudo systemctl start spence-watcher
```

---

## Usage Overview

| Tab | What it does |
|---|---|
| **Stock** | View and manage your full inventory; scan new receipts; edit quantities and prices |
| **Eat** | Consume items from inventory by weight or unit; logs macros to your daily record |
| **Cook** | Browse and execute recipes; checks stock levels and suggests substitutes |
| **RecipeDB** | Build and manage recipes with ingredients, instructions, tags and serving sizes |
| **Log** | Daily and weekly macro summaries, charts and cost analysis; weight progress tracking |
| **Settings** | Manage goals, macro targets, user profile, diet plan and the product master list |

---

## AI Vision & the Ingest Pipeline

When you scan a receipt, the image is passed to your configured vision-capable LLM. The model returns structured data including:

- Clean, title-cased and slightly genericised product names
- Quantity and unit information
- Price paid per line item
- Estimated macronutrient profiles (energy, protein, fat, carbs per 100g)
- A best-guess `Weight/Ea` value for unit-based products (e.g. a 10-pack of 250g cheese slices → 25g each)

Any potential duplicates detected against your existing product master are flagged for review, giving you the option to merge and consolidate stock.

---

## Planned Features

- [ ] Packager / automated setup and configuration
- [ ] Dynamically suggested meals based on targets and current daily intake
- [ ] AI-powered recipe recommendation portal — search the web by macro needs or available ingredients
- [ ] Automated expiry date tracking
- [ ] Improved mobile responsiveness (currently optimised for Chrome at 1920×1080)

---

## Licence

MIT
