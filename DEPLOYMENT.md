# Deployment layout

Spence is developed in this repository, but the website does not execute code
from this working tree.

- Editable source: `/home/james/src/spence`
- Versioned runtime: `/usr/local/lib/spence/RELEASE`
- Public compatibility path: `/srv/jaaaames.com/spence`
- Mutable database: `/var/lib/spence/spence.db`
- Mutable uploads: `/var/lib/spence/uploads`
- Credentials: `/srv/secrets/spence_credentials.env` and the existing
  NanoGPT credential source under `/srv/secrets`
- Read-only Forge integration: `/var/lib/forge/exports/spence-v1.db`

The runtime is created from a clean Git archive and is owned by root. Its live
database and uploads entries are symlinks to `/var/lib/spence`; they must never
be copied into Git or edited below `/srv` or `/usr/local/lib`.

`SPENCE_DB_PATH`, `SPENCE_UPLOAD_DIR`, and `FORGE_DB_PATH` can override the
default paths for tests or a future dedicated PHP-FPM pool. Production uses a
versioned, atomically published Forge projection; see
`docs/FORGE-INTEGRATION.md`. `FORGE_EXPORT_MAX_AGE_SECONDS` may raise the
default five-minute stale-export threshold, but must not be set below 60.

The current host has a known PHP CLI loader conflict with
`/usr/local/lib/libsqlite3.so.0`. Until that separate package issue is repaired,
run the tests with the system library selected explicitly:

```bash
LD_LIBRARY_PATH=/usr/lib/x86_64-linux-gnu php tests/test_db_helper.php
LD_LIBRARY_PATH=/usr/lib/x86_64-linux-gnu php tests/test_energy_calibration.php
```

Deployments must use a checkpoint, a timed rollback, SQLite integrity checks,
PHP lint, nginx validation, HTTP/authentication tests, and real database/upload
workflows before the old runtime is retired.
