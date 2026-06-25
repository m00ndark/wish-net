# Wish-net — Migration Plan

Migration of **Familjens Önskelista** (Swedish family gift-coordination app) from a
server-rendered PHP application to a **Blazor WebAssembly** front end backed by a
**purpose-built, secure PHP API**, hosted on **Hostinger** (shared Linux / Apache).

- **Legacy app (reference):** `wish` repo — server-rendered PHP, MySQL. Kept for reference only.
- **New app (this repo):** `wish-net` — Blazor WASM client (`src/`) + fresh PHP API (`api/`).
- **Database:** existing MySQL schema, unchanged.
- **Live host:** `wish.m00ndark.com`, served from a subfolder under `public_html`.

---

## 1. Architecture

```
Browser ── Blazor WASM (static files) ─┐
                                       │  same origin (same domain + subfolder)
                                       ├── /wish-net/            → Blazor SPA
                                       └── /wish-net/api/        → PHP API ── PDO ── MySQL
```

- App and API live under the **same domain and subfolder** → **same-origin → no CORS**.
- The Blazor client is fully static (`_framework/*.wasm`, etc.); all logic/auth is enforced
  by the PHP API. Nothing trusts client-supplied identity.

### Tech stack
| Layer    | Choice |
|----------|--------|
| Client   | Blazor WebAssembly **standalone**, **.NET 10** (LTS) |
| API      | PHP 8.x, PDO + prepared statements |
| Database | MySQL (existing schema) |
| Host     | Hostinger shared Linux / Apache, deploy over **SSH/SFTP** |
| CI/CD    | GitHub Actions → `dotnet publish` + `rsync` over SSH |

---

## 2. Repository structure

```
wish-net/
├── .github/workflows/deploy.yml      # build + deploy (staging & prod)
├── api/                              # PHP API
│   ├── index.php                     # front controller / router
│   ├── config.example.php            # template (tracked)
│   ├── config.php                    # real secrets (GIT-IGNORED, server-only)
│   ├── .htaccess                     # routes /api/* → index.php
│   ├── lib/                          # db.php, auth.php, crypto.php, helpers
│   └── endpoints/                    # auth, lists, wishes, reservations, categories...
├── db/                               # database reference + migrations (see §4)
│   ├── schema.reference.sql          # untouched mysqldump baseline — REFERENCE ONLY,
│   │                                 #   never run against a live DB (contains DROP TABLE)
│   └── migrations/                   # ordered, forward-only change scripts (run manually)
│       ├── 001_widen_users_password.sql
│       └── 002_create_sessions.sql
├── src/                              # Blazor WASM project
│   ├── Components/ (.razor pages + dialogs)
│   ├── Models/                       # C# DTOs
│   ├── Services/                     # ApiClient, AuthStateProvider, token store
│   ├── wwwroot/
│   │   ├── index.html                # <base href="/wish-net/" />
│   │   ├── .htaccess                 # SPA fallback + MIME + API carve-out
│   │   ├── css/  images/  ...
│   │   └── appsettings*.json         # API base URL per environment
│   ├── App.razor
│   ├── Program.cs
│   └── WishNet.Client.csproj
├── .gitignore
├── PLAN.md
└── README.md
```

> **One rule that protects production:** `api/config.php` is git-ignored **and** excluded
> from every deploy. The server keeps its own copy with the real DB credentials,
> encryption key, and master-password hash forever.

---

## 3. Server (Hostinger) layout

```
public_html/
├── other-site-1/                 # untouched
├── wish-net/                     # PRODUCTION
│   ├── api/  (config.php = prod creds)
│   ├── _framework/  css/  index.html  .htaccess  favicon.ico
└── wish-net-staging/             # STAGING (own DB)
    └── api/  (config.php = staging creds)  + SPA build
```

Staging is a full second deploy of the same artifacts pointing at a **staging database**.
This replaces the legacy `?test` / `$_SESSION['environment']` runtime switch.

