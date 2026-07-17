<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): View
    {
        return view('auth.login', [
            'redirect' => request('redirect'),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        $redirect = $request->input('redirect');

        if ($redirect) {
            $safeUrl = $this->safeRedirect($redirect, $request->getHost());

            if ($safeUrl !== null) {
                return redirect($safeUrl);
            }
        }

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Validate and return a safe redirect URL.
     *
     * Relative URLs (starting with /) are always safe.
     * Absolute URLs are only safe if they match the application host.
     * Returns the safe URL or null if the URL is unsafe.
     */
    private function safeRedirect(string $url, string $appHost): ?string
    {
        // Relative URL — always safe
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return $url;
        }

        // Absolute URL — validate same host
        $parsed = parse_url($url);

        if (($parsed['host'] ?? '') === $appHost) {
            return $url;
        }

        return null;
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
