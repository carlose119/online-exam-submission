<?php

namespace App\Http\Controllers;

use App\Models\Meeting;
use App\Services\IcalBuilder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class IcalExportController extends Controller
{
    /**
     * Download a single meeting as an RFC 5545 .ics file.
     *
     * Auth and role:student middleware run BEFORE this controller.
     */
    public function export(Meeting $meeting, Request $request): Response
    {
        if (! $meeting->classroom->students()->where('users.id', Auth::id())->exists()) {
            abort(403);
        }

        $icsContent = app(IcalBuilder::class)->build($meeting);

        return response($icsContent, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="meeting-' . $meeting->id . '.ics"',
        ]);
    }
}
