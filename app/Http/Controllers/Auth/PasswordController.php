<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class PasswordController extends Controller
{
    /**
     * Update the user's password.
     *
     * NOTE: The password is passed as plain text because the User model's
     * setPasswordAttribute mutator + 'hashed' cast handle bcrypt hashing.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validateWithBag('updatePassword', [
            'current_password' => ['required', 'current_password'],
            'password' => ['required', Password::defaults(), 'confirmed'],
        ]);

        if (Hash::check($validated['password'], $request->user()->getAuthPassword())) {
            throw ValidationException::withMessages([
                'password' => 'La nueva contraseña debe ser diferente de la actual.',
            ])->errorBag('updatePassword');
        }

        $request->user()->update([
            'password' => $validated['password'],
        ]);

        Auth::logoutOtherDevices($validated['password']);

        return back()->with('status', 'password-updated');
    }
}
