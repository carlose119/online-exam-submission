# Online Exam Submission (LMS-Lite)

Greenfield Learning Management System built with Laravel 13, Filament v5, and Livewire v4.
Admin manages Teacher accounts; Students register and take exams via Livewire pages (future changes).

## Tech Stack

| Layer | Technology | Version |
|-------|-----------|---------|
| Framework | Laravel | 13.19.0 |
| Admin Panel | Filament | 5.6.8 |
| Frontend reactivity | Livewire | 4.3.3 |
| JS framework | Alpine.js | ^3 (bundled by Filament) |
| Database | MariaDB | 10.11.9 (Laragon default) |
| PHP | PHP | 8.4.4 |
| Test runner | Pest | 4.7.5 |
| Composer | Composer | 2.8.11 |

## Quick Start (Laragon on Windows)

1. Clone the repo into `C:\laragon\www\online-exam-submission`.
2. Copy `.env.example` to `.env` and verify the MariaDB credentials:
   ```
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=online_exam_submission
   DB_USERNAME=root
   DB_PASSWORD=
   ```
3. Create the database in Laragon's HeidiSQL or via `mysql -u root -e "CREATE DATABASE IF NOT EXISTS online_exam_submission"`.
4. Run setup:
   ```bash
   composer install
   php artisan key:generate
   php artisan storage:link
   php artisan migrate:fresh --seed
   ```
5. Start the development server:
   ```bash
   php artisan serve
   ```
6. Open `http://localhost:8000/admin` and log in as the seeded admin.

### Default Admin Credentials

| Field | Value |
|-------|-------|
| Email | `admin@example.com` |
| Password | `password` |

Override via `.env`: `ADMIN_EMAIL` and `ADMIN_PASSWORD`. These are read by `AdminUserSeeder`, which is idempotent — safe to run multiple times.

## MariaDB ↔ MySQL Compatibility

This project connects to **MariaDB 10.11** using Laravel's `mysql` driver (`DB_CONNECTION=mysql`).
Laragon ships MariaDB and exposes it on port 3306 as a drop-in MySQL replacement.

### What works

- **DDL**: migrations, indexes, unique constraints, enums — all standard SQL supported by both engines.
- **DML**: `INSERT`, `UPDATE`, `DELETE`, `SELECT` with joins, subqueries, and window functions.
- **Eloquent features**: JSON column casts (`$casts = ['meta' => 'json']`), full-text search via `whereFullText()`, timestamps, and the query builder.
- **Laravel features**: migrations, seeders, factories, RefreshDatabase trait (uses SQLite `:memory:` for tests — no MariaDB needed).

### Caveats

- If future changes add **generated columns** (`STORED` / `VIRTUAL`), verify the syntax against the MariaDB version — MariaDB and MySQL 8.0+ diverge slightly on computed column syntax.
- Full-text search parsers differ. For cross-engine compatibility, use Laravel's `whereFullText()` abstraction rather than raw `MATCH ... AGAINST` queries.
- `utf8mb4_unicode_ci` collation is used by default and works identically on both engines.

## Livewire v4 Drift Note

The PRD originally specified **Livewire v3+**, but **Filament v5.6.8 hard-requires Livewire v4.3.3**.
Composer resolved Livewire v4 during `composer require filament/filament:^5.6`.

This is a **stack drift**, not a bug:

- All code written against Livewire v4 APIs (the v4 upgrade is source-compatible for the features we use).
- The PRD will be updated to reflect "Livewire v4+" in a follow-up change.
- No migration or rollback is needed — this is the standard Filament v5 dependency chain.

## Teacher Classes & Invitation Flow

Teachers can manage their classes via the Filament `/admin/classes` panel (auto-discovered resource).

### CRUD

- **List** — teachers see only their own classes (`teacher_id = Auth::id()` query scope).
- **Create** — an 8-character `invitation_code` is auto-generated via `Str::random(8)` with a retry-on-collision loop (max 5 attempts).
- **Edit** — title, description, and syllabus (RichEditor, WYSIWYG) are editable.
- **Delete** — removes the class. FK is `restrict` (no cascade — the `class_user` pivot is deferred).

### Invitation Code & Link

