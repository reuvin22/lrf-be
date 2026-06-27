# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands

```bash
# Full project setup (install deps, generate key, migrate, build assets)
composer setup

# Start dev servers concurrently (Laravel :8000, queue listener, Vite HMR)
composer dev

# Run tests (Pest)
composer test

# Run a single test file
php artisan test --filter=TestClassName

# Code style fixer
./vendor/bin/pint
```

## Architecture

**Laravel 12 REST API** for a construction site labor management system. The app tracks employees, subcontractor workers, construction sites, attendance, and OCR-processed documents.

**Stack:** Laravel 12 (PHP 8.2+), PostgreSQL, Laravel Sanctum (token auth), Pusher (real-time), Firebase Storage + Firestore.

**All application routes live under `/api/v1/`** in `routes/api.php` as RESTful resources. There is no frontend served by this app (Vite/Tailwind are present but unused in production logic). The welcome route at `/` is the only web route.

**Controllers** are versioned under `app/Http/Controllers/v1/`. Each controller maps directly to one model/domain. Form validation lives in `app/Http/Requests/v1/`. API response shaping uses `app/Http/Resources/v1/` (partially — some controllers return raw `response()->json()` instead).

**Key domain models:**
- `Employees` — internal staff with salary, role, LINE ID, and attendance history
- `SubContractors` / `SubContractorsWorkers` — external contractors and their workers
- `Attendance` / `AttendanceEmployee` / `AttendanceSubSegments` — daily work records
- `Segments` — work divisions within an attendance record
- `OcrUploads` / `OcrUploadCategory` — document digitization pipeline
- `TransportationExpenses` — expense tracking per attendance record
- `SiteAssignments` / `SiteSubContractors` — employee-to-site and contractor-to-site mappings

**Real-time events** are dispatched via `AttendanceEvent` and `SegmentEvent` (in `app/Events/`) using Pusher broadcasting.

**Queue** is set to `sync` driver in `.env` (runs inline, not deferred).

**Testing** uses Pest (`tests/Feature/`, `tests/Unit/`). The `phpunit.xml` config uses an in-memory SQLite database for tests.
