# N-Pannel

N-Pannel is the back office for a Sri Lankan ceiling-sheet and building-materials business. It is a Laravel 13 + Filament 5 admin panel at `/admin` covering inventory (products, suppliers, goods received, stock, stocktakes) and sales (customers, counter sales, quotes, orders, payments, invoices and receipts, returns and credit notes, reports).

Build status and phase logs are in [IMPLEMENTATION_PLAN.md](IMPLEMENTATION_PLAN.md). Project rules for contributors are in [CLAUDE.md](CLAUDE.md).

## Requirements

| Tool | Version | Notes |
|---|---|---|
| PHP | **8.4** (not 8.5) | Easiest on Windows via [Laravel Herd](https://herd.laravel.com), which includes Composer and the needed extensions |
| Composer | 2.x | |
| Node.js | 20 or 22 (LTS) | Builds the front-end assets |
| PostgreSQL | **17** | Provided by Docker (`compose.yaml`), or install it natively |
| Docker Desktop | any recent | Only needed for the bundled PostgreSQL |
| Authenticator app | — | Sign-in always requires a 6-digit code (Google/Microsoft Authenticator, Authy…) |

## Local setup

From a fresh clone of the `Develop` branch:

```sh
git clone -b Develop https://github.com/Pawan-Menuka/N-Pannel.git
cd N-Pannel
```

1. **Start PostgreSQL** (Docker Desktop must be running), then wait until `pgsql` shows `healthy`:

   ```sh
   docker compose up -d
   docker compose ps
   ```

   This creates the `npannel` and `npannel_test` databases with user/password `npannel`/`npannel` (local use only).

2. **Configure the environment:**

   ```sh
   cp .env.example .env        # PowerShell: copy .env.example .env
   ```

   In `.env`, set the admin login the seeder will create:

   ```dotenv
   SEED_ADMIN_EMAIL=admin@npannel.test
   SEED_ADMIN_PASSWORD=choose-a-strong-password
   ```

   If `SEED_ADMIN_PASSWORD` is left empty, the seeder generates one and prints it once (local/testing only).

3. **Install dependencies and build assets:**

   ```sh
   composer install
   php artisan key:generate
   npm install
   npm run build
   ```

4. **Create the tables and starting data** (location, districts, categories, the walk-in customer, the admin user and a sample catalog):

   ```sh
   php artisan migrate --seed
   ```

5. **Run it:**

   ```sh
   php artisan serve
   ```

   Open <http://localhost:8000/admin>, sign in, and set up the authenticator app on first sign-in. Save the recovery codes. `composer dev` starts the site together with the other development processes.

   Optional, for scheduled tasks (quote expiry, import clean-up): `php artisan schedule:work` in a second terminal.

### Without Docker

Install PostgreSQL 17 natively, then as the `postgres` user:

```sql
CREATE USER npannel WITH PASSWORD 'npannel';
CREATE DATABASE npannel OWNER npannel;
CREATE DATABASE npannel_test OWNER npannel;
```

Skip step 1; everything else is the same.

### Day to day

```sh
docker compose up -d
php artisan serve
```

After pulling new changes: `composer install && npm install && npm run build && php artisan migrate`.

To reset the local database to fresh starting data (deletes all local data): `php artisan migrate:fresh --seed`.

## Checks

```sh
composer lint            # Pint (code style)
composer analyse         # Larastan level 6
composer test            # Pest, excluding browser tests
```

Browser journey tests (real Chromium via the Pest browser plugin):

```sh
npx playwright install chromium   # one time
composer test:browser
```

On Windows Git Bash, Herd's shims are `.bat` files: use `php.bat`, `composer.bat`, `vendor/bin/pint.bat`, `vendor/bin/phpstan.bat`, `vendor/bin/pest.bat`.

CI (GitHub Actions) runs Pint, Larastan, the Pest suite and the browser tests against PostgreSQL 17 on pull requests to `Develop` and `main`, and on pushes to `Develop`.

## Troubleshooting

| Problem | Fix |
|---|---|
| Connection refused / `SQLSTATE[08006]` | Docker Desktop is not running or PostgreSQL is still starting: `docker compose up -d`, wait for `healthy`. |
| Port 5432 already in use | Stop the other PostgreSQL service, or map `"5433:5432"` in `compose.yaml` and set `DB_PORT=5433`. |
| Composer rejects the PHP version | PHP 8.4 is required; select it in Herd and open a new terminal. |
| "Unable to locate file in Vite manifest" / unstyled pages | `npm run build` |
| Authenticator code rejected | Make sure the phone's clock is set automatically, or use a recovery code. Another admin can use **Users → Reset two-factor**. |

## Deployment

Staging runs on Laravel Cloud (Asia Pacific, Singapore) from the `Develop` branch with Serverless PostgreSQL 17, the scheduler enabled and point-in-time recovery. Build: `composer install --no-dev … && npm ci && npm run build`. Deploy: `php artisan migrate --force`. Outside local/testing the seeder requires `SEED_ADMIN_EMAIL`, and `SEED_ADMIN_PASSWORD` only when creating the admin; it never prints a password there. See the IS-14 log in [IMPLEMENTATION_PLAN.md](IMPLEMENTATION_PLAN.md) for the full staging setup.