- Each class has a unique `invitation_code` (12-char unique column, currently storing 8-char values).
- **Copy invitation link** action on the edit page copies the full URL `https://{host}/clase/unirse/{invitation_code}` to the clipboard and shows a persistent notification.
- **Regenerate invitation code** action replaces the existing code with a new unique one.
- The table column is `copyable()` (Badge style).

### Public Join Route

`GET /clase/unirse/{invitation_code}` renders a minimal Blade view (`resources/views/class/join.blade.php`) showing the class title, description, and syllabus (raw HTML).

- **Guests** see a "Log in to join" link targeting the Filament admin login page.
- **Authenticated users** see a "TBD: join this class" placeholder button. No `class_user` pivot record is created — student subscription is deferred to a future change.

### Future: Student Subscription

The `class_user` pivot table and actual join logic are **not implemented** in this slice. The public route and TBD placeholder are honest affordances that unblock the Student module once it lands. See the delta specs:

- [teacher-class-management](openspec/changes/teacher-module/specs/teacher-class-management/spec.md)
- [class-invitation-flow](openspec/changes/teacher-module/specs/class-invitation-flow/spec.md)

## Teacher Materials: Files, Links, and Meetings

Teachers can attach study materials to their classes via the Filament `/admin/study-materials` panel. Materials are visible to anyone with the class invitation link — no authentication required for viewing.

### Material Types

| Type | Description | What Visitors See |
|------|-----------|-------------------|
| **FILE** | Uploaded document (PDF, DOCX, XLSX, MP4) | Title as a download link |
| **LINK** | External URL | YouTube links embed as responsive iframes; all other URLs render as plain anchor links (`target="_blank" rel="noopener"`) |
| **MEETING** | Live meeting (Google Meet, Zoom, etc.) | Title, formatted date/time, and a "Join meeting" button |

### File Upload Limits

- **Accepted MIME types**: `application/pdf`, `application/vnd.openxmlformats-officedocument.wordprocessingml.document` (DOCX), `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet` (XLSX), `video/mp4`
- **Maximum file size**: 50 MB (51,200 KB)
- **Storage**: Files are stored on the `public` disk under `materials/{class_id}/` and served via `Storage::url()`

### YouTube Embed Behavior

When a LINK material's URL matches a YouTube pattern (`youtube.com/watch?v=`, `youtube.com/embed/`, or `youtu.be/`), the 11-character video ID is extracted and rendered as a responsive `<iframe>` embedding `https://www.youtube.com/embed/{id}`. Non-YouTube URLs fall back to a plain `<a target="_blank" rel="noopener noreferrer">` link.

### Public Visibility

Materials are rendered on the public class join page (`/clase/unirse/{invitation_code}`) in the "Materials" section, ordered newest-first (`created_at DESC`). If a class has no materials, the section is hidden.

### Deferred Items

The following are explicitly out of scope for this slice and will be addressed in future changes:

- **File cleanup on disk**: Deleting a material or class does not remove the uploaded file from the `public` disk.
- **Storage quotas**: No per-teacher or per-class storage limits are enforced.
- **Bulk upload**: Only single-file upload is supported.
- **Material reordering**: Materials are fixed in `created_at DESC` order; drag-and-drop reordering is not implemented.
- **php.ini requirements**: The server's `upload_max_filesize` and `post_max_size` must be configured to ≥ 50 MB to accept maximum-size uploads. This is a server-level concern outside the application scope.

## Student Auth and Multi-Class Subscription

Students can register, log in, and subscribe to classes via the Breeze + Blade auth stack. The `Users` table is shared with the Filament admin panel; each guard (web for students, admin for Filament) operates independently.

### Registration & Login

1. Navigate to `/register` and create an account. The `role` is automatically set to `STUDENT` via the registration controller, and the password is hashed by the `User` model's existing `setPasswordAttribute` mutator + `hashed` cast — no explicit `Hash::make()` in auth controllers.
2. After registration, you are redirected to `/dashboard` (a Livewire component behind `auth` + `role:STUDENT` middleware).
3. Login is at `/login`; logout at `/logout` clears the session and returns to `/`.

### Joining a Class

