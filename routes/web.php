<?php

use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DssController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ReferenceDataController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ResidentController;
use App\Http\Controllers\TriageController;
use Illuminate\Support\Facades\Route;

// Show the login and portal pages.
Route::redirect('/', '/frontend/pages/index.html')->name('home');
Route::view('/frontend/pages/index.html', 'pages.index');
Route::view('/frontend/pages/resident-portal.html', 'pages.resident-portal')->middleware('page.role:resident');
Route::view('/frontend/pages/admin-dashboard.html', 'pages.admin-dashboard')->middleware('page.role:admin');
Route::view('/privacy', 'pages.privacy')->name('privacy');

// Handle login, registration, and session requests.
Route::post('/api/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
Route::post('/api/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
Route::post('/api/password/forgot', [AuthController::class, 'forgotPassword'])->middleware('throttle:5,1');
Route::post('/api/password/reset', [AuthController::class, 'resetPassword'])->middleware('throttle:5,1');
Route::get('/api/email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])
    ->middleware(['signed', 'throttle:6,1'])
    ->name('verification.verify');
Route::view('/reset-password/{token}', 'pages.password-reset')->name('password.reset');

Route::middleware('api.auth')->group(function (): void {
    Route::get('/api/session', [AuthController::class, 'session']);
    Route::get('/api/reference-data', [ReferenceDataController::class, 'index'])->middleware('role:resident,admin');
    Route::post('/api/reference-data/{resource}', [ReferenceDataController::class, 'store'])->middleware('role:admin');
    Route::put('/api/reference-data/{resource}/{id}', [ReferenceDataController::class, 'update'])
        ->whereNumber('id')
        ->middleware('role:admin');
    Route::delete('/api/reference-data/{resource}/{id}', [ReferenceDataController::class, 'destroy'])
        ->whereNumber('id')
        ->middleware('role:admin');
    Route::post('/api/logout', [AuthController::class, 'logout']);
    Route::post('/api/password/change', [AuthController::class, 'changePassword'])->middleware('throttle:5,1');
    Route::post('/api/password/change/confirm', [AuthController::class, 'confirmPasswordChange'])->middleware('throttle:10,1');
    Route::get('/api/notifications', [NotificationController::class, 'index'])->middleware('role:resident');
    Route::post('/api/notifications/{id}/read', [NotificationController::class, 'markAsRead'])
        ->whereNumber('id')
        ->middleware('role:resident');
    Route::get('/api/analytics/summary', [ReportController::class, 'summary'])->middleware('role:admin');
    Route::get('/api/analytics/dss', [DssController::class, 'index'])->middleware('role:admin');
    Route::post('/api/analytics/dss/snapshots', [DssController::class, 'store'])->middleware('role:admin');
    Route::delete('/api/analytics/dss/snapshots', [DssController::class, 'destroyAll'])->middleware('role:admin');
    Route::get('/api/reports', [ReportController::class, 'index'])->middleware('role:resident,admin');
    Route::get('/api/reports/archived', [ReportController::class, 'archived'])->middleware('role:admin');
    Route::post('/api/reports/{id}/archive', [ReportController::class, 'archive'])
        ->whereNumber('id')
        ->middleware('role:admin');
    Route::post('/api/reports/{id}/restore', [ReportController::class, 'restore'])
        ->whereNumber('id')
        ->middleware('role:admin');
    Route::post('/api/reports', [ReportController::class, 'store'])->middleware(['role:resident', 'throttle:10,1']);
    Route::get('/api/reports/{id}/history', [ReportController::class, 'history'])
        ->whereNumber('id')
        ->middleware('role:resident,admin');
    Route::post('/api/reports/update', [ReportController::class, 'update'])->middleware('role:admin');
    Route::post('/api/reports/clear', [ReportController::class, 'clear'])->middleware('role:admin');
    Route::delete('/api/reports/{id}/archived', [ReportController::class, 'destroyArchived'])
        ->whereNumber('id')
        ->middleware('role:admin');
    Route::post('/api/triage/category', [TriageController::class, 'suggestCategory'])
        ->middleware(['role:resident', 'throttle:20,1']);
    Route::get('/api/reports/{id}/triage', [TriageController::class, 'review'])
        ->whereNumber('id')
        ->middleware(['role:admin', 'throttle:60,1']);
    Route::get('/api/residents', [ResidentController::class, 'index'])->middleware('role:admin');
    Route::post('/api/residents/{id}/approval', [ResidentController::class, 'updateApproval'])
        ->whereNumber('id')
        ->middleware('role:admin');
    Route::delete('/api/residents/{id}', [ResidentController::class, 'destroy'])
        ->whereNumber('id')
        ->middleware('role:admin');
    Route::get('/api/attachments/{id}', [AttachmentController::class, 'show'])
        ->whereNumber('id')
        ->middleware('role:resident,admin');
});
