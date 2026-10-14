<?php

use App\Filament\Resources\ClassReportResource\Pages\ClassReport;
use App\Models\SchoolClass;
use App\Models\StudentAttempt;
use App\Models\User;
use Livewire\Livewire;

it('renders self-contained report styles and readable semantic sections without losing filter data', function () {
    config()->set('app.env', 'local');
    $teacher = User::factory()->create(['role' => 'TEACHER']);
    $student = User::factory()->create(['name' => 'Sample Student', 'role' => 'STUDENT']);
    $class = SchoolClass::create([
        'teacher_id' => $teacher->id, 'title' => 'Styled Class', 'invitation_code' => 'STYLEDCLASS',
    ]);
    $exam = $class->exams()->create(['title' => 'Styled Exam', 'duration_minutes' => 30, 'max_score' => 10]);
    StudentAttempt::create([
        'student_id' => $student->id, 'exam_id' => $exam->id, 'score_obtained' => 10,
        'started_at' => now()->subMinutes(20), 'finished_at' => now()->subMinutes(10),
    ]);
    $this->actingAs($teacher);

    Livewire::test(ClassReport::class, ['record' => $class])
        ->assertSeeHtml('<style data-report-styles>')
        ->assertSeeHtml('class="class-report"')
        ->assertSeeHtml('<dl class="report-stats">')
        ->assertSeeHtml('class="report-table-scroll"')
        ->assertSeeHtml('scope="col"')
        ->assertSee('Styled Class')->assertSee('Sample Student')->assertSee('10 / 10')
        ->assertSee('Download PDF')->assertSee('Download Excel')
        ->callAction('filters', data: ['statuses' => ['failed']])
        ->assertSee('No attempts match the current report filters.')
        ->assertSet('reportData.overall_stats.total_attempts', 0)
        ->assertSeeHtml('<style data-report-styles>')
        ->callAction('clearFilters')
        ->assertSet('reportData.overall_stats.total_attempts', 1)
        ->assertSee('Sample Student')->assertSee('10 / 10');
});