1. A guest (or authenticated user) visits a class invitation link: `/clase/unirse/{invitation_code}`.
2. **Guests** see a "Log in to join" link pointing to `/login?redirect=/clase/unirse/{code}` — after Breeze login, the user returns to the join page.
3. **Authenticated students** see an "Unirse a clase" button. Clicking it POSTs to `/clase/unirse/{code}/join`, creating a `class_user` pivot row via `firstOrCreate` (idempotent — no duplicate error on double-click).
4. Success redirects to `/dashboard` with a flash message.

### Student Dashboard

`/dashboard` is a Livewire component (`app/Livewire/Dashboard.php` + `resources/views/livewire/dashboard.blade.php`) that:

- Lists subscribed classes as cards (title, description, material count, exam count)
- Shows an empty state ("You haven't joined any classes yet.") when there are zero subscriptions
- Is gated by `auth` + `role:STUDENT` middleware — teachers and admins get a 403
- Uses the Breeze `layouts.app` Blade layout for consistent styling

### class_user Pivot Table

- `database/migrations/*_create_class_user_table.php`: `id`, `class_id` (FK → `classes`, cascade), `user_id` (FK → `users`, cascade), timestamps, `UNIQUE(class_id, user_id)`
- `User::subscribedClasses()`: `belongsToMany(SchoolClass::class, 'class_user', 'user_id', 'class_id')->withTimestamps()`
- `SchoolClass::students()`: `belongsToMany(User::class, 'class_user', 'class_id', 'user_id')->withTimestamps()`

### Breeze + Filament Coexistence

Two auth stacks share the `User` model:

| Feature | Student Side | Admin/Teacher Side |
|---------|-------------|-------------------|
| Auth stack | Breeze (web guard) | Filament (admin guard) |
| Panel URL | `/login`, `/register`, `/dashboard` | `/admin/login`, `/admin` |
| Role gate | `role:STUDENT` middleware | `CheckRole:ADMIN,TEACHER` in Filament panel provider |
| Password hashing | `User::setPasswordAttribute` mutator + `hashed` cast | Same model, same mechanism |

The `User` model is shared; roles are enforced at the middleware/guard level.

### No-Mailer Limitation

The `.env` file has no `MAIL_MAILER` configured. Password reset and email verification routes are wired by Breeze but **no emails are actually sent**. Password reset tokens are still generated and valid (usable directly in tests), but the email notification step is skipped in production. `MustVerifyEmail` is **not** implemented on the `User` model — email verification is deferred.

### Deferred Items

The following are out of scope for this slice and will be implemented in future changes:

- **Exam taking UI**: The student-side exam wizard with one-question-at-a-time display, answer submission, and server-validated countdown timer.
- **Grading engine**: Strict MCQ grading (all correct AND no incorrect selected = full points), score persistence, and instant result display.
- **Reports**: PDF and Excel teacher reports via `barryvdh/laravel-dompdf` and `maatwebsite/excel`.
- **Email verification**: Enable `MustVerifyEmail` on `User` once a mailer is configured.
- **Profile editing and password change**: Routes are wired but profile editing is deferred.

### Test Coverage

| File | Count | Covers |
|------|-------|--------|
| `tests/Feature/Auth/RegistrationTest.php` | 2 | Registration screen renders, new users can register |
| `tests/Feature/Auth/AuthenticationTest.php` | 4 | Login screen, valid/invalid login, logout |
| `tests/Feature/Auth/PasswordResetTest.php` | 4 | Forgot password screen, reset link request, reset screen, password reset |
| `tests/Feature/Auth/PasswordUpdateTest.php` | 2 | Password update, correct password required |
| `tests/Feature/ClassInvitationFlowTest.php` | 6 | Join page: valid/invalid codes, guest link with `?redirect`, auth join form, Materials section |
| `tests/Feature/StudentJoinClassTest.php` | 4 | Pivot creation + redirect, idempotent duplicate, 404 on nonexistent code, 302 unauthenticated |
| `tests/Feature/StudentDashboardTest.php` | 4 | Auth gate, STUDENT role gate, cards render, empty state |
| `tests/Feature/ClassUserPivotTest.php` | 5 | Schema columns, UNIQUE constraint, cascade delete (class + user), relationship resolution with timestamps |


Teachers can create exams with questions and answer options via the Filament `/admin/exams` panel. Each exam belongs to a class and follows a strict 3-tier structure: **exam → questions → answer options**.

### Creating an Exam

