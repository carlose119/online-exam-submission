<?php

use App\Http\Controllers\JoinClassController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/clase/unirse/{invitation_code}', [JoinClassController::class, 'show'])->name('class.join.show');
