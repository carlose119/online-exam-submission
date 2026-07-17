<?php

use App\Http\Controllers\JoinClassController;
use App\Livewire\Dashboard;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Breeze auth routes (login, register, forgot/reset password, etc.)
require __DIR__.'/auth.php';

// Public class join page (anyone can view)
Route::get('/clase/unirse/{invitation_code}', [JoinClassController::class, 'show'])->name('class.join.show');

// Authenticated join action (creates class_user pivot)
Route::post('/clase/unirse/{invitation_code}/join', [JoinClassController::class, 'join'])->name('class.join.action')->middleware('auth');

// Student dashboard (Livewire component, auth + role:STUDENT only)
Route::get('/dashboard', Dashboard::class)->name('dashboard')->middleware(['auth', 'role:STUDENT']);

// Profile routes (Breeze scaffold — profile editing is deferred)
Route::middleware('auth')->group(function () {
    Route::get('/profile', [App\Http\Controllers\ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [App\Http\Controllers\ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [App\Http\Controllers\ProfileController::class, 'destroy'])->name('profile.destroy');
});
