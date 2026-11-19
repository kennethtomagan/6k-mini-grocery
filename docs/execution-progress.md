# K6 Tindahan — Execution Progress

Tracks implementation of [implementation-plan.md](implementation-plan.md). Updated after every task.

## Working rules for this execution

| Rule | Detail |
|------|--------|
| Workflow | superpowers:subagent-driven-development — one implementer subagent per task, then a spec-compliance review, then a code-quality review. A task is **Completed** only after both reviews pass and its verification commands have been run. |
| No commits | The owner asked for no automatic commits. The plan's "Commit" steps are replaced by a **staging checkpoint**: after a task passes review, its changes are staged with `git add -A`. Nothing is committed. `git diff` therefore always shows only the task in progress; `git status` lists its new files. |
| Baseline | `git init` run on 2026-11-16; the untouched skeleton and the planning docs were staged as the baseline (65 files; `.env`, `vendor/`, `node_modules/` ignored). |
| Framework | Laravel 13.42 stays (approved plan, finding F1), although the execution brief mentions Laravel 12. |
| Databases | Development: `k6_tindahan`. Tests: `k6_tindahan_testing` only. The MySQL server also hosts other projects' schemas; nothing outside these two databases is touched. No `migrate:fresh` on the development database. |
| Scope of this run | One phase per run, then a phase report. Run 1: Phase 1. Run 2 (2026-11-16): Phase 2. Run 3 (2026-11-16): Phase 3. Run 4 (2026-11-17): Phases 4–11 in one run, at the owner's request ("continue to last phase, don't ask again"). |
| Checkpoint snapshots | From Phase 2 on, each checkpoint also records `git write-tree` (a tree object, not a commit) so reviewers can run `git diff <tree-before> <tree-after>`. Phase 1 end tree: `291c43db9a088190cf1ba58ab37aa5b16534ce11` (before the Phase 1 review fixes). Phase 1 final tree, after the review fixes: `34604aedfa9c9b927a0685751c9b790535d7c37c` (`tests/TestCase.php` return type and PHPDoc; rendered-brand assertion in ExampleTest; suite 8 passed / 16 assertions). |
| Guideline precedence | Boost's `CLAUDE.md`/`AGENTS.md` apply to every task, **except** where the approved plan is explicit. The plan counts as the owner's approval for its listed files, folders (`app/Enums`, `app/Services`, `app/Support`, `app/Pos`, `app/Exceptions`, `app/Livewire`, `app/Http/Responses`), docs (README, this tracker) and tinker-based checks (Tasks 11.2, 11.3). Implementers may scaffold with `php artisan make:*` and then replace the content, but keep the plan's exact filenames — migrations are named exactly as in Task 2.3. All other CLAUDE.md rules still apply: Pint, curly braces, explicit types, PHPDoc over inline comments, skills, `php artisan test --compact`. |
| PHP syntax | Stay **PHP 8.3-compatible** (composer.json requires `^8.3`; Herd runs 8.5). No property hooks, asymmetric visibility or pipe operator. **Correction (final review, 2026-11-17):** this holds for syntax only. The committed `composer.lock` (Symfony 8.1, Pest 5, PHPUnit 13) needs **PHP ≥ 8.4.1**, so the README says 8.4.1+. Tightening `composer.json` or downgrading packages for real 8.3 support would be a dependency change, which the owner must approve. |
| Node | Node **24** replaces every "Node 20" mention in the plan (Part C conventions; README in Task 11.4). |
| Open decision for Phase 11 | Plan Task 11.3 ends with `migrate:fresh --seed`, and Task 11.2 writes rows — both on the dev DB, which this run forbids. Proposed: run 11.2 and 11.3 against a separate scratch schema (e.g. `k6_tindahan_loadtest`). **Resolved 2026-11-17, owner said not to ask:** 11.2 and 11.3 run against the existing `k6_tindahan_testing` database, behind the `_testing` guard. No new schema is created on the shared server, and the dev DB gets no `migrate:fresh`. |
| Filament access in tests | Tests run with `APP_ENV=testing`, so Filament returns 403 to any user until `User` implements `FilamentUser::canAccessPanel()` (Task 2.4). Land Task 2.4 before any authenticated panel test. |

## Phase overview

| Phase | Name | Status |
|-------|------|--------|
| 1 | Foundation and tooling | Completed (2026-11-16) |
| 2 | Database schema and domain models | Completed (2026-11-16) |
| 3 | Users, roles and authorization | Completed (2026-11-17) |
| 4 | Product and category management | Completed (2026-11-17) |
| 5 | Inventory and stock management | Completed (2026-11-17) |
| 6 | Checkout and transaction processing | Completed (2026-11-17) |
| 7 | POS cashier interface | Completed (2026-11-17) |
| 8 | Transaction history and voiding | Completed (2026-11-17) |
| 9 | Store settings and receipt printing | Completed (2026-11-17) |
| 10 | Sales analytics and dashboard | Completed (2026-11-17) |
| 11 | Hardening, optimization and polish | Completed (2026-11-17) |

---

## Phase 1 — Foundation and tooling

Order change: Task 1.3 (Filament) runs **before** Task 1.2 (Boost), so Boost's generated guidelines and docs search cover Filament 4 and Livewire 3. No other effect.

