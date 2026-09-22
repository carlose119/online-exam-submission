<?php

use App\Models\Exam;
use App\Models\SchoolClass;
use App\Models\StudentAttempt;
use App\Models\User;
use App\Services\ClassReportService;

// ---------------------------------------------------------------------------
// ClassReportServiceTest — business logic (stats, pass rate, sort order, edge cases)
// ---------------------------------------------------------------------------

it('returns a structured array with expected keys for a class with exams', function () {
    $teacher = User::create(['name' => 'Teacher', 'email' => 't@test.com', 'password' => 'password', 'role' => 'TEACHER']);
    $class = SchoolClass::create(['title' => 'Math 101', 'description' => 'Basic Math', 'teacher_id' => $teacher->id, 'invitation_code' => 'MATH101']);
    $exam = Exam::create(['class_id' => $class->id, 'title' => 'Quiz 1', 'max_score' => 20, 'duration_minutes' => 30]);

    $service = new ClassReportService;
    $result = $service->generate($class);

    expect($result)->toBeArray();
    expect($result)->toHaveKeys(['class', 'teacher', 'exams', 'overall_stats']);
    expect($result['class']['title'])->toBe('Math 101');
    expect($result['teacher']['name'])->toBe('Teacher');
    expect($result['exams'])->toBeArray()->toHaveCount(1);
    expect($result['exams'][0])->toHaveKeys(['exam', 'attempts', 'stats']);
    expect($result['exams'][0]['exam']['title'])->toBe('Quiz 1');
    expect($result['exams'][0]['exam']['max_score'])->toBe(20);
});

it('includes class exams in the structured array', function () {
    $teacher = User::create(['name' => 'Teacher', 'email' => 't@test.com', 'password' => 'password', 'role' => 'TEACHER']);
    $class = SchoolClass::create(['title' => 'Physics', 'description' => null, 'teacher_id' => $teacher->id, 'invitation_code' => 'PHYSICS']);
    $examA = Exam::create(['class_id' => $class->id, 'title' => 'Exam A', 'max_score' => 10, 'duration_minutes' => 15]);
    $examB = Exam::create(['class_id' => $class->id, 'title' => 'Exam B', 'max_score' => 20, 'duration_minutes' => 30]);

    $service = new ClassReportService;
    $result = $service->generate($class);

    expect($result['exams'])->toHaveCount(2);
    $titles = array_column(array_column($result['exams'], 'exam'), 'title');
    // Exams are sorted by title.
    expect($titles)->toBe(['Exam A', 'Exam B']);
});

it('includes each exam attempts in the drill-down', function () {
    $teacher = User::create(['name' => 'Teacher', 'email' => 't@test.com', 'password' => 'password', 'role' => 'TEACHER']);
    $studentA = User::create(['name' => 'Alice', 'email' => 'a@test.com', 'password' => 'password', 'role' => 'STUDENT']);
    $studentB = User::create(['name' => 'Bob', 'email' => 'b@test.com', 'password' => 'password', 'role' => 'STUDENT']);
    $class = SchoolClass::create(['title' => 'Stats', 'teacher_id' => $teacher->id, 'invitation_code' => 'STATS1']);
    $exam = Exam::create(['class_id' => $class->id, 'title' => 'Midterm', 'max_score' => 100, 'duration_minutes' => 60]);

    StudentAttempt::create(['student_id' => $studentA->id, 'exam_id' => $exam->id, 'score_obtained' => 85, 'started_at' => now(), 'finished_at' => now()]);
    StudentAttempt::create(['student_id' => $studentB->id, 'exam_id' => $exam->id, 'score_obtained' => 92, 'started_at' => now(), 'finished_at' => now()]);

    $service = new ClassReportService;
    $result = $service->generate($class);

    expect($result['exams'][0]['attempts'])->toHaveCount(2);
    // Sorted by student name.
    expect($result['exams'][0]['attempts'][0]['student_name'])->toBe('Alice');
    expect($result['exams'][0]['attempts'][1]['student_name'])->toBe('Bob');
});

