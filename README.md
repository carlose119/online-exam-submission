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

- PHP 8.4.1 or newer for the current lockfile, Composer 2, and Laravel 13
- Filament 5 and Livewire 4
- MariaDB 10.11 in concurrency CI; MariaDB/MySQL connections for development and production data
- SQLite in memory for the normal test suite
- Vite, Tailwind CSS, Alpine.js, and Node.js 22 with npm in CI
- Pest 4 and Laravel Pint

## Installation

### Prerequisites

Install these project-supported tools before resolving dependencies:

| Tool | Supported baseline |
|---|---|
| PHP | 8.4.1 or newer. `composer.json` permits `^8.3`, but packages in the current `composer.lock` require 8.4.1. CI uses PHP 8.4. |
| PHP extensions | `ctype`, `curl`, `dom`, `fileinfo`, `filter`, `gd`, `hash`, `intl`, `json`, `mbstring`, `openssl`, `pdo`, `session`, `tokenizer`, `xml`, and `zip`; add `pdo_mysql` for MariaDB/MySQL or `pdo_sqlite` for SQLite and normal tests. |
| Composer | Composer 2, matching CI. |
| Node.js | Node.js 22 with npm, matching CI. |
| Database | MariaDB 10.11 is CI-verified for concurrency behavior. The application also defines MySQL and SQLite connections; use the PDO extension for the selected engine. |

### Clone and install

1. Clone the repository and install locked backend and frontend dependencies:

   ```bash
   git clone https://github.com/carlose119/online-exam-submission.git
   cd online-exam-submission
   composer install
   npm ci
   ```

2. Create the local environment file and generate an application key:

   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

3. Create an empty local database and configure `.env` with generic, environment-specific values. Do not commit `.env` or reuse a production database.

   ```dotenv
   APP_ENV=local
   APP_URL=http://localhost:8000

   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=online_exam_submission
   DB_USERNAME=app_user
   DB_PASSWORD=replace-with-a-local-password

   QUEUE_CONNECTION=database
   MAIL_MAILER=log
   ```

   Laravel's `mysql` connection supports both MariaDB and MySQL. Alternatively, use `sqlite` with an existing writable `DB_DATABASE` file. Important optional controls include `REPORTS_SYNC_THRESHOLD`, `STUDY_MATERIALS_TEACHER_QUOTA_BYTES`, and `STUDY_MATERIALS_MAX_UPLOAD_KILOBYTES`. `MAIL_MAILER=log` is an explicit local-only, non-delivery choice; never treat its log output as external delivery.

4. Create the schema and development administrator, expose public uploads, and build frontend assets:

   ```bash
   php artisan migrate --seed
   php artisan storage:link
   npm run build
   ```

5. Start the local development processes:

   ```bash
   composer run dev
   ```

   This Composer script runs the Laravel server, a database queue listener, Pail logs, and the Vite development server together. The application is normally available at `http://localhost:8000`; the Filament panel is at `http://localhost:8000/admin`.

For a production-like local run, use `npm run build` and serve Laravel without the Vite development server. Run `php artisan queue:work` as a separately supervised process when `QUEUE_CONNECTION` is asynchronous; otherwise larger report exports remain pending. Deployment must provide a writable `storage` tree, the public storage link, migrated database, built `public/build` assets, and correctly configured `APP_URL`.

### Production mail operations

Use generic SMTP credentials supplied by the deployment secret store. Production must use an HTTPS public application URL and a from address on a domain the organization controls:

```dotenv
APP_URL=https://exams.example.org
MAIL_MAILER=failover
MAIL_FAILOVER_MAILERS=smtp
MAIL_SCHEME=smtp
MAIL_HOST=smtp.example.net
MAIL_PORT=587
MAIL_USERNAME=replace-in-secret-store
MAIL_PASSWORD=replace-in-secret-store
MAIL_EHLO_DOMAIN=exams.example.org
MAIL_FROM_ADDRESS=no-reply@example.org
MAIL_FROM_NAME="${APP_NAME}"
```

Port `587` with `MAIL_SCHEME=smtp` is the usual submission setup when the server advertises STARTTLS. Use `MAIL_SCHEME=smtps` with port `465` only when the SMTP service requires implicit TLS. Follow the SMTP operator's requirements; do not disable certificate validation. An optional independent SMTP endpoint can use `MAIL_BACKUP_*` and `MAIL_FAILOVER_MAILERS=smtp,smtp_backup`. The failover list rejects `log`, `array`, unknown, or empty entries so an apparent success cannot silently become local logging.

Before production delivery, publish and validate SPF, DKIM, and DMARC for the from domain outside this application. After any mail configuration change, run `php artisan config:cache` and restart every queue worker with `php artisan queue:restart` so long-lived processes load the new values.