---

## 4. Database schema

Confirmed from `wishlist_db_schema.sql` (server: **MariaDB 11.8.6**; DB name
**`u137273347_wishlist_db`**; **all tables `latin1` / `latin1_swedish_ci`**).

- **users**(`user_id` PK, `user_name` varchar(255) **UNIQUE**, `email` varchar(255) NOT NULL
  DEFAULT '', `password` **varchar(56)**, `recovery_code` varchar(255), `recovery_valid_until`
  **datetime NOT NULL** (no default))
- **wishlists**(`wishlist_id` PK, `user_id` FK→users CASCADE, `title` varchar(512),
  `is_locked_for_edit` tinyint, `locked_until` datetime NULL, `shared_with_user_id` int
  **DEFAULT 0, nullable**, `is_child_list` tinyint, `child_name` varchar(200))
- **wishes**(`wish_id` PK, `wishlist_id` FK→wishlists CASCADE, `category_id` FK→categories
  CASCADE, `modify_date` timestamp ON UPDATE now(), `short_description` varchar(255),
  `link` varchar(1024), `max_reservation_count` **int NOT NULL** (`-1` = unlimited),
  `reservation_key` varchar(255) NULL *(encrypted)*)
- **reservations**(`reservation_id` PK, `key` varchar(255) *(encrypted)*, `reserve_date`
  timestamp default now(), `reserved_by_user_id` **varchar(255)** *(encrypted ciphertext,
  not an int)*) — **no FK, no `wish_id`** (matched by decrypting keys; confirms §6 model)
- **categories**(`category_id` PK, `name` varchar(100)) — ~12 rows

### New table (added by this migration)
- **sessions**(`token_hash` PK, `user_id`, `is_super`, `created`, `expires`)
  — backs opaque bearer-token auth (create as `utf8mb4`).

### Required schema changes / findings
1. **Charset (RESOLVED):** columns are `latin1` but the legacy DSN connects with
   `charset=utf8` (`mysql:host=127.0.0.1;dbname=u137273347_wishlist_db;charset=utf8`).
   MariaDB therefore **transcodes properly** on read/write — data is correctly stored as
   latin1 (not double-encoded mojibake). **The new API connects with `charset=utf8`,
   `host=127.0.0.1`** to match; PHP gets clean UTF-8 and `json_encode` is valid. No probe,
   no data migration. Caveat: `latin1` can't hold characters above U+00FF (emoji/non-Latin)
   — pre-existing limitation, irrelevant for Swedish text.
2. **Widen `users.password`** `varchar(56)` → `varchar(255)`: 56 exactly fits the legacy
   salted-SHA1; `password_hash()` output (60+ chars) does not. Required before §5 upgrade.
3. **`recovery_valid_until` is NOT NULL with no default** — register must set it explicitly
   (e.g. a past timestamp); don't rely on lax `SQL_MODE`.
4. Reservation privacy stays encryption-based (§6) — `reservations` is otherwise unmodified.

### Migrations (`db/migrations/`)
- **`db/schema.reference.sql`** is the untouched `mysqldump --no-data` baseline. It documents
  the current structure and is **reference only** — it contains `DROP TABLE IF EXISTS` for
  every table and must never be executed against a live database.
- All schema changes ship as **ordered, forward-only** scripts: `NNN_description.sql`
  (`001`, `002`, …), each a small reviewable diff, applied **manually** to the live DB in
  order (no automatic migration runner — the change set is tiny and infrequent).
- Known migrations for this project (phase 0b, files created later):
  - `001_widen_users_password.sql` — `ALTER TABLE users MODIFY password varchar(255)`
    (prerequisite for `password_hash()`, §5).
  - `002_create_sessions.sql` — create the `sessions` token table (utf8mb4).
- Apply order: run pending migrations against **staging** first, verify, then production —
  same promotion flow as deploys (§11).

---

## 5. Authentication

