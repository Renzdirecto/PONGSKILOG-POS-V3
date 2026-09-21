<?php

use App\Http\Controllers\ActiveBranchController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\BranchProductController;
use App\Http\Controllers\BranchSelectionController;
use App\Http\Controllers\CashierWorkspaceController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CurrentStoreSessionController;
use App\Http\Controllers\CustomerQrController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\ModifierGroupController;
use App\Http\Controllers\ModifierOptionController;
use App\Http\Controllers\OpenStoreSessionController;
use App\Http\Controllers\PosDraftOrderController;
use App\Http\Controllers\PosOrderReservationController;
use App\Http\Controllers\PosPayLaterController;
use App\Http\Controllers\PosPayLaterSettlementController;
use App\Http\Controllers\PosPaymentController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductImageController;
use App\Http\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::get('qr/{branch}', CustomerQrController::class)
    ->whereUuid('branch')->middleware('throttle:60,1')->name('qr.show');

Route::middleware(['auth'])->group(function () {
    Route::get('workspace', WorkspaceController::class)->name('workspace');
    Route::redirect('dashboard', '/workspace')->name('dashboard');

    Route::get('branches/select', BranchSelectionController::class)->name('branches.select');
    Route::resource('branches', BranchController::class)->only(['index', 'store', 'update']);
    Route::middleware('can:inventory.manage')->group(function () {
        Route::get('inventory', [InventoryController::class, 'index'])->name('inventory.index');
        Route::get('inventory/{branch}/{product}/movements', [InventoryController::class, 'movements'])->name('inventory.movements.index');
        Route::post('inventory/{branch}/{product}/adjustments', [InventoryController::class, 'store'])->name('inventory.adjustments.store');
    });
    Route::middleware('can:products.manage')->group(function () {
        Route::resource('products', ProductController::class)->only(['index', 'store', 'update']);
        Route::resource('categories', CategoryController::class)->only(['index', 'store', 'update']);
        Route::put('modifier-groups/{modifierGroup}/products', [ModifierGroupController::class, 'updateProducts'])->name('modifier-groups.products.update');
        Route::resource('modifier-groups', ModifierGroupController::class)->only(['index', 'store', 'update']);
        Route::resource('modifier-options', ModifierOptionController::class)->only(['store', 'update']);
        Route::post('products/{product}/image', [ProductImageController::class, 'store'])->middleware('throttle:20,1')->name('products.image.store');
        Route::delete('products/{product}/image', [ProductImageController::class, 'destroy'])->name('products.image.destroy');
        Route::put('products/{product}/branches/{branch}', [BranchProductController::class, 'update'])->name('products.branches.update');
    });
    Route::put('branch-context/{branch}', [ActiveBranchController::class, 'update'])
        ->name('branch-context.update');
    Route::delete('branch-context', [ActiveBranchController::class, 'destroy'])
        ->name('branch-context.destroy');

    Route::inertia('workspaces/super-admin', 'workspaces/show', [
        'workspace' => 'Super Admin',
        'eyebrow' => 'PONGSKILOG Control Center',
        'description' => 'Business-wide system administration workspace.',
    ])->middleware('permission:access_control.manage')->name('workspaces.super-admin');

    Route::inertia('workspaces/owner', 'workspaces/show', [
        'workspace' => 'Owner',
        'eyebrow' => 'Business Operations',
        'description' => 'Business-wide owner workspace.',
    ])->middleware('permission:reports.view')->name('workspaces.owner');

    Route::get('workspaces/cashier', CashierWorkspaceController::class)
        ->middleware(['permission:pos.access', 'branch'])->name('workspaces.cashier');

    Route::post('pos/payments', [PosPaymentController::class, 'store'])->middleware('permission:pos.access')->name('pos.payments.store');

    Route::middleware(['permission:pos.access', 'branch'])->group(function () {
        Route::post('pos/orders/reservations', PosOrderReservationController::class)->name('pos.orders.reservations.store');
        Route::post('pos/orders/drafts', [PosDraftOrderController::class, 'store'])->name('pos.orders.store');
        Route::post('pos/orders/{order}/pay-later', [PosPayLaterController::class, 'store'])->whereUuid('order')->name('pos.orders.pay-later.store');
        Route::post('pos/orders/{order}/settlements', [PosPayLaterSettlementController::class, 'store'])->whereUuid('order')->name('pos.orders.settlements.store');
        Route::get('pos/orders/{order}', [PosDraftOrderController::class, 'show'])->whereUuid('order')->name('pos.orders.show');
    });

    Route::post('store-sessions/open', OpenStoreSessionController::class)
        ->middleware(['permission:pos.access', 'permission:store.open_close', 'branch'])
        ->name('store-sessions.open');
    Route::get('store-sessions/current', CurrentStoreSessionController::class)
        ->middleware('permission:pos.access')
        ->name('store-sessions.current');

    Route::inertia('workspaces/kitchen', 'workspaces/show', [
        'workspace' => 'Kitchen',
        'eyebrow' => 'Branch Operations',
        'description' => 'Branch-scoped kitchen workspace.',
    ])->middleware(['permission:kitchen.access', 'branch'])->name('workspaces.kitchen');
});

require __DIR__.'/settings.php';
