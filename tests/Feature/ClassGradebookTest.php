<?php

use App\Models\Exam;
use App\Models\SchoolClass;
use App\Models\StudentAttempt;
use App\Models\User;
use App\Services\ClassReportService;
use App\Services\ReportFormatService;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\Process\Process;

function gradebookFixture(): array
{
    $class = SchoolClass::create([
        'title' => 'Student Grades', 'teacher_id' => User::factory()->create(['role' => 'TEACHER'])->id,
        'invitation_code' => 'GRADES01',
    ]);
    $student = User::factory()->create(['name' => '=1+1', 'role' => 'STUDENT']);
    $absent = User::factory()->create(['name' => 'Absent Student', 'role' => 'STUDENT']);
    $outsider = User::factory()->create(['name' => 'Historical Student', 'role' => 'STUDENT']);
    $class->students()->attach([$student->id, $absent->id]);
    $exam = Exam::create(['class_id' => $class->id, 'title' => 'Algebra', 'max_score' => 10, 'duration_minutes' => 30]);
    foreach ([[$student, 0, 1], [$student, 8, 2], [$outsider, 9, 1]] as [$user, $score, $number]) {
        StudentAttempt::create([
            'exam_id' => $exam->id, 'student_id' => $user->id, 'attempt_number' => $number,
            'score_obtained' => $score, 'started_at' => '2026-06-15 12:00:00', 'finished_at' => '2026-06-15 12:10:00',
        ]);
    }

    return compact('class', 'student', 'absent', 'outsider', 'exam');
}

it('includes enrolled students without attempts and all retakes without altering historical statistics', function () {
    extract(gradebookFixture());
    $data = app(ClassReportService::class)->generate($class);

    expect($data['gradebook'])->toHaveCount(3)
        ->and(array_column($data['gradebook'], 'student_name'))->toBe(['=1+1', '=1+1', 'Absent Student'])
        ->and(array_column($data['gradebook'], 'attempt_number'))->toBe([1, 2, null])
        ->and(array_column($data['gradebook'], 'score_obtained'))->toBe([0.0, 8.0, null])
        ->and(array_column($data['gradebook'], 'status'))->toBe(['Failed', 'Passed', 'No matching attempts'])
        ->and($data['exams'][0]['stats'])->toBe(['attempts_count' => 3, 'avg_score' => 5.67, 'pass_rate' => 66.67, 'median' => 8.0])
        ->and($data['overall_stats'])->toBe(['total_attempts' => 3, 'avg_score' => 5.67, 'pass_rate' => 66.67]);
});

it('keeps the selected roster while applying exam student status and inclusive start filters to grades', function () {
    extract(gradebookFixture());
    $filters = [
        'version' => 1, 'exam_ids' => [$exam->id], 'student_ids' => [$student->id, $absent->id],
        'statuses' => ['passed'], 'started_from' => '2026-06-15T12:00:00Z', 'started_until' => '2026-06-15T12:00:00Z',
    ];
    $data = app(ClassReportService::class)->generate($class, $filters);
    expect($data['gradebook'])->toHaveCount(2)
        ->and($data['gradebook'][0])->toMatchArray(['student_name' => '=1+1', 'attempt_number' => 2, 'score_obtained' => 8.0])
        ->and($data['gradebook'][1])->toMatchArray(['student_name' => 'Absent Student', 'score_obtained' => null]);
    $filters['student_ids'] = [$absent->id];
    $filters['started_from'] = '2026-06-15T12:00:01Z';
    $filters['started_until'] = null;
    $filtered = app(ClassReportService::class)->generate($class, $filters);
    expect($filtered['gradebook'])->toHaveCount(1)
        ->and($filtered['gradebook'][0]['student_name'])->toBe('Absent Student')
        ->and($filtered['overall_stats']['total_attempts'])->toBe(0);
});

it('distinguishes unfinished and ungraded attempts from actual zero grades', function () {
    extract(gradebookFixture());
    $attempt = StudentAttempt::where('student_id', $student->id)->where('attempt_number', 1)->firstOrFail();
    $attempt->update(['score_obtained' => null]);
    StudentAttempt::where('student_id', $student->id)->where('attempt_number', 2)->update(['finished_at' => null]);
    $data = app(ClassReportService::class)->generate($class);
    expect(array_column($data['gradebook'], 'status'))->toBe(['Not graded', 'In progress', 'No matching attempts'])
        ->and(array_column($data['gradebook'], 'score_obtained'))->toBe([null, null, null]);
});

it('exports the full gradebook in real PDF and XLSX files while retaining the summary worksheet', function () {
    Storage::fake('reports');
    config()->set('reports.storage_disk', 'reports');
    extract(gradebookFixture());
    $data = app(ClassReportService::class)->generate($class);
    $formats = app(ReportFormatService::class);
    $xlsx = $formats->toExcel($data, $class);
    $book = IOFactory::load(Storage::disk('reports')->path($xlsx));
    expect($book->getSheetCount())->toBe(2)
        ->and($book->getSheet(0)->rangeToArray('A1:D2'))->toBe([
            ['Exam Title', 'Attempts', 'Avg Score', 'Pass Rate'], ['Algebra', '3', '5.67 / 10', '66.67%'],
        ]);
    $sheet = $book->getSheet(1);
    expect($sheet->getTitle())->not->toBe($book->getSheet(0)->getTitle())
        ->and($sheet->rangeToArray('A1:G4'))->toBe([
            ['Student', 'Exam', 'Attempt', 'Score', 'Max Score', 'Status', 'Finished At'],
            ['=1+1', 'Algebra', '1', '0', '10', 'Failed', '2026-06-15 12:10:00'],
            ['=1+1', 'Algebra', '2', '8', '10', 'Passed', '2026-06-15 12:10:00'],
            ['Absent Student', 'Algebra', null, null, '10', 'No matching attempts', null],
        ])
        ->and($sheet->getCell('A2')->getDataType())->toBe(DataType::TYPE_STRING)
        ->and($sheet->getStyle('A1')->getFont()->getBold())->toBeTrue();

    $pdf = $formats->toPdf($data, $class);
    $process = new Process(['pdftotext', '-layout', Storage::disk('reports')->path($pdf), '-']);
    $process->run();
    expect($process->getExitCode())->toBe(0)->and($process->getErrorOutput())->toBe('');
    $text = $process->getOutput();
    expect($text)->toContain('Student Gradebook', 'Absent Student', 'No matching attempts', '=1+1', '0 / 10', '8 / 10', 'Overall Statistics');
});

it('exports an explicit empty gradebook for classes without exams or enrolled students', function (bool $removeExams) {
    Storage::fake('reports');
    config()->set('reports.storage_disk', 'reports');
    extract(gradebookFixture());
    $removeExams ? $class->exams()->delete() : $class->students()->detach();
    $data = app(ClassReportService::class)->generate($class);
    expect($data['gradebook'])->toBe([])
        ->and(view('reports.class-pdf', ['data' => $data, 'class' => $class])->render())->toContain('No enrolled students or exams match the current report filters.');
    $file = app(ReportFormatService::class)->toExcel($data, $class);
    expect(IOFactory::load(Storage::disk('reports')->path($file))->getSheet(1)->getHighestRow())->toBe(1);
})->with([true, false]);
