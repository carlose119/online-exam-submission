<?php

namespace App\Exports;

use App\Models\SchoolClass;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class ClassReportWorkbookExport implements Export, WithMultipleSheets
{
    public function __construct(
        private readonly array $data,
        private readonly SchoolClass $class,
    ) {}

    public function sheets(): array
    {
        $summary = new ClassReportExcelExport($this->data, $this->class);
        $gradebookTitle = strcasecmp($summary->title(), 'Student Grades') === 0
            ? 'Student Grades (detail)' : 'Student Grades';

        return [
            $summary,
            new ClassGradebookExcelExport($this->data['gradebook'] ?? [], $gradebookTitle),
        ];
    }
}
