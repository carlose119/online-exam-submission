<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Cell\StringValueBinder;

class EvaluationPlanExcelExport extends StringValueBinder implements FromCollection, ShouldAutoSize, WithCustomValueBinder, WithHeadings, WithStrictNullComparison, WithTitle
{
    public function __construct(private readonly array $rows)
    {
        $this->setNullConversion(false)->setNumericConversion(false);
    }

    public function collection(): Collection
    {
        return collect($this->rows)->map(fn (array $row): array => [
            $row['title'], $row['description'], $row['max_score'],
        ]);
    }

    public function headings(): array
    {
        return ['Exam', 'Description', 'Max Score'];
    }

    public function title(): string
    {
        return 'Evaluation Plan';
    }
}
