<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Support\InvitationReturnUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(InvitationReturnUrl $invitationReturnUrl): View
    {
        return view('auth.login', [
            'redirect' => $invitationReturnUrl->resolve(request('redirect'), request()->getHost()),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request, InvitationReturnUrl $invitationReturnUrl): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        $redirect = $invitationReturnUrl->resolve($request->input('redirect'), $request->getHost());

        if ($request->user()->role === 'STUDENT' && ! $request->user()->hasVerifiedEmail()) {
            if ($redirect !== null) {
                $request->session()->put('url.intended', $redirect);
            }

            return redirect()->route('verification.notice');
        }

        if ($redirect !== null) {
            return redirect($redirect);
        }

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}
