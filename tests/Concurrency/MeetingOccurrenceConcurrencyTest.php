<?php

use App\Models\Meeting;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\ExamConcurrencyHarness;

beforeEach(function () {
    expect(DB::getDriverName())->toBe('mysql')
        ->and(DB::getDatabaseName())->toBe('online_exam_submission_concurrency');
});

it('serializes child and parent occurrence deletions in either order', function (string $first) {
    $teacher = User::factory()->create(['role' => 'TEACHER']);
    $class = SchoolClass::create([
        'teacher_id' => $teacher->id, 'title' => 'Concurrent meetings', 'invitation_code' => 'MEETLOCK1234',
    ]);
    $rule = json_encode(['frequency' => 'weekly', 'interval' => 1, 'count' => 4], JSON_THROW_ON_ERROR);
    $parent = Meeting::create([
        'class_id' => $class->id, 'title' => 'Original', 'scheduled_at' => '2026-08-01 10:00:00',
        'recurrence_rule' => $rule,
    ]);
    $child = Meeting::create([
        'class_id' => $class->id, 'parent_id' => $parent->id, 'title' => 'Remove me',
        'scheduled_at' => '2026-08-08 10:00:00', 'agenda' => 'Removed override',
    ]);
    $successor = Meeting::create([
        'class_id' => $class->id, 'parent_id' => $parent->id, 'title' => 'Preserved title',
        'scheduled_at' => '2026-08-15 10:00:00', 'agenda' => 'Preserved agenda',
        'meeting_url' => 'https://example.com/successor', 'duration_minutes' => 45,
    ]);
    $sibling = Meeting::create([
        'class_id' => $class->id, 'parent_id' => $parent->id, 'title' => 'Last occurrence',
        'scheduled_at' => '2026-08-22 10:00:00', 'agenda' => 'Last override',
    ]);
    $worker = null;
    DB::beginTransaction();
    try {
        $firstId = $first === 'child' ? $child->id : $parent->id;
        $secondId = $first === 'child' ? $parent->id : $child->id;
        expect(Meeting::findOrFail($firstId)->deleteOccurrence())->toBeTrue();
        $blocker = (int) DB::scalar(
            'SELECT trx_id FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id = CONNECTION_ID()',
        );
        $worker = ExamConcurrencyHarness::startMeeting($secondId);
        $ready = ExamConcurrencyHarness::message($worker);
        expect($ready['event'])->toBe('ready');
        $wait = ExamConcurrencyHarness::observeLockWait($worker, $ready['connection_id']);
        expect((int) $wait['blocking_trx_id'])->toBe($blocker)
            ->and((int) $wait['waiting_connection_id'])->toBe($ready['connection_id']);
        DB::commit();
        $result = ExamConcurrencyHarness::message($worker);
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        ExamConcurrencyHarness::stop($worker);
    }

    $remaining = Meeting::query()->where('class_id', $class->id)->orderBy('id')->get()->keyBy('id');
    $expected = [$successor->id, $sibling->id];
    expect($result)->toMatchArray(['event' => 'result', 'status' => 'deleted'])
        ->and($remaining->keys()->all())->toBe($expected);
    if ($first === 'child') {
        expect($remaining[$successor->id]->parent_id)->toBeNull()
            ->and($remaining[$successor->id]->recurrence_rule)->toBe($rule)
            ->and($remaining[$successor->id]->title)->toBe('Preserved title')
            ->and($remaining[$successor->id]->agenda)->toBe('Preserved agenda')
            ->and($remaining[$successor->id]->meeting_url)->toBe('https://example.com/successor')
            ->and($remaining[$successor->id]->duration_minutes)->toBe(45)
            ->and($remaining[$sibling->id]->parent_id)->toBe($successor->id)
            ->and($remaining[$sibling->id]->agenda)->toBe('Last override');
    } else {
        expect($remaining[$successor->id]->parent_id)->toBeNull()
            ->and($remaining[$successor->id]->recurrence_rule)->toBe($rule)
            ->and($remaining[$successor->id]->title)->toBe('Preserved title')
            ->and($remaining[$successor->id]->agenda)->toBe('Preserved agenda')
            ->and($remaining[$successor->id]->meeting_url)->toBe('https://example.com/successor')
            ->and($remaining[$successor->id]->duration_minutes)->toBe(45)
            ->and($remaining[$sibling->id]->parent_id)->toBe($successor->id)
            ->and($remaining[$sibling->id]->agenda)->toBe('Last override');
    }
})->with(['child deletion first' => 'child', 'parent promotion first' => 'parent']);
