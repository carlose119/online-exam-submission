<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Cell\StringValueBinder;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ClassGradebookExcelExport extends StringValueBinder implements FromCollection, ShouldAutoSize, WithCustomValueBinder, WithHeadings, WithStrictNullComparison, WithStyles, WithTitle
{
    public function __construct(
        private readonly array $rows,
        private readonly string $sheetTitle,
    ) {
        // Treat names/titles as text, never formulas; keep scores numeric and blanks null.
        $this->setNullConversion(false)->setNumericConversion(false);
    }

    public function collection(): Collection
    {
        return collect($this->rows)->map(fn (array $row) => [
            $row['student_name'], $row['exam_title'], $row['attempt_number'],
            $row['score_obtained'], $row['max_score'], $row['status'], $row['finished_at'],
        ]);
    }

    public function headings(): array
    {
        return ['Student', 'Exam', 'Attempt', 'Score', 'Max Score', 'Status', 'Finished At'];
    }

    public function title(): string
    {
        return $this->sheetTitle;
    }

    public function styles(Worksheet $sheet): array
    {
        return [1 => ['font' => ['bold' => true]]];
    }
}
