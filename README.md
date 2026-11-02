# SPENCE

**A self-hosted kitchen inventory, recipe database and nutrition log, with AI receipt and food scanning.**

Photograph a grocery receipt and SPENCE stocks your pantry, fridge and freezer with clean product names, prices and macro estimates. Cook recipes from that stock, log what you eat, and track energy, protein and spend against a nutrition plan calibrated from your own intake and weight trend.

SPENCE is a single PHP application backed by one SQLite file. It is built for one person (plus optional guest accounts) on hardware they control.

> [!WARNING]
> SPENCE is designed for local-first personal use and has not been hardened for exposure to the public internet. Keep it behind HTTPS, set a strong access key, and prefer a private network or VPN.

---

## Features

**Stock**
- **Receipt scanning.** A vision model reads a receipt photo and returns product names, quantities, prices, categories, macros per 100 g, per-item weights and expiry estimates for perishables. The whole pipeline runs in one HTTP request, with no background worker.
- **Catalogue matching.** New receipt lines are matched against your existing products by AI, with a Levenshtein/Jaccard fallback. Exact matches restock the existing product and remember the receipt wording. Near matches become merge suggestions you review side by side.
- **Receipt total check.** If the scanned lines don't add up to the printed total, you're warned to check prices.
- **Expiry tracking.** Short-life items carry an expiry date, and the dashboard and Stock page surface whatever is due within three days.
- **Spend and waste.** Every purchase is logged, and the *wasted* action records the value thrown out. Waste is reported as a share of what you consumed plus what you wasted.
- **Spice rack.** On/off tracking for pantry staples, with a restock flag after 40 uses. Spices on a receipt are routed to the rack automatically.

**Eat**
- **Eat from stock** by weight, unit or a fraction of what's on hand.
- **Quick Eat** logs food that didn't come from inventory. You can photograph it, describe it in words, or pick an existing product. Scans are matched to your catalogue, so packaged items link to the real product and price.

**Cook**
- **Recipe builder** with live per-serve energy, protein, fat, carbs and cost.
- **Cooking** deducts each ingredient from stock at its own cost basis, logs the serves you eat now, and stores the rest as Meal Prep.
- **Substitutions on the fly.** Swap an ingredient or change an amount while cooking without touching the saved recipe. SPENCE records the variant as a hidden fork, so your history stays accurate.
- **Shopping list handoff** (optional) sends missing ingredients to an external JSON shopping list.

**Log & plan**
- **Daily, weekly and progress views** with charts, macro splits and cost, each compared against the plan that was in force on that date.
- **Nutrition plans:** fat loss, maintenance, lean gain, high gain, dirty bulk or custom. Targets are based on Katch-McArdle from lean body mass.
- **Energy calibration** estimates your real maintenance intake from logged intake and weight trend, with confidence gating. Calibrated targets can replace the formula once the estimate is reliable.
- **Dashboard** showing today's energy and protein, a 7-day trend, pantry value, month spend projection, expiring stock and top food sources. It links to a full food leaderboard.
- **Export** to a full JSON backup or to CSVs of inventory, consumption, recipes and vitals.

**Platform**
- **Installable PWA** with a mobile-first layout.
- **Guest tenants**, each with their own database, uploads and AI key.
- **Optional [FORGE](docs/FORGE-INTEGRATION.md) integration.** SPENCE reads bodyweight, body fat and training-cycle data from a companion training app through a read-only export.

---

## Tech stack