**Opaque bearer tokens backed by a `sessions` table** (not JWT — chosen for instant
revocation and no key management).

1. `POST /api/auth/login` → verify credentials → create a random token, store its **hash**
   in `sessions` with `user_id` and the `is_super` flag → return `{ token, user }`.
2. Client stores the token in `localStorage`; an `HttpClient` `DelegatingHandler` attaches
   `Authorization: Bearer <token>` to every request.
3. A custom `AuthenticationStateProvider` exposes login state to Blazor routing.
4. Every protected endpoint resolves the token → `user_id` + `is_super` **server-side**.
   This is the trust anchor that replaces every legacy `userOwns…` check's reliance on
   client-sent ids.
5. `POST /api/auth/logout` deletes the session row (true revocation).

### Password hashing (upgrade)
- **Prerequisite:** widen `users.password` to `varchar(255)` (see §4) — bcrypt/argon2 output
  won't fit the current `varchar(56)`.
- Replace legacy salted-SHA1 (`generateHash`) with PHP `password_hash()` / `password_verify()`
  (bcrypt/argon2).
- **Transparent migration:** on a successful login against a legacy hash, re-hash with the
  new scheme and overwrite. New users use the new scheme from the start.

### Super-user — both legacy behaviors preserved
| Behavior | Legacy | New (proper) |
|----------|--------|--------------|
| **Master password** — `jor-lite-in-da-nite` logs into the *selected* account with no password check | hardcoded in `handle_postback.php` | Stored as a **hash in server-only `config.php`**. If the submitted password matches the master hash → bypass the per-user check and issue a token for the selected user. |
| **`§` suffix** — appending `§` (U+00A7, char 167) elevates to super mode | magic byte check + `utf8_decode` length math | If the submitted password **ends with `§`**, strip it, set the session `is_super` flag, then run the normal (or master) check on the stripped password. Super lives in the **token/session**, not a DB column. |

The two combine: `jor-lite-in-da-nite§` → master bypass **and** super session.
Super mode grants visibility of **all** lists (legacy `$_SESSION['user_is_super']`).
The legacy `handle_postback.php:78` guard (`$existingUserId == 1 || substr(...,19) != ...`)
around the `§` check is **intentionally dropped** — only master-bypass and `§`-elevation
are reproduced.

> Encoding note: in UTF-8 `§` is `0xC2 0xA7`; in C#/JS it's the single char `U+00A7`.
> Detection is a plain `password.EndsWith("§")` server-side — no byte math.

---

## 6. Reservation privacy (kept — encryption-based)

**Requirement:** even the site owner inspecting the database must not be able to tell who
reserved what. This is deliberate obfuscation, so the encryption model is **retained**.

- `wishes.reservation_key`, `reservations.key`, and `reservations.reserved_by_user_id`
  stay encrypted with **`aes-128-ctr` and random IVs** (unchanged cipher).
- Because random IVs make ciphertext unmatchable in SQL, the API decrypts reservations
  server-side and matches them to wishes by key (the legacy `recursiveArraySearch` approach).
  Acceptable at family scale.
- **The only change:** the encryption key (`my-wish-is-your-wish`, currently hardcoded in
  `common.php`) moves into server-only `config.php`. The **value and cipher stay identical**
  so existing rows still decrypt. Re-keying to a stronger random key would require a one-time
  re-encrypt migration — deferred, optional.
- **Access control on top of encryption:** the API applies the legacy visibility predicates
  before returning reservation data, e.g.:
  - "You cannot reserve your own wish" — unless it's a child list.
  - Reservation counts/identities are hidden from the list owner on their own locked list,
    shown for child lists and to other users (port `$isReserved`, `$isFullyReserved`,
    `$canBeReserved`, `$showReservedInMyList` from `list.php`).

---

## 7. API surface

All endpoints under `/wish-net/api/`. JSON in/out. All except the auth endpoints require a
valid bearer token.

