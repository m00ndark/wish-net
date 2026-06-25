# wish-net

**Familjens Önskelista** — a family wishlist / gift-coordination app.

A migration of the legacy server-rendered PHP app to a **Blazor WebAssembly** front end
(`src/`) backed by a purpose-built, secure **PHP API** (`api/`), hosted on Hostinger.

See **[PLAN.md](PLAN.md)** for the full architecture, security model, API surface, and
delivery plan. This README is just how to run it.

## Layout

| Path  | What |
|-------|------|
| `src/` | Blazor WASM standalone client (.NET 10) |
| `api/` | PHP API (PDO + MySQL/MariaDB), routed through `index.php` |
| `db/`  | `schema.reference.sql` (reference only) + ordered `migrations/` |

## Prerequisites

- **.NET 10 SDK**
- **PHP 8.1+** and **MySQL/MariaDB** for the API (e.g. via XAMPP locally)

## Configuration

The API needs a server-only `api/config.php` (git-ignored). Create it from the template:

```sh
cp api/config.example.php api/config.php
```

Then fill in the DB credentials, the (unchanged) reservation **encryption key**, and the
**master-password hash**. See the comments in `config.example.php`.

## Running locally

**API** — serve `api/` under a PHP host (XAMPP) so it's reachable at, by default,
`http://localhost/wish-net/api/`. Smoke-test the wiring (no DB needed):

```
GET http://localhost/wish-net/api/ping  ->  { "status": "ok", "time": "..." }
```

**Client** — from `src/`:

```sh
dotnet run
```

The client reads its API base URL from `wwwroot/appsettings.Development.json` in dev (defaults
to the XAMPP URL above) and otherwise derives it from `<base href>` (same-origin `…/api/`).
Adjust the dev URL if your local PHP host differs.

## Database

`db/schema.reference.sql` is a **reference dump only** — it contains `DROP TABLE` statements and
must never be run against a live database. Using it to seed a *fresh local* DB is fine.

Local setup (XAMPP MySQL/MariaDB, user `root`, no password):

```sh
MYSQL="/c/xampp/mysql/bin/mysql.exe -u root"
$MYSQL -e "CREATE DATABASE IF NOT EXISTS wishlist_db CHARACTER SET latin1 COLLATE latin1_swedish_ci;"
# Skip line 1: the prod dump (MariaDB 11.8) starts with a sandbox-mode marker that
# XAMPP's older MariaDB client rejects and then aborts the whole import.
tail -n +2 db/schema.reference.sql | $MYSQL wishlist_db
$MYSQL wishlist_db < db/migrations/001_widen_users_password.sql
$MYSQL wishlist_db < db/migrations/002_create_sessions.sql
```

Apply `db/migrations/` in order (`001`, `002`, …); the same `tail -n +2` caveat applies to any
data dump exported from Hostinger. For staging/prod, run migrations there too (PLAN.md §11).

## Deployment

GitHub Actions builds the client and deploys it plus `api/` over SSH to the Hostinger
subfolder, excluding `api/config.php`. Details in PLAN.md §11.
