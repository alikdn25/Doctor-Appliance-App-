# Field Service App for Appliance Repair

Multi-tenant field-service management app (see [`SPEC.md`](SPEC.md)).
Stack: Laravel 13, Inertia.js + React + TypeScript, Tailwind CSS, PostgreSQL, Redis, Pest.

**Current stage: Stage 1 — MVP.** Progress per stage and task: [`docs/PROGRESS.md`](docs/PROGRESS.md).

MVP code is validated for a first testing installation: 674 backend tests and 20 desktop/mobile browser scenarios
passed. It has not been deployed to a server. See the [server handoff](docs/SERVER_HANDOFF.md),
[browser checks](docs/BROWSER_TESTING.md) and [remaining launch checks](docs/LAUNCH_TESTING.md).

## Local setup

Requirements: PHP 8.3+, Composer, Node 22, PostgreSQL 16, Redis.

```bash
composer install
npm install
cp .env.example .env && php artisan key:generate
# create databases (dev + tests) for the user in .env
createdb field_service && createdb field_service_test
# set DB_PASSWORD in .env; locally you may set AUTH_REQUIRE_TWO_FACTOR=false
php artisan migrate --seed      # demo data, every account uses password "password"
composer run dev                # app on http://localhost:8000 + Vite + queue worker
```

Demo accounts (local only):

| Email                | Role                                                                     |
| -------------------- | ------------------------------------------------------------------------ |
| `admin@example.com`  | Platform super-admin (`/admin`)                                          |
| `owner@example.com`  | Owner of _Doctor Appliance Group_ (brands Doctor Appliance + Duct Works) |
| `office@example.com` | Office/Admin of _Doctor Appliance Group_                                 |
| `tech@example.com`   | Technician in both companies (shows the company switcher)                |
| `other@example.com`  | Owner of _Coastal Repair Co_                                             |

Create a real super-admin with `php artisan app:create-super-admin`.

## Tests and checks

```bash
php artisan test            # Pest, runs against PostgreSQL database field_service_test
vendor/bin/pint --test      # PHP code style
npm run check               # lint + format (TS/React)
npm run types:check         # TypeScript
```

## Multi-tenancy in short

- Tenant = `companies`. Users are global; access goes through **memberships** (`company_user`: role, is_active).
  One person can belong to several companies and switch between them.
- Tenant-owned models use `App\Models\Concerns\BelongsToCompany`: a global scope filters by the current company,
  `company_id` is filled automatically, writes into another company throw, and **queries without a current company
  throw** (fail closed).
- The current company is set by the `tenant` middleware (`SetCurrentCompany`) before route-model binding,
  so `{brand}` of another company is a 404.
- Platform code (super-admin panel, seeders) bypasses the scope explicitly (`withoutCompanyScope()`, `CurrentCompany::runAs()`).
- Isolation is covered by `tests/Feature/Tenancy`. A test fails if a new tenant model is added without isolation coverage.

## Deployment

See [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md): GitHub Actions (manual run for now) → SSH → `deploy/deploy.sh` on the VPS.
