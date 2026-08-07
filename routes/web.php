<?php

use App\Http\Controllers\CalendarFeedController;
use App\Http\Controllers\IcalExportController;
use App\Http\Controllers\JoinClassController;
use App\Http\Controllers\ReportDownloadController;
use App\Http\Controllers\Student\ExamController;
use App\Livewire\Dashboard;
use App\Livewire\Student\ExamResult;
use App\Livewire\Student\ExamStart;
use App\Livewire\Student\ExamTake;
use App\Livewire\StudentProfile;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/calendar/{token}.ics', [CalendarFeedController::class, 'feed'])->name('calendar.feed');

// Breeze auth routes (login, register, forgot/reset password, etc.)
require __DIR__.'/auth.php';

// Public class join page (anyone can view)
Route::get('/clase/unirse/{invitation_code}', [JoinClassController::class, 'show'])->name('class.join.show');

// Explicit join action; the controller preserves the invitation GET across auth and verification.
Route::post('/clase/unirse/{invitation_code}/join', [JoinClassController::class, 'join'])->name('class.join.action');

// Student dashboard (Livewire component, auth + role:STUDENT only)
Route::get('/dashboard', Dashboard::class)->name('dashboard')->middleware(['auth', 'role:STUDENT', 'verified']);

// Verified student exam and meeting calendar routes.
Route::middleware(['auth', 'role:STUDENT', 'verified'])->group(function () {
    Route::get('/examenes/{exam}/intentar', ExamStart::class)->name('student.exam.start');
    Route::get('/examenes/{attempt}/tomar', ExamTake::class)->name('student.exam.take')->middleware('checkTimer');
    Route::post('/examenes/{attempt}/responder/{question}', [ExamController::class, 'answer'])->name('student.exam.answer')->middleware('checkTimer');
    Route::post('/examenes/{attempt}/finalizar', [ExamController::class, 'submit'])->name('student.exam.submit');
    Route::get('/examenes/{attempt}/resultado', ExamResult::class)->name('student.exam.result');

    Route::get('/meetings/{meeting}/ics', [IcalExportController::class, 'export'])
        ->name('meetings.ics');
});

// Student profile (name editing and read-only enrollment summary)
Route::get('/profile', StudentProfile::class)
    ->name('profile.show')
    ->middleware(['auth', 'role:STUDENT', 'verified']);

// Report download route (auth + role:admin,teacher only)
Route::get('/admin/reports/download/{filename}', [ReportDownloadController::class, 'download'])
    ->name('reports.download')
    ->where('filename', '.*')
    ->middleware(['auth', 'role:ADMIN,TEACHER']);
