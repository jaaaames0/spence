# SPENCE | Sovereign Product & Fuel Engine

[![Version](https://img.shields.io/badge/version-0.9--RC1-blue?style=for-the-badge&logo=opsgenie)](https://github.com/jaaaames/spence)
[![License](https://img.shields.io/badge/license-MIT-green?style=for-the-badge)](LICENSE)
[![Design](https://img.shields.io/badge/design-One_Dark_Industrial-00A3FF?style=for-the-badge)](docs/DESIGN.md)

**SPENCE** is a high-fidelity, local-first nutritional architect. It replaces corporate cloud-trackers with a sovereign, AI-augmented engine designed to manage your intake from grocery receipt to metabolic fuel.

Built for the "weird web" aesthetic and "One Dark Industrial" precision, SPENCE uses AI Vision to ingest entire receipts, automate inventory deduplication, and track macros with mathematical rigor.

---

## ⚡ Core Architecture

### 📦 Warehouse (Stock)
- **AI-Powered Ingest:** One-click receipt scanning via `watcher.sh`. Uses AI Vision to extract genericized names, quantities, price-paid, and macro profiles.
- **Sovereign Deduplication:** Intelligence engine with Category Locking and Jaccard Similarity (60%) to merge variants (e.g., "Full Cream Milk" into "Whole Milk") while averaging cost basis.
- **Industrial Metrics:** Track inventory across 11 metrics including **Protein-per-Dollar** and **Energy Density**.

### 🍎 Raid (Eat)
- **Consumer Density:** Normalized to Australian nutritional standards (**per 100g/mL** or **per Serve**).
- **Portion Control:** High-density modal with 15-button matrix for rapid precision logging (Percentage, Grams, or Units).
- **Live Preview:** Real-time macro-split visualization before you commit to the log.

### 🍳 Execute (Cook)
- **Ghost Recipe Engine:** Automatically forks blueprints into "Ghost" (historical) versions when ingredient substitutes are used, preserving log integrity without database clutter.
- **Stock Guard:** Live inventory check before cooking. Integrates with external shopping list apps via `ingredients_api.php`.
- **Portion Logic:** Automated stock deduction. Cooked servings not consumed are instantly moved to "Meal Prep" inventory.

### 📊 Log & Analytics
- **Chronological Intel:** Detailed daily intake with expandable recipe breakdowns.
- **Weekly Matrix:** Multi-axis Chart.js visualization with toggleable macro benchmarks and weighted averages.
- **Sovereign Vitals:** Local-first weight and body fat tracking with dual-Y-axis progress charts.

---

## 🛠️ Technical Stack

- **Backend:** PHP 8.3 (FastCGI)
- **Database:** SQLite 3 (Single-file sovereignty)
- **Frontend:** Bootstrap 5.3 (One Dark Industrial Theme) / Chart.js / Bootstrap Icons
- **Vision Layer:** Bash-driven `watcher.sh` pipeline (designed for systemd/cron execution).

---

## 🚀 Deployment (The "Looming" Install Script)

SPENCE is currently a "Vibe-Coded" Release Candidate. It assumes a standard LEMP/LAMP stack environment.

### 1. Requirements
- Nginx/Apache with PHP-FPM 8.2+
- SQLite3
- An API Key for a Vision-capable LLM (Gemini 3 Pro/Flash or GPT-5)

### 4. Database Initialization
SPENCE ships with a pre-configured schema. To initialize your local database:
```bash
cp database/spence_empty.db database/spence.db
chmod 666 database/spence.db
```
Alternatively, you can rebuild the schema using the provided SQL:
```bash
sqlite3 database/spence.db < database/schema.sql
```

### 3. The Watcher (Ingest Pipeline)
The `core/watcher.sh` script monitors an ingest directory for images. Set your API key inside this file and run it as a service:
```bash
# Example systemd unit snippet
ExecStart=/bin/bash /var/www/spence/core/watcher.sh
```

---

## 📅 Roadmap
- [ ] **SPENCE Setup:** Automated installer and config-wizard.
- [ ] **AI Chef:** Dynamic meal suggestions based on "Ingredients on Hand" vs "Macro Targets".
- [ ] **Expiry Sentinel:** Automated tracking of perishable inventory life-cycles.
- [ ] **Mobile Optimization:** Refining the Industrial UI for smaller screens.

---

## ⚖️ License
Distributed under the **MIT License**. See `LICENSE` for more information.

---

## ⚠️ Disclaimer
*This project was built using high-velocity agentic "Vibe Coding". It is a sovereign tool for personal use. It likely contains security vulnerabilities if exposed to the public web without proper hardening. Use at your own risk.*

**Built with Elias (OpenClaw Agent) in Byron Bay.**
