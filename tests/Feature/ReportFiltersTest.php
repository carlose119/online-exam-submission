<?php

use App\Exports\ClassReportExcelExport;
use App\Filament\Resources\ClassReportResource\Pages\ClassReport;
use App\Jobs\GenerateClassReportExcel;
use App\Jobs\GenerateClassReportPdf;
use App\Models\Exam;
use App\Models\SchoolClass;
use App\Models\StudentAttempt;
use App\Models\User;
use App\Services\ClassReportService;
use App\Services\ReportArtifactPublisher;
use App\Services\ReportFormatService;
use App\Values\ReportFilters;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\Process\Process;

function filterFixture(): array
{
    $teacher = User::factory()->create(['role' => 'TEACHER']);
    $class = SchoolClass::create(['title' => 'Filtered', 'teacher_id' => $teacher->id, 'invitation_code' => fake()->unique()->regexify('[A-Z0-9]{8}')]);
    $other = SchoolClass::create(['title' => 'Other', 'teacher_id' => $teacher->id, 'invitation_code' => fake()->unique()->regexify('[A-Z0-9]{8}')]);
    $student = User::factory()->create(['role' => 'STUDENT']);
    $excluded = User::factory()->create(['role' => 'STUDENT']);
    $foreign = User::factory()->create(['role' => 'STUDENT']);
    $class->students()->attach([$student->id, $excluded->id]);
    $other->students()->attach($foreign);
    $exam = Exam::create(['class_id' => $class->id, 'title' => 'Included', 'max_score' => 10, 'duration_minutes' => 10]);
    $otherExam = Exam::create(['class_id' => $class->id, 'title' => 'Excluded', 'max_score' => 10, 'duration_minutes' => 10]);
    $foreignExam = Exam::create(['class_id' => $other->id, 'title' => 'Foreign', 'max_score' => 10, 'duration_minutes' => 10]);

    StudentAttempt::create(['student_id' => $student->id, 'exam_id' => $exam->id, 'score_obtained' => 8, 'started_at' => '2026-06-15 12:00:00', 'finished_at' => '2026-06-15 12:05:00']);
    StudentAttempt::create(['student_id' => $excluded->id, 'exam_id' => $exam->id, 'score_obtained' => 4, 'started_at' => '2026-06-15 12:00:00', 'finished_at' => '2026-06-15 12:05:00']);
    StudentAttempt::create(['student_id' => $student->id, 'exam_id' => $otherExam->id, 'score_obtained' => null, 'started_at' => '2026-06-15 12:00:00']);

    return compact('teacher', 'class', 'student', 'excluded', 'exam', 'otherExam', 'foreign', 'foreignExam');
}

it('normalizes canonical scalar filters and rejects cross-class ids', function () {
    extract(filterFixture());
    $filters = ReportFilters::from([
        'version' => 1,
        'exam_ids' => [$exam->id, $exam->id],
        'student_ids' => [$student->id],
        'statuses' => ['passed'],
        'started_from' => '2026-06-15T08:00:00-04:00',
    ], $class)->toArray();
    expect($filters)->toMatchArray([
        'version' => 1, 'exam_ids' => [$exam->id], 'student_ids' => [$student->id],
        'statuses' => ['passed'], 'started_from' => '2026-06-15T12:00:00.000000Z',
    ]);
    expect(fn () => ReportFilters::from(['version' => 1, 'exam_ids' => [$foreignExam->id]], $class))
        ->toThrow(ValidationException::class);
    expect(fn () => ReportFilters::from(['version' => 1, 'student_ids' => [$foreign->id]], $class))
        ->toThrow(ValidationException::class);
});

it('applies combined filters to the shared report payload using started_at', function () {
    extract(filterFixture());
    $data = app(ClassReportService::class)->generate($class, [
        'version' => 1,
        'exam_ids' => [$exam->id], 'student_ids' => [$student->id], 'statuses' => ['passed'],
        'started_from' => '2026-06-15T11:59:00Z', 'started_until' => '2026-06-15T12:01:00Z',
    ]);
    expect($data['exams'])->toHaveCount(1)
        ->and($data['exams'][0]['attempts'])->toHaveCount(1)
        ->and($data['overall_stats'])->toMatchArray(['total_attempts' => 1, 'avg_score' => 8.0, 'pass_rate' => 100.0]);
});