it('computes per-exam stats (avg score, pass rate, attempts count) correctly', function () {
    $teacher = User::create(['name' => 'Teacher', 'email' => 't@test.com', 'password' => 'password', 'role' => 'TEACHER']);
    $class = SchoolClass::create(['title' => 'Stats', 'teacher_id' => $teacher->id, 'invitation_code' => 'STATS2']);
    $exam = Exam::create(['class_id' => $class->id, 'title' => 'Final', 'max_score' => 20, 'duration_minutes' => 60]);

    // Scores: 15, 12, 10, 8, 5
    // Pass threshold = 0.6 * 20 = 12. So scores >= 12 pass: 15, 12 → 2 passing out of 5.
    // Avg = (15+12+10+8+5)/5 = 50/5 = 10.00
    // Pass rate = 2/5 * 100 = 40.00%
    // Median of sorted [5,8,10,12,15] = 10.00
    $scores = [15, 12, 10, 8, 5];
    foreach ($scores as $i => $score) {
        $student = User::create(['name' => "Student $i", 'email' => "s{$i}@test.com", 'password' => 'password', 'role' => 'STUDENT']);
        StudentAttempt::create(['student_id' => $student->id, 'exam_id' => $exam->id, 'score_obtained' => $score, 'started_at' => now(), 'finished_at' => now()]);
    }

    $service = new ClassReportService;
    $result = $service->generate($class);

    $stats = $result['exams'][0]['stats'];
    expect($stats['attempts_count'])->toBe(5);
    expect($stats['avg_score'])->toBe(10.00);
    expect($stats['pass_rate'])->toBe(40.00);
    expect($stats['median'])->toBe(10.00);
});

it('computes overall stats correctly across multiple exams', function () {
    $teacher = User::create(['name' => 'Teacher', 'email' => 't@test.com', 'password' => 'password', 'role' => 'TEACHER']);
    $class = SchoolClass::create(['title' => 'Science', 'teacher_id' => $teacher->id, 'invitation_code' => 'SCIENCE']);
    $examA = Exam::create(['class_id' => $class->id, 'title' => 'A-Physics', 'max_score' => 20, 'duration_minutes' => 30]);
    $examB = Exam::create(['class_id' => $class->id, 'title' => 'B-Chemistry', 'max_score' => 10, 'duration_minutes' => 20]);

    // Exam A (max_score=20, pass >= 12): scores 15, 12, 9 → 2 passing, avg=12.00, pass=66.67%
    $student1 = User::create(['name' => 'S1', 'email' => 's1@test.com', 'password' => 'password', 'role' => 'STUDENT']);
    $student2 = User::create(['name' => 'S2', 'email' => 's2@test.com', 'password' => 'password', 'role' => 'STUDENT']);
    $student3 = User::create(['name' => 'S3', 'email' => 's3@test.com', 'password' => 'password', 'role' => 'STUDENT']);
    StudentAttempt::create(['student_id' => $student1->id, 'exam_id' => $examA->id, 'score_obtained' => 15, 'started_at' => now(), 'finished_at' => now()]);
    StudentAttempt::create(['student_id' => $student2->id, 'exam_id' => $examA->id, 'score_obtained' => 12, 'started_at' => now(), 'finished_at' => now()]);
    StudentAttempt::create(['student_id' => $student3->id, 'exam_id' => $examA->id, 'score_obtained' => 9, 'started_at' => now(), 'finished_at' => now()]);

    // Exam B (max_score=10, pass >= 6): scores 8, 4 → 1 passing, avg=6.00, pass=50%
    $student4 = User::create(['name' => 'S4', 'email' => 's4@test.com', 'password' => 'password', 'role' => 'STUDENT']);
    StudentAttempt::create(['student_id' => $student3->id, 'exam_id' => $examB->id, 'score_obtained' => 8, 'started_at' => now(), 'finished_at' => now()]);
    StudentAttempt::create(['student_id' => $student4->id, 'exam_id' => $examB->id, 'score_obtained' => 4, 'started_at' => now(), 'finished_at' => now()]);

    $service = new ClassReportService;
    $result = $service->generate($class);

    expect($result['overall_stats']['total_attempts'])->toBe(5);
    // Overall avg: (15+12+9+8+4)/5 = 48/5 = 9.60
    expect($result['overall_stats']['avg_score'])->toBe(9.60);
    // Overall pass rate: 3 passing / 5 total * 100 = 60.00%
    expect($result['overall_stats']['pass_rate'])->toBe(60.00);
});

