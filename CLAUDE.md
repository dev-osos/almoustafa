# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**Al-Mostafa** (المصطفى) — an integrated company management system (ERP) for a honey production/sales business in Egypt. Built as a PHP web application with MySQL, deployed to shared hosting (Thamara Cloud) via FTP. Supports PWA with Arabic-first UI.

The system manages: sales (retail & wholesale), production (batch tracking, barcode), inventory (multiple warehouses including vehicle stock), accounting (invoices, collections, salaries), attendance, and an internal chat system.

## Repository Structure

All application code lives under `almostafa/`. The root directory only contains this subdirectory.

- `almostafa/index.php` — Login page and main entry point (~167K lines, monolithic)
- `almostafa/includes/` — Core PHP libraries:
  - `config.php` — DB credentials, timezone (Africa/Cairo), currency (EGP), constants
  - `db.php` — `Database` singleton class (mysqli), used everywhere
  - `auth.php` — Session management, role checking
  - `csrf_protection.php`, `input_validation.php`, `security_config.php` — Security layer
  - `lang/ar.php`, `lang/en.php` — i18n strings (Arabic primary)
- `almostafa/api/` — PHP API endpoints (AJAX handlers), one file per action
- `almostafa/dashboard/` — Role-based dashboard pages: `manager.php`, `accountant.php`, `sales.php`, `production.php`, `driver.php`
- `almostafa/modules/` — Feature modules by role: `manager/`, `accountant/`, `sales/`, `production/`, `driver/`, `warehouse/`, `chat/`, `shared/`, `user/`
- `almostafa/assets/js/` — Frontend JavaScript (vanilla JS, no framework)
- `almostafa/cron/` — Scheduled tasks (backups, attendance cleanup, payment reminders)
- `almostafa/database/` — SQL schema and migrations
- `almostafa/reader/` — Separate sub-app with its own manifest/service-worker

## Key Architecture Details

- **No framework** — raw PHP with mysqli. No Composer, no autoloader.
- **Direct access guard** — most included files check `defined('ACCESS_ALLOWED')` before executing.
- **Database** — MySQL via `Database` singleton (`Database::getInstance()->getConnection()`). DB name: `albarakah_db_v2`.
- **User roles** — manager, accountant, sales, production, driver. Role determines which dashboard and modules are accessible.
- **Deployment** — push to `main` triggers GitHub Actions workflow (`.github/workflows/debloy.yml`) which FTP-deploys to server at `/httpdocs/`. FTP password stored in `secrets.FTP_PASSWORD`.
- **PWA** — `manifest.json` is rewritten to `manifest.php` via `.htaccess`. Service worker at `almostafa/assets/js/` handles offline support.
- **Notifications** — Telegram bot integration + browser notifications + in-app polling (`api/unified_polling.php`).

## Development Notes

- No build step, test suite, or linter configured. PHP files are edited and deployed directly.
- The `.htaccess` handles HTTPS redirection, manifest rewriting, and API routing.
- Currency is Egyptian Pound (EGP / ج.م). Timezone is Africa/Cairo.
- Bilingual support (Arabic/English) via `includes/lang/` files, Arabic is default.
- Version tracked in `almostafa/version.json` (currently 1.5.1).
      