1. Navigate to `/admin/exams` and click **Create**.
2. Select a class (only the teacher's own classes appear).
3. Fill in exam details: title, description (optional), duration in minutes, and max score.
4. Add one or more questions using the **Questions Repeater**. Each question has:
   - **Text**: The question body.
   - **Type**: `SINGLE` (one correct answer) or `MULTIPLE` (multiple correct answers).
   - **Points**: The score weight of this question.
5. For each question, add at least **two answer options** via the nested **Options Repeater**:
   - **Text**: The option text.
   - **Is Correct**: Toggle to mark the option as correct. Each question must have **at least one correct option**.
6. Submit. The exam appears in the list, sorted newest-first.

## Teacher Exams: Builder and Question Types

### 3-Tier Structure

| Tier | Model | Relationship |
|------|-------|-------------|
| 1 — Exam | `Exam` | `belongsTo SchoolClass`, hasMany `Question` |
| 2 — Question | `Question` | `belongsTo Exam`, hasMany `AnswerOption` |
| 3 — Option | `AnswerOption` | `belongsTo Question` |

All three tiers use **cascade delete**: deleting an exam removes all its questions and their options at the database level.

### SINGLE vs MULTIPLE Question Types

| Type | Description | Label Color |
|------|-----------|-------------|
| **SINGLE** | Exactly one correct option per question | Blue (info) |
| **MULTIPLE** | One or more correct options per question | Orange (warning) |

When a teacher switches a question's type between SINGLE and MULTIPLE, a **warning notification** reminds them to review the `is_correct` flags — the existing flags are preserved, NOT auto-cleared.

### Strict MCQ Rule

The **strict MCQ rule** is enforced at grading time, not in the builder: a SINGLE question with multiple correct options, or a MULTIPLE question with no correct option at all, is treated as an **invalid question** during grading. The builder allows any combination of flags so teachers can draft freely, but the grading engine will reject inconsistent configurations.

### max_score Auto-Calculation

The `max_score` field defaults to the **sum of all question points** when left at the default value (100). Teachers can override this by setting an explicit max_score. A user-interface warning suggests reviewing the value if `max_score` is less than the sum of question points.

### Question Order

Questions are numbered sequentially based on their position in the Repeater (order column: 0, 1, 2, …). On edit, the order is renumbered to match the current Repeater arrangement.

### Preview Action

The Edit page includes a **Preview as student** header action that renders the full exam as formatted JSON in a modal, showing all questions and options with their `is_correct` flags.

### Cascade Delete Warning

When deleting an exam, a confirmation modal warns: "This exam, its questions, and all answer options will be permanently deleted." All related rows are removed from the database in a single cascading operation.

### Deferred Grading Engine

The grading engine that scores student submissions is **not implemented** in this slice. The builder creates exams and questions; the actual MCQ grading logic is deferred to a future change. The strict MCQ rule described above is the grading contract that future implementations must honor.

## Running Tests

```bash
php artisan test          # All tests (Pest v4 + PHPUnit)
php artisan test --parallel  # Faster (8 processes)
```

Pest v4 uses the existing `phpunit.xml` config. Tests run against SQLite `:memory:` by default (see `<env name="DB_CONNECTION" value="sqlite"/>` in `phpunit.xml`). No MariaDB instance is needed for tests.

### Test Coverage

| File | Count | Covers |
|------|-------|--------|
| `tests/Feature/AdminPanelSmokeTest.php` | 4 | Filament panel boot, login redirects, no class-not-found errors |
| `tests/Feature/TeacherResourceTest.php` | 13 | Password hashing, double-hash guard, CRUD, suspend toggle, temp password, unique email, role scope, mass-assignment guard |
| `tests/Feature/ClassResourceTest.php` | 9 | Teacher-scoped class CRUD, auto-generated invitation_code, cross-teacher access guard, syllabus RichEditor, copy-link action |
| `tests/Feature/ClassInvitationFlowTest.php` | 5 | Public join page: valid/invalid codes, guest login link, auth TBD placeholder, Materials section |
| `tests/Feature/StudyMaterialResourceTest.php` | 8 | Material CRUD per type, scope isolation, ordering, JSON round-trip, form schema validation |
| `tests/Feature/StudyMaterialPublicViewTest.php` | 6 | Public Materials section: per-type rendering, YouTube embed, non-YouTube anchor, MEETING card, empty state |
| `tests/Feature/ExamResourceTest.php` | 10 | CRUD query scope, cascade delete, form rendering, class Select scoping, max_score integer, max_score sum-from-questions, question ordering, questions_count withCount, type-switch, form renders |
| `tests/Feature/QuestionModelTest.php` | 3 | Question→exam & options relationships, QuestionType enum cast, AnswerOption is_correct boolean cast |
| `tests/Feature/AnswerOptionModelTest.php` | 3 | AnswerOption→question relationship, is_correct boolean cast, DB persistence as integer |
| `tests/Feature/ExampleTest.php` | 1 | Skeleton test (PHPUnit) |
| `tests/Unit/ExampleTest.php` | 1 | Skeleton test (PHPUnit) |

## Project Structure

```
app/
├── Enums/
│   ├── QuestionType.php              # SINGLE | MULTIPLE backed enum (getLabel, getColor, getIcon)
│   └── StudyMaterialType.php         # FILE | LINK | MEETING backed enum
├── Filament/
│   └── Resources/
│       ├── ClassResource.php         # Class CRUD (create, edit, delete, regenerate, copy-link)
│       ├── ExamResource.php          # Exam CRUD with nested questions+options Repeaters, teacher-scoped query
│       │   └── Pages/
│       │       ├── CreateExam.php    # max_score defaulting, question order, correct-option validation
│       │       ├── EditExam.php      # order renumbering, preview JSON modal
│       │       └── ListExams.php     # ListRecords stub
│       ├── StudyMaterialResource.php # Study material CRUD with conditional form
│       └── TeacherResource.php       # Teacher CRUD (create, edit, suspend, delete, temp password)
├── Http/
│   ├── Controllers/
│   │   └── JoinClassController.php   # Public class join page + Materials section
│   └── Middleware/
│       └── CheckRole.php             # Role-based access control (ADMIN, TEACHER)
├── Models/
│   ├── AnswerOption.php              # Eloquent model with question(), is_correct boolean cast
│   ├── Exam.php                      # Eloquent model with classroom(), questions()
│   ├── Question.php                  # Eloquent model with exam(), options(), QuestionType enum cast
│   ├── SchoolClass.php               # Eloquent model with teacher(), studyMaterials(), exams()
│   ├── StudyMaterial.php             # Eloquent model with classroom(), extra_metadata JSON cast
│   └── User.php                      # Eloquent model with role enum, suspended_at, password hashing
└── Providers/
    └── Filament/
        └── AdminPanelProvider.php    # Single panel at /admin, role middleware

database/
├── factories/
│   └── UserFactory.php
├── migrations/
│   ├── 0001_01_01_000000_create_users_table.php  # users schema (role, suspended_at)
│   ├── *_create_exams_table.php                  # exams (class_id FK, title, duration, max_score)
│   ├── *_create_questions_table.php              # questions (exam_id FK, text, type ENUM, points, order)
│   └── *_create_answer_options_table.php         # answer_options (question_id FK, text, is_correct)
└── seeders/
    └── AdminUserSeeder.php          # Idempotent admin seeder

tests/
├── Feature/
│   ├── AdminPanelSmokeTest.php
│   ├── AnswerOptionModelTest.php
│   ├── ClassInvitationFlowTest.php
│   ├── ClassResourceTest.php
│   ├── ExamResourceTest.php
│   ├── QuestionModelTest.php
│   ├── StudyMaterialPublicViewTest.php
│   ├── StudyMaterialResourceTest.php
│   └── TeacherResourceTest.php
└── Pest.php                         # Pest v4 config with RefreshDatabase
```

## SDD Artifacts

This project follows the Spec-Driven Development (SDD) workflow.
Active change artifacts are in `openspec/changes/`.

- [Proposal](openspec/changes/scaffold-and-admin/proposal.md)
- [Specs](openspec/changes/scaffold-and-admin/specs/)
- [Design](openspec/changes/scaffold-and-admin/design.md)
- [Tasks](openspec/changes/scaffold-and-admin/tasks.md)
- [Verify Report](openspec/changes/scaffold-and-admin/verify-report.md)

## License

MIT — see the [LICENSE](LICENSE) file.
