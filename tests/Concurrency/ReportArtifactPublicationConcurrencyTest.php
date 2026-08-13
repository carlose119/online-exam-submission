<?php

use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ExamConcurrencyHarness;

beforeEach(function () {
    expect(DB::getDriverName())->toBe('mysql')
        ->and(DB::getDatabaseName())->toBe('online_exam_submission_concurrency');
    config()->set('reports.storage_disk', 'reports');
    Storage::disk('reports')->deleteDirectory('staging');
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
