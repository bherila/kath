# Katherine Herila

The personal website for Katherine Herila, eventually served at
`https://katherine.herila.net`. It is deliberately close to blank right now:
a Home page with real content plus placeholder Blog and Contact pages.

There is no login/authentication anywhere in this app, and no "apps" (no
finance, PHR, client-management, admin, etc.) — it was generated from
[`x-template`](https://github.com/bherila/x-template), Ben Herila's shared
Laravel + React starter, with all of that stripped out.

## Tech Stack

- **Backend**: Laravel 13 on PHP 8.5 (CI runs on PHP 8.5)
- **Frontend**: React 19 with TypeScript
- **UI Components**: shadcn-style components backed by Base UI primitives
- **Styling**: Tailwind CSS v4
- **Build**: Vite
- **Package manager**: pnpm for JavaScript dependencies, Composer for PHP

## Getting Started

### Prerequisites

- PHP 8.5
- Composer
- Node.js 20.19+ or 22.12+
- pnpm

### Installation

1. **Clone the repository**
   ```bash
   git clone git@github.com:bherila/kath.git
   cd kath
   ```

2. **Install dependencies**
   ```bash
   composer install
   pnpm install
   ```

3. **Configure environment**
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

   `.env.example` is set up to point at shared production resources rather
   than a local stack: MySQL on the production host directly (no local
   database — there are no domain tables yet, so nothing actually needs to
   connect to it), and Cloudflare R2 placeholders for future asset storage.
   Ask for real credentials rather than inventing them.

4. **Build assets when needed**
   ```bash
   pnpm run build
   ```

### Development

Run the full local development stack:

```bash
composer dev
```

Or run services separately:

```bash
php artisan serve
pnpm run dev
```

## Project Structure

- `app/` - Laravel application code
- `database/migrations/` - currently empty; no domain tables exist yet
- `resources/js/` - React and TypeScript source (`home.tsx`, `blog.tsx`,
  `contact.tsx` are the three page entry points, mounted from
  `resources/views/welcome.blade.php`, `blog.blade.php`, `contact.blade.php`)
- `resources/js/components/ui/` - shadcn-style UI components
- `resources/css/` - Tailwind CSS entry point
- `tests/` - PHPUnit feature and unit tests

## Validation

### Frontend

```bash
pnpm run type-check
pnpm run lint
pnpm run test
pnpm run build
```

Oxlint remains the type-aware linter, with import/export sorting and React Compiler
config/gating hosted by its JS plugin API. Narrow optional-peer metadata keeps those
rule packages without installing the ESLint engine. Biome 2.6.7 runs only the
imports-only check; its formatter and assist features are disabled. Automatic JSX
does not consume a legacy `React` binding, so this check now reports that binding
as unused too. Reviewed cleanup retains React module initialization when necessary
through a bare import; unused locals remain permitted.

`pnpm run lint` checks both tools without editing files. `pnpm run lint:fix` applies
Oxlint fixes and checks imports. Deliberate import removal is separate:
`pnpm run lint:unused-imports:fix -- resources/js/path.tsx`. It requires explicit
paths and uses Biome's unsafe removal fix only for `noUnusedImports`; inspect the
result for comments and module initialization. Existing bare side-effect imports
remain intact. Use `// biome-ignore lint/correctness/noUnusedImports: reason` for
an intentional unused import.

`pnpm run lint:test` exercises invalid/valid rules, severity, scopes, sorting and
fixes. `pnpm run toolchain:check` reads active installed package manifests and lock
identities, rejecting the ESLint engine and classic compiler aliases while allowing
optional peer declarations and pure `eslint-*` rule/type packages. CI runs both
after a fresh frozen-lockfile install; full validation stays in CI.

### Backend

```bash
./vendor/bin/pint --test
composer test
```

## Database Safety

Tests always use SQLite in-memory. This is enforced in two places:

1. `phpunit.xml` sets `DB_CONNECTION=sqlite` and `DB_DATABASE=:memory:`.
2. `Tests\SafeTestCase` verifies the active test database at runtime.

This prevents tests from accidentally running against a persistent or shared
database, even once the production `.env` points at real MySQL credentials.

## Where this is going

This site is intentionally structured to not fight two future phases, neither
of which is built yet:

1. **A markdown-based blog** whose posts are composed of photos and videos.
   Cloudflare R2 (S3-compatible) is the intended asset store — see the
   `KATH_R2_*` placeholders in `.env.example` and the `r2` disk in
   `config/filesystems.php`.
2. **Optional auth plus a lightweight CMS**: `Pages` that can be created, with
   `Widgets` added to them — text, image, and *container* widgets (e.g. a
   "Two columns" widget) that can themselves contain other widgets, i.e. a
   recursive/nestable widget tree.

Neither is implemented yet. When they land, expect real migrations to appear
under `database/migrations/`, and a `users`/auth table to come back if login
is added.

## CI

GitHub Actions workflows run on hosted `ubuntu-24.04-arm` runners (free for
public repos): build, type-check, lint, and both test suites on every PR. The
`deploy.yml` workflow is `workflow_dispatch`-only (manual) and has no deploy
secrets configured yet — it will not run on push to `main`. If it's ever
wired up, note that cPanel stores the vhost's PHP-version handler inside
`public/.htaccess`, and a plain rsync deploy replaces that file, silently
dropping the site to the account-default PHP and 500ing every page while the
workflow still reports success. `htaccess-append.txt` holds the handler
block that must be appended after every deploy to avoid that trap.

## Independent Event gallery

The **Deploy Event gallery** workflow deploys a pinned Vora release using the
`event-prod` GitHub environment. Kath continues to own its personal website and
its existing manual production workflow. Upgrade the Event app by reviewing a
PR that changes both the reusable workflow SHA and `source_ref` in
`.github/workflows/deploy-event.yml`. Run the workflow manually after merging.

Configure the environment's SSH secrets and deployment variables using Vora's
[Event instance guide](https://github.com/bherila/vora/blob/main/docs/event-instances.md).
Keep server configuration, credentials, and migration manifests outside this
public repository. Existing wedding buckets may be adopted by the new instance;
only one app may manage their uploaded media after cutover.

Before transferring ownership, preserve the live database/configuration and set
`WEDDING_MIGRATION_FROZEN=true` on the legacy site. This blocks old wedding
requests and disables its pruning, HLS resolution, and reclustering commands.
Import and verify originals, hidden records, and the ceremony in the new app.
Then set `WEDDING_EXTERNAL_URL` to its HTTPS origin and refresh the configuration
cache. Legacy pages redirect to `/events`, old API/write requests return 410
with the new location, and legacy maintenance stays disabled. Other site pages
continue normally. Leave these flags unset on development/test installations.

## License

Private - All rights reserved
