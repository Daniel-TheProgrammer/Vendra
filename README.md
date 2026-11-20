# Vendra API

[![CI](https://github.com/Daniel-TheProgrammer/api_laravel/actions/workflows/ci.yml/badge.svg?branch=develop)](https://github.com/Daniel-TheProgrammer/api_laravel/actions/workflows/ci.yml)

Multi-tenant commerce and billing platform exposed purely as a JSON API: product
catalog, orders, double-entry ledger, card payments, outbound webhooks, search and
reporting. Built with Laravel 12 on PHP 8.3, PostgreSQL 16, Redis 7 and
Meilisearch, packaged with Docker and delivered through GitHub Actions.

## Architecture in one paragraph

A single deployable application organised as a **modular monolith**. Each
business area lives in `app/Modules/<Name>` with its own `Domain`,
`Application`, `Infrastructure`, `Http`, `Database` and `Tests` directories.
Modules talk to each other only through `Application` services and domain
events, a rule enforced by Deptrac in CI. Tenancy is a single PostgreSQL
database with a `tenant_id` column on every tenant table, defended by an
Eloquent scope, PostgreSQL row-level security and composite foreign keys. Money
is integer minor units plus a currency, never a float. Every mutating endpoint
is idempotent and every domain event goes through a transactional outbox.

Decisions are recorded in [`docs/adr`](docs/adr).

## Requirements

- Docker Engine 24+ with Compose v2
- `make` (optional; every target is a thin wrapper around `docker compose`)
- No local PHP, Composer or database is required

## Getting started

```bash
git clone git@github.com:Daniel-TheProgrammer/api_laravel.git vendra-api
cd vendra-api
make setup          # copies .env, builds images, installs deps, migrates, installs git hooks
```

Without `make`, run the equivalent commands:

```bash
cp .env.example .env
docker compose build
docker compose up -d
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
git config core.hooksPath .githooks
```

The API answers at <http://localhost:8080>. Health check: `GET /up`.

| Service     | Local URL                | Notes                                   |
|-------------|--------------------------|-----------------------------------------|
| API (nginx) | http://localhost:8080    | Proxies to PHP-FPM in the `app` service |
| Mailpit     | http://localhost:8025    | Captures all outgoing mail              |
| Meilisearch | http://localhost:7700    | Master key from `MEILISEARCH_KEY`       |
| PostgreSQL  | localhost:5432           | `vendra` / `secret`, db `vendra`        |
| Redis       | localhost:6379           |                                         |

## Day-to-day commands

```bash
make up            # start the stack
make down          # stop it (data volumes are kept)
make sh            # shell inside the app container
make logs          # tail every service
make migrate       # run pending migrations
make fresh         # drop, migrate and seed
make test          # run the test suite (real PostgreSQL)
make test-coverage # with coverage, fails under 85%
make check         # every quality gate CI runs: Pint, Larastan, Rector, Deptrac
make help          # full list
```

## Quality gates

Every pull request must pass:

| Gate            | Tool                        | Config            |
|-----------------|-----------------------------|-------------------|
| Code style      | Laravel Pint                | `pint.json`       |
| Static analysis | Larastan, level 8           | `phpstan.neon`    |
| Modernisation   | Rector (dry run)            | `rector.php`      |
| Boundaries      | Deptrac                     | `deptrac.yaml`    |
| Tests           | PHPUnit 11 on PostgreSQL 16 | `phpunit.xml`     |
| Vulnerabilities | `composer audit`, Trivy     | `ci.yml`          |

Tests never run on SQLite: row-level security, locking and sequences behave
differently, and those behaviours are exactly what the suite must prove.

## Branching and releases

| Branch                  | Purpose                                    |
|-------------------------|--------------------------------------------|
| `main`                  | Production. Tagged `vX.Y.Z` on every merge. |
| `develop`               | Integration. Deployed to staging.           |
| `feature/<phase>-<slug>`| One unit of work, branched from `develop`.  |
| `fix/<slug>`            | Non-urgent fix, same rules as a feature.    |
| `release/vX.Y.0`        | Stabilisation; only fixes and changelog.    |
| `hotfix/vX.Y.Z`         | Urgent production fix from `main`.          |

Commits follow [Conventional Commits](https://www.conventionalcommits.org) with
the module name as scope (`feat(orders): …`). The `commit-msg` hook rejects
anything else; the `pre-commit` hook runs Pint on staged files. Pull requests
use the template in `.github/PULL_REQUEST_TEMPLATE.md`, and `main` and
`develop` only move through pull requests with green CI.

## Docker image

`docker/app/Dockerfile` is multi-stage:

| Target   | Purpose                                                           |
|----------|-------------------------------------------------------------------|
| `dev`    | Local development; source is bind-mounted, pcov enabled           |
| `prod`   | Immutable, non-root, optimised autoloader, caches warmed at start |
| `web`    | nginx with the built `public/` directory, fronts `prod`           |

`compose.yaml` is the production-shaped base; `compose.override.yaml` adds
bind mounts and host ports and is only loaded locally.

## Project layout

```
app/
  Modules/<Name>/        Tenancy, Identity, Catalog, Orders, Ledger, Payments, Webhooks, Search, Reporting
    Domain/              entities, value objects, events, exceptions
    Application/         actions (use cases), DTOs, queries, policies
    Infrastructure/      Eloquent models, repositories, gateways
    Http/                controllers, requests, resources, routes
    Database/            migrations, factories, seeders
    Tests/               unit and feature tests for the module
  Shared/                kernel: Money, Clock, outbox, error envelope
  Http/Middleware/       tenant resolution, idempotency, rate limiting, versioning
docker/                  Dockerfile, nginx, PHP-FPM and PostgreSQL configuration
docs/adr/                architecture decision records
.github/                 CI, Dependabot, PR template, CODEOWNERS
```

## License

MIT
