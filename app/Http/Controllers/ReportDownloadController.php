<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ReportDownloadController extends Controller
{
    /**
     * Download a generated report file from the reports storage disk.
     *
     * The filename is validated to prevent path-traversal attacks.
     * Only authenticated ADMIN and TEACHER users can access this route
     * (enforced via the `auth` + `role:admin,teacher` middleware on the route).
     */
    public function download(string $filename): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        // Path-traversal guard: ensure filename contains no directory separators.
        if ($filename !== basename($filename)) {
            abort(400, 'Invalid filename.');
        }

        $disk = config('reports.storage_disk', 'reports');

        if (! Storage::disk($disk)->exists($filename)) {
            abort(404, 'Report file not found.');
        }

        return Storage::disk($disk)->download($filename);
    }
}
