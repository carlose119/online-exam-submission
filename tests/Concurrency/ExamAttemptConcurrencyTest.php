<?php

use App\Models\Exam;
use App\Models\ExamAllowance;
use App\Models\SchoolClass;
use App\Models\StudentAttempt;
use App\Models\User;
use App\Services\ExamAllowanceService;
use App\Services\ExamAttemptCreator;
use Illuminate\Support\Facades\DB;
use Tests\Support\ExamConcurrencyHarness;

function retakeConcurrencyFixture(int $additionalAttempts, bool $withFinishedAttempt = true): array
{
    $teacher = User::factory()->create(['role' => 'TEACHER']);
    $student = User::factory()->create(['role' => 'STUDENT']);
    $class = SchoolClass::create([
        'teacher_id' => $teacher->id, 'title' => 'Retake concurrency', 'invitation_code' => 'RETAKE123456',
    ]);
    $class->students()->attach($student);
    $exam = Exam::create([
        'class_id' => $class->id, 'title' => 'Retake concurrency', 'duration_minutes' => 60, 'max_score' => 10,
    ]);
    ExamAllowance::create(['exam_id' => $exam->id, 'student_id' => $student->id,
        'additional_attempts' => $additionalAttempts, 'extra_time_minutes' => 0,
    ]);
    if ($withFinishedAttempt) {
        StudentAttempt::create([
            'student_id' => $student->id, 'exam_id' => $exam->id, 'attempt_number' => 1,
            'allowed_duration_minutes' => 60, 'started_at' => now()->subMinute(), 'finished_at' => now(),
        ]);
    }

    return compact('teacher', 'class', 'exam', 'student');
}

beforeEach(function () {
    expect(DB::getDriverName())->toBe('mysql')
        ->and(DB::getDatabaseName())->toBe('online_exam_submission_concurrency');
});

it('does not over-consume the final retake slot', function () {
    $data = retakeConcurrencyFixture(1);
    $worker = null;
    DB::beginTransaction();
    try {
        User::query()->lockForUpdate()->findOrFail($data['student']->id);
        $blockingTransaction = (int) DB::scalar(
            'SELECT trx_id FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id = CONNECTION_ID()',
        );
        $worker = ExamConcurrencyHarness::start('start', $data['exam']->id, $data['student']->id);
        $ready = ExamConcurrencyHarness::message($worker);
        $wait = ExamConcurrencyHarness::observeLockWait($worker, $ready['connection_id']);
        expect((int) $wait['blocking_trx_id'])->toBe($blockingTransaction);

        $winner = app(ExamAttemptCreator::class)->create($data['exam'], $data['student']->id);
        DB::commit();
        $loser = ExamConcurrencyHarness::message($worker);
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        ExamConcurrencyHarness::stop($worker);
    }

    $attempts = StudentAttempt::query()->whereBelongsTo($data['student'], 'student')->whereBelongsTo($data['exam'])->get();
    expect($winner->attempt_number)->toBe(2)
        ->and($loser)->toMatchArray(['event' => 'result', 'status' => 'http_exception', 'code' => 403])
        ->and($attempts)->toHaveCount(2)
        ->and($attempts->pluck('attempt_number')->unique())->toHaveCount(2)
        ->and($attempts->max('attempt_number'))->toBe(2);
});