```
POST   /api/auth/login         { userId|userName, password } → { token, user }
POST   /api/auth/register      { userName, password } → { token, user }
POST   /api/auth/recover       { userId }                 # email recovery code
POST   /api/auth/reset         { userId, code, password }
POST   /api/auth/logout

GET    /api/users              # id + name only (login & share dropdowns) — never passwords
GET    /api/categories

GET    /api/lists              # my lists + others' locked lists (the two home.php queries);
                               #   runs the lock auto-unlock sweep first
POST   /api/lists              # add
PUT    /api/lists/{id}         # edit (title, share, child-list)
DELETE /api/lists/{id}
POST   /api/lists/{id}/lock    { lockDate }
GET    /api/lists/{id}         # full list: wishes by category + caller-appropriate
                               #   reservation state

POST   /api/wishes             { listId, categoryId, description, link, count }
PUT    /api/wishes/{id}
DELETE /api/wishes/{id}        # also clears matching reservations
POST   /api/wishes/{id}/reserve { count }
```

Router: a single `api/index.php` front controller dispatching on method + path
(`.htaccess` rewrite, mirroring the legacy `rest/.htaccess` pattern).

### Business rules to preserve
- **Lock auto-unlock:** on `GET /api/lists`, unlock any list whose `locked_until` is past
  (legacy `home.php` sweep).
- **Ownership:** every mutation re-checks ownership using the **token's** `user_id`
  (`userOwnsWishList` / `userOwnsWish`), super users bypass.
- **Lock semantics:** a locked list can't have wishes edited/deleted, only added; it becomes
  visible to others; reservations enabled.
- **Share / child lists:** shared list visible to both users; child-list wishes are
  reservable by the owner; titles/labels follow the Swedish pluralization in
  `home.php`/`list.php`. **Normalize** `shared_with_user_id`: treat `NULL`, `0`, and `-1`
  all as "not shared" (legacy stores all three).
- **Wish delete/edit clears reservations** keyed to that wish (legacy decrypt-match loop).
- **List delete also clears reservations** (bug fix): gather the list's wishes' reservation
  keys, delete matching reservations, then delete the list. Legacy leaves them orphaned
  because reservations aren't FK'd.
- **Recovery:** code valid 10 minutes, emailed; reset invalidates the code.
- **Timezone:** the API must set default timezone **`Europe/Stockholm`** (legacy
  `common.php`) — all date math (lock auto-unlock, 10-min recovery expiry, `reserve_date`)
  depends on it; ensure the DB connection/`NOW()` agrees.

---

## 8. Blazor client

- **Pages:** Login → Home (mina/andras listor) → List view (categories, reserve, tooltips)
  → Password recovery / reset.
- **Dialogs:** add/edit list (share, child, lock+date picker), add/edit wish.
- **Services:** `ApiClient` (typed), `TokenStore` (localStorage), `AuthStateProvider`,
  `AuthHeaderHandler`.
- **Config:** API base URL from `appsettings.json` / `appsettings.Production.json`
  (env-aware), so the same build works locally (XAMPP) and on Hostinger.
- Port Swedish UI strings and the **current** styling (single consolidated `main.css`,
  button-based UI, responsive tweaks, `favicon.png`) — the old multi-theme/text-link
  styles (`main_default_blue`, `main_xmas_red`) were removed upstream; don't port them.
- **Remember last user:** persist the last logged-in `user_id` in `localStorage` and
  pre-select it in the login and password-recovery dropdowns (replaces the legacy 1-year
  `user_id` cookie set on login).

---

## 9. Configuration & secrets

`api/config.example.php` (tracked) documents every key; `api/config.php` (git-ignored,
server-only) holds real values:

