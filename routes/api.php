<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\MaterialController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\PlanController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\ProductReviewController;
use App\Http\Controllers\Api\PublicCatalogController;
use App\Http\Controllers\Api\PublicReviewController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Api\PrinterController;
use App\Http\Controllers\Api\ProductCategoryController;
use App\Http\Controllers\Api\ProductCollectionController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\QuoteController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SettingsController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    Route::get('status', function () {
        return response()->json(['status' => 'API V1 Meu Custo Total is alive!'], 200);
    });

    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/forgot-password', [PasswordResetController::class, 'forgot']);
    Route::post('/reset-password', [PasswordResetController::class, 'reset']);

    // Catálogo público — sem autenticação, acessado por clientes finais via link compartilhado.
    Route::get('/public/catalog/{token}', [PublicCatalogController::class, 'show'])
        ->middleware('throttle:60,1');

    // Avaliação do cliente final — sem autenticação, só com o link recebido.
    // O POST tem limite mais apertado que a leitura: é escrita vinda de fora.
    Route::get('/public/review/{token}', [PublicReviewController::class, 'show'])
        ->middleware('throttle:60,1');
    Route::post('/public/review/{token}', [PublicReviewController::class, 'store'])
        ->middleware('throttle:10,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);

        Route::apiResource('materials', MaterialController::class);
        Route::apiResource('printers', PrinterController::class);
        Route::apiResource('customers', CustomerController::class);
        Route::get('/product-categories', [ProductCategoryController::class, 'index']);
        Route::post('/product-categories', [ProductCategoryController::class, 'store']);
        Route::put('/product-categories/{productCategory}', [ProductCategoryController::class, 'update']);
        Route::delete('/product-categories/{productCategory}', [ProductCategoryController::class, 'destroy']);
        Route::get('/product-reviews', [ProductReviewController::class, 'index']);
        Route::patch('/product-reviews/{productReview}/moderate', [ProductReviewController::class, 'moderate']);
        Route::delete('/product-reviews/{productReview}', [ProductReviewController::class, 'destroy']);
        Route::post('/quotes/{quote}/review-link', [ProductReviewController::class, 'link']);
        Route::get('/product-collections', [ProductCollectionController::class, 'index']);
        Route::post('/product-collections', [ProductCollectionController::class, 'store']);
        Route::put('/product-collections/{productCollection}', [ProductCollectionController::class, 'update']);
        Route::delete('/product-collections/{productCollection}', [ProductCollectionController::class, 'destroy']);
        Route::post('/product-collections/{productCollection}/banner', [ProductCollectionController::class, 'uploadBanner']);
        Route::delete('/product-collections/{productCollection}/banner', [ProductCollectionController::class, 'destroyBanner']);
        Route::get('/products/generate-sku', [ProductController::class, 'generateSku']);
        Route::post('/products/{product}/images', [ProductController::class, 'uploadImages']);
        Route::delete('/products/{product}/images/{image}', [ProductController::class, 'destroyImage']);
        Route::patch('/products/{product}/images/reorder', [ProductController::class, 'reorderImages']);
        Route::apiResource('products', ProductController::class);

        Route::post('/quotes/preview', [QuoteController::class, 'preview']);
        Route::post('/quotes/quick-sale', [QuoteController::class, 'quickSale']);
        Route::patch('/quotes/{quote}/approve', [QuoteController::class, 'approve']);
        Route::patch('/quotes/{quote}/reject', [QuoteController::class, 'reject']);
        Route::patch('/quotes/{quote}/cancel', [QuoteController::class, 'cancel']);
        Route::patch('/quotes/{quote}/payment', [QuoteController::class, 'updatePayment']);
        Route::patch('/quotes/{quote}/pause', [QuoteController::class, 'pause']);
        Route::patch('/quotes/{quote}/resume', [QuoteController::class, 'resume']);
        Route::patch('/quotes/{quote}/production-status', [QuoteController::class, 'updateProductionStatus']);
        Route::patch('/quotes/production-order', [QuoteController::class, 'reorderProduction']);
        Route::apiResource('quotes', QuoteController::class)->only(['index', 'show', 'store', 'update', 'destroy']);

        Route::get('/settings', [SettingsController::class, 'show']);
        Route::put('/settings', [SettingsController::class, 'update']);

        Route::get('/catalog', [CatalogController::class, 'show']);
        Route::get('/catalog/analytics', [CatalogController::class, 'analytics']);
        Route::patch('/catalog', [CatalogController::class, 'update']);
        Route::post('/catalog/regenerate', [CatalogController::class, 'regenerate']);
        Route::patch('/catalog/slug', [CatalogController::class, 'updateSlug']);
        Route::post('/catalog/logo', [CatalogController::class, 'uploadLogo']);
        Route::delete('/catalog/logo', [CatalogController::class, 'destroyLogo']);
        Route::post('/catalog/banners', [CatalogController::class, 'uploadBanner']);
        Route::patch('/catalog/banners/reorder', [CatalogController::class, 'reorderBanners']);
        Route::patch('/catalog/banners/{banner}', [CatalogController::class, 'updateBanner']);
        Route::delete('/catalog/banners/{banner}', [CatalogController::class, 'destroyBanner']);

        Route::get('/reports', [ReportController::class, 'show']);

        Route::get('/plan', [PlanController::class, 'show']);
        Route::post('/plan/checkout', [PlanController::class, 'checkout']);
        Route::post('/plan/portal', [PlanController::class, 'portal']);

        Route::get('/subscription', [SubscriptionController::class, 'show']);
        Route::post('/subscription/cancel', [SubscriptionController::class, 'cancel']);
        Route::post('/subscription/resume', [SubscriptionController::class, 'resume']);

        Route::put('/profile', [ProfileController::class, 'update']);
        Route::put('/profile/password', [ProfileController::class, 'updatePassword']);
    });
});