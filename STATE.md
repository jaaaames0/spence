# STATE.md

## Project State Snapshot

- **Stage:** v1.6.0 — stable, in active daily use
- **Deployment:** Production on jaaaames.com (`/srv/jaaaames.com/spence/`). Migration to OptiPlex pending hardware delivery.
- **Current Target:** Infrastructure migration (same blocker as FORGE)
- **Current Focus:** Daily nutritional logging; no active feature development

---

## Working Agreements

- All pages start with `require_once __DIR__ . '/../core/auth.php';`
- Database writes use PDO prepared statements exclusively — no string interpolation
- HMAC cookie: `hash_hmac('sha256', 'spence_auth_v1', $ACCESS_KEY)`, 1-year lifetime
- Auth key in `core/auth.php` `ACCESS_KEY` constant — must be changed from default before network exposure
- OpenRouter key in `core/openrouter.env` (single line, gitignored)
- Spice rack and `recipe_spices` tables auto-migrate on every `get_db_connection()` call
- Ghost Recipe Engine forks are always `is_active=0`; browse queries filter `is_active=1`
- `syncRecipeToProduct()` runs on every recipe save; each version gets its own `product_id`

---

## Current Sprint Focus

Daily use. No active feature development. The primary blocker is shared with FORGE: the OptiPlex server has not arrived, so all infrastructure migration is paused.

---

## Active Task Board

### In Progress

_(nothing currently in active development)_

### Todo

- [ ] Migrate jaaaames.com stack to OptiPlex once hardware arrives
- [ ] Wire up `inventory.expiry_date` — dashboard expiry card, stock page sort/column (v2.0)
- [ ] Adaptive Daily Targets — Training Day / Rest Day macro toggle (v2.0)
- [ ] Label Scan — Photo → Genesis Product without receipt (v2.0)
- [ ] Shopping List Predictor — auto-generated from consumption vs stock (v2.0)
- [ ] AI Chef — meal recommendations from on-hand ingredients vs macro targets (v2.0)
- [ ] Recipe Discovery — import from external sources (v2.0)
- [ ] SPENCE Installer — one-shot `setup.sh` for Nginx, SQLite, permissions (v2.1)
- [ ] Export Engine — CSV/JSON export (v2.1)

### Deferred

- FORGE/SPENCE integration (shared profile, dynamic TDEE, combined dashboard)
- Multi-user support
- Hardened deployment configuration (noted as v2.1 concern)

### Done

- v1.6.0 (2026-04-05): Mobile Pass — all surfaces fully responsive
- v1.5.0 (2026-04-05): Intelligence Dashboard — live 4×2 metrics
- v1.4.0 (2026-04-05): Spice Rack — binary pantry tracking, receipt AI routing
- v1.3.0 (2026-04-05): HMAC cookie auth (1-year), PWA packaging
- v1.2.0 (2026-04-05): Ghost Recipe Engine, on-the-fly forking, live cook macros
- v1.1.0 (earlier): Core nutritional logging, recipe builder, inventory management

---

## Blockers

1. **OptiPlex not arrived** — tracking: 363GP5067272 (Australia Post). Public holiday delay. Migration blocked.
2. **FORGE/SPENCE integration** — both apps store bodyweight independently. No shared profile or API. Decision needed on integration depth before any work begins.

---

## Decisions Log

| Date | Decision | Rationale |
|------|----------|-----------|
| 2026-04-27 | Bootstrap stateful-docs 7-file system in project root | Aligning with FORGE and workspace standard |
| 2026-04-05 | HMAC cookie over PHP sessions | PHP session GC was wiping sessions randomly; HMAC survives restart with no server-side state |
| 2026-04-05 | Synchronous receipt ingest | Single HTTP request simplifies architecture; no background worker or queue needed for personal use scale |
| 2026-04-05 | 10g tolerance for ghost fork creation | Prevents ghost creation from minor quantity rounding; nothing meaningful is lost |
| 2026-04-05 | Spice restock at 40 uses | Arbitrary but reasonable; enough to track depletion without constant nagging |
| 2026-04-05 | Jaccard similarity for deduplication | String matching alone fails on "Full Cream Milk" vs "Whole Milk"; category lock prevents cross-category false merges |
| Pre-v1.2 | Ghost Recipe Engine design | Forks preserve historical accuracy without polluting active recipe list; fingerprint prevents duplicates |
| Pre-v1.2 | Katch-McArdle TDEE | Uses actual measured body fat; more accurate than BMR formulas that use total body weight |
| Pre-v1.2 | OpenRouter for vision | Gemini Flash is fast and cheap enough for receipt parsing; any OpenAI-compatible endpoint works |

---

## Daily Update Template

```
Date: YYYY-MM-DD
- Yesterday:
- Today:
- Risks/Blockers:
- Decisions Needed:
```

---

*Last updated: 2026-04-27*