| Layer | Technology |
|---|---|
| Backend | PHP 8.2+, no framework |
| Database | SQLite 3 via PDO; schema migrations run on connect |
| Frontend | Server-rendered PHP pages, vanilla JS, Bootstrap 5.3 (dark), Bootstrap Icons, Chart.js 4 |
| AI | [NanoGPT](https://nano-gpt.com) chat-completions API (`google/gemini-3.7-flash`) |

---

## Quick start (local)

Requirements:
- PHP 8.2+ with the `pdo_sqlite`, `curl` and `fileinfo` extensions.
- A NanoGPT API key, for scanning only. Everything else works without one.

SPENCE expects to be served under the `/spence/` URL path, so serve the directory *above* the checkout:

```bash
git clone https://github.com/jaaaames0/spence.git
cp spence/database/spence_empty.db spence/database/spence.db

SPENCE_ACCESS_KEY=change-me \
NANOGPT_API_KEY=your-nanogpt-key \
php -S localhost:8000 -t .
```

Open <http://localhost:8000/spence/>, log in with the access key, and go to **Settings** to set up your profile and targets.

The auth cookie is marked `Secure`. Browsers accept that over plain HTTP on `localhost`, but anywhere else SPENCE must be served over HTTPS.

The environment-variable keys are a development convenience. For a real deployment, put the keys in files; see [DEPLOYMENT.md](DEPLOYMENT.md).

---

## Configuration

All configuration is read from environment variables. Paths must be absolute.

| Variable | Purpose | Default |
|---|---|---|
| `SPENCE_CREDENTIAL_FILE` | File containing the access key | `/etc/spence/access-key` |
| `NANOGPT_CREDENTIAL_FILE` | File containing the NanoGPT API key | `/etc/spence/nanogpt-key` |
| `SPENCE_ACCESS_KEY`, `NANOGPT_API_KEY` | Key values, used only when the credential file is missing (development) | none |
| `SPENCE_DB_PATH` | SQLite database | `database/spence.db` |
| `SPENCE_UPLOAD_DIR` | Uploaded receipt and food photos | `uploads/` |
| `SPENCE_TENANTS_DIR` | Guest key files | `/etc/spence/tenants` |
| `SPENCE_TENANT_DATA_DIR` | Guest databases and uploads | `/var/lib/spence/tenants` |
| `FORGE_DB_PATH` | Read-only FORGE export (optional) | `/var/lib/forge/exports/spence-v1.db` |
| `FORGE_EXPORT_MAX_AGE_SECONDS` | How old the FORGE export may be before it's ignored (minimum 60) | `300` |
| `SPENCE_INGREDIENTS_PATH` | External shopping-list JSON file (optional) | unset (handoff off) |
| `SPENCE_AI_DEBUG` | `1` adds request and response diagnostics to AI error replies | `0` |

SPENCE refuses every request if no access key is configured.

---

## Using SPENCE

| Page | What it's for |
|---|---|
| **Home** | Dashboard: today against your targets, the trend, spend, expiring stock, top sources |
| **Stock** | Inventory, receipt scanning, inline edits, waste, spice rack |
| **Eat** | Eat from stock, or Quick Eat (photo, describe, existing product) |
| **Cook** | Browse and cook recipes, with substitutions, live macros and a spice check |
| **RecipeDB** | Build and edit recipes |
| **Log** | Daily view, weekly trends, weight and body-fat progress |
| **Settings** | Profile and nutrition plan, product master, deduplication, export |

See the [user guide](docs/USER_GUIDE.md) for a walkthrough of each page.

---

## Development

```bash
for t in tests/test_*.php; do php "$t" || break; done   # run the test suite
php ops/rebuild-schema.php                               # regenerate schema.sql and spence_empty.db
```

Schema migrations live in `get_db_connection()` (`core/db_helper.php`) and are the source of truth. After adding one, run `ops/rebuild-schema.php`, or `tests/test_schema.php` will fail.

See [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) for how the pieces fit together.

## Documentation

- [User guide](docs/USER_GUIDE.md)
- [Architecture](docs/ARCHITECTURE.md)
- [Deployment](DEPLOYMENT.md)
- [FORGE integration](docs/FORGE-INTEGRATION.md)
- [Recipe Intelligence design](docs/RECIPE_INTELLIGENCE.md)
- [Roadmap](ROADMAP.md) · [Changelog](CHANGELOG.md)

## Licence

[MIT](LICENSE)