it('applies and clears page filters and uses the filtered threshold for both jobs', function () {
    Queue::fake();
    Storage::fake('reports');
    config()->set('reports.storage_disk', 'reports');
    config()->set('reports.sync_threshold', 2);
    extract(filterFixture());
    $this->actingAs($teacher);

    $page = Livewire::test(ClassReport::class, ['record' => $class])
        ->callAction('filters', data: ['exam_ids' => [(string) $exam->id], 'student_ids' => [(string) $student->id], 'statuses' => ['passed']])
        ->assertSet('reportData.overall_stats.total_attempts', 1)
        ->callAction('downloadPdf')
        ->callAction('downloadExcel');

    Queue::assertNothingPushed();
    expect(Storage::disk('reports')->allFiles())->toHaveCount(2);
    $page->callAction('clearFilters')->assertSet('filters', ReportFilters::EMPTY)->assertSet('reportData.overall_stats.total_attempts', 3);
    expect(fn () => ReportFilters::fromTrustedForm(['exam_ids' => [(string) $foreignExam->id]], $class))
        ->toThrow(ValidationException::class);
    Livewire::test(ClassReport::class, ['record' => $class])
        ->callAction('filters', data: ['exam_ids' => [(string) $foreignExam->id]])
        ->assertSet('filters', ReportFilters::EMPTY)
        ->assertSet('reportData.overall_stats.total_attempts', 3);
});

it('passes canonical filters through both queued publishers and rejects tampering', function (string $jobClass, string $format) {
    extract(filterFixture());
    $filters = ReportFilters::from(['version' => 1, 'exam_ids' => [$exam->id], 'statuses' => ['passed']], $class)->toArray();
    $publisher = Mockery::mock(ReportArtifactPublisher::class);
    $publisher->shouldReceive('publish')->once()->withArgs(fn ($record, $user, $actualFormat, $actualFilters) => $record->is($class)
        && $user->is($teacher) && $actualFormat === $format && $actualFilters === $filters);

    (new $jobClass($class->id, $teacher->id, $filters))->handle($publisher);

    expect(fn () => app(ClassReportService::class)->generate($class, ['version' => 1, 'exam_ids' => [$foreignExam->id]]))
        ->toThrow(ValidationException::class);
})->with([
    'PDF' => [GenerateClassReportPdf::class, 'pdf'],
    'Excel' => [GenerateClassReportExcel::class, 'xlsx'],
]);

it('rejects malformed or tampered queued filters before creating an artifact', function (array $filters) {
    Storage::fake('reports');
    config()->set('reports.storage_disk', 'reports');
    extract(filterFixture());

    $filters['exam_ids'] ??= [$foreignExam->id];
    $job = new GenerateClassReportPdf($class->id, $teacher->id, $filters);

    expect(fn () => $job->handle(app(ReportArtifactPublisher::class)))->toThrow(ValidationException::class);
    expect(Storage::disk('reports')->allFiles())->toBeEmpty()->and($teacher->notifications()->count())->toBe(0);
})->with([
    'tampered class id' => [['version' => 1]],
    'missing version' => [['exam_ids' => []]],
    'relative date' => [['version' => 1, 'started_from' => 'tomorrow']],
]);

it('strictly parses versioned integers and canonical timestamps', function (array $payload) {
    extract(filterFixture());
    expect(fn () => ReportFilters::from($payload, $class))->toThrow(ValidationException::class);
})->with([
    'missing version' => [['exam_ids' => []]],
    'numeric string' => [['version' => 1, 'exam_ids' => ['1']]],
    'float' => [['version' => 1, 'student_ids' => [1.0]]],
    'boolean' => [['version' => 1, 'student_ids' => [true]]],
    'wrong version' => [['version' => 2]],
    'relative date' => [['version' => 1, 'started_from' => 'tomorrow']],
    'timezone-less date' => [['version' => 1, 'started_from' => '2026-06-15T12:00:00']],
    'invalid date' => [['version' => 1, 'started_until' => '2026-02-30T12:00:00Z']],
    'year zero' => [['version' => 1, 'started_from' => '0000-01-01T00:00:00Z']],
    'offset 14:01' => [['version' => 1, 'started_from' => '2026-01-01T00:00:00+14:01']],
    'offset 24' => [['version' => 1, 'started_from' => '2026-01-01T00:00:00+24:00']],
    'offset 99' => [['version' => 1, 'started_from' => '2026-01-01T00:00:00+99:99']],
    'malformed sign' => [['version' => 1, 'started_from' => '2026-01-01T00:00:00++01:00']],
]);

it('rejects malformed trusted select values', function (mixed $id) {
    extract(filterFixture());
    expect(fn () => ReportFilters::fromTrustedForm(['exam_ids' => [$id]], $class))->toThrow(ValidationException::class);
})->with(['+1', '-1', '1.0', ' 1', '1e2', (string) PHP_INT_MAX.'0', true]);

it('rejects nested trusted select values', function () {
    extract(filterFixture());
    expect(fn () => ReportFilters::fromTrustedForm(['exam_ids' => [['1']]], $class))->toThrow(ValidationException::class);
});

