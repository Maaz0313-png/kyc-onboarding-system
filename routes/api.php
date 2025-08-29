<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\KycApplicationController;
use App\Http\Controllers\DocumentUploadController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

/*
|--------------------------------------------------------------------------
| KYC Application Routes
|--------------------------------------------------------------------------
|
| Routes for KYC application management including CRUD operations,
| submission, approval, and status tracking.
|
*/

Route::middleware(['auth:sanctum'])->group(function () {

    // KYC Applications
    Route::prefix('kyc')->group(function () {
        Route::get('/', [KycApplicationController::class, 'index']);
        Route::post('/', [KycApplicationController::class, 'store']);
        Route::get('/{kycApplication}', [KycApplicationController::class, 'show']);
        Route::put('/{kycApplication}', [KycApplicationController::class, 'update']);
        Route::post('/{kycApplication}/submit', [KycApplicationController::class, 'submit']);
        Route::get('/{kycApplication}/status', [KycApplicationController::class, 'status']);

        // Admin/Compliance Officer routes
        Route::middleware(['permission:review-kyc'])->group(function () {
            Route::get('/review/pending', [KycApplicationController::class, 'forReview']);
            Route::post('/{kycApplication}/approve', [KycApplicationController::class, 'approve']);
            Route::post('/{kycApplication}/reject', [KycApplicationController::class, 'reject']);
        });

        // Document Management
        Route::prefix('{kycApplication}/documents')->group(function () {
            Route::get('/', [DocumentUploadController::class, 'index']);
            Route::post('/', [DocumentUploadController::class, 'upload']);
            Route::get('/{document}', [DocumentUploadController::class, 'show']);
            Route::get('/{document}/download', [DocumentUploadController::class, 'download']);
            Route::delete('/{document}', [DocumentUploadController::class, 'destroy']);

            // Document verification (Admin/KYC Officer only)
            Route::middleware(['permission:verify-documents'])->group(function () {
                Route::post('/{document}/verify', [DocumentUploadController::class, 'verify']);
            });
        });
    });
});

/*
|--------------------------------------------------------------------------
| Public Routes
|--------------------------------------------------------------------------
|
| Routes that don't require authentication for testing and health checks.
|
*/

Route::get('/health', function () {
    return response()->json([
        'status' => 'healthy',
        'timestamp' => now()->toISOString(),
        'version' => '1.0.0',
        'services' => [
            'database' => 'connected',
            'cache' => 'operational',
            'queue' => 'operational'
        ]
    ]);
});

Route::get('/system-info', function () {
    return response()->json([
        'php_version' => PHP_VERSION,
        'laravel_version' => app()->version(),
        'environment' => app()->environment(),
        'timezone' => config('app.timezone'),
        'locale' => config('app.locale')
    ]);
});