# Deployment layout

Spence is developed in this repository, but the website does not execute code
from this working tree.

- Editable source: `/home/james/src/spence`
- Versioned runtime: `/usr/local/lib/spence/RELEASE`
- Public compatibility path: `/srv/jaaaames.com/spence`
- Mutable database: `/var/lib/spence/spence.db`
- Mutable uploads: `/var/lib/spence/uploads`
- Dedicated-pool credentials: `/etc/spence/access-key` and
  `/etc/spence/nanogpt-key`
- Read-only Forge integration: `/var/lib/forge/exports/spence-v1.db`

The runtime is created from a clean Git archive and is owned by root. Its live
database and uploads entries are symlinks to `/var/lib/spence`; they must never
be copied into Git or edited below `/srv` or `/usr/local/lib`.

`SPENCE_DB_PATH`, `SPENCE_UPLOAD_DIR`, `SPENCE_CREDENTIAL_FILE`,
`NANOGPT_CREDENTIAL_FILE`, `SPENCE_INGREDIENTS_PATH`, and `FORGE_DB_PATH`
provide non-secret absolute paths for tests and the dedicated PHP-FPM pool.
Production uses a versioned, atomically published Forge projection; see
`docs/FORGE-INTEGRATION.md`. `FORGE_EXPORT_MAX_AGE_SECONDS` may raise the
default five-minute stale-export threshold, but must not be set below 60.

The legacy `/srv/secrets` credential defaults remain only to make the source
preparation release safe on the shared pool before cutover. The dedicated pool
must set the `/etc/spence` paths explicitly. Credential values must not be put
in FPM environment variables.

NanoGPT failures always return the provider's HTTP status and error message.
For temporary deeper diagnostics, set `env[SPENCE_AI_DEBUG] = 1` in the active
Spence PHP-FPM pool and reload PHP-FPM. Error JSON will then also include the
model, encoded request size, request timing, finish/refusal reason, and at most
2,000 bytes of the provider response. Return it to `0` after diagnosis; debug
output may contain model-generated receipt or food text, but never the API key
or uploaded image.

The retained recipe **Get Ingredients** workflow is the sole Spence-to-
Ingredients write. The dedicated identity receives `rw` access to exactly
`/srv/jaaaames.com/ingredients/shopping_list.json` through a narrow bridge
group; it receives no access to Ingredients PHP or credentials and no directory
create/delete permission.

The current host has a known PHP CLI loader conflict with
`/usr/local/lib/libsqlite3.so.0`. Until that separate package issue is repaired,
run the tests with the system library selected explicitly:

```bash
LD_LIBRARY_PATH=/usr/lib/x86_64-linux-gnu php tests/test_db_helper.php
LD_LIBRARY_PATH=/usr/lib/x86_64-linux-gnu php tests/test_energy_calibration.php
```

## Deploying

Code-only changes are fast-lane deploys (`/home/james/AGENTS.md`, host handbook
§9.1). Commit, then:

```bash
fastdeploy spence             # tests, build, PHP lint, SQLite snapshot, switch, reload
fastdeploy spence --status
fastdeploy spence --rollback  # back to the previous release
```

`/srv/jaaaames.com/spence` links to `/usr/local/lib/spence/current`, which links to
the release. `fastdeploy` exports an allowlist of tracked runtime paths (see
`/home/james/src/fastdeploy/projects/spence.conf`) into a new root-owned release,
recreates the state symlinks, takes a consistent SQLite snapshot under
`/data/security-backups/fastdeploy/spence`, switches `current`, reloads
`php8.3-fpm`, and switches back if `https://jaaaames.com/spence/` stops
answering. A new top-level runtime directory must be added to that allowlist.

Use the full procedure (checkpoint, timed rollback, operator acceptance) for
changes to the PHP-FPM pool, AppArmor hat, credentials, nginx, or a schema
change the previous release cannot read.