it('includes exact endpoints, keeps selected empty exams, and gives every format the same payload', function () {
    extract(filterFixture());
    $filters = ['version' => 1, 'exam_ids' => [$exam->id, $foreignExam->id], 'started_from' => '2026-06-15T12:00:00Z', 'started_until' => '2026-06-15T12:00:00Z'];
    expect(fn () => app(ClassReportService::class)->generate($class, $filters))->toThrow(ValidationException::class);
    $filters['exam_ids'] = [$exam->id, $otherExam->id];
    $filters['statuses'] = ['passed'];
    $data = app(ClassReportService::class)->generate($class, $filters);
    $html = view('reports.class-pdf', ['data' => $data, 'class' => $class])->render();
    $page = view('filament.resources.class-report-resource.pages.class-report', ['reportData' => $data])->render();
    $rows = (new ClassReportExcelExport($data, $class))->collection();

    expect($data['overall_stats']['total_attempts'])->toBe(1)
        ->and($data['exams'][0]['stats']['attempts_count'])->toBe(0)
        ->and($html)->toContain('Included', 'Excluded')->and($page)->toContain('Included', 'Excluded')
        ->and($rows)->toHaveCount(2)->and($rows->first()[0])->toBe('Excluded');
});

it('denies stale option loading without revealing class labels', function (string $revocation) {
    extract(filterFixture());
    $this->actingAs($teacher);
    $page = Livewire::test(ClassReport::class, ['record' => $class]);
    $revocation === 'ownership'
        ? $class->update(['teacher_id' => User::factory()->create(['role' => 'TEACHER'])->id])
        : $teacher->update(['role' => 'STUDENT']);

    $page->call('mountAction', 'filters')->assertForbidden()->assertDontSee('Included')->assertDontSee($student->name);
})->with(['ownership', 'role']);

it('evaluates current matching data when a queued job runs', function () {
    Storage::fake('reports');
    config()->set('reports.storage_disk', 'reports');
    extract(filterFixture());
    $job = new GenerateClassReportExcel($class->id, $teacher->id, ReportFilters::from(['version' => 1, 'exam_ids' => [$exam->id]], $class)->toArray());
    StudentAttempt::create(['student_id' => $student->id, 'exam_id' => $exam->id, 'score_obtained' => 10, 'started_at' => now(), 'finished_at' => now()]);

    $job->handle(app(ReportArtifactPublisher::class));
    $file = Storage::disk('reports')->allFiles()[0];
    $rows = IOFactory::load(Storage::disk('reports')->path($file))->getActiveSheet()->toArray();
    expect($rows[1][1])->toBe('3')
        ->and(app(ClassReportService::class)->generate($class)['overall_stats'])->toMatchArray(['total_attempts' => 4, 'avg_score' => 5.5, 'pass_rate' => 50.0]);
});

it('generates filtered PDF and XLSX artifacts with matching sparse content', function () {
    Storage::fake('reports');
    config()->set('reports.storage_disk', 'reports');
    extract(filterFixture());
    $data = app(ClassReportService::class)->generate($class, [
        'version' => 1, 'exam_ids' => [$exam->id, $otherExam->id], 'student_ids' => [$student->id], 'statuses' => ['passed'],
    ]);
    $formats = app(ReportFormatService::class);
    $pdf = $formats->toPdf($data, $class, 'parity.pdf');
    $xlsx = $formats->toExcel($data, $class, 'parity.xlsx');
    $text = tempnam(sys_get_temp_dir(), 'report-pdf-');
    $process = new Process(['pdftotext', Storage::disk('reports')->path($pdf), $text]);
    $process->mustRun();
    $pdfText = file_get_contents($text);
    @unlink($text);
    $rows = IOFactory::load(Storage::disk('reports')->path($xlsx))->getActiveSheet()->toArray();

    expect($pdfText)->toContain('Included', 'Excluded', $student->name)->not->toContain($excluded->name, $foreign->name)
        ->and($rows[1])->toMatchArray(['Excluded', null])->and($rows[2])->toMatchArray(['Included', '1']);
});

it('queued PDF and XLSX include current matching data and notify', function (string $jobClass, string $extension) {
    Storage::fake('reports');
    config()->set('reports.storage_disk', 'reports');
    extract(filterFixture());
    $filters = ReportFilters::from(['version' => 1, 'exam_ids' => [$exam->id]], $class)->toArray();
    $job = new $jobClass($class->id, $teacher->id, $filters);
    $late = User::factory()->create(['name' => 'Late Current', 'role' => 'STUDENT']);
    StudentAttempt::create(['student_id' => $late->id, 'exam_id' => $exam->id, 'score_obtained' => 9, 'started_at' => now(), 'finished_at' => now()]);

    $job->handle(app(ReportArtifactPublisher::class));
    $file = collect(Storage::disk('reports')->allFiles())->first(fn ($path) => str_ends_with($path, $extension));
    expect($file)->not->toBeNull()->and($teacher->notifications()->count())->toBe(1);
    if ($extension === '.pdf') {
        $text = tempnam(sys_get_temp_dir(), 'queued-pdf-');
        (new Process(['pdftotext', Storage::disk('reports')->path($file), $text]))->mustRun();
        expect(file_get_contents($text))->toContain('Late Current');
        @unlink($text);
    } else {
        expect(IOFactory::load(Storage::disk('reports')->path($file))->getActiveSheet()->toArray()[1][1])->toBe('3');
    }
})->with([[GenerateClassReportPdf::class, '.pdf'], [GenerateClassReportExcel::class, '.xlsx']]);
