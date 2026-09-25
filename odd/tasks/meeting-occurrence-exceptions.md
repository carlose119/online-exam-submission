# Individual meeting occurrence changes

## Outcome and scope

D1 delivers independent editing of the selected occurrence, including the first: parent edits no longer propagate to children. Deletion/promotion is delivered in D2, not D1. The combined candidate was independently verified against issue #3.

Whole-series selection and exception reconciliation remain deferred; this slice does not close issue #3. User explicitly chose ODD and TDD ON, then authorized isolated MariaDB verification and updates to canonical R12/R13/R15 with their related scenarios. No SDD phase or archived-spec change was performed.

## Work and delivery boundaries

- Branch: `feat/meeting-occurrence-exceptions`; base: `0b7334b2d3512916e4a728cea5a5e0fc3ad305ba`.
- Delegated multi-file implementation and independent verification; parent maintained this record and performed narrow specification readback/edits.
- Preserve `.atl/`, unrelated work, recurrence generation, notification/access policies and dependencies.
- Direct model deletion and the Filament table still use the baseline FK cascade in D1. D2 introduces the Filament table `deleteOccurrence()` action; no bulk or edit-page delete action is included.
- The separately authorized PDF correction is a later, separate work unit documented in `odd/tasks/report-pdf-concurrency.md`.
- Delivery strategy: `ask-on-risk`. The accumulated branch exceeds the initial forecast; resolve review slices before committing or publishing. Do not reduce coverage to fit a line heuristic.
- D1 delivers editing only; D2 deletion/promotion and D3 PDF correction are separate follow-up work units. Three local work-unit commits were authorized, not publication: no push, PR, merge, branch deletion or issue closure is authorized.
- Local commit sequence: editing with its regression/spec hunks, then deletion/promotion with its tests/specs, then the separate report unit. Keep final source behavior unchanged and verify intermediate candidates. PR chain strategy remains undecided; no PR is being prepared.

## Tasks

- [x] E1 Map behavior and confirm individual-only scope and TDD.
- [x] E2 Implement isolated edits and safe deletion with observed RED/GREEN.
- [x] E3 Independently verify behavior, downstream consumers, complete suites and quality.
- [x] E5 Add and execute isolated MariaDB concurrent-deletion checks.
- [x] E6 Align canonical R12/R13/R15 and their scenarios; preserve archived specs.
- [x] E4 Record evidence, limitations and pending delivery status.
- [x] I1 Audit possible report-file impact read-only and obtain the user's recovery decision.
- [x] D1 Editing work unit verified in an isolated snapshot and identified by containing commit subject `feat(meetings): edit individual occurrences independently`; parent records its full hash externally.
- [ ] D2 Follow-up deletion/promotion work unit: `feat(meetings): preserve recurring siblings on occurrence deletion`.

## Snapshot boundary

D1 contains only independent editing; table deletion still cascades under baseline behavior. The deletion tests and concurrency harness belong to D2. Combined-candidate verification below is historical, not evidence of this intermediate snapshot. Independent isolated-snapshot verification is recorded separately below. Commit identity is its unique subject; full hashes are recorded externally. Local commits remain unpublished.

## Independent isolated-snapshot verification

The verifier exported the pinned tree into an isolated snapshot and confirmed archive blob identities (D1: 416; D2: 418). It copied the physical vendor tree and three existing build assets without changing repository sources; a synthetic snapshot-only environment and fresh storage per command isolated parent/child paths and effective SQLite `:memory:`. No real `.env` or secret was read or copied. The original 15 working-tree SHA-256 values, HEAD, real index, status and habitual report metadata remained unchanged.

| Snapshot | Command | Result |
|---|---|---|
| D1 | `php artisan test tests/Feature/RecurringMeetingTest.php tests/Feature/CalendarFeedTest.php tests/Feature/IcalExportTest.php tests/Feature/StudentDashboardTest.php` | 44 tests / 272 assertions, exit 0, no warnings |
| D1 | `php vendor/bin/pint --test` | Passed, exit 0 |
| D1 | `composer analyse` | 101 files, no errors, exit 0 (Windows verifier invoked the Composer PHAR through PHP) |

`git diff --check` passed for all three prepared deltas. The first D1 snapshot attempt lacked an ignored Vite manifest (15 failed, 29 warnings); after three existing assets were copied, tests passed with 44 warnings from a missing snapshot-only environment file. A minimal synthetic environment fixed the harness; the final D1 run above had no warnings. A missing Composer executable caused a pre-spawn WinError2; invoking the PHP Composer PHAR resolved it. These harness diagnostics are not product regressions or new TDD RED evidence. MariaDB concurrency was not rerun for the intermediate snapshots; the 3/3 combined-candidate results remain historical.

## Verification evidence

Observed RED demonstrated overwritten child fields, cascading UI deletion and missing Filament success notification. GREEN coverage includes child isolation, chronological/equal-date successor choice, one/no children, cancelled-delete rollback, teacher denial, preserved overrides, direct-model cascade compatibility and scoped calendar output. Real Filament feature actions verify the runtime entry points; a successful deletion returns the truthy result required by `DeleteAction`.

Final independent verification of the combined candidate:

| Check | Result |
|---|---|
| Meeting concurrency, both deletion orders | 2 tests / 38 assertions; actual InnoDB LOCK WAIT and matching blocker transaction IDs |
| Complete MariaDB concurrency suite | 3/3 bounded final runs; each 15 tests / 204 assertions, exit 0 |
| Seven focused meeting/report feature files | 92 tests / 487 assertions, exit 0 |
| Complete SQLite suite | 509 tests / 2096 assertions, exit 0 |
| `vendor/bin/pint --test` | Passed |
| `composer analyse` | Passed; 101 files, no errors |
| `git diff --check` | Passed |

The normal runner is `vendor/bin/pest --configuration=phpunit.xml`; the concurrency runner is `php vendor/bin/pest --configuration=phpunit.concurrency.xml`. These results were obtained only after the isolation checks below. Native review remained off; risk assessment was unavailable because of untracked artifacts, so the returned conservative independent-verifier plan was followed.

## Isolation and incident record

Every final run verified the owned MariaDB datadir/port/schema and fresh parent/child `LARAVEL_STORAGE_PATH` before tests. A nonrecursive path probe and one process supervisor prevented loss of the temporary server between checks. Final cleanup left no owned worker or test-port listener; habitual report metadata and Git status were unchanged.

An earlier existing report concurrency test used habitual `storage/app/reports` despite database isolation and deletes files during preparation. No prior inventory or recoverable Git history existed, so prior file loss could not be confirmed or excluded. The user reported no backup and declined further recovery investigation. This decision does not authorize further habitual-storage writes. All subsequent runs used isolated files and database; private diagnostics remain outside Git.

## Limits and rollback

The final three passing runs do not identify an earlier worker exception whose payload was lost, nor prove every possible interleaving. The PDF investigation and correction are recorded separately. Browser, external-calendar-client and other-OS behavior were not verified. A future cancelling Meeting update observer would require reconsidering the unchecked successor update result; no such observer exists in the current application.

Rollback boundary: this unit's meeting model/resource/edit-page changes, occurrence tests and worker/harness addition, canonical R12/R13/R15 changes and this record. Preserve the separate PDF unit and prior project work. No rollback was executed.
