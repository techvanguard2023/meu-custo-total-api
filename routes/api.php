<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\MaterialController;
use App\Http\Controllers\Api\PlanController;
use App\Http\Controllers\Api\PrinterController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\QuoteController;
use App\Http\Controllers\Api\SettingsController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    Route::get('status', function () {
        return response()->json(['status' => 'API V1 Meu Custo Total is alive!'], 200);
    });

    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);

        Route::apiResource('materials', MaterialController::class);
        Route::apiResource('printers', PrinterController::class);
        Route::apiResource('customers', CustomerController::class);
        Route::post('/products/{product}/image', [ProductController::class, 'uploadImage']);
        Route::delete('/products/{product}/image', [ProductController::class, 'destroyImage']);
        Route::apiResource('products', ProductController::class);

        Route::post('/quotes/preview', [QuoteController::class, 'preview']);
        Route::patch('/quotes/{quote}/approve', [QuoteController::class, 'approve']);
        Route::patch('/quotes/{quote}/reject', [QuoteController::class, 'reject']);
        Route::patch('/quotes/{quote}/production-status', [QuoteController::class, 'updateProductionStatus']);
        Route::patch('/quotes/production-order', [QuoteController::class, 'reorderProduction']);
        Route::apiResource('quotes', QuoteController::class)->only(['index', 'show', 'store', 'update', 'destroy']);

        Route::get('/settings', [SettingsController::class, 'show']);
        Route::put('/settings', [SettingsController::class, 'update']);

        Route::get('/plan', [PlanController::class, 'show']);
        Route::post('/plan/checkout', [PlanController::class, 'checkout']);
        Route::post('/plan/portal', [PlanController::class, 'portal']);
    });
});