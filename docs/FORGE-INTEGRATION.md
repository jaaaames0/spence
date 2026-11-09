# FORGE integration

FORGE is a companion workout and body-composition tracker. When it's available, SPENCE reads from it:

- **Bodyweight** logged with workouts, and **body fat** (smoothed, falling back to calipers), shown on Progress and used by energy calibration.
- **Workout days**, for training-day energy targets and the formula's activity-rate suggestion.
- **Training cycles** (cut/bulk), as phase boundaries for energy calibration.

The integration is optional and read-only, and SPENCE never writes to FORGE. Without it, SPENCE uses its own weigh-ins and phase detection. Guest tenants never see FORGE data.

## How SPENCE finds the data

`getForgeExportPath()` in `core/forge.php` looks in three places, in order:

1. `FORGE_DB_PATH`, if set. This must be a version 1 export (see below).
2. `/var/lib/forge/exports/spence-v1.db`, if readable. This is also a version 1 export.
3. `../forge/database/forge.db`, a sibling FORGE checkout's live database, for local development only. SPENCE builds a version 1 projection of it with that checkout's own builder (`core/spence_export.php`), so development reads exactly what production would.

An export is rejected if its schema version is unsupported, or if it's older than `FORGE_EXPORT_MAX_AGE_SECONDS` (default 300, minimum 60). SPENCE then behaves as if FORGE weren't there.

In production, SPENCE should read a published export rather than FORGE's live database. Reading the live write-ahead-log (WAL) database would tie SPENCE's process to FORGE's writable SQLite sidecar files, and would expose FORGE data that SPENCE doesn't need.

## Version 1 export

The export contains only what SPENCE uses:

- `workouts(started_at, finished_at, type, bodyweight_kg)`
- `body_measurements(measured_at, caliper_bf_pct, smoothed_bf_pct)`
- `training_eras(id, is_active)`
- `training_cycles(era_id, cycle_type, starts_at, ends_at, source, confidence)`
- `forge_export_metadata(schema_version, exported_at_utc)`

`smoothed_bf_pct` was added compatibly. The publisher writes NULLs when FORGE's database predates the column, and SPENCE falls back to `caliper_bf_pct` for NULLs and for older exports.

Timestamps are **local wall time** (`Y-m-d H:i:s`, no offset), so SPENCE takes a day from `DATE(started_at)` or the first 10 characters. FORGE stores UTC and converts each timestamp through its own time zone history when it publishes, the same model as SPENCE's `timezone_periods`: each is read in the zone in force when it was written. Two details keep SPENCE's reads right while travelling:

- A row's date is FORGE's local day, which, after a westward zone change past midnight, stays on the day already started.
- `finished_at` is the local start plus the real duration, so `julianday(finished_at) - julianday(started_at)` stays correct across a DST or zone change. Use it only for durations.

## Publishing the export

FORGE owns the publisher, because only FORGE can convert its timestamps: `ops/spence-export-v1.php` in the FORGE repository, shipped in each FORGE release (`/usr/local/lib/forge/current/ops/spence-export-v1.php`), using `core/spence_export.php`. It replaced a shell publisher here that copied columns verbatim. It works like this:

1. It reads FORGE's database in a single SQLite read transaction.
2. It checks the result, syncs it to disk and makes it read-only.
3. It renames the file over the previous export.

A request already in flight finishes against the old file, and the next request opens the new one.

The sample systemd [service](../ops/forge-spence-export-v1.service) and [timer](../ops/forge-spence-export-v1.timer) run the publisher once a minute as FORGE's own user, with no network access. The source database is read-only to it, and it can write only to FORGE's state directory. SPENCE's PHP user needs read access to the published file and nothing else. Expect changes in FORGE to show up in SPENCE within about a minute.

The publisher reads `/var/lib/forge/forge.db` and writes to `/var/lib/forge/exports` (`FORGE_EXPORT_SOURCE` and `FORGE_EXPORT_DIR` override them), and the sample unit runs it as a `forge` user; adjust these to your FORGE install.

To check the published export, run `ops/validate-forge-export-v1.php` as a user that can read both databases (here, `sudo -u forge-web env LD_LIBRARY_PATH=/usr/lib/x86_64-linux-gnu php ops/validate-forge-export-v1.php`). It builds the expected projection from FORGE's live database with FORGE's deployed builder and compares row counts and deterministic hashes, printing no body-composition, workout or cycle values. A FORGE change that hasn't been published yet shows as a FAIL; run it again after a minute.

## Extending the contract

To give SPENCE more FORGE data, extend the export; don't open the live database.

For a compatible, additive field, update the publisher and SPENCE's consumer, and their tests, together.

For a larger or breaking change, such as using exercise and set data to estimate workout energy, publish a `spence-v2.db` alongside version 1:

1. Document the new fields and what they're for.
2. Add only those projections to a version 2 publisher.
3. Ship a SPENCE consumer that accepts version 2, with tests.
4. Compare the version 1 and version 2 results where they overlap.
5. Switch SPENCE to version 2, with a way to roll back.
6. Retire version 1 once version 2 is accepted.

Running both versions side by side avoids a lock-step deploy and keeps new FORGE tables private until a SPENCE feature genuinely needs them.
