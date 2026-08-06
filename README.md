# Online Exam Submission

Online Exam Submission is an LMS-lite application for managing classes, learning materials, exams, reports, and live meetings. Administrators and teachers work through a Filament panel, while students register, join classes, and complete exams through the Laravel and Livewire application.

## Capabilities

| Area | Current behavior |
|---|---|
| Administration | Manage teacher accounts and access the shared Filament panel at `/admin`. |
| Classes and materials | Teachers manage their own classes, invitation links, and file, link, or meeting materials. |
| Exams | Teachers build single- and multiple-choice exams; students receive one attempt with server-enforced timing and automatic grading. |
| Reports | Teachers and administrators view class performance and export PDF or Excel reports. |
| Student experience | Students register, join multiple classes, use a dashboard, view a read-only profile, take exams, and review scores. |
| Live meetings | Teachers schedule one-off, weekly, biweekly, or monthly meetings; recurring instances are materialized as individual records. |
| Calendars | Subscribed students can download one meeting as `.ics` or use a private aggregate calendar subscription feed. |

## Stack

- PHP 8.3 or newer and Laravel 13
- Filament 5 and Livewire 4
- MariaDB/MySQL for development and production data
- SQLite in memory for the normal test suite
- Vite, Tailwind CSS, Alpine.js, and Node.js 22 in CI
- Pest 4 and Laravel Pint

## Quick Start

### Prerequisites

Install PHP with the extensions required by Laravel and the selected database, Composer 2, Node.js with npm, and MariaDB/MySQL if you are not using another supported local database.

### Install

1. Install backend and frontend dependencies:

   ```bash
   composer install
   npm ci
   ```

2. Create the local environment file and application key:

   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

3. Create a local database, then set the `DB_*` values in `.env`. Do not reuse a production database.

4. Prepare the application and frontend assets:

   ```bash
   php artisan migrate --seed
   php artisan storage:link
   npm run build
   ```

5. Start the local development processes:

   ```bash
   composer run dev
   ```

The application is normally available at `http://localhost:8000`; the Filament panel is at `http://localhost:8000/admin`.

### Seeded Administrator

`AdminUserSeeder` intentionally creates a local administrator when the database is seeded:

| Field | Development default |
|---|---|
| Email | `admin@example.com` |
| Password | `password` |

Set `ADMIN_EMAIL` and `ADMIN_PASSWORD` before seeding to override these values. Never use the development defaults in a deployed environment.

Students create their own accounts at `/register`. Teacher accounts are managed by an administrator in Filament.

## Verification

Run the same core checks used by the primary CI workflow:

```bash
npm run build
vendor/bin/pint --test
vendor/bin/pest --configuration=phpunit.xml
```

The normal Pest configuration uses SQLite `:memory:` and does not require MariaDB. The latest local full-suite result is **252 tests and 810 assertions**.

### CI Checks

| Workflow | Required check/job | Purpose |
|---|---|---|
| [`.github/workflows/quality.yml`](.github/workflows/quality.yml) | `PHP quality` | Composer validation/install, generated Filament asset drift, Pint, and the normal Pest suite. |
| [`.github/workflows/quality.yml`](.github/workflows/quality.yml) | `Frontend build` | Reproducible npm install and Vite production build. |
| [`.github/workflows/database-concurrency.yml`](.github/workflows/database-concurrency.yml) | `mariadb-concurrency` | Isolated locking and race-condition checks against MariaDB 10.11/InnoDB. |

### MariaDB Concurrency Suite

The concurrency suite is separate because SQLite cannot prove real database locking behavior:

```bash
vendor/bin/pest --configuration=phpunit.concurrency.xml
```

This command is configured to use `online_exam_submission_concurrency`. Create that database and supply the local `DB_HOST`, `DB_PORT`, `DB_USERNAME`, and `DB_PASSWORD` environment values before running it.

**Use only a disposable database. Never point this suite at the normal development, staging, or production database.** The suite applies and rolls back migrations, must not run in parallel against one database, and may require the MariaDB user to read InnoDB transaction/lock metadata. CI provides an ephemeral MariaDB service for this job.

## Architecture Map

| Path | Responsibility |
|---|---|
| [`app/Filament/Resources`](app/Filament/Resources) | Administrator and teacher resources for teachers, classes, materials, exams, meetings, and reports. |
| [`app/Livewire`](app/Livewire) | Student dashboard, profile, exam start/take/result flow, and calendar subscription controls. |
| [`app/Http/Controllers`](app/Http/Controllers) | Authentication-adjacent actions, class joining, exam submissions, reports, and calendar responses. |
| [`app/Services`](app/Services) | Exam access, attempt creation, answer persistence, grading, reports, and iCalendar serialization. |
| [`app/Models`](app/Models) | Eloquent domain model, relationships, and recurring-meeting materialization. |
| [`routes/web.php`](routes/web.php) | Public, student, report-download, and calendar routes. |
| [`database/migrations`](database/migrations) | Relational schema and database constraints. |
| [`tests/Feature`](tests/Feature) | Normal application behavior against in-memory SQLite. |
| [`tests/Concurrency`](tests/Concurrency) | MariaDB-specific concurrency behavior. |
| [`openspec/specs`](openspec/specs) | Canonical specifications for archived, completed changes. |
| [`openspec/changes`](openspec/changes) | Active change artifacts and the archive of prior changes. |

The application uses one `User` model with role-based boundaries. Students use the standard web authentication flow; administrators and teachers use Filament. Database constraints and server-side guards enforce class ownership, subscriptions, attempt uniqueness, timer expiry, and report access.

## Operational Caveats

- Calendar subscription URLs are bearer credentials. Anyone with a URL can read that student's meeting metadata. Regenerate a compromised token from the student dashboard and replace the URL in every calendar client.
- Calendar providers control polling frequency. Feed changes may not appear immediately even though the response disables caching.
- Recurring meetings are stored as materialized events, not exported as an RFC 5545 `RRULE` series.
- Reports with at least `REPORTS_SYNC_THRESHOLD` attempts, 100 by default, are queued. Keep a queue worker running in environments that generate larger reports; `composer run dev` starts one locally.
- Uploaded public materials require `php artisan storage:link`. Server upload limits must accommodate the application's maximum material size.
- The single-meeting `.ics` endpoint requires an authenticated student subscribed to the meeting's class. The aggregate feed is public only through its opaque token.

## SDD Artifacts

Most completed changes are archived under [`openspec/changes/archive`](openspec/changes/archive), and their consolidated specifications are under [`openspec/specs`](openspec/specs).

The calendar subscription implementation is represented by [`openspec/changes/calendar-integration`](openspec/changes/calendar-integration). Its implementation tasks are complete, but final SDD verification and archive are still blocked and pending. The active artifacts must not be treated as a successful verify report or archived change.

## License

This project declares the MIT license in [`composer.json`](composer.json).
