<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\OwnerController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\RetailerDashboardController;
use App\Http\Controllers\Api\RetailerSettingsController;
use App\Http\Controllers\Api\WhatsappWebhookController;
use App\Models\Category;
use Illuminate\Support\Facades\Route;

Route::get('/webhooks/whatsapp', [WhatsappWebhookController::class, 'verify']);
Route::post('/webhooks/whatsapp', [WhatsappWebhookController::class, 'store']);

Route::post('/auth/token', [AuthController::class, 'token']);

Route::middleware('auth:sanctum')->group(function () {
    // Categories
    Route::get('/categories', fn () => response()->json(Category::query()->orderBy('name')->get()));
    Route::post('/categories', fn (\Illuminate\Http\Request $r) => response()->json(
        Category::query()->create([
            'retailer_id' => $r->integer('retailer_id') ?: null,
            'name'        => $r->string('name')->trim(),
            'slug'        => \Illuminate\Support\Str::slug($r->string('name')->trim()),
        ]),
        201
    ));

    // Chat inbox / threads
    Route::get('/chats', [ChatController::class, 'index']);
    Route::get('/chats/{customer}', [ChatController::class, 'show']);

    Route::apiResource('products', ProductController::class);
    Route::post('/products/{product}/adjust-stock', [ProductController::class, 'adjustStock']);

    Route::apiResource('customers', CustomerController::class)->only(['index', 'store', 'show']);
    Route::apiResource('orders', OrderController::class)->only(['index', 'store', 'show', 'update']);

    Route::get('/carts', [CartController::class, 'index']);
    Route::post('/carts', [CartController::class, 'store']);

    Route::get('/dashboard/retailer', RetailerDashboardController::class)
        ->middleware('role:retailer,staff');

    // Retailer settings
    Route::get('/retailers/{retailer}/settings', [RetailerSettingsController::class, 'show'])
        ->middleware('role:owner,retailer,staff');
    Route::put('/retailers/{retailer}/settings', [RetailerSettingsController::class, 'update'])
        ->middleware('role:owner,retailer');
    Route::post('/retailers/{retailer}/settings/whatsapp/test', [RetailerSettingsController::class, 'testWhatsApp'])
        ->middleware('role:owner,retailer,staff');

    // Owner routes
    Route::middleware('role:owner')->group(function () {
        Route::get('/owner/summary', [OwnerController::class, 'summary']);
        Route::get('/owner/retailers', [OwnerController::class, 'retailers']);
        Route::post('/owner/retailers', [OwnerController::class, 'storeRetailer']);
        Route::patch('/owner/retailers/{retailer}', [OwnerController::class, 'updateRetailer']);
    });
});

