# Deployment

This guide covers running SPENCE as a long-lived service behind Nginx or Apache with PHP-FPM. For a quick local trial, see the [quick start](README.md#quick-start-local).

## Requirements

- PHP 8.2+ (FPM) with `pdo_sqlite`, `curl` and `fileinfo`.
- Nginx or Apache, serving HTTPS. The auth cookie is `Secure`, so logins will not stick over plain HTTP.
- A NanoGPT API key, if you want receipt and Quick Eat scanning.

SPENCE is served under the URL path `/spence/`. Navigation, asset links and the auth cookie path all assume that prefix.

## Layout

Keep code and state apart, so a release can be replaced without touching your data:

| What | Example location | Configured by |
|---|---|---|
| Application code (read-only to PHP) | `/opt/spence` | web server root / alias |
| Database (read-write) | `/var/lib/spence/spence.db` | `SPENCE_DB_PATH` |
| Uploads (read-write) | `/var/lib/spence/uploads` | `SPENCE_UPLOAD_DIR` |
| Access key (one line) | `/etc/spence/access-key` | `SPENCE_CREDENTIAL_FILE` |
| NanoGPT key (one line) | `/etc/spence/nanogpt-key` | `NANOGPT_CREDENTIAL_FILE` |

Without `SPENCE_DB_PATH` / `SPENCE_UPLOAD_DIR`, SPENCE uses `database/spence.db` and `uploads/` inside the checkout. The PHP user then needs write access to both directories; SQLite also writes `-wal` and `-shm` files next to the database.

Create the database from the blank template. Migrations in `get_db_connection()` bring any older database up to date on first connect.

```bash
install -d -m 0750 /var/lib/spence /var/lib/spence/uploads
cp database/spence_empty.db /var/lib/spence/spence.db
chown -R www-data:www-data /var/lib/spence     # your PHP-FPM user
```

## Credentials

Each key lives alone on one line in a file readable by the PHP-FPM user and nobody else:

```bash
install -d -m 0750 -g www-data /etc/spence
openssl rand -base64 24 | install -m 0640 -g www-data /dev/stdin /etc/spence/access-key
printf '%s\n' 'YOUR-NANOGPT-KEY' | install -m 0640 -g www-data /dev/stdin /etc/spence/nanogpt-key
```

Pass only the *paths* to PHP. Credential values should not go in the FPM environment. The `SPENCE_ACCESS_KEY` / `NANOGPT_API_KEY` environment variables exist only as a development fallback. With no access key at all, SPENCE answers every request with HTTP 503.

## PHP-FPM

A dedicated pool keeps SPENCE's permissions separate from other sites. A minimal pool:

```ini
[spence]
user = spence
group = spence
listen = /run/php/spence-fpm.sock
listen.owner = www-data
listen.group = www-data

pm = ondemand
pm.max_children = 4
request_terminate_timeout = 130s   ; a receipt scan is one long request
clear_env = yes

env[SPENCE_DB_PATH] = /var/lib/spence/spence.db
env[SPENCE_UPLOAD_DIR] = /var/lib/spence/uploads
env[SPENCE_CREDENTIAL_FILE] = /etc/spence/access-key
env[NANOGPT_CREDENTIAL_FILE] = /etc/spence/nanogpt-key

php_admin_value[upload_max_filesize] = 12M
php_admin_value[post_max_size] = 12M
```

Photos are shrunk in the browser before upload: receipts to 2048 px and Quick Eat to 1536 px JPEG. Uploads therefore stay small, but the upload limits still need to allow a few megabytes.

[`ops/php-fpm-spence.conf`](ops/php-fpm-spence.conf) is a fuller hardened example, with an AppArmor hat in [`ops/apparmor-php-fpm-spence`](ops/apparmor-php-fpm-spence). Both follow the layout above (code in `/opt/spence`), so adjust them if yours differs. [`ops/spence-isolation-probe.php`](ops/spence-isolation-probe.php) checks a running pool's isolation; see its header for how to run it.

## Web server

Nginx, with the checkout (or a release directory) at `/opt/spence`:

```nginx
location /spence/ {
    alias /opt/spence/;
    index index.php;

    location ~ ^/spence/(database|ops|tests|uploads)/ { deny all; }
    location ~ /\.            { deny all; }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $request_filename;
        fastcgi_pass unix:/run/php/spence-fpm.sock;
    }
}
```

Never serve the `database/`, `ops/` or `tests/` directories, or dotfiles. If the database and uploads live outside the code directory, as above, they are never reachable over HTTP anyway.

## Upgrading

1. Back up the database with `sqlite3 /var/lib/spence/spence.db ".backup /path/to/backup.db"`. That command is safe while the app is running.
2. Replace the code, for example with `git pull` or by unpacking a `git archive` of the new release into a fresh directory and switching a symlink.
3. Reload PHP-FPM.

Schema migrations run automatically on the next request. A schema change that the previous release can't read makes rolling back the code alone unsafe; restore the backup as well.

## Diagnostics

AI errors always report the provider's HTTP status and message. For deeper diagnostics, set `env[SPENCE_AI_DEBUG] = 1` in the pool and reload. Error replies then also include the model, request size and timing, finish or refusal reason, and up to 2,000 bytes of the provider's response. Turn it back off afterwards. The output never contains the API key or the image, but it can contain model-generated receipt or food text.

## Guest tenants

Besides the primary user, SPENCE can host guests. Each guest has their own database, uploads and NanoGPT key. Guests never see FORGE data or the shopping-list handoff.

| What | Location |
|---|---|
| Guest access key | `$SPENCE_TENANTS_DIR/<id>.key` (default dir `/etc/spence/tenants`) |
| Guest NanoGPT key (optional) | `$SPENCE_TENANTS_DIR/<id>.nanogpt` |
| Guest database and uploads | `$SPENCE_TENANT_DATA_DIR/<id>/` (default `/var/lib/spence/tenants`) |

Create a guest, seeding their product catalogue from a snapshot of yours:

```bash
sqlite3 /var/lib/spence/spence.db ".backup /tmp/seed.db"
SPENCE_TENANT_DATA_DIR=/var/lib/spence/tenants \
  php ops/spence-create-tenant.php alice --seed-from /tmp/seed.db
chown -R spence:spence /var/lib/spence/tenants/alice
rm /tmp/seed.db

openssl rand -base64 18 | install -m 0640 -g spence /dev/stdin /etc/spence/tenants/alice.key
```

The seed copies only raw, live products: macros, units and last unit cost (`--no-prices` zeroes the cost), plus their receipt aliases, spice aliases and dismissed dedupe pairs. Stock, logs, recipes and profile are never copied.

- **Scanning:** without an `<id>.nanogpt` file, scanning is off for that guest. It never falls back to the primary key.
- **Unique keys:** the login form tries every key, so each one must be unique.
- **Revoking:** delete the guest's key file. Rotating the key logs out only that guest's devices.
- **Backups:** back up the tenant databases alongside the primary one.

## Optional integrations

- **FORGE:** see [docs/FORGE-INTEGRATION.md](docs/FORGE-INTEGRATION.md). Point `FORGE_DB_PATH` at the published read-only export.
- **Shopping list:** recipe *Get Ingredients* appends missing items to an external JSON shopping list at `SPENCE_INGREDIENTS_PATH`; the button is hidden while that is unset or the file doesn't exist. It is the only thing SPENCE writes outside its own state. Grant read-write on that one file and nothing else.