Smoke-test with a dedicated non-sensitive message and test mailbox. Confirm only the metadata events `mail.sending` and `mail.sent` appear, with configured mailer and recipient count; never print a recipient, subject, body, signed URL, verification hash, reset token, credential, or serialized notification payload. `mail.sent` means the configured transport accepted the message, not that an inbox received it. Confirm inbox placement separately without exposing message content.

Restrict application-log and failed-job access to operational staff, apply retention controls, and treat both stores as sensitive even though mail operations add metadata only. Investigate `mail.notification_failed`, `mail.job_failed`, and `notification.job_failed` by exception class and queue metadata; inspect message content or serialized job payloads only through an approved incident process.

### Seeded Administrator

`AdminUserSeeder` intentionally creates a local administrator when the database is seeded:

| Field | Development default |
|---|---|
| Email | `admin@example.com` |
| Password | `password` |

Set `ADMIN_EMAIL` and `ADMIN_PASSWORD` before seeding to override these values. Never use the development defaults in a deployed environment.

Students create their own accounts at `/register`. Teacher accounts are managed by an administrator in Filament.

## System Usage

The core dependency is the class: an administrator creates a teacher account, the teacher creates a class, and only then can the teacher attach invitations, materials, exams, or meetings. Students must join that class before its protected dashboard, exam, and calendar actions are available.

### Administrator

1. Sign in at `/admin` with an `ADMIN` account.
2. Open **Teachers** to create or edit teacher accounts, suspend/reactivate access, generate a one-time visible temporary password, or delete an account.
3. Open **Reports** to view report summaries across all classes and export class performance as PDF or Excel. Reports show exam, student, and attempt counts; large exports require the queue worker.

Administrators do not use public registration to create teachers. Student registration always assigns the `STUDENT` role, while the Teachers resource is restricted to administrators.

### Teacher

1. Sign in at `/admin` with the account created by an administrator.
2. Create a class first. Share its generated invitation URL/code with students; teacher-facing class, material, exam, and report queries are scoped to owned classes.
3. Add **Study Materials** as a `FILE`, `LINK`, or `MEETING` reference. Material storage usage appears above the material list.
4. Create an exam for one owned class, then add ordered single-choice or multiple-choice questions, at least two answer options per question, correct-option flags, points, duration, and maximum score.
5. Create meetings for a class with a schedule, duration, optional URL and agenda. Recurrence supports weekly, biweekly, or monthly materialized instances, up to 52 instances from the creation form.
6. Open **Reports** for an owned class to review performance and download PDF or Excel output.

The `MEETING` study-material type is a dated link shown with class materials; the separate **Meetings** resource provides scheduled meetings, recurrence, and student calendar export.

### Student

1. Register at `/register` or sign in at `/login`. Self-registration always creates a `STUDENT` account.
2. Open a teacher's invitation URL and join while authenticated. Joining is idempotent, and the class then appears on `/dashboard`.
3. Use the dashboard to review joined classes and their materials, exams, and meetings. `/profile` displays the student's read-only account and enrollment information.
4. Start an exam only after joining its class. Each student receives one attempt per exam; the server enforces the timer, grades submission automatically, and exposes only that student's result.
5. Download an individual meeting as `.ics`, or copy the private aggregate calendar subscription URL from the dashboard. Regenerate the feed token if the URL is exposed.

### Material uploads

- `FILE` accepts PDF, DOCX, XLSX, and MP4 uploads on the public disk. `php artisan storage:link` must expose `storage/app/public` through `public/storage`.
- Each file is limited to 50 MB by default. Web server and PHP upload limits must be at least as large.
- All distinct active `FILE` paths across classes owned by one teacher count toward a 5 GB aggregate quota by default. `LINK` and `MEETING` URLs do not consume file quota.
- The material-list heading shows used, limit, and remaining storage. An upload that would exceed the quota is rejected before permanent storage with the current usage summary.
- Replacing, switching, or deleting a `FILE` material removes its obsolete managed file after the database commit when no active material still references it.

### Material file reconciliation

Use the operator command to find historical files and leftovers from database class cascades beneath the configured managed prefix. It never scans or deletes outside that boundary, and active `FILE` references are always preserved.

```bash
php artisan materials:reconcile
php artisan materials:reconcile --delete
```

The default dry-run reports exact scanned, active, orphaned, deleted, skipped, and failed counts without changing files or printing managed paths. Review it first, then add `--delete`; production deletion also requires explicit `--force`. A scan or deletion failure returns a non-zero exit code for monitoring and automation.

## Verification

Run the same core checks used by the primary CI workflow:

