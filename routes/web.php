<?php

use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ResidentController;
use Illuminate\Support\Facades\Route;

// Show the login and portal pages.
Route::redirect('/', '/frontend/pages/index.html')->name('home');
Route::view('/frontend/pages/index.html', 'pages.index');
Route::view('/frontend/pages/resident-portal.html', 'pages.resident-portal');
Route::view('/frontend/pages/admin-dashboard.html', 'pages.admin-dashboard');
Route::view('/privacy', 'pages.privacy')->name('privacy');

// Handle login, registration, and session requests.
Route::post('/api/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
Route::post('/api/register', [AuthController::class, 'register'])->middleware('throttle:5,1');

Route::middleware('api.auth')->group(function (): void {
    Route::get('/api/session', [AuthController::class, 'session']);
    Route::post('/api/logout', [AuthController::class, 'logout']);
    Route::get('/api/reports', [ReportController::class, 'index'])->middleware('role:resident,admin');
    Route::get('/api/reports/archived', [ReportController::class, 'archived'])->middleware('role:admin');
    Route::post('/api/reports/{id}/archive', [ReportController::class, 'archive'])
        ->whereNumber('id')
        ->middleware('role:admin');
    Route::post('/api/reports/{id}/restore', [ReportController::class, 'restore'])
        ->whereNumber('id')
        ->middleware('role:admin');
    Route::post('/api/reports', [ReportController::class, 'store'])->middleware('role:resident');
    Route::post('/api/reports/update', [ReportController::class, 'update'])->middleware('role:admin');
    Route::post('/api/reports/clear', [ReportController::class, 'clear'])->middleware('role:admin');
    Route::delete('/api/reports/{id}/archived', [ReportController::class, 'destroyArchived'])
        ->whereNumber('id')
        ->middleware('role:admin');
    Route::get('/api/residents', [ResidentController::class, 'index'])->middleware('role:admin');
    Route::post('/api/residents/{id}/approval', [ResidentController::class, 'updateApproval'])
        ->whereNumber('id')
        ->middleware('role:admin');
    Route::get('/api/attachments/{id}', [AttachmentController::class, 'show'])
        ->whereNumber('id')
        ->middleware('role:resident,admin');
});
