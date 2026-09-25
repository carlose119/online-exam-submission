# Concurrent class-report PDF rendering

## Objective and authorization

Implemented and independently verified the separately approved correction for concurrent cold-cache class-report PDF rendering on Windows. The compiled-view lock now protects Blade rendering, releases in `finally`, and leaves expensive PDF generation outside the lock and publication transaction. TDD remained ON by the current session's explicit choice; no test-only prewarming or error suppression was used.

Preserve PDF content, report staging, user/class authorization rechecks, publication atomicity and exactly-once notification/run behavior. Render PDF outside database publication locks. Do not modify vendor code, dependencies, unrelated meeting behavior or archived specs.

## Boundaries

- Current branch: `feat/meeting-occurrence-exceptions`, base `0b7334b2d3512916e4a728cea5a5e0fc3ad305ba`. Preserve its existing meeting changes; report changes are a separately reviewable work unit.
- Delegate multi-file implementation and independent verification. Parent maintains this record.
- Implemented surfaces: `app/Services/ReportFormatService.php`, `tests/Feature/GenerateClassReportPdfJobTest.php`, `tests/Concurrency/ReportArtifactPublicationConcurrencyTest.php` and `tests/Support/ExamConcurrencyWorker.php`.
- Strategy `ask-on-risk`; forecast 60–140 source/test lines plus this record. Combined branch may exceed the review budget; decide the delivery split before commits, not by cutting tests or compressing code.
- The user now authorizes a separate local PDF work-unit commit after the two meeting commits. No push, PR, merge, dependency install or public issue update is authorized.
- Every runtime test requires a disposable database where applicable AND a fresh inherited `LARAVEL_STORAGE_PATH`, covering reports/logs/views/cache/sessions/testing files. Verify parent and subprocess resolved paths before running. Never use or clean habitual report storage or the normal database service.
- Only owned temporary processes/data may be started, reset or cleaned. Check habitual reports metadata before/after. Preserve private diagnostic evidence outside Git.

## Tasks

- [x] P1 Map rendering/compilation and preserve publication constraints.
- [x] P2 Establish meaningful RED, implement the narrow production correction, then GREEN/REFACTOR.
- [x] P3 Independently verify cold multiprocess behavior, report security regressions, complete concurrency and SQLite suites, and quality.
- [x] P4 Record evidence, limitations, rollback boundary and separate delivery status.
- [x] D3 PDF work unit verified in an isolated snapshot and identified by containing commit subject `fix(reports): serialize cold PDF view rendering`; parent records its full hash externally.

## Acceptance and verification

- All eight simultaneous cold-cache scheduled-report workers complete; exactly one final artifact, notification and completed run remain.
- Compilation coordination must cover the race boundary; expensive PDF generation must remain outside database locks and outside any unnecessary global rendering lock.
- Preserve report contents and authorization/staging cleanup behavior, including exceptions and lock/resource release.
- A deterministic test must expose the missing protection before production edits. Record actual assertions/results; do not invent RED from the prior timing-dependent diagnostic or break assertions to manufacture it.
- A fresh cold-cache multiprocess runtime check remains required even with a deterministic unit/feature seam. No test-only prewarming or hidden retries.
- Correct concurrency runner: `php vendor/bin/pest --configuration=phpunit.concurrency.xml tests/Concurrency/ReportArtifactPublicationConcurrencyTest.php`, then the complete dedicated concurrency configuration. Never run concurrency tests through the normal SQLite configuration.
- Focused SQLite runner: `php artisan test tests/Feature/GenerateClassReportPdfJobTest.php`; also run the actual report-focused tests discovered during implementation.
- Final checks: `vendor/bin/pest --configuration=phpunit.xml`, `vendor/bin/pint --test`, `composer analyse`, `git diff --check` under safe scratch storage.
- Rollback boundary: this report-specific production/test change and its record; preserve meeting changes and existing report publication security. No rollback performed.

## Verification evidence

Observed deterministic RED: a competing lock probe acquired the lock during baseline view rendering (`true` instead of required `false`). GREEN verifies exclusion during rendering, release at the actual `Pdf::loadHTML()` boundary using the real PDF wrapper, and exception cleanup. The eight-worker test explicitly removes only the compiled report view before starting workers, verifies absence, captures full error JSON and cleans workers in `finally`. Worker IPC now includes exception class/code.