it('computes pass rate correctly: 3 of 5 students pass with max_score 20', function () {
    $teacher = User::create(['name' => 'Teacher', 'email' => 't@test.com', 'password' => 'password', 'role' => 'TEACHER']);
    $class = SchoolClass::create(['title' => 'Math', 'teacher_id' => $teacher->id, 'invitation_code' => 'MATH']);
    $exam = Exam::create(['class_id' => $class->id, 'title' => 'Quiz', 'max_score' => 20, 'duration_minutes' => 30]);

    // 3 pass (>=12), 2 fail
    $scores = [18, 14, 12, 8, 5];
    foreach ($scores as $i => $score) {
        $student = User::create(['name' => "S{$i}", 'email' => "s{$i}@test.com", 'password' => 'password', 'role' => 'STUDENT']);
        StudentAttempt::create(['student_id' => $student->id, 'exam_id' => $exam->id, 'score_obtained' => $score, 'started_at' => now(), 'finished_at' => now()]);
    }

    $service = new ClassReportService;
    $result = $service->generate($class);

    expect($result['exams'][0]['stats']['pass_rate'])->toBe(60.00); // 3/5 * 100
});

it('sorts exams by title', function () {
    $teacher = User::create(['name' => 'Teacher', 'email' => 't@test.com', 'password' => 'password', 'role' => 'TEACHER']);
    $class = SchoolClass::create(['title' => 'Sorted', 'teacher_id' => $teacher->id, 'invitation_code' => 'SORTED']);
    Exam::create(['class_id' => $class->id, 'title' => 'Zeta Exam', 'max_score' => 10, 'duration_minutes' => 10]);
    Exam::create(['class_id' => $class->id, 'title' => 'Alpha Exam', 'max_score' => 10, 'duration_minutes' => 10]);

    $service = new ClassReportService;
    $result = $service->generate($class);

    $titles = array_column(array_column($result['exams'], 'exam'), 'title');
    expect($titles)->toBe(['Alpha Exam', 'Zeta Exam']);
});

it('sorts attempts by student name', function () {
    $teacher = User::create(['name' => 'Teacher', 'email' => 't@test.com', 'password' => 'password', 'role' => 'TEACHER']);
    $class = SchoolClass::create(['title' => 'Sorted', 'teacher_id' => $teacher->id, 'invitation_code' => 'SORT2']);
    $exam = Exam::create(['class_id' => $class->id, 'title' => 'Quiz', 'max_score' => 10, 'duration_minutes' => 10]);

    $bob = User::create(['name' => 'Bob', 'email' => 'bob@test.com', 'password' => 'password', 'role' => 'STUDENT']);
    $alice = User::create(['name' => 'Alice', 'email' => 'alice@test.com', 'password' => 'password', 'role' => 'STUDENT']);
    StudentAttempt::create(['student_id' => $bob->id, 'exam_id' => $exam->id, 'score_obtained' => 8, 'started_at' => now(), 'finished_at' => now()]);
    StudentAttempt::create(['student_id' => $alice->id, 'exam_id' => $exam->id, 'score_obtained' => 7, 'started_at' => now(), 'finished_at' => now()]);

    $service = new ClassReportService;
    $result = $service->generate($class);

    expect($result['exams'][0]['attempts'][0]['student_name'])->toBe('Alice');
    expect($result['exams'][0]['attempts'][1]['student_name'])->toBe('Bob');
});

