# K6 Tindahan

A point-of-sale (POS) system for a sari-sari store.

- **Cashiers** use `/pos` to scan or search products, build a cart, take payment and print a receipt.
- **Admins** use `/admin` for the product catalog, inventory, sales history, reports, users and store settings.

The full design (data model, POS workflow, roadmap) is in the [`docs/`](docs) folder.

## Requirements

- PHP 8.4.1 or newer (tested on 8.5). The locked packages (Symfony 8.1, Pest 5) need 8.4.1; `composer install` fails on PHP 8.3.
- Composer
- MySQL 8
- Node 24 (pinned in `.nvmrc`)

Node 24 is required, not Node 20: Node 20 is end-of-life, and `composer dev` needs Node 22.13 or newer. Run `nvm use` before any `npm` command.

## First-time setup

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Edit `.env` and set the `DB_*` values for your MySQL database (the default database name is `k6_tindahan`). Create that empty database first, then:

```bash
php artisan migrate --seed
php artisan storage:link
nvm use && npm ci && npm run build
```

`npm ci` installs exactly what the committed lockfile lists.

Optional demo products and categories:

```bash
php artisan db:seed --class=DemoCatalogSeeder
```

## Default accounts

`DatabaseSeeder` creates two users:

| Role    | Email                     | Password   |
|---------|---------------------------|------------|
| Admin   | `admin@k6tindahan.test`   | `password` |
| Cashier | `cashier@k6tindahan.test` | `password` |

**Change both passwords immediately after installing.** Also change both emails: `.test` addresses cannot receive password-reset mail. If you do not need the cashier account, deactivate it instead. Manage users in the admin panel under **Settings → Users**.

Re-running the seeder never overwrites existing users.

## URLs and roles

| URL            | Purpose                  |
|----------------|--------------------------|
| `/pos`         | Point of sale (cashiers) |
| `/admin`       | Back office (admins)     |
| `/admin/login` | Login page               |

- After login, cashiers land on `/pos` and admins land on `/admin`.
- Cashiers see only their own sales.
- Permission to override a price and to give a discount is set per cashier in **Users**.

## Scanner setup

Use a USB HID barcode scanner in keyboard mode, with the suffix set to Enter. The barcode field on `/pos` keeps focus, so scanning needs no clicking.

## Printer setup

Receipts print from the browser's print dialog.

- Paper size: the same as the roll width, 58 mm or 80 mm. Choose the width in **Settings → Store Settings** in the admin panel.
- Margins: none.
- Headers and footers: off.

You can reprint a receipt right after a sale, from **Sales history** on the POS screen, or from **Sales → Transactions** in the admin panel.

## Backups

The whole business lives in one MySQL database and the `storage/app/public` folder (product images).

Back up daily, from the project folder (the image paths are relative):

    mysqldump --single-transaction --no-tablespaces -u k6_tindahan -p k6_tindahan > backup-$(date +%F).sql
    tar czf images-$(date +%F).tar.gz storage/app/public

Restore, from the project folder:

    mysql -u k6_tindahan -p k6_tindahan < backup-2026-11-16.sql
    tar xzf images-2026-11-16.tar.gz

`--no-tablespaces` is needed because a user with rights on one database only lacks the `PROCESS` privilege that `mysqldump` otherwise asks for (MySQL 8.0.21+). If `mysqldump` or `mysql` is not found, use its full path (for example inside your MySQL or Herd installation).

Copy the backup files off this computer (USB drive or cloud folder). A backup on the same disk does not survive a dead disk.

`-p` prompts for the password, so never put the password on the command line.

## Production settings

In the store's `.env`:

```env
APP_ENV=production
APP_DEBUG=false
BOOST_ENABLED=false
```

- `BOOST_ENABLED=false`: Laravel Boost is a development tool and activates whenever `APP_DEBUG=true`.
- Use a non-root MySQL user with rights on the store database only (replace the placeholder password):

  ```sql
  CREATE USER 'k6_tindahan'@'localhost' IDENTIFIED BY 'choose-a-strong-password';
  GRANT ALL PRIVILEGES ON k6_tindahan.* TO 'k6_tindahan'@'localhost';
  ```

  Then set `DB_USERNAME` and `DB_PASSWORD` in `.env` to match.
- Optional: `SESSION_EXPIRE_ON_CLOSE=true` on a shared till, and `SESSION_SECURE_COOKIE=true` once the site is served over HTTPS.

Install and optimize:

```bash
composer install --no-dev --optimize-autoloader
php artisan key:generate            # first install only
php artisan migrate --force         # first install: php artisan migrate --seed --force
php artisan storage:link            # first install only
nvm use && npm ci && npm run build  # public/build is not in the repository
php artisan optimize                # also runs filament:optimize
```

Re-run `php artisan optimize` after every update (it caches config and routes; `php artisan optimize:clear` undoes it).

Never use `--no-scripts` with Composer: the scripts republish Filament's assets. If scripts were skipped, run `php artisan filament:upgrade`.

Never run `config:cache` (or `optimize`) on a development machine. The test suite refuses to run if config is cached and points at a non-testing database. If that happens, run `php artisan config:clear`.

## Running the tests (developers)

Tests need a MySQL database named `<db>_testing`, for example `k6_tindahan_testing`. `phpunit.xml` forces it, and a guard refuses any database whose name does not end in `_testing`.

```bash
php artisan test --compact
vendor/bin/pint --dirty --format agent
```

`--parallel` is not supported: the guard refuses it on purpose.

## Developer notes

- New migrations must sort after `2026_11_17_000008`.
- Stock changes go only through `InventoryService`, and sales only through `CheckoutService`.
- Money is held in integer centavos.

## Manual checks before going live

The automated tests cannot cover these. Do them on the store's real hardware:

- A real USB scanner: fast back-to-back scans, and focus returning to the barcode field.
- A real thermal printer at both widths (58 mm and 80 mm).
- The touch screen.
- A browser look at the dashboard and Sales Reports.

The full checklist is in `docs/pos-workflow.md`, section 10.

## Not an official receipt

The printed receipt says "This is not an official receipt." This system is not BIR-accredited.
