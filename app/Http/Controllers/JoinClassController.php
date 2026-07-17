<?php

namespace App\Http\Controllers;

use App\Models\ClassUser;
use App\Models\SchoolClass;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class JoinClassController extends Controller
{
    /**
     * Show the class invitation page for a given invitation code.
     *
     * Renders class details regardless of authentication state.
     */
    public function show(Request $request, string $invitationCode): View
    {
        $class = SchoolClass::where('invitation_code', $invitationCode)->firstOrFail();

        $materials = $class->studyMaterials()->orderByDesc('created_at')->get();

        return view('class.join', [
            'class' => $class,
            'materials' => $materials,
            'isAuthenticated' => Auth::check(),
            'invitationCode' => $invitationCode,
        ]);
    }

    /**
     * Subscribe the authenticated user to the class.
     *
     * An explicit POST action behind the auth middleware:
     *   1. Find the class by invitation_code or 404.
     *   2. firstOrCreate the class_user pivot (idempotent).
     *   3. Redirect to /dashboard with a success flash.
     */
    public function join(Request $request, string $invitationCode): RedirectResponse
    {
        $class = SchoolClass::where('invitation_code', $invitationCode)->firstOrFail();

        ClassUser::firstOrCreate([
            'class_id' => $class->id,
            'user_id' => Auth::id(),
        ], []);

        return redirect()->route('dashboard')
            ->with('status', 'You have joined ' . $class->title . '!');
    }
}
