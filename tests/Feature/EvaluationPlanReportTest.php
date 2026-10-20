<?php

use App\Filament\Resources\ClassReportResource\Pages\ClassReport;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\EvaluationPlanReportService;
use App\Services\ReportFormatService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\Process\Process;

function planClass(): SchoolClass
{
    return SchoolClass::create([
        'title' => 'Evaluation Class', 'teacher_id' => User::factory()->create(['role' => 'TEACHER'])->id,
        'invitation_code' => Str::random(8),
    ]);
}

beforeEach(function () {
    config()->set('app.env', 'local');
    Storage::fake('reports');
    config()->set('reports.storage_disk', 'reports');
});

it('reads fresh ordered exams and ignores irrelevant invalid filters', function () {
    $class = planClass();
    $z = $class->exams()->create(['title' => 'Z', 'description' => null, 'max_score' => 4, 'duration_minutes' => 10]);
    $a = $class->exams()->create(['title' => 'A', 'description' => '', 'max_score' => 7, 'duration_minutes' => 10]);
    $class->load('exams');
    $a->update(['description' => "Updated\nDescripción"]);
    $service = app(EvaluationPlanReportService::class);
    $data = $service->generate($class, ['student_ids' => 'invalid', 'statuses' => ['invalid'], 'started_from' => 'invalid']);
    expect(array_column($data['exams'], 'title'))->toBe(['A', 'Z'])
        ->and($data['exams'][0])->toBe(['title' => 'A', 'description' => "Updated\nDescripción", 'max_score' => 7])
        ->and($service->generate($class, ['exam_ids' => [$z->id]])['exams'])->toBe([
            ['title' => 'Z', 'description' => null, 'max_score' => 4],
        ]);
});

it('renders real escaped PDF and literal XLSX with numeric scores', function () {
    $class = planClass();
    $class->exams()->create(['title' => '=1+1', 'description' => "=SUM(1,2)\nDescripción <b>plain</b>", 'max_score' => 17, 'duration_minutes' => 10]);
    foreach ([null, ''] as $description) {
        $class->exams()->create(['title' => 'Empty description', 'description' => $description, 'max_score' => 0, 'duration_minutes' => 10]);
    }
    $data = app(EvaluationPlanReportService::class)->generate($class);
    $formats = app(ReportFormatService::class);
    $xlsx = $formats->toEvaluationPlanExcel($data, $class);
    $sheet = IOFactory::load(Storage::disk('reports')->path($xlsx))->getActiveSheet();
    expect($sheet->rangeToArray('A1:C2'))->toBe([
        ['Exam', 'Description', 'Max Score'], ['=1+1', "=SUM(1,2)\nDescripción <b>plain</b>", '17'],
    ])->and($sheet->getCell('A2')->getDataType())->toBe(DataType::TYPE_STRING)
        ->and($sheet->getCell('B2')->getDataType())->toBe(DataType::TYPE_STRING)
        ->and($sheet->getCell('C2')->getDataType())->toBe(DataType::TYPE_NUMERIC);
    $pdf = $formats->toEvaluationPlanPdf($data, $class);
    $process = new Process(['pdftotext', '-enc', 'UTF-8', '-layout', Storage::disk('reports')->path($pdf), '-']);
    $process->run();
    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain('Evaluation Plan', '=1+1', '=SUM(1,2)', 'Descripción <b>plain</b>', '17');
    expect($formats->toEvaluationPlanExcel($data, $class))->not->toBe($xlsx);
});

it('exports empty plans explicitly', function () {
    $class = planClass();
    $data = app(EvaluationPlanReportService::class)->generate($class);
    $formats = app(ReportFormatService::class);
    $xlsx = $formats->toEvaluationPlanExcel($data, $class);
    expect(IOFactory::load(Storage::disk('reports')->path($xlsx))->getActiveSheet()->getHighestRow())->toBe(1);
    $pdf = $formats->toEvaluationPlanPdf($data, $class);
    $process = new Process(['pdftotext', Storage::disk('reports')->path($pdf), '-']);
    $process->run();
    expect($process->getExitCode())->toBe(0)->and($process->getOutput())->toContain('No exams selected or available.');
});

it('offers authorized synchronous actions and guards downloads', function (string $role, string $action) {
    $class = planClass();
    $actor = $role === 'TEACHER' ? $class->teacher : User::factory()->create(['role' => $role]);
    $selected = $class->exams()->create(['title' => 'Selected exam', 'description' => 'Plan description', 'max_score' => 13, 'duration_minutes' => 10]);
    $class->exams()->create(['title' => 'Excluded exam', 'max_score' => 99, 'duration_minutes' => 10]);
    $this->actingAs($actor);
    Livewire::test(ClassReport::class, ['record' => $class])
        ->set('filters', ['exam_ids' => [$selected->id], 'student_ids' => 'stale', 'statuses' => ['invalid']])
        ->callAction($action)->assertRedirect()->assertNotNotified();
    $files = Storage::disk('reports')->allFiles();
    expect($files)->toHaveCount(1)->and($files[0])->toContain('-evaluation-plan-');
    if (str_ends_with($files[0], '.xlsx')) {
        $sheet = IOFactory::load(Storage::disk('reports')->path($files[0]))->getActiveSheet();
        expect($sheet->getHighestRow())->toBe(2)->and($sheet->getCell('A2')->getValue())->toBe('Selected exam');
    } else {
        $process = new Process(['pdftotext', '-enc', 'UTF-8', Storage::disk('reports')->path($files[0]), '-']);
        $process->run();
        expect($process->getExitCode())->toBe(0)->and($process->getOutput())->toContain('Selected exam', 'Plan description', '13')->not->toContain('Excluded exam');
    }
    $this->get(route('reports.download', ['filename' => $files[0]]))->assertOk();
    $this->flushSession();
    $this->actingAs(User::factory()->create(['role' => 'TEACHER']))
        ->get(route('reports.download', ['filename' => $files[0]]))->assertForbidden();
})->with(['TEACHER', 'ADMIN'])->with(['downloadEvaluationPlanPdf', 'downloadEvaluationPlanExcel']);

it('rejects tampered selections without artifacts or notifications', function (mixed $selection) {
    $class = planClass();
    $foreign = planClass()->exams()->create(['title' => 'Foreign', 'max_score' => 3, 'duration_minutes' => 10]);
    $this->actingAs($class->teacher);
    Livewire::test(ClassReport::class, ['record' => $class])
        ->set('filters.exam_ids', $selection === 'foreign' ? [$foreign->id] : $selection)
        ->callAction('downloadEvaluationPlanExcel')->assertHasErrors(['exam_ids'])->assertNotNotified();
    expect(Storage::disk('reports')->allFiles())->toBe([]);
})->with([['foreign'], [['1']], ['invalid']]);

it('rejects a stale ownership action without side effects', function () {
    $class = planClass();
    $this->actingAs($class->teacher);
    $page = Livewire::test(ClassReport::class, ['record' => $class]);
    $class->update(['teacher_id' => User::factory()->create(['role' => 'TEACHER'])->id]);
    $page->call('mountAction', 'downloadEvaluationPlanPdf')->assertNotFound();
    expect(Storage::disk('reports')->allFiles())->toBe([]);
    expect(session('filament.notifications', []))->toBe([]);
});