```bash
npm run build
vendor/bin/pint --test
vendor/bin/pest --configuration=phpunit.xml
```

The normal Pest configuration uses SQLite `:memory:` and does not require MariaDB. The latest local full-suite result is **302 tests and 981 assertions**.

For a fresh installation, also confirm the application can boot and the schema is current:

```bash
php artisan about
php artisan migrate:status
```

### CI Checks

| Workflow | Required check/job | Purpose |
|---|---|---|
| [`.github/workflows/quality.yml`](.github/workflows/quality.yml) | `PHP quality` | Composer validation/install, generated Filament asset drift, Pint, and the normal Pest suite. |
| [`.github/workflows/quality.yml`](.github/workflows/quality.yml) | `Frontend build` | Reproducible npm install and Vite production build. |
| [`.github/workflows/database-concurrency.yml`](.github/workflows/database-concurrency.yml) | `mariadb-concurrency` | Isolated locking and race-condition checks against MariaDB 10.11/InnoDB. |
| [`.github/workflows/security.yml`](.github/workflows/security.yml) | `PHP static analysis` | Larastan/PHPStan analysis of `app/` at level 1. |
| [`.github/workflows/security.yml`](.github/workflows/security.yml) | `Dependency vulnerability audit` | Composer and npm lockfile advisory checks. |

## Security and Dependency Maintenance

Dependabot checks Composer, npm, and GitHub Actions every Monday at 06:00 UTC. Compatible minor and patch updates are grouped per ecosystem, each ecosystem is capped at three open pull requests, and updates are never merged automatically. The `Security` workflow runs on pull requests, manually, and every Monday at 09:00 UTC so newly disclosed dependency advisories are checked without a code change.

Run the same checks locally:

```bash
composer analyse
composer audit --locked --no-interaction
npm audit --package-lock-only --audit-level=high
```

Larastan analyzes `app/` at PHPStan level 1 without a baseline or broad exclusions. Level 1 is the highest currently clean ratchet: fix new findings rather than suppressing them, and raise the level when existing code passes the next level. Composer advisories and high or critical npm advisories fail CI; lower-severity npm findings remain visible and must be triaged for reachability, available fixes, and deployment impact.

Maintainers should prioritize exploitable production findings, update the narrowest affected dependency set, and run the normal quality checks before merging. Record and periodically revisit any deferred remediation; do not add an ignore or advisory exception without a concrete, documented reason and review date.

GitHub CodeQL does not support PHP, so this repository uses Larastan/PHPStan for PHP static analysis instead of claiming a nonfunctional CodeQL PHP check. These checks do not perform penetration testing, production monitoring, automatic dependency merging, or complete vulnerability detection.

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

## Troubleshooting

| Symptom | Current fix |
|---|---|
| Vite manifest missing or pages have no compiled assets | Run `npm ci` and `npm run build`. During development, keep `npm run dev` running, or use `composer run dev` for the full process set. Deploy `public/build` with the application. |
| Public material URL returns 404 | Run `php artisan storage:link`, confirm `public/storage` targets `storage/app/public`, and verify the referenced file exists. Use `php artisan materials:reconcile` to inspect orphan counts; reconciliation does not restore missing active files. |
| Database connection, missing-table, or migration error | Recheck `DB_CONNECTION` and `DB_*`, create the selected database/file, then run `php artisan migrate:status` and `php artisan migrate --seed`. Never troubleshoot against production data. |
| A large PDF/Excel report stays queued | Start `php artisan queue:work`, verify `QUEUE_CONNECTION` and the `jobs` migration, and inspect failed jobs/logs. Reports at or above `REPORTS_SYNC_THRESHOLD` attempts are asynchronous. |
| Password-reset or application email is not delivered | `MAIL_MAILER=log` is local non-delivery only. Configure SMTP, rebuild the config cache, restart queue workers, and distinguish transport acceptance from inbox placement. Never expose tokens or message content while troubleshooting. |
| A route or Filament resource returns 403 or is hidden | Use the intended role and ownership boundary: students use web routes, administrators/teachers use `/admin`, teachers only manage owned class data, and students must join a class before protected exam/calendar access. Do not bypass authorization by changing role values manually. |

## SDD Artifacts

Most completed changes are archived under [`openspec/changes/archive`](openspec/changes/archive), and their consolidated specifications are under [`openspec/specs`](openspec/specs).

The calendar subscription implementation is represented by [`openspec/changes/calendar-integration`](openspec/changes/calendar-integration). Its implementation tasks are complete, but final SDD verification and archive are still blocked and pending. The active artifacts must not be treated as a successful verify report or archived change.

## License

This project is available under the [MIT License](LICENSE), consistently declared in [`composer.json`](composer.json).