it('returns valid empty structure for a class with no exams', function () {
    $teacher = User::create(['name' => 'Teacher', 'email' => 't@test.com', 'password' => 'password', 'role' => 'TEACHER']);
    $class = SchoolClass::create(['title' => 'Empty Class', 'teacher_id' => $teacher->id, 'invitation_code' => 'EMPTY']);

    $service = new ClassReportService;
    $result = $service->generate($class);

    expect($result['class']['title'])->toBe('Empty Class');
    expect($result['exams'])->toBeArray()->toBeEmpty();
    expect($result['overall_stats']['total_attempts'])->toBe(0);
    expect($result['overall_stats']['avg_score'])->toBe(0.0);
    expect($result['overall_stats']['pass_rate'])->toBe(0.0);
});

it('returns exams with empty attempts and zero stats for a class with exams but no attempts', function () {
    $teacher = User::create(['name' => 'Teacher', 'email' => 't@test.com', 'password' => 'password', 'role' => 'TEACHER']);
    $class = SchoolClass::create(['title' => 'No Attempts', 'teacher_id' => $teacher->id, 'invitation_code' => 'NOATT']);
    Exam::create(['class_id' => $class->id, 'title' => 'Untaken Exam', 'max_score' => 20, 'duration_minutes' => 30]);

    $service = new ClassReportService;
    $result = $service->generate($class);

    expect($result['exams'])->toHaveCount(1);
    expect($result['exams'][0]['attempts'])->toBeArray()->toBeEmpty();
    expect($result['exams'][0]['stats']['attempts_count'])->toBe(0);
    expect($result['exams'][0]['stats']['avg_score'])->toBe(0.0);
    expect($result['exams'][0]['stats']['pass_rate'])->toBe(0.0);
    expect($result['exams'][0]['stats']['median'])->toBe(0.0);
});

it('computes correct all-pass (100%) scenario', function () {
    $teacher = User::create(['name' => 'Teacher', 'email' => 't@test.com', 'password' => 'password', 'role' => 'TEACHER']);
    $class = SchoolClass::create(['title' => 'All Pass', 'teacher_id' => $teacher->id, 'invitation_code' => 'ALLPASS']);
    $exam = Exam::create(['class_id' => $class->id, 'title' => 'Easy', 'max_score' => 10, 'duration_minutes' => 15]);

    // All scores >= 6 (0.6 * 10 = 6)
    $scores = [10, 9, 8, 7, 6];
    foreach ($scores as $i => $score) {
        $student = User::create(['name' => "P{$i}", 'email' => "p{$i}@test.com", 'password' => 'password', 'role' => 'STUDENT']);
        StudentAttempt::create(['student_id' => $student->id, 'exam_id' => $exam->id, 'score_obtained' => $score, 'started_at' => now(), 'finished_at' => now()]);
    }

    $service = new ClassReportService;
    $result = $service->generate($class);

    expect($result['exams'][0]['stats']['pass_rate'])->toBe(100.00);
    expect($result['exams'][0]['stats']['avg_score'])->toBe(8.00);
    expect($result['exams'][0]['stats']['median'])->toBe(8.00);
});

it('computes correct all-fail (0%) scenario', function () {
    $teacher = User::create(['name' => 'Teacher', 'email' => 't@test.com', 'password' => 'password', 'role' => 'TEACHER']);
    $class = SchoolClass::create(['title' => 'All Fail', 'teacher_id' => $teacher->id, 'invitation_code' => 'ALLFAIL']);
    $exam = Exam::create(['class_id' => $class->id, 'title' => 'Hard', 'max_score' => 20, 'duration_minutes' => 60]);

    // All scores < 12 (0.6 * 20 = 12)
    $scores = [11, 10, 5];
    foreach ($scores as $i => $score) {
        $student = User::create(['name' => "F{$i}", 'email' => "f{$i}@test.com", 'password' => 'password', 'role' => 'STUDENT']);
        StudentAttempt::create(['student_id' => $student->id, 'exam_id' => $exam->id, 'score_obtained' => $score, 'started_at' => now(), 'finished_at' => now()]);
    }

    $service = new ClassReportService;
    $result = $service->generate($class);

    expect($result['exams'][0]['stats']['pass_rate'])->toBe(0.00);
    expect($result['exams'][0]['stats']['avg_score'])->toBe(round((11 + 10 + 5) / 3, 2));
    expect($result['exams'][0]['stats']['median'])->toBe(10.00);
});