Latest writer checks passed: focused PDF 15 tests / 35 assertions; publication/schedule 26 / 98; full SQLite 509 / 2096; Pint, PHPStan and diff hygiene. Final independent verification of the combined meeting/report candidate:

| Check | Result |
|---|---|
| Complete MariaDB concurrency suite | 3/3 bounded runs; each 15 tests / 204 assertions, exit 0; no retries after failure |
| Seven focused meeting/report feature files | 92 tests / 487 assertions, exit 0 |
| Complete SQLite suite | 509 tests / 2096 assertions, exit 0 |
| `vendor/bin/pint --test` | Passed |
| `composer analyse` | Passed; 101 files, no errors |
| `git diff --check` | Passed |

Source readback confirmed the lock spans Blade rendering and releases before PDF output. Publisher/schedule files, dependencies and archived specs were unchanged. Native review remained off; native assessment was unavailable because of untracked files, so conservative independent verification was used instead.

## Independent isolated-snapshot verification

The verifier exported pinned D3 tree `529311155aeee3619e74a78be9405f7fc2ddf180` and verified 419 archived blob identities. A physical vendor copy, three existing build assets, synthetic snapshot-only environment, fresh per-command storage and reflected parent/child effective SQLite `:memory:` paths isolated execution. No real `.env` or secret was read or copied. The original 15 working-tree SHA-256 values, HEAD, real index, status and habitual report metadata remained unchanged.

| Command | Result |
|---|---|
| `php artisan test tests/Feature/GenerateClassReportPdfJobTest.php tests/Feature/ReportArtifactPublisherFailureTest.php tests/Feature/ReportScheduleArchitectureTest.php tests/Feature/ReportScheduleResourceTest.php tests/Feature/ReportScheduleServiceTest.php` | 47 tests / 185 assertions, exit 0, no warnings |
| `php vendor/bin/pest --configuration=phpunit.xml` | 509 tests / 2096 assertions, exit 0, no warnings |
| `php vendor/bin/pint --test` | Passed, exit 0 |
| `composer analyse` | 101 files, no errors, exit 0 (Windows verifier invoked the Composer PHAR through PHP) |

`git diff --check` passed for all three prepared deltas. The initial D1 harness lacked an ignored Vite manifest (15 failed, 29 warnings); copying three existing assets resolved those failures. The remaining 44 warnings with passing tests came from a missing snapshot-only environment; adding a minimal synthetic environment yielded a clean 44/272 D1 run. A missing Composer executable caused a pre-spawn WinError2, resolved by using the PHP Composer PHAR. None is product regression or a new TDD RED. MariaDB was not rerun during snapshot packaging; the earlier combined-candidate 3/3 runs, each 15 tests / 204 assertions, remain historical rather than D3 snapshot proof.

## Isolation and limitations

Final runs used fresh parent/child scratch-storage probes, checked the owned MariaDB datadir/port/schema immediately before tests, and kept SQLite on `:memory:`. A nonrecursive preflight and one supervisor preserved server liveness through verification. Cleanup left no owned workers or test-port listener; habitual report metadata and Git status remained unchanged. Private diagnostic evidence remains outside Git.

An earlier isolated diagnostic captured seven successful workers and one compiled-view `rename` failure: Windows access denied, OS code 5. Throwable class/PHP code were not transmitted. A separate post-correction worker exception lost its payload and remains unexplained. Another diagnostic failed before useful test execution because a timed-out path probe stopped its temporary server (15 connection refusals, zero assertions); this was invalid runtime evidence, not a code regression. The final bounded passes do not identify those earlier unknown errors or prove every workload.

The original report test used habitual storage by mistake. Prior file loss could not be confirmed or excluded; the user declined recovery. That decision does not permit further habitual-storage writes. Browser behavior, external calendar clients and other OS configurations remain unverified.

## Delivery status

D3 delivers the separate PDF correction after the D1/D2 meeting work units. This isolated snapshot was independently verified below. The containing commit has unique subject `fix(reports): serialize cold PDF view rendering`; its full hash is recorded externally. Local commits remain unpublished, and publication remains a separate user decision. Meeting work and its separate rollback boundary remain recorded in `odd/tasks/meeting-occurrence-exceptions.md`; issue #3 remains open for deferred whole-series work.
