<?php

namespace App\Http\Controllers;

use App\Models\SchoolClass;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class JoinClassController extends Controller
{
    /**
     * Show the class invitation page for a given invitation code.
     *
     * Renders class details regardless of authentication state.
     * The view renders an auth-aware join affordance:
     *   - Guest → "Log in to join" link to /admin/login
     *   - Authenticated → "TBD: join this class" placeholder button
     */
    public function show(Request $request, string $invitationCode): View
    {
        $class = SchoolClass::where('invitation_code', $invitationCode)->firstOrFail();

        return view('class.join', [
            'class' => $class,
            'isAuthenticated' => Auth::check(),
            'loginUrl' => route('filament.admin.auth.login'),
        ]);
    }
}
