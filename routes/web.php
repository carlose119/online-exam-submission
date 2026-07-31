<?php

use App\Http\Controllers\JoinClassController;
use App\Http\Controllers\ReportDownloadController;
use App\Http\Controllers\Student\ExamController;
use App\Livewire\Dashboard;
use App\Livewire\Student\ExamResult;
use App\Livewire\Student\ExamStart;
use App\Livewire\Student\ExamTake;
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

// Student exam taking routes (auth + role:STUDENT only)
Route::middleware(['auth', 'role:STUDENT'])->group(function () {
    Route::get('/examenes/{exam}/intentar', ExamStart::class)->name('student.exam.start');
    Route::get('/examenes/{attempt}/tomar', ExamTake::class)->name('student.exam.take')->middleware('checkTimer');
    Route::post('/examenes/{attempt}/responder/{question}', [ExamController::class, 'answer'])->name('student.exam.answer')->middleware('checkTimer');
    Route::post('/examenes/{attempt}/finalizar', [ExamController::class, 'submit'])->name('student.exam.submit');
    Route::get('/examenes/{attempt}/resultado', ExamResult::class)->name('student.exam.result');
});

// Profile routes (Breeze scaffold — profile editing is deferred)
Route::middleware('auth')->group(function () {
    Route::get('/profile', [App\Http\Controllers\ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [App\Http\Controllers\ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [App\Http\Controllers\ProfileController::class, 'destroy'])->name('profile.destroy');
});

// Report download route (auth + role:admin,teacher only)
Route::get('/admin/reports/download/{filename}', [ReportDownloadController::class, 'download'])
    ->name('reports.download')
    ->where('filename', '.*')
    ->middleware(['auth', 'role:ADMIN,TEACHER']);