### Task 1.1 — Git baseline and Node version
- **Status:** Completed (2026-11-16)
- **Deviation from plan:** `.nvmrc` pins **24**, not 20. Code review found that on macOS `composer dev` runs `@laravel/multiplex` (engines `>=22.13`), which npm silently skipped under Node 20, and that Node 20 reached end of life in April 2026. Node 24.22.3 (current LTS) was installed into Herd's nvm; the shell's default Node is still 18 and was not changed.
- **Git step:** `git init` done by the controller; baseline staged, not committed (owner's rule).
- **Files:** created `.nvmrc`; `package-lock.json` +69 lines (nested bundled deps of `@tailwindcss/oxide-wasm32-wasi`, no version changes); `node_modules/` reinstalled with `npm ci`; `public/build/` rebuilt (gitignored).
- **Tests:** none (tooling task).
- **Verification:** `node -v` → v24.22.3; `npm ci` → 0 vulnerabilities, 0 EBADENGINE warnings; `npm run build` → ✓ built (re-run independently by the controller); `node_modules/@laravel/multiplex` present; `package.json` unchanged.
- **Reviews:** spec ✅; code quality — first pass "with fixes" (Node 20 → 24), re-review ✅ approved.
- **Issues:** first implementer attempt used Node 20, as planned; corrected after review.
- **Remaining:** every `npm` command needs `nvm use` first (or Node 24 on PATH). Document in the README (Task 11.4).

### Task 1.3 — Install Filament 4
- **Status:** Completed (2026-11-16)
- **Installed:** filament/filament **v4.16.4**, livewire/livewire **v3.8.11**; laravel/framework unchanged at **v13.42.0**. Lockfile: 35 packages added, **0 changed, 0 removed** (verified by name→version comparison of staged vs working lock).
- **Files:** created `app/Providers/Filament/AdminPanelProvider.php` (installer scaffold: id/path `admin`, `->login()` only — no registration or password reset); modified `bootstrap/providers.php`, `composer.json` (filament require + `@php artisan filament:upgrade` in post-autoload-dump; Composer reformatted `keywords`), `composer.lock`, `.gitignore` (ignores published `public/{css,fonts,js}/filament`).
- **Tests:** existing 2 example tests pass.
- **Verification:** `route:list` shows `filament.admin.auth.login`; `GET http://k6-tindahan.test/admin/login` → 200 (re-checked by the controller).
- **Filament API names (plan Part A table): all MATCH.** Notes for later tasks:
  - `InteractsWithPageFilters` lives in the filament/filament package (namespace `Filament\Widgets\Concerns\InteractsWithPageFilters`, `public ?array $pageFilters`) — namespace as in the plan.
  - `$pollingInterval`, `$heading`, `$maxHeight` on widgets are **non-static** `protected ?string` — as written in the plan.
  - `callTableAction`, `assertTableActionHidden`, `TestAction`, `HasFiltersForm::filtersForm(Schema)`, `ManageRecords`, `Filter::schema()`, all 11 Heroicon cases exist.
- **Reviews:** spec ✅; code quality ✅ approved.
- **Follow-ups:** `User` must implement `FilamentUser::canAccessPanel()` before production (planned in Task 2.4). Deploys must not run Composer with `--no-scripts` (or must run `php artisan filament:upgrade` afterwards) — add to README in Task 11.4.

### Task 1.2 — Laravel Boost
- **Status:** Completed (2026-11-16)
- **Installed:** laravel/boost **v2.10.4** (require-dev) + laravel/mcp, laravel/roster, composer/semver, symfony/yaml. Lockfile: 5 added, 0 changed, 0 removed.
- **Deviation:** `livewire/livewire: ^3.8` added as a **direct** `require` (still locked at 3.8.11; done with `--no-update` + `composer update --lock`). Boost only installs Livewire guidance for direct dependencies, and the POS screen uses Livewire directly. Keep this constraint in step with Filament's (`filament/support` requires `^3.8.7`).
- **Boost configuration (`boost.json`):** agents `claude_code` + `codex` (codex is what writes AGENTS.md); guidelines, skills and MCP on; Laravel Cloud, Nightwatch and Sail off; Filament package skill opted in.
- **Files:** `CLAUDE.md` and `AGENTS.md` regenerated and identical (sections: foundation, boost, php, deployments, herd, laravel/core, livewire/core, pint/core, pest/core); new `boost.json`, `.mcp.json` (`php artisan boost:mcp`), `.claude/skills/` and `.agents/skills/` (filament-development, infer-conventions, laravel-best-practices, livewire-development (v3), tailwindcss-development, testing-best-practices), `.codex/config.toml` (gitignored); `composer.json`, `composer.lock`.
- **Tests:** existing 2 tests pass.
- **Verification:** `/admin/login` → 200; `composer validate` OK; versions confirmed with `composer show --direct` (controller).
- **Reviews:** spec ✅; code quality ✅ approved.
- **Rules from the new CLAUDE.md, applied to every later task:** run `vendor/bin/pint --dirty --format agent` after PHP changes; create files with `php artisan make:*` and `--no-interaction`; curly braces on all control structures; explicit return and parameter types; activate the relevant skill (Livewire, Filament, testing); tests via `php artisan test --compact`.
- **Follow-ups (README / deployment, Task 11.4):** production must use `composer install --no-dev`, `APP_DEBUG=false`, and preferably `BOOST_ENABLED=false` — Boost activates whenever `APP_DEBUG=true`, even outside `local`. One harmless line about Laravel Cloud remains in CLAUDE.md (Boost's fixed text, comes back on every `boost:update`).


### Task 1.4 — Application configuration
- **Status:** Completed (2026-11-16)
- **Files:** `config/app.php` (timezone `Asia/Manila`); `config/database.php` (mysql connection `'timezone' => '+08:00'` → `SET time_zone` per connection; mariadb untouched); `.env` and `.env.example` (`APP_NAME="K6 Tindahan"`, `APP_URL=http://k6-tindahan.test`, `SESSION_LIFETIME=720`); `bootstrap/providers.php` (Pint normalised it to `use` imports so later `pint --dirty` runs are clean); `public/storage` symlink created.
- **Tests:** existing 2 pass.
- **Verification:** `php artisan about` → K6 Tindahan, Asia/Manila, public/storage LINKED; MySQL `@@session.time_zone` = `+08:00` and MySQL `NOW()` equals PHP `now()`; `/admin/login` → 200.
- **Reviews:** spec ✅; code quality ✅ approved. Reviewer confirmed the pairing is consistent (the Philippines has no DST; TIMESTAMP round trips, `useCurrent()` defaults and day-range reports line up; session, cache and job timestamps are integers). The MySQL test connection inherits `+08:00` automatically.
- **Notes for later:** Laravel regenerates `bootstrap/providers.php` with fully qualified names whenever a provider is added — harmless. Possible hardening for Phase 9/11: `SESSION_EXPIRE_ON_CLOSE=true`, and `SESSION_SECURE_COOKIE` once HTTPS is used.

### Task 1.5 — MySQL test database and Pest setup
- **Status:** Completed (2026-11-16)
- **Database:** created `k6_tindahan_testing` (utf8mb4_unicode_ci). Dev DB `k6_tindahan` untouched — `migrate:status` still shows only the 3 original migrations, before and after.
- **Additions to the plan (safety):** `RefreshDatabase` drops every table, and this MySQL server hosts other databases, so:
  - `phpunit.xml`: `DB_CONNECTION=mysql`, `DB_DATABASE=k6_tindahan_testing` **and** `DB_URL=""`, all with `force="true"` (a value exported in the shell cannot override them).
  - `tests/TestCase.php`: `setUpTraits()` calls `ensureTestingDatabase()` before RefreshDatabase runs. It reads the **resolved** connection name (`DB::connection()->getDatabaseName()`, which includes any `DB_URL` override) and throws unless it ends in `_testing`. Demonstrated: with a cached config pointing at the dev DB, the suite aborts before any table is touched.
- **Files:** `phpunit.xml`, `tests/TestCase.php`, `tests/Pest.php` (Feature → TestCase + RefreshDatabase; Unit → TestCase), `tests/Feature/ExampleTest.php` (admin login smoke test), new `tests/Feature/TestDatabaseGuardTest.php`.
- **Tests created:** TestDatabaseGuardTest — runs on mysql/`k6_tindahan_testing`; refuses a non-testing database; refuses a `DB_URL` that points at the dev DB (shown to fail against the earlier config-only guard); the default connection is unaffected by the probe tests; `TestCase` overrides `setUpTraits`. Negative probes use a throwaway `guard_probe` connection and never modify the real `mysql` connection.
- **Tests executed:** full suite 7 passed / 11 assertions (re-run by the controller after `config:clear`).
- **Reviews:** spec ✅; code quality — first pass "with fixes" (a `DB_URL` in the shell bypassed the guard), second fix (probe tests had re-pointed the live connection); re-review ✅ approved.
- **Known limitation:** `php artisan test --parallel` fails closed (parallel DB names end in `_test_N`). Loosen the guard if parallel runs are ever wanted.
- **Rule for every later task:** run `php artisan config:clear` before the test suite if config was ever cached; never run `config:cache` on a development machine.

### Task 1.6 — Panel branding
- **Status:** Completed (2026-11-16)
- **Files:** `app/Providers/Filament/AdminPanelProvider.php` (`brandName('K6 Tindahan')`, `darkMode(false)`, primary `Color::Emerald` replacing Amber, `navigationGroups(['Sales', 'Catalog', 'Inventory', 'Settings'])`, FilamentInfoWidget removed, AccountWidget kept); `tests/Feature/ExampleTest.php`.
- **Tests created:** "brands the admin panel for the store" — sets `app.name` to `Laravel` first so the brand check can't pass through Filament's APP_NAME fallback (mutation-tested: fails with `brandName()` removed); asserts brand, dark mode off, nav groups, no info widget.
- **Tests executed:** full suite 8 passed / 15 assertions (re-run by the controller); login page HTML contains "K6 Tindahan"; `/admin/login` → 200.
- **Reviews:** spec ✅; code quality ✅ approved. Reviewer confirmed `darkMode(false)` forces light mode even when the OS prefers dark, and that the theme switcher is hidden; empty navigation groups stay hidden until a resource uses them.
- **Snapshot:** checkpoint tree `291c43db9a088190cf1ba58ab37aa5b16534ce11` (`git write-tree`; no commit). From Phase 2 on, every task records its checkpoint tree so reviewers can run `git diff <tree-before> <tree-after>`.

---

## Phase 2 — Database schema and domain models

Start tree: `554c5c690c39fa54580f45e42761818663314f5e`. The 8 plan tasks run in 4 review batches: **A** = 2.1 + 2.2, **B** = 2.3, **C** = 2.4–2.7 (2.7's behaviour tests are written first, then the models), **D** = 2.8.

**Additions to the plan (tests only, no behaviour change):** a unit test for `StockMovementType` (`sign`, `isManual`, `manualOptions`); `User` tests for `isAdmin` and `canAccessPanel` (active / inactive); a test that soft-deleting a product keeps its transaction items and stock movements (a phase acceptance criterion the plan's tests do not cover).

**`migrate:fresh` acceptance criterion:** verified only on `k6_tindahan_testing` (RefreshDatabase runs `migrate:fresh` there, behind the guard). The dev DB only gets the additive `php artisan migrate` of plan step 2.3.9.

### Task 2.1 — Money helper
- **Status:** Completed (2026-11-16)
- **Files:** created `app/Support/Money.php`, `tests/Unit/MoneyTest.php`.
- **Changes from the plan (code review):**
  - `toCents()` returns 0 for non-finite input or amounts of ₱1e13 or more. Before this, `'1e20'` produced a garbage int, and on PHP 8.5 a float-to-int warning, which becomes a 500 inside a request. It must not throw: the POS calls it on raw keystrokes for the live change display (plan lines 4789–4809).
  - Rounding is `round(round($v, 2) * 100)`, so 3+-decimal input rounds consistently half away from zero (`'1.005'` → 101).
  - `format()` renders negatives as `-₱12.50`.
- **Tests:** the plan's MoneyTest, plus named dataset rows for negatives, whitespace, one decimal, the ₱99,999,999.99 maximum, `1e20`, `INF`, three rounding cases, negative and maximum `fromCents`, and a negative `format`. Red runs seen: `'1.005'` 100≠101, `format(-1250)` gave `₱-12.50`, `'1e20'` raised ErrorException.
- **Verification:** the reviewer fuzzed 2,000,000 random 2-decimal strings in ±₱99,999,999.99, plus cents round trips: all exact.
- **Reviews:**
  - Spec: ✅.
  - Code quality: "approved with fixes", then the fixes above, then re-review ✅.
  - The controller corrected the docblock wording to "half away from zero".
- **Notes for later phases:**
  - `'1,2,3'` → 12300, because commas are stripped. Laravel's `numeric` rule rejects `'1,000'` while `toCents` accepts it, so Phase 7 forms must validate in a way consistent with the display. Money inputs keep their `max:` rules.

### Task 2.2 — Enums
- **Status:** Completed (2026-11-16)
- **Files:** created `app/Enums/UserRole.php`, `app/Enums/TransactionStatus.php`, `app/Enums/StockMovementType.php` (verbatim from the plan); `tests/Unit/EnumsTest.php` (an addition).
- **Tests:**
  - `sign()` for all 8 cases.
  - `isManual()`.
  - `manualOptions()`: exact content and order.
  - UserRole and TransactionStatus values, labels, colours and their Filament contracts.
- **Verification:** full suite 40 passed / 62 assertions (re-run by the controller); Pint passed.
- **Reviews:** spec ✅; code quality ✅ (within batch A).
- **Note for Phase 5:** `StockMovementType::Correction->sign()` is 0 ("set to the counted quantity"). InventoryService must branch on Correction rather than compute `qty * sign()`, which would silently do nothing. A Phase 5 test must cover this.

### Task 2.3 — Migrations
- **Status:** Completed (2026-11-16)
- **Files:** created 8 files in `database/migrations/`, named exactly as in the plan, `2026_11_17_000001_add_pos_fields_to_users_table.php` … `2026_11_17_000008_create_inventory_movements_table.php`. The code is as in the plan, except that the unused `DB` import is removed everywhere but 000005.
- **Database changes:**
  - **Testing DB** (`k6_tindahan_testing`): `migrate:fresh`, then `migrate:rollback --step=8`, which proved every `down()`, then `migrate`. Result: 11 Ran. Every CLI command was guarded by a check that the resolved database name equals `k6_tindahan_testing`.
  - **Dev DB** (`k6_tindahan`): additive `php artisan migrate` only (plan step 2.3.9), run by the controller. Result: 11 Ran; `users` had 0 rows before and after; 1 `store_settings` row.
- **Tests:** no new tests (the schema is exercised by batch C's model tests). Full suite 40 passed / 62 assertions on the new schema.
- **Verification:** `db:table` / `SHOW CREATE TABLE` confirmed every column, unique, index, PK and FK rule. The reviewer probed inside rolled-back transactions:
  - Duplicate `checkout_token` → `UniqueConstraintViolationException`.
  - Multiple NULL `reference_number`s allowed.
  - Hard-deleting a product with movements, or a user with sales, is blocked (1451).
  - Strict mode rejects out-of-range DECIMALs (1264) and negative quantities.
  - `inventory_movements.created_at` default matches PHP `now()` in `+08:00`.
- **Reviews:** spec ✅; code quality ✅ approved.
- **Owner decision raised (not changed — it would alter the approved design):**
  - **What happens today:** a raw `DELETE` on `transactions` cascades away its `transaction_items` and nulls `inventory_movements.transaction_id`. Only policies and the absence of a route prevent it.
  - **Proposed change:** switch both FKs to `restrictOnDelete`, so the DB itself refuses to delete a sale. This matches design principle 3, "nothing financial is deleted". It costs nothing, because the app never deletes transactions. It is a one-line change in 000007 and 000008 while no real data exists.
- **Notes for later phases:**
  - **Migration filenames are dated 2026-11-17, one day after today.** A migration created with `make:migration` before that date would sort earlier and run before these tables exist. Any later migration must be named so it sorts after `2026_11_17_000008`.
  - **The schema has no CHECK constraints.** Invariants such as quantity ≥ 1, change ≥ 0 and before + change = after are enforced in the services only, as designed.
  - **Unique barcode/SKU matching ignores case and trailing spaces** (`utf8mb4_unicode_ci`). The model trims input and turns blanks into NULL; raw inserts get no such protection.

### Tasks 2.4–2.7 — Models, factories and model behaviour tests (batch C)
- **Status:** Completed (2026-11-16)
- **Order:** the tests were written first. Red run: 15 of 16 failed (missing classes and factory states, role not an enum, `/admin` 403 for an active user); then implementation, then green.
- **Files:**
  - Modified: `app/Models/User.php`, `database/factories/UserFactory.php` (2.4).
  - Created: `app/Models/{Category,Product,StoreSetting}.php`, `database/factories/{Category,Product}Factory.php` (2.5).
  - Created: `app/Models/{Transaction,TransactionItem,InventoryMovement}.php` (2.6).
  - Created: `tests/Feature/Models/{ProductModelTest,UserModelTest,StoreSettingModelTest,TransactionModelTest}.php` (2.7 plus additions).
- **Deviations from the plan code:**
  - Inline `//` comments moved to PHPDoc (CLAUDE.md); PHPDoc generics added.
  - Two code-review fixes:
    - `StoreSetting::current()` reads `orderBy('id')->first()`, and its fallback row keeps the default footer `Salamat po! Balik kayo.`.
    - `Product::isLowStock()` casts both sides to int.
  - No factories for Transaction, TransactionItem or InventoryMovement, on purpose: later tests create sales through CheckoutService, so its invariants can't be bypassed.
- **Tests:**
  - ProductModelTest (15): the 9 plan tests verbatim, plus:
    - Soft-deleting a product keeps its items and movements; the `withTrashed()` relations and the name snapshot survive (phase acceptance criterion).
    - `active()->search()` OR-grouping.
    - Literal `%` and `_` in search.
    - A barcode of `'0'` is kept.
    - `sellingPriceCents()` and `imageUrl()`.
  - UserModelTest (6):
    - Factory defaults.
    - `isAdmin`.
    - `canAccessPanel` for active and inactive users.
    - `/admin` returns 200 for an active user and 403 for an inactive one.
    - Hidden attributes.
  - StoreSettingModelTest (2): the fallback recreates exactly one default row.
  - TransactionModelTest (2): `isVoided()` and the cashier relation. Its helper is a `beforeEach` closure, not a global function, so it can't collide with other files.
- **Tests executed:** full suite 65 passed / 122 assertions, re-run by the controller. The reviewer ran it 3× with identical results (no flakiness). Pint passed.
- **Reviews:**
  - Spec: ✅.
  - Code quality: approved, with follow-ups applied; re-review approved.
  - The controller converted the global test helper to a closure.
- **Notes for later phases:**
  - **Phases 4/7, barcode/SKU fields:** trim input *before* `unique(ignoreRecord: true)` validation. Livewire requests skip the TrimStrings middleware, so an untrimmed duplicate would pass validation and then hit the DB unique index as a 500. The POS barcode lookup must also trim scanned input.
  - **Array input:** a tampered array barcode raises a TypeError in the mutator. Form validation must reject non-strings.
  - **Ledger checks:** `ProductFactory` sets `stock_quantity = 50` without an Initial movement. Ledger-sum checks must create stock through InventoryService.
  - **Owner awareness (Phase 3 UserResource / any profile page):** `role`, `is_active`, `can_override_price` and `can_apply_discount` are fillable, as the planned UserResource needs. Never feed self-service input into `User::update()` unfiltered.
  - **Optional hardening:** append-only protection for `inventory_movements` and `transactions` currently relies on policies. Model-level `updating`/`deleting` guards would add a second layer.

### Task 2.8 — Test helpers and seeders (batch D)
- **Status:** Completed (2026-11-16)
- **Files:** `tests/Pest.php` (`admin()` and `cashier()` helpers), `database/seeders/DatabaseSeeder.php`, new `tests/Feature/DatabaseSeederTest.php`.
- **Deviation from the plan (code review, Important):** the seeder uses `firstOrCreate` instead of `updateOrCreate`. Credentials are create-only.
  - With the plan's `updateOrCreate`, re-running `db:seed` on a live shop database would:
    - reset the admin password to `password`;
    - reactivate deactivated cashiers with that known password;
    - revert role changes.
  - The plan text in Task 2.8 still shows `updateOrCreate`. This entry overrides it.
  - There is deliberately no production guard: a first install may need the seeder to get its first admin.
- **Tests (8):**
  - Exactly the two users, with the right names, roles and active flags.
  - Both passwords stored hashed.
  - Both pass `canAccessPanel`.
  - Seeding twice is idempotent.
  - A re-seed **keeps** a changed password, a changed name and a deactivation.
  - The `admin()` / `cashier()` helpers work, including attribute overrides.
  - The `cashier` state overrides an admin base.
  - Red run seen: under the old seeder, the "never modifies existing users" test failed at the changed-password check.
- **Verification:**
  - Full suite 73 passed / 147 assertions (controller); Pint passed.
  - Dev DB `db:seed` (controller) created `admin@k6tindahan.test` (admin) and `cashier@k6tindahan.test` (cashier), both active.
  - `Auth::validate` with `password` succeeds for both, and both pass `canAccessPanel`.
  - `/admin/login` → 200.
- **Reviews:** spec ✅; code quality — approved with one Important item (above), fixed; re-review ✅.
- **Follow-ups (README / Task 11.4 deployment checklist):**
  - After the first install, change **both** default passwords, or deactivate the cashier account.
  - Change both emails: the `.test` domain cannot receive password-reset mail.
  - The helper names `admin` and `cashier` are not defined anywhere else (checked with `function_exists` and a vendor grep).

### Phase 2 close-out
- **Final whole-phase review:** "Phase 2 ready to close: yes". No Critical or Important issues.
  - All 5 acceptance criteria are met, with evidence.
  - Every model, column and method that Phases 3–11 reference exists with a matching signature.
  - No dependency changed: the composer and npm files are identical to the phase-start tree.
  - Money was re-run under PHP 8.3.30 with identical results. Every changed file passes `php83 -l`.
- **Final suite:** 73 passed / 148 assertions. The controller added the reviewer's optional exactly-one-category assertion in `ProductModelTest`. Pint passed.
- **Snapshot:** end tree recorded below (after this entry was staged).
- **Open owner decision:**
  - **Question:** should the database itself block deleting a sale? This means FKs `transaction_items.transaction_id` and `inventory_movements.transaction_id` → `restrictOnDelete`.
  - **How it would be done:** the dev DB has already run these migrations, so the change would be a **new** migration that sorts after `2026_11_17_000008`, not an edit.
  - **Deadline:** decide before Phase 6 writes real sales.
- **Notes for later phases (from the final review):**
  - **Phase 7, price override:** `Money::toCents` turns out-of-range input into 0, and `CheckoutService::guard()` only rejects negative prices. The price-override and manual-price fields must keep `numeric|min:0|max:` validation before calling `toCents`, or a typed `1e13` would charge ₱0.00.
  - **Phase 7, manual item name:** validate `max:150`, the `transaction_items.product_name` length. Strict mode would otherwise raise a 500.
  - **Phase 11, `DemoCatalogSeeder`:** `firstOrCreate(['barcode' => …])` (plan line ~7591) ignores soft-deleted products. Use `withTrashed()`, or re-seeding after a demo product is deleted hits the unique index.
  - **Unit tests:** must not call `admin()` / `cashier()`. Unit tests have no RefreshDatabase, so rows would persist in the testing DB.
- **Phase 2 end tree:** `84fe8a2c5d0bf7ba173d6b27fe4a2d66d1593ecf` (all Phase 2 code and tests; this tracker line was staged afterwards).

---

## Phase 3 — Users, roles and authorization

Start tree: `84fe8a2c5d0bf7ba173d6b27fe4a2d66d1593ecf`, plus the tracker line. The 4 plan tasks run in 2 review batches:
- **E** = 3.1 Policies + 3.2 Gates, with the policy and gate tests of 3.4 written first.
- **F** = 3.3 User resource, with the remaining 3.4 tests (page access, create and edit forms, self-demotion).

**Additions to the plan (tests only):** every B9 rule not already covered by the plan's 10 tests:
- Category and StoreSetting admin-only.
- StoreSetting cannot be created or deleted.
- The InventoryMovement ledger cannot be created, updated, deleted or restored.
- Transactions cannot be created.
- `viewAny` on transactions is refused for inactive users.
- The `access-pos` gate.
- Cashiers get 403 on the user create and edit pages.

Open owner decision carried over from Phase 2: block deleting sales in the database (decide before Phase 6).

### Tasks 3.1 + 3.2 — Policies and gates (batch E)
- **Status:** Completed (2026-11-17)
- **Files:**
  - Created: `app/Policies/{AdminOnly,Product,Category,User,StoreSetting,InventoryMovement,Transaction}Policy.php`, as planned. New `app/Policies/TransactionItemPolicy.php` (addition, see below). `tests/Feature/Auth/AuthorizationTest.php` (the plan's first 6 tests of 3.4, verbatim) and `tests/Feature/Auth/PolicyMatrixTest.php`.
  - Modified: `app/Providers/AppServiceProvider.php` (the 4 gates, as planned).
- **Deviations from the plan (code review):**
  - **New `TransactionItemPolicy`.** Filament *allows* every action on a model with no policy. As a cashier, a probe got `update` and `delete` on TransactionItem allowed. The new policy sets:
    - `viewAny`: allowed for active users.
    - `view`: delegates to TransactionPolicy on the item's transaction; false if the item has none.
    - Everything else: false.
    - This keeps to the plan's own rule that every method Filament may ask about is declared.
  - **`replicate` and `reorder` declared.** `isAdmin()` in AdminOnlyPolicy; false in InventoryMovementPolicy and TransactionPolicy. Before this, a cashier was "allowed" to replicate a sale in Filament.
  - **`TransactionPolicy::view` comparison.** It is now `isAdmin() || (user_id !== null && key !== null && (string) user_id === (string) key)`, instead of the plan's `user_id === id`. The plan's strict int comparison denied a string `user_id` and allowed null === null.
    - `BelongsTo::is()` was tried first. It also compares connection names, so it broke on unsaved transactions.
  - Inline comments were moved to PHPDoc.
- **Tests:**
  - AuthorizationTest (6, plan).
  - PolicyMatrixTest: a dataset with one row per (model, ability, role) for every method declared by all 7 policies (172 rows). Each row asserts both:
    - the `can()` result;
    - `method_exists` on the resolved policy. Filament allows undeclared abilities, so deleting a deny method would otherwise stay green.
  - Named tests:
    - Category, StoreSetting, InventoryMovement, Transaction and User rules; the `access-pos` gate.
    - Product restore and forceDelete.
    - Inactive users on `apply-discount` and `view-reports`.
    - String and non-canonical `user_id` dataset; null keys.
    - Owner cashier can view their own item, other cashiers cannot.
    - An item without a transaction is denied, with no TypeError.
    - TransactionItem policy discovery.
  - Red runs seen at each step:
    - 10 of 13 failing before the policies existed.
    - Then the replicate/reorder, TransactionItem, string-`user_id` and null-key rows.
    - Then the `"N.9"`, `"0N"` and `" N"` rows and the TypeError.
- **Tests executed:** full suite 268 passed / 571 assertions (controller re-run); Pint passed.
- **Verification:**
  - `Gate::getPolicyFor()` resolves every model's policy; the abstract base is never resolved.
  - Filament's `Authenticate` middleware (Filament) and the `can:` middleware (Livewire) both re-run on every `/livewire/update`, so a deactivated user is blocked on the next request.
- **Reviews:**
  - Spec: ✅.
  - Code quality: 3 rounds. "Approve with fixes" (I1 untested deny rules, I2 no TransactionItem policy, M1 replicate/reorder, M2 view comparison). Then the deleted-method gap, and minors on the `(int)` comparison, null transaction and owner case. Final: ✅ approved.
  - The review was interrupted once by an API usage limit and resumed; the working tree was checked clean before resuming.
- **Not applied (owner's option):** a central `Gate::before(fn (User $u): ?bool => $u->is_active ? null : false)`.
  - Today every policy except `TransactionPolicy::viewAny` ignores `is_active`. That is safe over HTTP and Livewire, where `canAccessPanel` and `can:access-pos` re-run on each request.
  - It is exposed only if a service is later called from a queued job or console command with a user loaded before deactivation.
- **Rejected after analysis:** "last active admin lockout". The acting admin can't demote or deactivate themselves (Task 3.3), so at least one active admin always remains.
- **Notes for later phases:**
  - **Bulk actions:** Filament's `DeleteBulkAction` checks only `deleteAny`. Any per-record delete rule, e.g. "a category with products can't be deleted", needs `->authorizeIndividualRecords()`.
  - **Relation managers:** attach/detach/associate in Filament 4.16 check only `isReadOnly()`, not the policy. Put `->authorize()` on those actions if finer control is needed.
  - **Phase 8:** eager-load `transaction` for item tables; `TransactionItemPolicy::view` lazy-loads it per row.

### Tasks 3.3 + 3.4 — User management resource and authorization tests (batch F)
- **Status:** Completed (2026-11-17)
- **Files:**
  - Created: `app/Filament/Resources/Users/UserResource.php` and `Pages/{ListUsers,CreateUser,EditUser}.php` (plan verbatim; the inline comments moved to PHPDoc); `tests/Feature/Filament/UserResourceTest.php`.
  - Modified:
    - `tests/Feature/Auth/AuthorizationTest.php`: now holds all 10 plan tests.
    - `app/Models/User.php`: `saving` hook.
    - `tests/Feature/Models/UserModelTest.php`.
- **Deviations from the plan (code review):**
  1. **`User::booted()` saving hook clears `can_override_price` and `can_apply_discount` whenever an *admin* is saved.**
     - **Problem:** the form hides those toggles for admins, so they were not saved. A cashier promoted to admin kept the flags, and on a later demotion silently got the powers back.
     - **Why the model:** the rule lives in the model, not the Filament resource. It covers every Eloquent model save (not query-builder mass updates such as `User::query()->update()`, which nothing in the plan uses), and business logic stays out of resources.
     - **First version:** `role !== Cashier`. Re-review probes showed it also wiped a real cashier's flags when a partially selected model was saved, or when a user was created with no role (the DB defaults it to cashier). The final condition is `isAdmin()`.
  2. **Password `dehydrated` closure takes `mixed`.** A tampered array payload threw a TypeError (500); it now returns a validation error.
- **Tests:**
  - AuthorizationTest (10, plan). The self-demotion test was tightened: it tampers with `->set('data.role'/'data.is_active')` together with a name change and asserts `assertHasNoFormErrors`, so it can't pass without saving.
  - UserResourceTest (15):
    - Cashier 403 and admin 200 on create and edit pages; cashier forbidden at the Livewire level.
    - `canAccess` by role.
    - List shows users; no delete or bulk delete action; no DeleteAction on edit.
    - Admin can demote or deactivate *another* admin.
    - Validation: duplicate email, short password on create and on edit, missing password, array password.
    - Cashier-only toggles visible and hidden by role.
    - Promote-then-demote round trip leaves both gates false.
    - `data.password` is null after mount, and the hash is not in the HTML.
  - UserModelTest (+4): the admin flags are cleared; a cashier keeps its flags; a partially selected save leaves the flags alone; a user created without a role keeps its flags.
  - Red runs seen: missing classes, 404 instead of 403, then the flag, round-trip, TypeError and partial-save cases.
- **Tests executed:** full suite 289 passed / 666 assertions (controller re-run after the final hook change); Pint passed.
- **Verification:**
  - Guest `GET /admin/users` → 302 to `/admin/login`.
  - Probes against Filament 4.16 confirmed:
    - Disabled fields are validated but never saved, even under `->set()` tampering.
    - Hidden fields are neither saved nor validated.
    - The edit form fills from `attributesToArray()`, so the hash never reaches the snapshot.
    - Demotion or deactivation takes effect on the user's next request.
    - Filament's `AuthenticateSession` logs out a user's other sessions after an admin changes their password.
- **Reviews:**
  - Spec: ✅.
  - Code quality: approve with one Important fix (stale flags) and minors; re-review found the `!== Cashier` edge case. The controller applied the `isAdmin()` change with TDD (both new tests red, then green).
- **Owner notes:**
  - **Admins changing their own credentials:** an admin can change their own email and password in this form without re-entering the current password. A separate profile page could take that over later.
  - **Zero admins:** possible only if two admins demote each other at the same moment. Recovery would need the seeder or DB access.
  - **POS route:** deactivated users keep an authenticated session but get 403. The POS route must keep `can:access-pos` (Phase 7, as planned).

### Phase 3 close-out
- **Final whole-phase review:** "Phase 3 ready to close: YES". No Critical or Important issues.
  - All 6 acceptance criteria are met, with test evidence.
  - Every B9 rule has an enforcement point and a test. Three B9 items can only be tested once their phases exist: the transaction query scope (Phase 8), the service re-checks (Phases 6 and 8) and the POS route (Phase 7).
  - Every ability, gate and policy method that Phases 4–10 reference exists with a matching signature, with plan line numbers checked.
  - Nothing in the plan gives admins the permission flags, so the saving hook breaks nothing.
  - Dependencies unchanged; no migrations; every changed file passes `php83 -l`.
- **Minor items the controller fixed after the final review (TDD, red then green):**
  - `StoreSettingPolicy::replicate()` returns false: there is one settings row, and admins had inherited `replicate = true`. The matrix row was flipped.
  - The password field has `->maxLength(72)`, because bcrypt silently ignores anything past 72 bytes. Test: 73 characters → `max` error.
  - The user list's "Price override" and "Discounts" columns show ✓ for admins, who have both powers through the gates. They had shown ✗ because the hook clears admins' flags. Test: `assertTableColumnStateSet` for admin, plain cashier and trusted cashier.
  - Tracker wording: the saving hook covers Eloquent model saves, not query-builder mass updates.
- **Final suite:** 291 passed / 689 assertions; Pint passed.
- **Smoke checks:** guest `/admin/users` → 302; dev DB `migrate:status` is unchanged (11 Ran, no Phase 3 migrations).
- **Note for Phase 8:** `TransactionItemPolicy::view` lazy-loads `transaction`, so item tables must eager-load it.
- **Phase 3 end tree:** `f8cbee250aec9f23bff76228cdf67f1c64b0f2e5` (all Phase 3 code and tests; this tracker line was staged afterwards).

---

## Decisions taken without asking (owner instruction, 2026-11-17: "continue to last phase, don't ask again")
- **Database-level protection against deleting a sale (raised in Phase 2):** the approved design is **kept**: `transaction_items` cascades and `inventory_movements.transaction_id` is set null. Changing it would alter an approved architectural decision without approval. It remains a recommendation in the final report.
- **Phase 11 scratch database:** use `k6_tindahan_testing`, as above.
- **Plan decisions D1–D6:** the plan's defaults stand.
- **Working method for Phases 4–11:**
  - Implementers and reviewers read their task text straight from `docs/implementation-plan.md` by line range, with the cross-phase notes recorded in this tracker passed to them.
  - Small batches get one combined spec-then-quality reviewer.
  - Each phase still ends with a final review and a close-out entry here.

---

## Phase 4 — Product and category management
- **Status:** Completed (2026-11-17). One batch: Tasks 4.1, 4.2 and 4.3.
- **Files created:**
  - `app/Filament/Resources/Categories/{CategoryResource.php,Pages/ManageCategories.php}`
  - `app/Filament/Resources/Products/{ProductResource.php,Pages/ListProducts.php,CreateProduct.php,EditProduct.php}`
  - `tests/Feature/Catalog/CatalogResourceTest.php`
- **Code:** as in the plan, with inline comments moved to PHPDoc. Every Filament API in the plan exists in 4.16.
- **Deviations from the plan (all from review):**
  - **Barcode/SKU:** `->trim()->string()`. Livewire skips TrimStrings, so an untrimmed duplicate would have hit the DB unique index (a 500), and a tampered array would have caused a TypeError in the mutator.
  - **Product image:**
    - `->acceptedFileTypes(['image/jpeg','image/png','image/webp'])`: `->image()` alone accepted SVG with script, i.e. stored XSS on the public disk.
    - `->preventFilePathTampering()`: a tampered path is now a form error, and the legitimate edit flow keeps the existing image.
  - **Other fields:**
    - `low_stock_threshold` `->maxValue(100000)`; above the unsigned-int range it gave a 500.
    - Prices `->rule('decimal:0,2')`; `'12.345'` had been rounded silently.
    - Category name `->trim()`.
    - Product and category description `->maxLength(5000)`; a TEXT overflow had given a 500.
- **Tests (30 in the file):** the plan's 8, plus:
  - untrimmed duplicate barcode and SKU; tampered array; no stock field;
  - restore from the edit page; a category delete keeps its products;
  - SVG rejected; tampered image path rejected; existing image kept on edit; JPG stored under `products/`;
  - threshold cap; decimals; category trim; description length;
  - soft delete from the table and from the edit page;
  - search by name and by SKU; category, active and trashed filters; low-stock badge colour;
  - category edit and duplicate name; cashier forbidden at the Livewire level on all 4 pages.
  - **The plan's manual check is automated:**
    - The barcode `<input id="form.barcode">` renders `x-on:keydown.enter.prevent`.
    - An image upload is stored on a fake public disk.
- **Tests executed:** full suite 321 passed / 861 assertions (controller re-run); Pint passed. Guest `/admin/products` and `/admin/categories` → 302.
- **Reviews:** combined spec and quality review: spec ✅; quality "approve with fixes" (2 Important, 6 Minor); fixes applied; re-review "ready to close".
  - The reviewer probed:
    - Cashier 403 on every page, at both the HTTP and Livewire level.
    - No force delete; no bulk actions.
    - Global search is admin-only and excludes trashed products.
    - The list runs 4 queries whether it shows 2 or 10 products, so no N+1.
    - Category uniqueness is case- and space-insensitive, giving a form error rather than a 500.
- **Follow-ups:**
  - Replaced or removed product images are not deleted from disk. Add an `updated` observer that deletes `getOriginal('image_path')` (tampering protection is now on, so this is safe). Candidate for Phase 11.
  - Over HTTPS, `k6-tindahan.test` returns 500 for every URL, including `/up`. Herd has no TLS for this site and the app uses `http://`, so this is unrelated to the app.

---

## Phase 5 — Inventory and stock management
- **Status:** Completed (2026-11-17). One batch covering Tasks 5.1, 5.2 and 5.3.
- **Files created:**
  - `app/Exceptions/InsufficientStockException.php`
  - `app/Services/InventoryService.php`
  - `app/Filament/Resources/Products/Actions/AdjustStockAction.php`
  - `app/Filament/Resources/InventoryMovements/{InventoryMovementResource.php,Pages/ListInventoryMovements.php}`
  - `tests/Feature/Inventory/{InventoryServiceTest,StockAdjustmentUiTest}.php`
- **Files modified:** `ProductResource.php` (opening-stock and current-stock fields, Adjust stock action), `Pages/CreateProduct.php`, `Pages/EditProduct.php`.
- **Design as planned:**
  - `adjust()` is the only writer of `stock_quantity`. It runs inside `DB::transaction` with `lockForUpdate()` and re-reads the row under the lock.
  - Every change writes a movement where before + change = after.
  - A Correction branches to (counted − current). A correction to the same quantity writes a zero-change movement as proof of the count.
  - `allowNegative` is passed in by the caller; the service never reads StoreSetting. Manual write-offs never go below zero. Opening stock creates an `initial` movement; 0 creates none.
- **Deviations from the plan (hardening):**
  - The reason field has `trim()` and `string()`; an empty reason is stored as null.
  - The type field validates `in(manualOptions keys)`, so tampered `sale`, `void_return` and `initial` are rejected.
  - Quantity is capped at `maxValue(1000000)` (`MAX_QUANTITY`). The minimum is 1, or 0 for Correction (the plan had 0 for all types; the service already rejected 0 for non-corrections).
  - The action has an explicit `->authorize(can('adjustStock'))`. Opening stock has a `maxValue`.
  - After review, `adjust()` throws `InvalidArgumentException` when the change or the result leaves the signed 32-bit range. A restock near the limit had given an uncaught QueryException (500); the action now turns it into a "Stock was not changed" notification.
  - `callTableAction` is replaced by `callAction(TestAction::make(...)->table(...))`, because the Filament 4 testing API differs.
- **Tests:** InventoryServiceTest and StockAdjustmentUiTest, the plan's tests plus about 40 extra:
  - Correction up, down, same and from negative stock.
  - Stale model; soft-deleted product; ledger sum equals stock, with before + change = after on every row.
  - Overflow dataset; `FOR UPDATE` query present; rollback when the movement insert fails.
  - Opening-stock validation. 13 invalid adjust-stock inputs, each keyed to its field.
  - Correction to 0; adjusting from the edit page; action hidden for trashed products; insufficient-stock notification.
  - Cashier 403 at the Livewire level. Movements resource is read-only. N+1 guard on the history list. Trashed products' movements are listed.
- **Mutation checks by the controller:**
  - Removing `lockForUpdate()` makes the lock test fail.
  - Disabling the overflow guard makes both the service and UI overflow tests fail.
  - The service file was restored byte-for-byte from a backup.
- **Tests executed:** full suite 377 passed / 1172 assertions; Pint passed; guest `/admin/inventory-movements` → 302.
- **Reviews:** combined spec and quality review. Spec ✅. Quality: no Critical or Important issues; minors 1–4 applied.
  - The reviewer confirmed the Phase 6 checkout and Phase 8 void calls into `adjust()` match its signature exactly.
  - The reviewer confirmed lock ordering (ascending id) cannot deadlock against checkout or void.
- **Notes for later phases:**
  - **Checkout:** must pass `allowNegative: StoreSetting::current()->allow_negative_stock`; the plan already does.
  - **Phase 11 demo seeder:** `adjust()` with 0 throws "Stock change cannot be zero". Guard opening stock with `if ($stock > 0)`.
  - **Adjust-stock modal:** shows current stock as of when it opened (cosmetic; saved values come from the locked row).

---

## Phase 6 — Checkout and transaction processing
- **Status:** Completed (2026-11-17). One batch: Tasks 6.1 Cart and 6.2 CheckoutService.
- **Files:**
  - Created: `app/Pos/Cart.php`, `app/Exceptions/CheckoutException.php`, `app/Services/CheckoutService.php`, `tests/Unit/CartTest.php` (the plan's 7 tests, verbatim), `tests/Feature/Checkout/CheckoutServiceTest.php` (the plan's 15 tests plus about 40 extra).
  - Modified: `tests/Pest.php` (`cartOf()` and `checkout()` helpers).
- **Design as planned:**
  - One `DB::transaction(attempts: 3)`: read the setting; lock the products with `whereIn ... ORDER BY id FOR UPDATE`; check availability, price and stock under the locks; check discount and cash.
  - Then insert the transaction, set `reference_number` (`Ymd-%06d` of the id), write the items with snapshots, and call `InventoryService::adjust()` for catalog lines only.
  - `allowNegative` comes from StoreSetting. The `override-price` and `apply-discount` gates are re-checked on the server.
- **Deviations from the plan (hardening):**
  - **Bounds:**
    - `Cart::MAX_QUANTITY = 9999`.
    - `guard()` enforces the DECIMAL(10,2) and DECIMAL(12,2) upper bounds (`amount_too_large`, `invalid_line`). Without them, oversized amounts reached the DB as a 500.
  - **Line validation** (`isValidLine()`):
    - Checks types.
    - A catalog line's key must be `p{id}`, so one product can't be split across lines to dodge the stock check.
    - A manual line needs `product_id` null and a key matching `^m[0-9a-f]{8}$`.
    - A manual name is trimmed, 1–150 characters (`mb_strlen`), and stored trimmed.
  - **Idempotency:**
    - Catches `CheckoutException|UniqueConstraintViolationException`, then returns `findByToken($cashier)` or rethrows. In a real race, the second request waits on the first's locks, then fails the stock re-check before it reaches the unique insert. With the plan's narrower catch it would have said "Not enough stock" instead of returning the sale that was saved.
    - `findByToken()` is scoped to the calling cashier, so another cashier's token never returns that cashier's sale.
  - **Inactive cashiers:** `guard()` refuses a cashier without `access-pos` ("You are not allowed to make sales."). This is defence in depth for callers outside the `/pos` route, such as the Phase 11 scripts. It runs after the token lookup, so a retry of a committed sale still returns it.
  - Comments moved to PHPDoc; `@phpstan-type CartLine`.
- **Tests:** beyond the plan's, they cover:
  - **Bounds:** DECIMAL bound edges, both accepted and refused.
  - **Tampering:**
    - Lines: string quantity, float price, missing key, array name, split product line, a manual line with a `product_id` or under a catalog key.
    - Overrides: a tampered override flag, revoked permissions, a deactivated user, an override at the catalog price, and an override surviving a catalog price change.
  - **Concurrency:**
    - Two deterministic race tests (one hits the unique index, one hits the stock re-check), with exactly one sale and one deduction.
    - Lock order is ascending in a single query (mutation-checked: removing `orderBy` fails).
  - **Integrity:**
    - Atomic rollback when a later step fails.
    - Exact stored DECIMAL values.
    - Every snapshot field survives a product edit and delete.
    - The reference number under a fixed clock.
    - Several short items are listed in the message.
    - A cross-cashier token doesn't leak a sale, and a committed sale is still returned after deactivation.
- **Tests executed:** full suite 439 passed / 1455 assertions; Pint passed; `php83 -l` clean.
- **Reviews:** combined spec and quality review.
  - Spec: ✅. The plan's tests are verbatim; two inline comments in plan tests were dropped (cosmetic).
  - Quality: no Critical or Important issues. Minors 1–4 applied. All of Phase 7's Pos calls (Cart methods, `checkout()` signature, exception `reason`/`context['prices']`) and Phase 8's void calls match.
- **Notes for Phase 7:**
  - `Pos::addToCart()` and `applyQuantity()` must cap at `Cart::MAX_QUANTITY` (the plan hard-codes 9999 in one place and doesn't cap scans).
  - Keep the `numeric|min:0|max:` validation on cash, discount, override and manual price before `Money::toCents` (which maps ≥ 1e13 to 0).
  - Manual name `max:150`.
  - Trim the scanned barcode.
  - A cross-cashier token collision raises `UniqueConstraintViolationException` (practically impossible with a server-side UUID).
- **Correction to the Phase 2 note above:** `guard()` no longer only rejects negative prices; it also enforces the upper bounds above. The Phase 7 validation requirement still holds.

---

## Phase 7 — POS cashier interface
- **Status:** Completed (2026-11-17). One batch covering Tasks 7.1–7.5.
- **Files:**
  - **Created:**
    - `app/Livewire/Pos.php`
    - `app/Http/Responses/LoginResponse.php`
    - `resources/views/livewire/pos.blade.php`
    - `resources/views/components/layouts/pos.blade.php`
    - `tests/Feature/Pos/PosTest.php` (60 tests)
  - **Modified:**
    - `routes/web.php` (`/pos` with `auth` + `can:access-pos`; `/` redirects by role)
    - `bootstrap/app.php` (`redirectGuestsTo`)
    - `app/Providers/AppServiceProvider.php` (LoginResponse binding)
    - `app/Providers/Filament/AdminPanelProvider.php` ("Open POS" navigation item)
    - `resources/css/app.css` (`x-cloak`)
- **Deviations from the plan:**
  - **Test helper:** `pos()` is renamed to `posScreen()`, because PHP has a built-in `pos()`.
  - **Quantity cap:** `Cart::MAX_QUANTITY` is enforced in scans, `applyQuantity` and manual quantity, with a toast at the cap.
  - **Barcode focus:** `scan()` dispatches `focus-barcode`, and the input has `autofocus`.
  - **Manual name:** trimmed before `max:150`.
  - **Live totals** (`livePaymentCents()`) accept only input that passes `numeric|min:0|max`. Commas are rejected both on screen and on submit.
  - **Other:** constants for the maxima; `@error('discount')`; `<main>` scrolls below 1024 px; PHPDoc.
  - **From review:**
    - `hydrate()` reloads `lines` and `checkoutToken` from the session on every request.
      - Cause: Livewire's `#[Session]` reads only on mount, so two tabs kept separate snapshots with one token.
      - Effect: a second tab's sale "completed" as the first tab's sale and was never recorded.
    - **Race guard in `completeSale()`.** A sale returned by token that was not created in this request and doesn't match the cart triggers:
      - a fresh token
      - the cart is kept
      - a toast: "This sale was already recorded. Your cart has changed — press Complete Sale again."
      - A genuine double-submit (same cart) still shows the existing sale.
    - **Discount larger than the subtotal** disables Complete Sale and shows an inline message.
    - **`editingPriceKey` is `#[Locked]`, and all JS arguments go through `@js()`.** It was client-settable and rendered raw, which allowed self-XSS.
    - **"Use regular price" is shown for overridden lines even without the permission.** Otherwise a revoked cashier was stuck.
    - **Other review fixes:**
      - header controls `min-h-12`
      - one StoreSetting query per request (`#[Computed]`)
      - the double-submit test re-sets cash so it really reaches `checkout()`
      - the `hydrate()` token fallback is a new UUID
- **Tests (60):**
  - The plan's 21 tests are verbatim (only the helper is renamed), plus the 2 Task 7.4 tests.
  - Extra tests cover:
    - **Money input:** `1e13`/oversize/negative money in every field; quantity caps; manual quantity validation; commas.
    - **Stock:** negative-stock mode; sold-out cards; a product deactivated mid-sale.
    - **Tampering:**
      - a tampered `savePrice` gives 403
      - Locked `lines`, token, completed id and `editingPriceKey`
      - discount and price controls render only for permitted users
    - **Payment flow:** Complete Sale gating; exact or quick cash; the token survives a reload; Clear Cart issues a new token.
    - **Access:**
      - another cashier's completed sale is hidden
      - login landing by role through the real Filament Login
      - a deactivated cashier gets 403 on a real `/livewire/update` POST
    - **Multi-tab:** the two-tab shared cart; two-tab two sales; the race guard; a genuine retry; the token fallback; the header 48 px check; one settings query.
  - **Mutation checks:**
    - removing `can:access-pos` fails the update test
    - removing the cart reset fails the double-submit test
    - forcing a mismatch fails the retry test
- **Tests executed:** full suite 499 passed / 1734 assertions; Pint passed. `npm run build` (Node 24) succeeded. Guest `/pos` → 302, `/admin/login` → 200.
- **Reviews:** combined spec and quality review.
  - Spec ✅.
  - Quality: 1 Important (two tabs) plus 7 minors, all applied except tampered-scalar 500s (Minor 6, accepted).
  - Re-review confirmed:
    - `hydrate()` can't bypass `#[Locked]`
    - mount and reload still work
    - logout invalidates the session (a stale tab gets a 419)
    - a duplicate sale is impossible
  - The race guard closes the remaining lost-sale window.
- **Still to do by hand (pos-workflow §10):** real USB scanner (fast back-to-back scans, focus return) and touch-screen checks. The project has no browser-test plugin.
- **Notes:**
  - `resources/views/welcome.blade.php` is no longer routed; it was left in place.
  - The "Sales history" header link targets `/admin/transactions` (Phase 8).
  - Barcode matching is case-insensitive (collation).

---

## Phase 8 — Transaction history and voiding
- **Status:** Completed (2026-11-17). One batch: Tasks 8.1 and 8.2.
- **Interruption:** the implementer stopped once on an API usage limit. The working tree was checked and the work resumed from its partial files.
- **Files created:**
  - `app/Exceptions/TransactionAlreadyVoidedException.php`
  - `app/Services/TransactionService.php`
  - `app/Filament/Resources/Transactions/{TransactionResource.php,Pages/ListTransactions.php,Pages/ViewTransaction.php}`
  - `tests/Feature/Transactions/{VoidTransactionTest,TransactionResourceTest}.php`
- **Files modified:** `app/Policies/TransactionPolicy.php` (`void` now also requires `is_active`), `tests/Feature/Auth/PolicyMatrixTest.php`.
- **Void, as planned:** one `DB::transaction(attempts: 3)`.
  - **Locking:** lock the sale row (`FOR UPDATE`) and re-read it. Check "not voided" and `can('void')` under the lock.
  - **Restock:** restore catalog lines in ascending `product_id` order through `adjust()` (`void_return`). Soft-deleted products are included; manual lines are skipped.
  - **Record:** then set `status`, `voided_at`, `voided_by` and `void_reason`.
  - **Second void:** a later void throws `TransactionAlreadyVoidedException`. A parallel void blocks on the row lock, then sees the sale as voided.
- **Deviations from the plan (hardening):**
  - **Void reason:** required, trimmed with `Str::trim` (Unicode-aware), at most 255 characters by `mb_strlen` (`MAX_REASON_LENGTH`).
  - **View page eager loading:** done in `resolveRecord()`. Filament 4.16's `ViewRecord` skips `mutateFormDataBeforeFill` when the page has an infolist, so the plan's hook would have produced N+1 queries.
  - **Void action:**
    - `->after()` refreshes the view page after a void.
    - `->authorize(can('void'))`.
    - The reason field has `trim()` and `string()`.
    - `InvalidArgumentException` is shown as a "Sale was not voided" notification.
  - **Date filter:** a strict `Y-m-d` parser replaces `Carbon::parse`.
  - **Tampered filter state** (array or scalar) caused 500s on this cashier-accessible table.
    - The fix normalises filters in the public Livewire `mount()` and `updated()` hooks of `ListTransactions`.
    - The first version overrode three Filament internals. Review found it recursed into nested arrays and wiped legitimate URL filters (`?filters[status][value]=voided`), so it was replaced.
  - **`TransactionPolicy::void`** requires `is_active`. An inactive admin could void through a direct service call.
- **Tests:** 7 void tests and 5 resource tests from the plan, verbatim, plus about 50 more:
  - **Void behaviour:**
    - reason validation at the service and in the UI (including non-breaking spaces and 256 characters)
    - cashiers refused; inactive admin refused
    - lock-query order; a stale second admin copy; a race with the modal open
    - rollback on overflow; one `void_return` per catalog line; the ledger untouched
  - **Access and scope:**
    - cashier scope: HTTP 404 and Livewire `ModelNotFoundException`
    - no create, edit or delete
  - **Filters:**
    - inclusive date range
    - cashier filter for admins only
    - tampered filters over Livewire and URL
    - legitimate URL status and date filters
  - **Display and performance:**
    - detail page in order: regular vs sold-at price, "Price changed by", the manual line, payment totals
    - flat query counts for the list and the detail page
  - **Mutation checks by the implementer:** each disabled safeguard made its named test fail. The reviewer confirmed the race can't restore stock twice.
- **Tests executed:** full suite 565 passed / 1999 assertions; Pint passed; guest `/admin/transactions` → 302.
- **Reviews:** combined spec + quality review.
  - Spec ✅.
  - Quality: 1 Important (the sanitizer wiping URL filters) plus minors. Fixed I1, M3, M4 and M5.
  - Accepted and backlogged:
    - M1: a few Filament-wide self-inflicted 500s under tampering, e.g. `set('tableFilters','x')`.
    - M2: InventoryMovementResource's `type` filter has the same tampered-Livewire 500; it is admin-only.
    - M6: no override/manual indicator on the list.
- **Note:** `price_override_user_id` is always the cashier who rang up the sale; CheckoutService records the caller.

---

## Phase 9 — Store settings and receipt printing
- **Status:** Completed (2026-11-17). One batch covering Tasks 9.1, 9.2 and 9.3.
- **Files created:**
  - `app/Filament/Resources/StoreSettings/{StoreSettingResource.php,Pages/ListStoreSettings.php,Pages/EditStoreSetting.php}`
  - `app/Http/Controllers/ReceiptController.php`
  - `resources/views/receipts/show.blade.php`
  - `tests/Feature/Receipts/{StoreSettingsTest,ReceiptTest}.php`
- **Files modified:**
  - `routes/web.php`: the receipt route `transactions/{transaction}/receipt`.
  - `resources/views/livewire/pos.blade.php`: the "Print receipt" button on the sale-complete overlay.
  - `TransactionResource.php`: `receiptAction()` added to the table.
  - `Pages/ViewTransaction.php`: Print header action.
  - `tests/Feature/Transactions/TransactionResourceTest.php`: warm-up request, see below.
- **Deviations from the plan:**
  - **Print buttons use `self.print()` / `self.close()`.** The plan's view used `window.print()` in its buttons, while its own test asserts `assertDontSee('window.print')` when not auto-printing, so the plan contradicted itself. The auto-print script still uses `window.print`.
  - **Receipt route middleware.** It sits in the `auth` + `can:access-pos` group instead of plain `auth`, so inactive users, including inactive admins, get 403. `Gate::authorize('view')` (TransactionPolicy) is still applied.
  - **Settings text fields** get `->trim()->string()`, and paper width gets `->in([58, 80])`. Tampered array input gives a form error, not a 500.
  - **Warm-up request** in the existing detail-page query-count test. Filament's `NavigationManager` is bound with `scoped()`, so it mounts once per container scope: once per test in the suite, once per request in production. The plan's `getNavigationUrl()` runs `StoreSetting::current()` on that first mount. No stale data can be served across requests.
- **Tests:** the plan's 6, verbatim (the 58 mm test also asserts width and font), plus extras:
  - **Escaping:** XSS escaping of every store and product field.
  - **Content:** no cost price on the receipt; VOIDED mark.
  - **Print layout:** 58 mm and 80 mm CSS (`@page size`, 48 and 72 mm widths, 10 and 12 px fonts). Print buttons in all three places (list, detail, POS overlay after a real sale).
  - **Access:**
    - Cashiers can open only their own receipts.
    - A guest is redirected to login.
    - Inactive cashiers and inactive admins get 403.
    - A cashier gets 403 on settings, both by URL and as a Livewire component.
    - No create page.
  - **Validation:** tampered settings input.
  - **Performance:** receipt query count is flat (4 queries).
- **Tests executed:** full suite 583 passed / 2101 assertions; Pint passed; `npm run build` (Node 24) passed.
  - Guest `/transactions/1/receipt` → 302 to login (controller check); `/admin/store-settings` → 302.
- **Reviews:** combined spec + quality review. Spec ✅. Quality: no Critical or Important issues; minors 1, 2 and 4 applied.
  - The reviewer confirmed that only the single settings row can ever exist or be edited: create and `/999/edit` return 404, and tampered `id` values are ignored.
- **Notes:**
  - A real thermal printer check is still to be done by hand (pos-workflow §10, steps 18–20). The plan's CSS left-aligns the 48 and 72 mm body on the page.
  - **Test-harness caveat:** because the `NavigationManager` is scoped, a cashier request made after an admin request *in the same test* sees the admin's navigation. Future navigation-visibility tests should use one user per test.
  - **Optional cleanup:** a redirect in `ListStoreSettings::mount()` would remove the settings query from every admin page.

---

## Phase 10 — Sales analytics and dashboard
- **Status:** Completed (2026-11-17). One batch: Tasks 10.1, 10.2 and 10.3.
- **Files:**
  - Created:
    - `app/Support/ReportRange.php`
    - `app/Services/SalesReportService.php`
    - `app/Filament/Widgets/{TodayStatsOverview,ReportSummaryStats,SalesTrendChart,BestSellersTable,LowStockTable,SalesByProductTable,SalesByCategoryTable,SalesByCashierTable}.php`
    - `app/Filament/Pages/{Dashboard,SalesReports}.php`
    - `app/Http/Middleware/DropNonArrayFilters.php`
    - `tests/Feature/Reports/{SalesReportServiceTest,DashboardAccessTest,ReportWidgetsTest}.php`
  - Modified:
    - `app/Providers/Filament/AdminPanelProvider.php`: `->pages([])` and `->widgets([])`, as the plan says.
    - `app/Filament/Resources/Transactions/Pages/ListTransactions.php`: the middleware.
- **Report rules (as in the plan):**
  - Voided sales are excluded everywhere.
  - Figures come from item snapshots.
  - A product in several categories counts in each of them, so category totals can exceed total sales; the table notes this.
  - Manual and uncategorised items go under "Uncategorized".
  - Product, category and best-seller figures are **before** sale discounts (`SUM(line_total)`), and those tables say so. Summary, by day or period, by cashier and "Today's sales" are **after** discount (`transactions.total`).
  - Weeks start on Monday, done in PHP so ISO-week edge cases can't happen.
  - Empty days, weeks and months appear as zero rows.
- **Deviations from the plan:**
  - Date bounds are half-open (`>= from AND < to + 1s`), with both bounds converted to the app timezone. Query bindings carry no timezone.
  - `ReportRange` uses a strict `Y-m-d` parser instead of `Carbon::parse`, which crashed on arrays and accepted phrases such as "next monday". Ranges are capped at `MAX_DAYS = 731`, keeping the end date.
  - The low-stock link uses the URL key `filters`; the plan's `tableFilters` opened an unfiltered list in Filament 4.16.
  - `SalesReports` repairs `$filters` in its public `mount()` and `updated()` hooks.
  - Tie-break ordering added.
  - From review:
    - **Tampered URLs:** `DropNonArrayFilters` (via `$routeMiddleware`) is attached to SalesReports and ListTransactions, so `?filters=x` no longer gives a 500.
    - **Category report:** rewritten as a per-product subquery driven from the date-filtered transactions. The plan's version scanned all of `transaction_items`.
    - **Product names:** a product renamed within the range shows its **latest** snapshot name (highest item id) instead of `MIN(product_name)`.
    - **Labels:** daily and weekly labels include the year when a range spans more than one calendar year.
    - **Optimizer hints removed again:** MySQL hints were tried and then removed, because on committed data after `ANALYZE` the unhinted rewrite already uses `transactions_status_created_at_index` → `transaction_items` ref (3k and 20k sales). Plan verification moves to Task 11.3.
- **Tests:**
  - The plan's 9 service tests and 3 access tests, verbatim.
  - Timezone and day-boundary tests (23:59:59 / 00:00:00, UTC bounds); Monday weeks across year ends; zero-fill.
  - Discount net/gross split; "Today's sales" equals the report total.
  - Multi-category products, manual items and deleted categories; snapshots surviving a rename or delete; latest-name rename cases where MIN or MAX would be wrong.
  - Tampered page filters over Livewire and the URL; strict dates.
  - Widget access: `canView`, a direct-mount 403 for all 8 widgets, a cashier sees no figures, an inactive admin is refused.
  - Flat query counts.
  - The low-stock link really filters.
  - Year labels.
  - The reviewer's hand-computed dataset.
  - Mutation checks: void filter, timezone conversion, half-open end, the `mount`/`updated` repairs, `canView`/`canAccess`.
- **Tests executed:** full suite 751 passed / 2462 assertions; Pint passed. Guest `/admin/sales-reports` → 302.
- **Reviews:** combined spec + quality review. Spec ✅. All 10 hand-computed figure sets matched exactly. No Critical or Important issues. Minors applied; re-review ✅.
  - The reviewer committed and truncated rows in `k6_tindahan_testing` (testing DB only) to get real `ANALYZE` statistics. The suite passes afterwards.
- **For Phase 11:**
  - Panel-wide hardening. Other list pages still give a 500 on tampered `?filters=x` (Products, Categories, Users, InventoryMovements), and every list page on `?search[]=x` / `?sort[]=x`.
  - Proposed fix: one panel middleware in `AdminPanelProvider->middleware()` that drops non-array `filters` and array-valued `search`, `sort`, `tab` and `grouping`.
  - Verify the report query plans on committed data in Task 11.3.
- **Manual check still to do:** the plan's browser check of the dashboard and Sales Reports (Step 5).

---

## Phase 11 — Hardening, optimization and polish

Batches:
- **G:** Task 11.1, plus carried-over hardening.
- **H:** Tasks 11.2 and 11.3. These run against `k6_tindahan_testing`, per the owner decision above.
- **I:** Task 11.4, README.

### Batch G — Demo catalog seeder and hardening
- **Status:** Completed (2026-11-17).
- **Files created:**
  - `database/seeders/DemoCatalogSeeder.php`
  - `app/Http/Middleware/DropTamperedTableQuery.php`
  - `app/Observers/ProductObserver.php`
  - `tests/Feature/{DemoCatalogSeederTest,TamperedTableQueryTest,ProductImageCleanupTest}.php`
- **Files modified:**
  - `AdminPanelProvider.php`: registers the middleware panel-wide.
  - `Product.php`: `#[ObservedBy]`.
  - `ListTransactions.php` and `SalesReports.php`: per-page `$routeMiddleware` removed.
- **Deleted:** `app/Http/Middleware/DropNonArrayFilters.php`, replaced by `DropTamperedTableQuery`. The Phase 10 entries above still mention the old class.
- **Seeder:** the plan's code, plus these fixes:
  - `withTrashed()` on both lookups, with trashed products skipped (`continue`). Re-seeding after a soft delete no longer hits the unique index or re-attaches categories.
  - Opening stock is recorded only when `wasRecentlyCreated && $stock > 0`.
  - It looks up an active admin.
- **Middleware `DropTamperedTableQuery`:**
  - Registered on the panel only, not persistent, so it never runs on `/livewire/update`.
  - It only touches the query string. It drops:
    - a non-array `filters`;
    - `search`, `sort`, `tab`, `grouping`, `relation` and `reordering` when they are an array, or a string that JSON-decodes to an array. Livewire JSON-decodes query strings, so `?search=[1]` would otherwise 500.
- **Observer:** on `updated`, when `image_path` really changed, it deletes the old file. Safeguards:
  - Only on the public disk, under `products/`, with no `..`.
  - Not if any product, including trashed ones, still references the file.
  - Errors are passed to `report()`.
  - Nothing happens on soft delete. Force delete is disallowed by policy.
- **Tests:**
  - Seeder: counts, idempotency, re-run after a soft delete, no re-attach to a trashed product, attribution to the active admin.
  - Middleware: 72 tampered-URL cases across 5 list pages plus the product edit page, and legitimate search, sort and filters still applying.
  - Images: replace, clear, untouched, soft delete, shared path, outside `products/`, `..` path.
  - Red runs were seen for each fix.
- **Reviews:** combined spec + quality review. Spec ✅.
  - Quality round 1 found 2 Important issues: JSON-encoded arrays bypassed the middleware, and the `reordering`/`relation` keys were unguarded. Minors: property-name keys that are not URL keys, seeder re-attach, a missing active-admin test, observer error handling. All fixed.
  - The final fixes were verified by red/green tests; the controller re-ran the suite.
- **Tests executed:** full suite 839 passed / 2597 assertions; Pint passed.
- **Dev DB (Task 11.1 step 2, run by the controller):** `db:seed --class=DemoCatalogSeeder` on `k6_tindahan`. Result: 14 products, 2 low stock, 4 categories, 14 Initial movements, matching the plan.
- **Note:** lazy Livewire components read URL properties from the Referer header, which this middleware doesn't see. No lazy component has `#[Url]` today.

### Batch H — Concurrency and load checks (Tasks 11.2, 11.3)
- **Status:** Completed (2026-11-17). Verification only; no application code changed.
- **Database:**
  - All runs used `k6_tindahan_testing`, never the dev DB. Every artisan call used the guarded `DB_DATABASE=k6_tindahan_testing` form, and the scratchpad scripts abort unless `getDatabaseName() === 'k6_tindahan_testing'`.
  - The testing DB was `migrate:fresh`ed before and after. Afterwards it held 0 rows; the suite then passed: 839 tests, 2597 assertions.
- **Task 11.2: two real PHP processes against MySQL.** Both started from one bash line, using a sleep barrier so they began within 0–5 ms of each other.
  - **Last unit (stock 1):** 5 of 5 runs gave exactly one SOLD and one "REFUSED: Not enough stock … (only 0 left)". End state each time: `stock=0 movements=1 sales=1`. The winner varied.
  - **Same checkout token (stock 5):** 5 of 5 runs had both processes return the same transaction id. End state each time: stock 4, 1 movement, 1 item, 1 transaction.
  - **Double void:** 5 of 5 runs gave one VOIDED and one `TransactionAlreadyVoidedException`. Stock was back to 10 with exactly one `void_return`.
- **Task 11.3: volume**
  - **Seeded:** 2,014 products, 50,000 transactions, 99,892 items and 99,906 movements, every sale through `CheckoutService` (212 s). The data covered the last 365 days, two cashiers and 12 categories.
  - **Bug in the plan's seed script:** it calls `setTestNow(now()->subMinutes(...))` inside the loop, so once the clock is frozen the dates drift backwards instead of spreading over the year. The run captured a fixed base time instead.
  - **EXPLAIN** (after `ANALYZE TABLE`):
    - **The plan's four queries all use the expected index:**
      - today summary → `transactions_status_created_at_index` (range)
      - user history → `PRIMARY` (backward scan of 20 rows, allowed by the plan)
      - barcode → `products_barcode_unique` (const)
      - movements → `inventory_movements_product_id_created_at_index` (ref)
    - **Reports at 90 days:**
      - summary, salesByProduct and salesByCategory range-scan `(status, created_at)`; `transaction_items` is `ref` on the FK.
      - salesByDay and salesByCashier full-scan `transactions` (about 50k rows in about 9 ms). This is the optimizer's choice: it estimates about half the table, and `FORCE INDEX` was slower (18.5 ms vs 17.3 ms). Today and 30 days use the range scan.
      - **No index added.** Optional future addition: a covering `(status, created_at, user_id, total)`.
  - **Timing** (in-process, median of 5; the project rules forbid starting a server):
    - **Report service calls:** at most 2.5 ms for today, and 17–41 ms at 90 days.
    - **Queries:** POS search 2 ms; barcode 0.4 ms; history first page 2–3 ms.
    - **Full HTTP kernel renders** (`handle()` / whole process):
      - `/pos` 32 / 142 ms
      - `/admin` 54 / 160 ms
      - `/admin/sales-reports` 62 / 169 ms
      - `/admin/transactions` 102 / 218 ms
    - **Result:** everything is under the 1 s criterion.
- **Notes:**
  - **Skipped ids:** a duplicate-token loser rolls back its insert and uses up an auto-increment id, so transaction ids and reference numbers can occasionally skip a value. This is normal InnoDB behaviour.
  - **Full scans:** POS `%term%` search and the low-stock count scan `products`, at 2 ms with 2k products.

### Batch I — README (Task 11.4)
- **Status:** Completed (2026-11-17).
- **Files:** `README.md` replaced (it was the Laravel default).
- **Sections:**
  - **About the system:** intro with a pointer to `docs/`; requirements (PHP 8.3+, Composer, MySQL 8, **Node 24**, `nvm use`); first-time setup (`npm ci`, optional demo seeder).
  - **Accounts and access:** default accounts, with the instruction to change both passwords and the `.test` emails; URLs and roles.
  - **Hardware:** scanner setup (USB HID, Enter suffix); printer setup (roll width in Settings → Store Settings, no margins or headers; reprint from the POS Sales history or Sales → Transactions).
  - **Backups:** the plan's section, using the store's MySQL user instead of root; `-p` prompts for the password.
  - **Production settings:**
    - `APP_ENV=production`, `APP_DEBUG=false`, `BOOST_ENABLED=false` (verified in `vendor/laravel/boost/config/boost.php`).
    - A non-root MySQL user (`CREATE USER`/`GRANT` with a placeholder password); optional session hardening.
    - `composer install --no-dev --optimize-autoloader`, never `--no-scripts` (otherwise run `filament:upgrade`).
    - `migrate --force`, `optimize`, `filament:optimize`; never cache config on a dev machine.
  - **For developers:** running the tests (`*_testing` DB guard, no `--parallel`); developer notes (migration ordering; only the services write stock and sales; integer centavos).
  - **Before going live:** manual checks (scanner, thermal printer, touch screen, dashboard look, per `docs/pos-workflow.md` §10); "This is not an official receipt" (not BIR-accredited).
- **Controller corrections:** the settings and reprint labels match the real UI ("Store Settings" in the Settings group; the "Sales" group's Transactions); the backup commands use the `k6_tindahan` user.
- **Not done here (plan Step 2):** production commands were documented, not run; there is no production server.

### Final whole-system review and close-out
- **Verdict:** "Ready to hand over: yes".
- **Owner's 12 critical business rules:** each has enforcing code and a proving test (table in the review). Rules 7 and 8 were also proven with real two-process MySQL races in batch H.
- **Rule 12, auditability:** covered for inventory and transactions. Edits to product prices and to user roles/flags keep no history; the rule does not ask for it, but the owner should know.
- **Other checks:**
  - **Routes:** all 17 non-vendor routes have the right auth.
  - **Writes:** stock is written only in InventoryService. Sales are created only in CheckoutService, and movements only in InventoryService.
  - **Locking:** checkout and void lock in a consistent order.
  - **Clean code:** no unescaped output, no debug calls, no TODOs, no secrets staged. `.gitignore` covers the build and storage outputs.
- **Fixes applied after the review:**
  - **README:**
    - PHP **8.4.1+**.
    - `mysqldump --no-tablespaces`: a single-database user lacks the PROCESS privilege on MySQL 8.0.21+.
    - Run backups from the project folder; full paths to the MySQL tools if they are not on PATH.
    - **Settings → Users** label.
    - First-install production steps: `key:generate`, `storage:link`, `npm ci && npm run build`, `migrate --seed --force`. `optimize` already runs `filament:optimize`.
  - **Code** (TDD; red runs seen for the new behaviour):
    - `CreateProduct` has `$hasDatabaseTransactions = true`, so a product with opening stock is created atomically. If `adjust()` fails, no product and no movement remain; the user then gets an error page, not a notification, because Filament rethrows.
    - POS money inputs (price override, manual price, cash, discount) get `decimal:0,2`, and the live totals agree. `12.345` is now an error instead of a silently rounded ₱12.35.
    - Regression tests: stock and movements stay unchanged through cart edits and through failed checkouts.
- **Final verification (controller):**
  - Config not cached.
  - `php artisan test --compact`: **843 passed / 2651 assertions**.
  - Pint passed.
  - `npm run build` (Node 24): ✓.
  - `composer validate`: valid.
  - Guest HTTP: `/`, `/pos`, `/admin`, `/admin/products`, `/admin/transactions`, `/admin/sales-reports` and `/transactions/1/receipt` → 302; `/admin/login` → 200.
  - Dev DB `migrate:status`: 11 Ran.
- **Accepted minor items, not done:**
  - `AdminOnlyPolicy` ignores `is_active` (safe through the panel; the optional `Gate::before` remains).
  - Hard-coded `/admin` URLs, and duplicated role-landing logic between `routes/web.php` and `LoginResponse`.
  - `TransactionResource::parseFilterDate()` duplicates `ReportRange::parseDate()`.
  - Unused `resources/views/welcome.blade.php`, left in place.
  - Tampered typed Livewire scalars, such as POS `categoryId`, can still cause self-inflicted 500s.
  - The optional covering index `(status, created_at, user_id, total)`.
- **Still open for the owner:**
  - Database-level protection against deleting a sale. Recommended; the approved design is kept.
  - The optional `Gate::before` that denies inactive users.
  - Manual hardware and browser checks: USB scanner, 58/80 mm thermal printer, touch screen, a visual check of the dashboard and Sales Reports.
  - Production install, documented in the README but not run.
