<?php

use App\Models\SchoolClass;
use App\Models\User;
use App\Services\ReportScheduleService;
use App\Values\ReportFilters;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Tests\Support\ExamConcurrencyHarness;

beforeEach(function () {
    expect(DB::getDriverName())->toBe('mysql')
        ->and(DB::getDatabaseName())->toBe('online_exam_submission_concurrency');
    config()->set('reports.storage_disk', 'reports');
    Storage::disk('reports')->delete(array_diff(Storage::disk('reports')->allFiles(), ['.gitkeep']));
});

it('cannot publish after an authorization change commits while publication waits', function (string $change, string $format) {
    $teacher = User::factory()->create(['role' => 'TEACHER']);
    $newTeacher = User::factory()->create(['role' => 'TEACHER']);
    $class = SchoolClass::create([
        'teacher_id' => $teacher->id,
        'title' => 'Publication race',
        'invitation_code' => fake()->unique()->regexify('[A-Z0-9]{8}'),
    ]);
    $worker = null;

    DB::beginTransaction();
    try {
        if ($change === 'ownership') {
            DB::table('classes')->where('id', $class->id)->update(['teacher_id' => $newTeacher->id]);
        } else {
            DB::table('users')->where('id', $teacher->id)->update(['role' => 'STUDENT']);
        }

        $blockingTransaction = (int) DB::scalar(
            'SELECT trx_id FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id = CONNECTION_ID()',
        );
        $worker = ExamConcurrencyHarness::start('publish-report', $class->id, $teacher->id, $format);
        $ready = ExamConcurrencyHarness::message($worker);
        $wait = ExamConcurrencyHarness::observeLockWait($worker, $ready['connection_id']);

        expect((int) $wait['blocking_trx_id'])->toBe($blockingTransaction);
        DB::commit();
        $result = ExamConcurrencyHarness::message($worker);
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        ExamConcurrencyHarness::stop($worker);
    }

    $files = Storage::disk('reports')->allFiles();

    expect($result)->toMatchArray(['event' => 'result', 'status' => 'unauthorized'])
        ->and(collect($files)->filter(fn (string $file): bool => str_starts_with($file, "class-{$class->id}-") || str_starts_with($file, 'staging/')))->toBeEmpty()
        ->and(DB::table('notifications')->where('notifiable_id', $teacher->id)->exists())->toBeFalse();
})->with([
    'PDF ownership transfer' => ['ownership', 'pdf'],
    'Excel role revocation' => ['role', 'xlsx'],
]);

it('serializes duplicate occurrence claims and deliveries', function () {
    Queue::fake();
    $teacher = User::factory()->create(['role' => 'TEACHER']);
    $class = SchoolClass::create(['teacher_id' => $teacher->id, 'title' => 'Scheduled race', 'invitation_code' => 'RUNRACE1']);
    $schedule = app(ReportScheduleService::class)->create($teacher, $class->id, ['format' => 'pdf', 'filters' => ReportFilters::EMPTY, 'recurrence' => 'daily', 'local_time' => '09:30', 'timezone' => 'UTC']);
    DB::table('report_schedules')->where('id', $schedule->id)->update(['next_run_at' => now()->subDays(10)]);
    $claimWorker = null;
    DB::beginTransaction();
    try {
        User::query()->lockForUpdate()->findOrFail($teacher->id);
        $claimWorker = ExamConcurrencyHarness::start('claim-reports');
        $ready = ExamConcurrencyHarness::message($claimWorker);
        expect(app(ReportScheduleService::class)->dispatchDue())->toBe(1);
        ExamConcurrencyHarness::observeLockWait($claimWorker, $ready['connection_id']);
        DB::commit();
        expect(ExamConcurrencyHarness::message($claimWorker))->toMatchArray(['event' => 'result', 'claimed' => 0]);
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        ExamConcurrencyHarness::stop($claimWorker);
    }

    $run = DB::table('report_runs')->first();
    expect($run)->not->toBeNull();
    $compiled = app('blade.compiler')->getCompiledPath(View::getFinder()->find('reports.class-pdf'));
    if (File::exists($compiled) && ! File::delete($compiled)) {
        throw new RuntimeException('Unable to clear the report compiled view for the cold worker test.');
    }
    expect(File::exists($compiled))->toBeFalse();

    $barrier = tempnam(sys_get_temp_dir(), 'report-barrier-');
    if ($barrier === false) {
        throw new RuntimeException('Unable to create the report worker barrier.');
    }
    $workers = [];
    try {
        foreach (range(1, 8) as $index) {
            $workers[$index] = ExamConcurrencyHarness::start('run-report', $run->id, $barrier, 8);
        }
        foreach ($workers as &$worker) {
            expect(ExamConcurrencyHarness::message($worker))->toMatchArray(['event' => 'ready']);
            $result = ExamConcurrencyHarness::message($worker);
            if (($result['event'] ?? null) !== 'result' || ($result['status'] ?? null) !== 'completed') {
                throw new RuntimeException('Report worker did not complete: '.json_encode($result, JSON_THROW_ON_ERROR));
            }
            expect($result)->toMatchArray(['event' => 'result', 'status' => 'completed']);
        }
        unset($worker);
    } finally {
        foreach ($workers as &$worker) {
            ExamConcurrencyHarness::stop($worker);
        }
        unset($worker);
        @unlink($barrier);
    }
    expect(DB::table('report_runs')->where('id', $run->id)->value('status'))->toBe('completed')
        ->and(DB::table('notifications')->count())->toBe(1)
        ->and(array_diff(Storage::disk('reports')->allFiles(), ['.gitkeep']))->toHaveCount(1);
});