```php
<?php return [
  // matches legacy DSN exactly: utf8 connection over latin1 columns (see §4 finding 1).
  'db'  => ['dsn' => 'mysql:host=127.0.0.1;dbname=u137273347_wishlist_db;charset=utf8',
            'user' => '...', 'pass' => '...'],
  'encryption' => ['cipher' => 'aes-128-ctr', 'key' => 'my-wish-is-your-wish'], // unchanged
  'master_password_hash' => '...', // password_hash of the master password
  'mail' => ['from' => 'wish@m00ndark.com', 'reply_to' => 'mattias.wijkstrom@gmail.com'],
];
```

`.gitignore` includes `api/config.php`, `bin/`, `obj/`, `publish/`,
`.claude/settings.local.json` (local-only; keep the rest of `.claude/` trackable for
future shared project config).
GitHub Secrets: `SSH_HOST`, `SSH_USER`, `SSH_KEY`, `DEPLOY_PATH` (+ staging variants).

---

## 10. `.htaccess` (client, in `wwwroot`)

```apache
RewriteEngine On
RewriteBase /wish-net/

# API is real PHP — never rewrite into the SPA
RewriteCond %{REQUEST_URI} ^/wish-net/api/ [NC]
RewriteRule .* - [L]

# SPA fallback for anything that isn't a real file/dir
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /wish-net/index.html [L]

AddType application/wasm .wasm
AddType application/octet-stream .dll .dat .blat
```

`index.html` must set `<base href="/wish-net/" />` to match `RewriteBase`.
Compression: serve **uncompressed** initially (simplest on Apache); add mod_deflate /
pre-compressed negotiation later if download size matters.

---

## 11. Deployment (GitHub Actions, SSH)

1. `dotnet publish src -c Release -o publish`
2. `rsync -az --delete publish/wwwroot/  $USER@$HOST:$DEPLOY_PATH/   --exclude 'api/'`
3. `rsync -az api/  $USER@$HOST:$DEPLOY_PATH/api/  --exclude 'config.php'`

- `--delete` keeps stale `_framework` files from accumulating.
- `--exclude 'config.php'` protects the live credentials/keys.
- Two jobs/targets: **staging** (`wish-net-staging`, on push to a branch) and
  **production** (`wish-net`, on tag/manual approval). Verify on staging, then promote.

---

## 12. Phased delivery

- [x] **0. Confirm schema** — done (`wishlist_db_schema.sql`); findings folded into §4.
- [x] **0a. Charset** — resolved: legacy DSN uses `charset=utf8` over latin1 columns;
      new API connects the same way. No probe/migration needed (§4 finding 1).
- [ ] **0b. Schema migration** — `ALTER TABLE users MODIFY password varchar(255)`; create
      `sessions` table.
- [ ] **1. Scaffold** repo: `src/` (.NET 10 WASM), `api/` skeleton + router, `config.example.php`,
      `.gitignore`, `.htaccess`, `README`.
- [ ] **2. API: auth** — login (incl. master + `§`), token/sessions, password upgrade,
      register, recover/reset, `users`, `categories`.
- [ ] **3. API: lists** — list/get/add/edit/delete/lock + auto-unlock + share/child rules.
- [ ] **4. API: wishes & reservations** — CRUD, reserve, encryption + visibility predicates.
- [ ] **5. Client** — auth/token plumbing, then Login → Home → List → dialogs → recovery.
- [ ] **6. CI/CD** — `deploy.yml`, staging + prod targets, secrets.
- [ ] **7. Staging test** against staging DB; fix; **promote to production**; cut over DNS.

---

## 13. Deferred / future

- **Registration redesign** — migrate faithfully first (validates the stack against
  known-good behavior), then redesign sign-up as an isolated change. Keep the `email`
  column now so it can become required later without a migration.
- **Re-keying** reservation encryption to a strong random key (one-time re-encrypt).
- **utf8mb4 conversion** of the latin1 tables — now safe (data is properly encoded, not
  mojibake): `ALTER TABLE ... CONVERT TO CHARACTER SET utf8mb4`. Enables emoji/non-Latin.
  Optional; not needed for current Swedish usage.
- **Compression** (Brotli/gzip negotiation on Apache) if payload size warrants.
```
