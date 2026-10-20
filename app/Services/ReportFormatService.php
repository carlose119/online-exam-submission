<?php

namespace App\Services;

use App\Exports\ClassReportWorkbookExport;
use App\Exports\EvaluationPlanExcelExport;
use App\Models\SchoolClass;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;

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

        $template = 'reports.class-pdf';
        $compiledPath = app('blade.compiler')->getCompiledPath(View::getFinder()->find($template));
        $lockPath = $compiledPath.'.lock';
        if (! is_dir(dirname($lockPath)) && ! app('files')->makeDirectory(dirname($lockPath), 0755, true, true)) {
            throw new RuntimeException("Unable to create report view lock directory: {$lockPath}");
        }

        $lock = @fopen($lockPath, 'c');
        if ($lock === false) {
            throw new RuntimeException("Unable to open report view lock: {$lockPath}");
        }
        try {
            if (! flock($lock, LOCK_EX)) {
                throw new RuntimeException("Unable to acquire report view lock: {$lockPath}");
            }
            // Blade can compile and include the same file during render; protect both.
            $html = View::make($template, ['data' => $data, 'class' => $class])->render();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        $pdfContent = Pdf::loadHTML($html)->setPaper('a4', 'landscape')->output();

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
            new ClassReportWorkbookExport($data, $class),
            $filename,
            config('reports.storage_disk'),
        );

        return $filename;
    }

    public function toEvaluationPlanPdf(array $data, SchoolClass $class): string
    {
        $filename = $this->filename($class, 'pdf', 'evaluation-plan-'.Str::uuid());
        $template = 'reports.evaluation-plan-pdf';
        $compiledPath = app('blade.compiler')->getCompiledPath(View::getFinder()->find($template));
        $lockPath = $compiledPath.'.lock';
        if (! is_dir(dirname($lockPath)) && ! app('files')->makeDirectory(dirname($lockPath), 0755, true, true)) {
            throw new RuntimeException("Unable to create report view lock directory: {$lockPath}");
        }
        $lock = @fopen($lockPath, 'c');
        if ($lock === false) {
            throw new RuntimeException("Unable to open report view lock: {$lockPath}");
        }
        try {
            if (! flock($lock, LOCK_EX)) {
                throw new RuntimeException("Unable to acquire report view lock: {$lockPath}");
            }
            $html = View::make($template, ['data' => $data])->render();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        Storage::disk(config('reports.storage_disk'))->put(
            $filename, Pdf::loadHTML($html)->setPaper('a4', 'portrait')->output(),
        );

        return $filename;
    }

    public function toEvaluationPlanExcel(array $data, SchoolClass $class): string
    {
        $filename = $this->filename($class, 'xlsx', 'evaluation-plan-'.Str::uuid());
        Excel::store(new EvaluationPlanExcelExport($data['exams']), $filename, config('reports.storage_disk'));

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
