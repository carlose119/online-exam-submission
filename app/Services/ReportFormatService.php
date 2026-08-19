<?php

namespace App\Services;

use App\Exports\ClassReportExcelExport;
use App\Models\SchoolClass;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class ReportFormatService
{
    /**
     * Generate a PDF report for a class and store it on the reports disk.
     *
     * @param  array  $data  Structured data from ClassReportService::generate()
     * @return string The filename (not full path) stored on the reports disk.
     */
    public function toPdf(array $data, SchoolClass $class, ?string $filename = null): string
    {
        $filename ??= $this->filename($class, 'pdf');

        $pdfContent = Pdf::loadView('reports.class-pdf', [
            'data' => $data,
            'class' => $class,
        ])
            ->setPaper('a4', 'landscape')
            ->output();

        Storage::disk(config('reports.storage_disk'))
            ->put($filename, $pdfContent);

        return $filename;
    }

    /**
     * Generate an Excel report for a class and store it on the reports disk.
     *
     * @param  array  $data  Structured data from ClassReportService::generate()
     * @return string The filename (not full path) stored on the reports disk.
     */
    public function toExcel(array $data, SchoolClass $class, ?string $filename = null): string
    {
        $filename ??= $this->filename($class, 'xlsx');

        Excel::store(
            new ClassReportExcelExport($data, $class),
            $filename,
            config('reports.storage_disk'),
        );

        return $filename;
    }

    /**
     * Build a deterministic filename: class-{id}-{timestamp}.{ext}
     */
    public function filename(SchoolClass $class, string $ext, ?string $suffix = null): string
    {
        return sprintf('class-%d-%s%s.%s', $class->id, now()->format('Ymd-His'), $suffix ? '-'.$suffix : '', $ext);
    }
}
