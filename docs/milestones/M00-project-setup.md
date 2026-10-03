# M00 — Project setup

**Goal:** a scaffolded Laravel 13 app that runs locally on Herd, has full spec docs and Boost guidelines, and a green baseline in tooling and CI. Every later milestone builds on this.

**Depends on:** nothing.
**Read:** `01-architecture.md`, `decisions.md` (D-001).

---

### M00.1 — Scaffold Laravel 13 (Vue starter kit, teams, Pest, Boost, MySQL `evalyst`) — _done_

**What was done**

- Created the MySQL database `evalyst` (utf8mb4_unicode_ci) on the local MySQL 8 server (root, no password).
- Ran `laravel new evalyst --vue --teams --pest --database=mysql --npm --boost -n` **in a temporary directory**, then moved everything (dotfiles included) into `/Users/abbas/Sites/evalyst`. The installer refuses to run on a directory that already exists.
- Result: Laravel 13.x, Inertia v3, Vue 3 + TypeScript, Tailwind v4, Fortify (2FA and passkeys), Wayfinder, teams scaffold (`Team`, `Membership`, `TeamInvitation`, `TeamRole`, `EnsureTeamMembership`, `{current_team}` route prefix), Pest 5, Larastan level 7, Pint, Boost 2.x. Migrations have run.

### M00.2 — Local environment — _done_

**What was done**

- `.env`: `APP_NAME=Evalyst`, `APP_URL=http://evalyst.test` (served by Herd, because `~/Sites` is parked). `.env.example`: `APP_NAME=Evalyst`.
- `git init -b main`. **Nothing committed yet** (see M00.6).

### M00.3 — Spec docs, PROGRESS.md, Boost guideline and skills — _done_

**What was done**

- `docs/`: product, architecture, data model, AI, GitHub ingestion, testing, deployment, decisions, `features/*`, `milestones/*`, `PROGRESS.md`.
- `.ai/guidelines/evalyst.md` (always loaded) and `.ai/skills/*` (loaded when needed). Ran `php artisan boost:update` so `AGENTS.md` includes them.

---

### M00.4 — Baseline tooling green; CI uses MySQL

**Goal:** before we write any domain code, every check passes on the untouched starter kit, and tests run on the same database engine as production.

**Build**

- Create the local test database `evalyst_test` (utf8mb4).
- `phpunit.xml`: replace the sqlite in-memory env with `DB_CONNECTION=mysql`, `DB_DATABASE=evalyst_test`, `DB_HOST=127.0.0.1`, `DB_USERNAME=root`, `DB_PASSWORD=` (empty). Keep the `RefreshDatabase` usage in `tests/Pest.php` as the starter kit has it.
- `.github/workflows/tests.yml`:
    - Add a `services: mysql` (mysql:8.0) container with `MYSQL_DATABASE=evalyst_test`, `MYSQL_ALLOW_EMPTY_PASSWORD=yes`, port 3306, and a health check.
    - Pass the DB env vars to the `composer setup` / `composer ci:check` steps.
    - Keep the pinned action SHAs.
- Fix anything in the starter kit that fails `composer test`, `npm run types:check`, `npm run check` or `npm run build`.
- **Known issue:** PHPStan crashes at the default 128M memory limit on a fresh scaffold. Change the `types:check` composer script to `phpstan analyse --memory-limit=1G`.

**Acceptance criteria**

- `composer test` passes locally against MySQL: Pint check, PHPStan level 7, all starter kit tests.
- `npm run types:check`, `npm run check` and `npm run build` succeed.
- The CI workflow file is valid and uses the MySQL service. The run itself is checked after the first push.

**Notes**

- **Why MySQL rather than sqlite:** JSON columns, ULIDs, decimal precision, `unique` with soft deletes, and datetime behaviour differ between the two, and grading maths and scheduling depend on them. The cost is a few seconds of test time.

### M00.5 — `config/evalyst.php` skeleton + `.env.example` keys

**Goal:** one home for all app settings, matching `01-architecture.md` §Config.

**Build**

- `config/evalyst.php` with the `ai`, `github` and `quiz` sections, exactly as in `01-architecture.md`. The ignore lists are filled from `04-github-ingestion.md` (`ignored_paths` split into `directories`, `files`, `extensions`). Add `ai.rate_limit_per_minute` (default 60).
- `.env.example`: `EVALYST_AI_PROVIDER=openai`, `EVALYST_AI_MODEL=gpt-5.4-mini`, `OPENAI_API_KEY=`, `GITHUB_TOKEN=`.
- Set `config/app.php` timezone to `UTC` (it should already be UTC; confirm).

**Acceptance criteria**

- `config('evalyst.ai.model')` returns `gpt-5.4-mini` when the env var isn't set. PHPStan passes.

**Tests**

- `tests/Unit/ConfigTest.php`: the key config values exist and have the expected types (threshold is a float between 0 and 1, ignore lists are non-empty arrays).

### M00.6 — First commit (ask the user first)

**Goal:** a clean first commit of the scaffold, docs and config.

**Build**

- Check `.gitignore` covers `.env`, `vendor`, `node_modules`, `public/build`, `public/hot`. The Boost-generated files (`AGENTS.md`, `.mcp.json`, `boost.json`, `.claude/`, …) stay **git-ignored**, as the starter kit has them. `.ai/` (our guideline and skills) is committed, and `php artisan boost:install` / `boost:update` regenerates the rest on each machine.
- **Ask the user before running `git commit`.** The message has no AI attribution (user's global rule).

**Acceptance criteria**

- `git status` is clean after the commit. The commit was made only with the user's explicit OK.
