<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\InvitationReturnUrl;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(InvitationReturnUrl $invitationReturnUrl): View
    {
        return view('auth.register', [
            'redirect' => $invitationReturnUrl->resolve(request('redirect'), request()->getHost()),
        ]);
    }

    /**
     * Handle an incoming registration request.
     *
     * NOTE: The password is passed as plain text because the User model's
     * setPasswordAttribute mutator + 'hashed' cast handle bcrypt hashing.
     * role is set to STUDENT because this is the student-facing auth stack;
     * the Filament admin panel uses its own guard and is unaffected.
     *
     * @throws ValidationException
     */
    public function store(Request $request, InvitationReturnUrl $invitationReturnUrl): RedirectResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => $request->password,
            'role' => 'STUDENT',
        ]);

        event(new Registered($user));

        Auth::login($user);

        $redirect = $invitationReturnUrl->resolve($request->input('redirect'), $request->getHost());

        if ($redirect !== null) {
            $request->session()->put('url.intended', $redirect);
        }

        return redirect(route('verification.notice', absolute: false));
    }
}
