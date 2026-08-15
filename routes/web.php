<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\AccountRegistrationController;
use App\Http\Controllers\Admin\UserAccountController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Admin\DashboardController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

// ---------- Public Routes ----------
Route::get('/', [AuthController::class, 'index'])->name('home');

// ---------- Authentication Routes ----------
Route::get('/login', [AuthController::class, 'index'])->name('login');
Route::get('/signup', [AuthController::class, 'index'])->name('signup');

// ---------- Admin Routes (Vue.js SPA) ----------
Route::prefix('admin')->name('admin.')->group(function () {
    // Main admin entry point - loads the Vue.js SPA
    Route::get('/{any?}', function () {
        return view('admin');
    })->where('any', '.*')->name('dashboard');
});

// ---------- API Routes for Admin (AJAX calls from Vue) ----------
Route::prefix('api/admin')->name('api.admin.')->group(function () {
    // Account Registrations
    Route::get('/registrations', [AccountRegistrationController::class, 'index'])->name('registrations.index');
    Route::get('/registrations/{profile}', [AccountRegistrationController::class, 'show'])->name('registrations.show');
    Route::post('/registrations/{profile}/approve', [AccountRegistrationController::class, 'approve'])->name('registrations.approve');
    Route::post('/registrations/{profile}/reject', [AccountRegistrationController::class, 'reject'])->name('registrations.reject');
    Route::post('/documents/{document}/review', [AccountRegistrationController::class, 'reviewDocument'])->name('documents.review');
    
    // User Accounts
    Route::get('/accounts', [UserAccountController::class, 'index'])->name('accounts.index');
    Route::get('/accounts/{profile}', [UserAccountController::class, 'show'])->name('accounts.show');
    Route::put('/accounts/{profile}/status', [UserAccountController::class, 'updateStatus'])->name('accounts.update-status');
    
    // Dashboard Stats
    Route::get('/dashboard/stats', [DashboardController::class, 'stats'])->name('dashboard.stats');
    Route::get('/dashboard/notifications', [DashboardController::class, 'notifications'])->name('dashboard.notifications');
    
    // Seller Compliance
    Route::get('/compliance', [ComplianceController::class, 'index'])->name('compliance.index');
    Route::post('/compliance/{seller}/warn', [ComplianceController::class, 'issueWarning'])->name('compliance.warn');
    Route::post('/compliance/{seller}/suspend', [ComplianceController::class, 'suspend'])->name('compliance.suspend');
    
    // Complaints
    Route::get('/complaints', [ComplaintController::class, 'index'])->name('complaints.index');
    Route::get('/complaints/{complaint}', [ComplaintController::class, 'show'])->name('complaints.show');
    Route::put('/complaints/{complaint}/status', [ComplaintController::class, 'updateStatus'])->name('complaints.update-status');
    
    // Commission
    Route::get('/commission', [CommissionController::class, 'index'])->name('commission.index');
    Route::get('/commission/report', [CommissionController::class, 'report'])->name('commission.report');
    
    // Reports
    Route::post('/reports/sales', [ReportController::class, 'generateSalesReport'])->name('reports.sales');
    Route::post('/reports/commission', [ReportController::class, 'generateCommissionReport'])->name('reports.commission');
    
    // Platform Settings
    Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::post('/settings/announcements', [SettingsController::class, 'postAnnouncement'])->name('settings.announcements');
    Route::put('/settings/policies/{policy}', [SettingsController::class, 'updatePolicy'])->name('settings.policies.update');
    
    // Chat
    Route::get('/chat/contacts', [ChatController::class, 'contacts'])->name('chat.contacts');
    Route::get('/chat/messages/{user}', [ChatController::class, 'messages'])->name('chat.messages');
    Route::post('/chat/messages', [ChatController::class, 'sendMessage'])->name('chat.send');
    
    // Profile
    Route::get('/profile', [ProfileController::class, 'show'])->name('profile.show');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::post('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');

    Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    
    // Account Registrations
    Route::get('/registrations', [AccountRegistrationController::class, 'index'])->name('registrations.index');
    Route::get('/registrations/{profile}', [AccountRegistrationController::class, 'show'])->name('registrations.show');
    Route::post('/registrations/{profile}/approve', [AccountRegistrationController::class, 'approve'])->name('registrations.approve');
    Route::post('/registrations/{profile}/reject', [AccountRegistrationController::class, 'reject'])->name('registrations.reject');
    
    // User Accounts
    Route::get('/accounts', [UserAccountController::class, 'index'])->name('accounts.index');
    Route::get('/accounts/{profile}', [UserAccountController::class, 'show'])->name('accounts.show');
    Route::put('/accounts/{profile}/status', [UserAccountController::class, 'updateStatus'])->name('accounts.update-status');
});
});

// ---------- Fallback Route (SPA) ----------
// This catches all other routes and renders the main Vue app
Route::get('/{any}', function () {
    return view('app');
})->where('any', '.*');