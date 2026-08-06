<?php

namespace App\Exports;

use App\Models\SchoolClass;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ClassReportExcelExport implements FromCollection, ShouldAutoSize, WithHeadings, WithStyles, WithTitle
{
    /**
     * @param  array  $data  The structured array from ClassReportService::generate()
     * @param  SchoolClass  $class
     */
    public function __construct(
        private readonly array $data,
        private readonly mixed $class,
    ) {}

    public function collection(): Collection
    {
        $rows = [];

        foreach ($this->data['exams'] as $examEntry) {
            $exam = $examEntry['exam'];
            $stats = $examEntry['stats'];

            $rows[] = [
                $exam['title'],
                $stats['attempts_count'],
                $stats['avg_score'].' / '.$exam['max_score'],
                $stats['pass_rate'].'%',
            ];
        }

        return collect($rows);
    }

    public function headings(): array
    {
        return [
            'Exam Title',
            'Attempts',
            'Avg Score',
            'Pass Rate',
        ];
    }

    public function title(): string
    {
        $title = $this->data['class']['title'] ?? 'Class Report';

        // Sheet titles are limited to 31 characters.
        return mb_substr($title, 0, 31);
    }

    public function styles(Worksheet $sheet): void
    {
        // Bold header row.
        $sheet->getStyle('1')->getFont()->setBold(true);
    }
}