it('separates scored finalized chart measures from legacy aggregates', function () {
    config()->set('reports.pass_rate_threshold', 0.6);
    $teacher = User::factory()->create(['role' => 'TEACHER']);
    $class = SchoolClass::create(['title' => 'Charts', 'teacher_id' => $teacher->id, 'invitation_code' => 'CHARTS']);
    $exam = Exam::create(['class_id' => $class->id, 'title' => 'Mixed', 'max_score' => 10, 'duration_minutes' => 10]);

    foreach ([[6, true], [0, true], [null, true], [10, false], [null, false]] as [$score, $finished]) {
        StudentAttempt::create([
            'student_id' => User::factory()->create(['role' => 'STUDENT'])->id,
            'exam_id' => $exam->id, 'score_obtained' => $score,
            'started_at' => now(), 'finished_at' => $finished ? now() : null,
        ]);
    }

    $entry = app(ClassReportService::class)->generate($class)['exams'][0];

    expect($entry)->toHaveKey('chart');
    expect($entry['chart'])->toBe(['attempts_count' => 5, 'scored_attempts_count' => 2, 'scored_pass_rate' => 50.0]);
    expect($entry['stats'])->toBe(['attempts_count' => 5, 'avg_score' => 3.2, 'pass_rate' => 40.0, 'median' => 0.0]);
});

it('distinguishes zero scored pass rate from absent chart data', function (?float $score, bool $finished, bool $hasAttempt, ?float $rate) {
    $teacher = User::factory()->create(['role' => 'TEACHER']);
    $class = SchoolClass::create(['title' => 'Sparse', 'teacher_id' => $teacher->id, 'invitation_code' => 'SPARSE']);
    $exam = Exam::create(['class_id' => $class->id, 'title' => 'Only exam', 'max_score' => 10, 'duration_minutes' => 10]);
    if ($hasAttempt) {
        StudentAttempt::create([
            'student_id' => User::factory()->create(['role' => 'STUDENT'])->id,
            'exam_id' => $exam->id, 'score_obtained' => $score,
            'started_at' => now(), 'finished_at' => $finished ? now() : null,
        ]);
    }

    $entry = app(ClassReportService::class)->generate($class)['exams'][0];
    expect($entry)->toHaveKey('chart');
    expect($entry['chart'])->toBe([
        'attempts_count' => $hasAttempt ? 1 : 0,
        'scored_attempts_count' => $rate === null ? 0 : 1,
        'scored_pass_rate' => $rate,
    ]);
})->with([
    'completed zero score' => [0.0, true, true, 0.0],
    'completed missing score' => [null, true, true, null],
    'unfinished passing score' => [10.0, false, true, null],
    'no attempts' => [null, false, false, null],
]);

it('returns teacher name from the loaded relationship', function () {
    $teacher = User::create(['name' => 'Alice Teacher', 'email' => 'alice@test.com', 'password' => 'password', 'role' => 'TEACHER']);
    $class = SchoolClass::create(['title' => 'Teacher Test', 'teacher_id' => $teacher->id, 'invitation_code' => 'TEACH']);

    $service = new ClassReportService;
    $result = $service->generate($class);

    expect($result['teacher']['name'])->toBe('Alice Teacher');
    expect($result['class']['title'])->toBe('Teacher Test');
});
