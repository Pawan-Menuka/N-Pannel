# N-Pannel

N-Pannel is a Laravel back-office for a Sri Lankan ceiling-sheet and building-materials business, starting with inventory and sales.

IS-0 setup is incomplete: this checkout currently contains standalone tooling configuration only. Laravel scaffolding and package installation are blocked by runtime access restrictions in the implementation session; the commands below require the scaffold and dependencies first.

## Local setup

Use PHP 8.4 with Composer, Node.js 20.20.2 or compatible, and Docker Desktop.

1. Install dependencies with `composer install` and `npm install`.
2. Copy `.env.example` to `.env`, then run `php artisan key:generate`.
3. Run `docker compose up -d` and wait for PostgreSQL to become healthy (`docker compose ps`). First initialization also creates `npannel_test`.
4. Run `php artisan migrate` and `npm run build`.
5. Run `composer dev` to start local development.

The intended local PostgreSQL connection uses host `127.0.0.1`, port `5432`, database `npannel`, and username/password `npannel`/`npannel`. These credentials are for disposable local development only. Tests use `npannel_test`.

## Checks

```sh
composer lint
composer analyse
composer test
npm run build
```

CI is configured for pull requests to `Develop` and `main`, and pushes to `Develop`, using PHP 8.4, Node.js 22, and PostgreSQL 17. It requires the pending scaffold and lockfiles before it can pass.
