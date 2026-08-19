<?php

namespace App\Http\Controllers;

use App\Models\SchoolClass;
use App\Services\ReportAccess;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportDownloadController extends Controller
{
    /**
     * Download a generated report file from the reports storage disk.
     *
     * The filename is validated to prevent path-traversal attacks.
     * Only authenticated ADMIN and TEACHER users can access this route
     * (enforced via the `auth` + `role:admin,teacher` middleware on the route).
     */
    public function download(string $filename, ReportAccess $access): StreamedResponse|BinaryFileResponse
    {
        // Path-traversal guard: ensure filename contains no directory separators.
        if ($filename !== basename($filename)) {
            abort(400, 'Invalid filename.');
        }

        if (! preg_match('/^class-(\d+)-.+\.(pdf|xlsx)$/', $filename, $matches)) {
            abort(404, 'Report file not found.');
        }

        $class = SchoolClass::findOrFail((int) $matches[1]);
        $access->authorize(auth()->user(), $class);

        $disk = config('reports.storage_disk', 'reports');

        if (! Storage::disk($disk)->exists($filename)) {
            abort(404, 'Report file not found.');
        }

        return Storage::disk($disk)->download($filename);
    }
}
