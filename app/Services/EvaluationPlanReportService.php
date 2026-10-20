<?php

namespace App\Services;

use App\Models\SchoolClass;
use App\Values\ReportFilters;

class EvaluationPlanReportService
{
    public function generate(SchoolClass $class, array $filters = []): array
    {
        $selection = ReportFilters::from([
            ...ReportFilters::EMPTY, 'exam_ids' => $filters['exam_ids'] ?? [],
        ], $class);
        $class = SchoolClass::query()->with('teacher:id,name')->findOrFail($class->id);
        $exams = $class->exams()->select(['id', 'title', 'description', 'max_score'])
            ->when($selection->examIds, fn ($query) => $query->whereKey($selection->examIds))
            ->orderBy('title')->orderBy('id')->get();

        return [
            'class_title' => $class->title,
            'teacher_name' => $class->teacher?->name,
            'exams' => $exams->map(fn ($exam): array => [
                'title' => $exam->title, 'description' => $exam->description, 'max_score' => (int) $exam->max_score,
            ])->all(),
        ];
    }
}