it('keeps allowance reduction and attempt consumption authorized in either lock order', function (string $first) {
    $data = retakeConcurrencyFixture(2);
    $worker = null;
    DB::beginTransaction();
    try {
        if ($first === 'attempt') {
            app(ExamAttemptCreator::class)->create($data['exam'], $data['student']->id);
            $worker = ExamConcurrencyHarness::start('allowance', $data['exam']->id, $data['student']->id, 1);
        } else {
            app(ExamAllowanceService::class)->save($data['exam'], $data['student'], 1, 0);
            $worker = ExamConcurrencyHarness::start('start', $data['exam']->id, $data['student']->id);
        }
        $blockingTransaction = (int) DB::scalar(
            'SELECT trx_id FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id = CONNECTION_ID()',
        );
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

    expect($result['status'])->toBe($first === 'attempt' ? 'allowance_saved' : 'started')
        ->and(ExamAllowance::query()->whereBelongsTo($data['exam'])->whereBelongsTo($data['student'], 'student')->sole()->additional_attempts)->toBe(1)
        ->and(StudentAttempt::query()->whereBelongsTo($data['student'], 'student')->whereBelongsTo($data['exam'])->pluck('attempt_number')->all())->toBe([1, 2]);
})->with(['attempt acquires the lock first' => 'attempt', 'allowance acquires the lock first' => 'allowance']);

it('serializes concurrent starts without duplicate attempt numbers', function () {
    $data = retakeConcurrencyFixture(1, false);
    $worker = null;
    DB::beginTransaction();
    try {
        $first = app(ExamAttemptCreator::class)->create($data['exam'], $data['student']->id);
        $first->update(['finished_at' => now()]);
        $blockingTransaction = (int) DB::scalar(
            'SELECT trx_id FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id = CONNECTION_ID()',
        );
        $worker = ExamConcurrencyHarness::start('start', $data['exam']->id, $data['student']->id);
        $ready = ExamConcurrencyHarness::message($worker);
        $wait = ExamConcurrencyHarness::observeLockWait($worker, $ready['connection_id']);
        expect((int) $wait['blocking_trx_id'])->toBe($blockingTransaction);
        DB::commit();
        $second = ExamConcurrencyHarness::message($worker);
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        ExamConcurrencyHarness::stop($worker);
    }

    $numbers = StudentAttempt::query()->whereBelongsTo($data['student'], 'student')->whereBelongsTo($data['exam'])
        ->orderBy('attempt_number')->pluck('attempt_number');
    expect($second)->toMatchArray(['event' => 'result', 'status' => 'started', 'attempt_number' => 2])
        ->and($numbers->all())->toBe([1, 2])
        ->and($numbers->unique())->toHaveCount(2);
});

it('serializes teacher allowance mutation against ownership transfer', function () {
    $data = retakeConcurrencyFixture(0, false);
    $newTeacher = User::factory()->create(['role' => 'TEACHER']);
    $worker = null;
    DB::beginTransaction();
    try {
        app(ExamAllowanceService::class)->saveForTeacher($data['exam'], $data['student'], $data['teacher'], 1, 0);
        $blockingTransaction = (int) DB::scalar('SELECT trx_id FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id = CONNECTION_ID()');
        $worker = ExamConcurrencyHarness::start('transfer-class', $data['class']->id, $newTeacher->id);
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

    expect($result['status'])->toBe('ownership_transferred')
        ->and($data['class']->fresh()->teacher_id)->toBe($newTeacher->id)
        ->and(ExamAllowance::query()->whereBelongsTo($data['exam'])->exists())->toBeTrue();
});

it('serializes teacher allowance mutation against unenrollment', function () {
    $data = retakeConcurrencyFixture(0, false);
    $worker = null;
    DB::beginTransaction();
    try {
        app(ExamAllowanceService::class)->saveForTeacher($data['exam'], $data['student'], $data['teacher'], 1, 0);
        $blockingTransaction = (int) DB::scalar('SELECT trx_id FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id = CONNECTION_ID()');
        $worker = ExamConcurrencyHarness::start('unenroll', $data['class']->id, $data['student']->id);
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

    expect($result['status'])->toBe('unenrolled')
        ->and(DB::table('class_user')->where('class_id', $data['class']->id)->where('user_id', $data['student']->id)->exists())->toBeFalse()
        ->and(ExamAllowance::query()->whereBelongsTo($data['exam'])->exists())->toBeTrue();
});

it('rejects a waiting teacher allowance after authority changes commit', function (string $change) {
    $data = retakeConcurrencyFixture(0, false);
    $newTeacher = User::factory()->create(['role' => 'TEACHER']);
    $worker = null;
    DB::beginTransaction();
    try {
        if ($change === 'ownership') {
            DB::table('classes')->where('id', $data['class']->id)->update(['teacher_id' => $newTeacher->id]);
        } else {
            DB::table('class_user')->where('class_id', $data['class']->id)->where('user_id', $data['student']->id)->delete();
        }
        $blockingTransaction = (int) DB::scalar('SELECT trx_id FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id = CONNECTION_ID()');
        $worker = ExamConcurrencyHarness::start('teacher-allowance', $data['exam']->id, $data['student']->id, $data['teacher']->id);
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

    expect($result['status'])->toBe($change === 'ownership' ? 'http_exception' : 'validation_exception')
        ->and(ExamAllowance::query()->whereBelongsTo($data['exam'])->sole()->additional_attempts)->toBe(0);
})->with(['ownership transfer first' => 'ownership', 'unenrollment first' => 'enrollment']);
