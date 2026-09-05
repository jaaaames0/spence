# Forge integration contract

Spence consumes a versioned, read-only projection of Forge data. Production
must not open Forge's live WAL database, because doing so couples the Spence
identity to Forge's writable SQLite sidecars and exposes unrelated Forge data.

## Version 1

The publisher atomically replaces `/var/lib/forge/exports/spence-v1.db`. The
version 1 contract contains only the tables and columns used by Spence:

- `workouts(started_at, finished_at, type, bodyweight_kg)`
- `body_measurements(measured_at, caliper_bf_pct)`
- `training_eras(id, is_active)`
- `training_cycles(era_id, cycle_type, starts_at, ends_at, source, confidence)`
- `forge_export_metadata(schema_version, exported_at_utc)`

The export is built in one SQLite read transaction, checked, synced, made
read-only, and renamed over the prior export. An in-flight request can finish
against the old inode; the next request opens the new immutable export.

The publisher is a separate locked `forge-web` process with no network access.
Its systemd sandbox makes the source database and WAL read-only, permits the
SQLite shared-memory coordination file, and allows writes only within Forge's
own state tree. The web-facing Spence identity receives read access to the
published file, not permission to execute or control the publisher.

Spence rejects an unsupported schema or an export older than five minutes and
falls back to its existing local-data behaviour. Publication normally runs
once per minute, so a newly started workout or saved body-composition reading
can take approximately one minute to affect Spence.

## Extending the contract

Do not grant Spence access to the live Forge database to add a feature. For a
compatible additive field, extend the publisher and consumer tests together.
For a larger or breaking integration—such as exercise/set/rep data used to
estimate workout energy—publish `spence-v2.db` alongside version 1:

1. document the exact new fields and purpose;
2. add only those projections to a version 2 publisher;
3. deploy a Spence consumer that accepts and tests version 2;
4. compare version 1 and version 2 results for their shared behaviours;
5. switch Spence to version 2 under rollback;
6. retire version 1 only after acceptance.

Parallel versions avoid a lock-step deployment and keep new Forge tables
private unless a reviewed Spence workflow genuinely needs them.
