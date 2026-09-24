<?php

use App\Http\Controllers\ActiveBranchController;
use App\Http\Controllers\AuditTrailController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\BranchProductController;
use App\Http\Controllers\BranchQrSettingsController;
use App\Http\Controllers\BranchSelectionController;
use App\Http\Controllers\CashierDashboardController;
use App\Http\Controllers\CashierWorkspaceController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CommittedOrderEditController;
use App\Http\Controllers\CurrentStoreSessionController;
use App\Http\Controllers\CustomerDisplayController;
use App\Http\Controllers\CustomerQrController;
use App\Http\Controllers\CustomerQrOrderController;
use App\Http\Controllers\IngredientController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\KitchenStatusController;
use App\Http\Controllers\KitchenWorkspaceController;
use App\Http\Controllers\ModifierGroupController;
use App\Http\Controllers\ModifierOptionController;
use App\Http\Controllers\OpenStoreSessionController;
use App\Http\Controllers\OperationPlanController;
use App\Http\Controllers\OperationsController;
use App\Http\Controllers\OrderAdjustmentAllocationController;
use App\Http\Controllers\OwnerDashboardController;
use App\Http\Controllers\PamamalengkeController;
use App\Http\Controllers\PaymentInvoiceProofController;
use App\Http\Controllers\PosDraftOrderController;
use App\Http\Controllers\PosOrderReservationController;
use App\Http\Controllers\PosPayLaterController;
use App\Http\Controllers\PosPayLaterSettlementController;
use App\Http\Controllers\PosPaymentController;
use App\Http\Controllers\PosRecipeCapacityController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductImageController;
use App\Http\Controllers\ReceiptShareController;
use App\Http\Controllers\RecipeController;
use App\Http\Controllers\ReportsController;
use App\Http\Controllers\SetVoidAuthorizationPinController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\StaffQrOrderController;
use App\Http\Controllers\StoreSessionCloseController;
use App\Http\Controllers\StoreSessionExpenseController;
use App\Http\Controllers\StoreSessionExpenseReceiptController;
use App\Http\Controllers\StoreSessionInventoryAdjustmentController;
use App\Http\Controllers\TransactionHistoryController;
use App\Http\Controllers\VoidOrderController;
use App\Http\Controllers\VoidOrdersController;
use App\Http\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');
Route::get('receipt/{order}', [ReceiptShareController::class, 'show'])->whereUuid('order')->middleware('throttle:60,1')->name('receipt.show');
Route::get('branches/{branch}/receipt-logo', [BranchQrSettingsController::class, 'logo'])->whereUuid('branch')->name('branches.receipt-logo');

Route::get('kiosk/{branch:kiosk_code}', CustomerQrController::class)->middleware('throttle:60,1')->name('kiosk.show');

Route::get('qr/{branch}', [CustomerQrController::class, 'legacy'])
    ->whereUuid('branch')->middleware('throttle:60,1')->name('qr.show');

Route::prefix('qr/{branch}')->whereUuid('branch')->middleware('throttle:120,1')->group(function (): void {
    Route::post('orders', [CustomerQrOrderController::class, 'store'])->middleware('throttle:15,1')->name('qr.orders.store');
    Route::post('recipe-capacity', [CustomerQrOrderController::class, 'capacity'])->name('qr.recipe-capacity');
    Route::get('orders/{tracking}', [CustomerQrOrderController::class, 'show'])->name('qr.orders.show');
    Route::get('orders/{tracking}/receipt', [CustomerQrOrderController::class, 'receipt'])->name('qr.orders.receipt');
    Route::post('new-order', [CustomerQrOrderController::class, 'reset'])->name('qr.reset');
    Route::post('broadcasting/auth', [CustomerQrOrderController::class, 'authorizeChannel'])->name('qr.broadcasting.auth');
});

Route::middleware(['auth'])->group(function () {
    Route::get('workspace', WorkspaceController::class)->name('workspace');
    Route::redirect('dashboard', '/workspace')->name('dashboard');

    Route::get('branches/select', BranchSelectionController::class)->name('branches.select');
    Route::put('branches/{branch}/qr-settings', [BranchQrSettingsController::class, 'update'])->name('branches.qr-settings.update');
    Route::get('branches/{branch}/qr-history', [BranchQrSettingsController::class, 'history'])->name('branches.qr-history');
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

    Route::inertia('workspaces/super-admin', 'super-admin/dashboard')
        ->middleware('permission:access_control.manage')->name('workspaces.super-admin');

    Route::prefix('workspaces/super-admin')->name('super-admin.')->middleware('permission:access_control.manage')->group(function () {
        Route::get('staff', [StaffController::class, 'index'])->name('staff.index');
        Route::post('staff', [StaffController::class, 'store'])->middleware('throttle:20,1')->name('staff.store');
        Route::get('staff/{user}/avatar', [StaffController::class, 'avatar'])->whereNumber('user')->name('staff.avatar');
        Route::inertia('notifications', 'super-admin/placeholder', ['destination' => 'notifications'])
            ->name('notifications');
        Route::inertia('access-control', 'super-admin/placeholder', ['destination' => 'access-control'])
            ->name('access-control');
    });

    Route::get('workspaces/audit-trail', AuditTrailController::class)
        ->middleware('permission:audit.view')
        ->name('workspaces.audit-trail');

    Route::get('workspaces/void-orders', VoidOrdersController::class)
        ->middleware('permission:void_orders.manage')
        ->name('workspaces.void-orders');
    Route::put('workspaces/void-orders/pin', SetVoidAuthorizationPinController::class)
        ->middleware(['permission:void_orders.manage', 'throttle:5,1'])
        ->name('workspaces.void-orders.pin.update');

    Route::get('workspaces/owner', OwnerDashboardController::class)
        ->middleware('permission:reports.view')->name('workspaces.owner');

    Route::get('workspaces/reports', ReportsController::class)
        ->middleware('permission:reports.view')
        ->name('workspaces.reports');
    Route::get('workspaces/reports/export', [ReportsController::class, 'export'])
        ->middleware(['permission:reports.view', 'throttle:20,1'])
        ->name('workspaces.reports.export');

    Route::get('workspaces/transactions', [TransactionHistoryController::class, 'business'])
        ->middleware('permission:transactions.view')
        ->name('workspaces.transactions');
    Route::get('workspaces/transactions/{order}', [TransactionHistoryController::class, 'businessShow'])
        ->whereUuid('order')
        ->middleware('permission:transactions.view')
        ->name('workspaces.transactions.show');

    /** Owner Operations & Pamamalengke (Phase 16E): Owner/Super Admin business-wide scope, checked again server-side. */
    Route::prefix('workspaces/operations')->name('operations.')->middleware('permission:inventory.manage')->group(function () {
        Route::get('/', [OperationsController::class, 'plans'])->name('plans');
        Route::get('overview', [OperationsController::class, 'overview'])->name('overview');
        Route::get('ingredients', [OperationsController::class, 'ingredients'])->name('ingredients');
        Route::get('recipes', [OperationsController::class, 'recipes'])->name('recipes');
        Route::get('stock', [OperationsController::class, 'stock'])->name('stock');
        Route::get('pamamalengke', [OperationsController::class, 'pamamalengke'])->name('pamamalengke');
        Route::get('purchases', [OperationsController::class, 'purchases'])->name('purchases');

        Route::middleware('throttle:60,1')->group(function () {
            Route::post('plans', [OperationPlanController::class, 'store'])->name('plans.store');
            Route::put('plans/{plan}', [OperationPlanController::class, 'update'])->whereUuid('plan')->name('plans.update');
            Route::post('plans/{plan}/archive', [OperationPlanController::class, 'archive'])->whereUuid('plan')->name('plans.archive');
            Route::post('ingredients', [IngredientController::class, 'store'])->name('ingredients.store');
            Route::put('ingredients/{ingredient}', [IngredientController::class, 'update'])->whereUuid('ingredient')->name('ingredients.update');
            Route::post('ingredients/{ingredient}/archive', [IngredientController::class, 'archive'])->whereUuid('ingredient')->name('ingredients.archive');
            Route::post('ingredients/{ingredient}/restore', [IngredientController::class, 'restore'])->whereUuid('ingredient')->name('ingredients.restore');
            Route::post('ingredients/{ingredient}/adjustments', [IngredientController::class, 'adjust'])->whereUuid('ingredient')->name('ingredients.adjust');
            Route::put('recipes/{product}', [RecipeController::class, 'update'])->whereUuid('product')->name('recipes.update');
            Route::put('recipes/{product}/mode', [RecipeController::class, 'mode'])->whereUuid('product')->name('recipes.mode');
            Route::put('recipes/{product}/modifier-effects/{option}', [RecipeController::class, 'effect'])->whereUuid(['product', 'option'])->name('recipes.effects.update');
            Route::post('pamamalengke/{plan}/manual-items', [PamamalengkeController::class, 'storeManual'])->whereUuid('plan')->name('pamamalengke.manual.store');
            Route::delete('pamamalengke/manual-items/{entry}', [PamamalengkeController::class, 'destroyManual'])->whereUuid('entry')->name('pamamalengke.manual.destroy');
            Route::put('pamamalengke/{plan}/skips/{ingredient}', [PamamalengkeController::class, 'skip'])->whereUuid(['plan', 'ingredient'])->name('pamamalengke.skip');
        });
        Route::post('pamamalengke/{plan}/confirm', [PamamalengkeController::class, 'confirm'])
            ->whereUuid('plan')->middleware('throttle:20,1')->name('pamamalengke.confirm');
    });

    Route::prefix('workspaces/staff')->name('staff.')->middleware('permission:staff.manage')->group(function () {
        Route::get('/', [StaffController::class, 'index'])->name('index');
        Route::post('/', [StaffController::class, 'store'])->middleware('throttle:20,1')->name('store');
        Route::get('{user}/avatar', [StaffController::class, 'avatar'])->whereNumber('user')->name('avatar');
    });

    Route::get('workspaces/cashier', CashierWorkspaceController::class)
        ->middleware(['permission:pos.access', 'branch'])->name('workspaces.cashier');

    Route::get('workspaces/cashier-dashboard', CashierDashboardController::class)
        ->middleware(['permission:pos.access', 'branch'])->name('workspaces.cashier-dashboard');

    Route::get('workspaces/transaction-history', [TransactionHistoryController::class, 'index'])
        ->middleware(['permission:transactions.view', 'branch'])->name('workspaces.transaction-history');

    Route::post('pos/payments', [PosPaymentController::class, 'store'])->middleware('permission:pos.access')->name('pos.payments.store');

    Route::middleware(['permission:pos.access', 'branch'])->group(function () {
        Route::post('pos/orders/{order}/receipt-share', [ReceiptShareController::class, 'store'])->whereUuid('order')->name('pos.orders.receipt-share');
        Route::post('pos/qr-orders/{order}/cancel-load', [StaffQrOrderController::class, 'cancelLoad'])->whereUuid('order')->name('pos.qr-orders.cancel-load');
        Route::post('pos/qr-orders/{order}/restore', [StaffQrOrderController::class, 'restore'])->whereUuid('order')->name('pos.qr-orders.restore');
        Route::get('pos/qr-orders', [StaffQrOrderController::class, 'index'])->name('pos.qr-orders.index');
        Route::post('pos/qr-orders/{order}/load', [StaffQrOrderController::class, 'load'])->whereUuid('order')->name('pos.qr-orders.load');
        Route::delete('pos/qr-orders/{order}', [StaffQrOrderController::class, 'destroy'])->whereUuid('order')->name('pos.qr-orders.destroy');
        Route::post('pos/orders/reservations', PosOrderReservationController::class)->name('pos.orders.reservations.store');
        Route::post('pos/recipe-capacity', PosRecipeCapacityController::class)->middleware('throttle:240,1')->name('pos.recipe-capacity');
        Route::post('pos/orders/drafts', [PosDraftOrderController::class, 'store'])->name('pos.orders.store');
        Route::post('pos/orders/{order}/pay-later', [PosPayLaterController::class, 'store'])->whereUuid('order')->name('pos.orders.pay-later.store');
        Route::post('pos/orders/{order}/settlements', [PosPayLaterSettlementController::class, 'store'])->whereUuid('order')->name('pos.orders.settlements.store');
        Route::get('pos/orders/{order}', [PosDraftOrderController::class, 'show'])->whereUuid('order')->name('pos.orders.show');
        Route::get('pos/transactions/{order}', [TransactionHistoryController::class, 'show'])->whereUuid('order')->name('pos.transactions.show');
        Route::patch('pos/transactions/{order}', CommittedOrderEditController::class)->whereUuid('order')->name('pos.transactions.update');
        Route::post('pos/transactions/{order}/void', VoidOrderController::class)
            ->whereUuid('order')
            ->middleware('throttle:5,1')
            ->name('pos.transactions.void');
        Route::post('pos/order-adjustments/{adjustment}/allocation', OrderAdjustmentAllocationController::class)
            ->whereUuid('adjustment')
            ->middleware('permission:transactions.view')
            ->name('pos.order-adjustments.allocation.store');
        Route::post('pos/payments/{payment}/invoice', [PaymentInvoiceProofController::class, 'store'])->whereUuid('payment')->name('pos.payments.invoice.store');
        Route::get('pos/payments/{payment}/invoice', [PaymentInvoiceProofController::class, 'show'])->whereUuid('payment')->name('pos.payments.invoice.show');
        Route::delete('pos/payments/{payment}/invoice', [PaymentInvoiceProofController::class, 'destroy'])->whereUuid('payment')->name('pos.payments.invoice.destroy');
    });

    Route::patch('orders/{order}/kitchen-status', KitchenStatusController::class)
        ->whereUuid('order')
        ->middleware('branch')
        ->name('orders.kitchen-status.update');

    Route::post('store-sessions/open', OpenStoreSessionController::class)
        ->middleware(['permission:pos.access', 'permission:store.open_close', 'branch'])
        ->name('store-sessions.open');
    Route::get('store-sessions/current', CurrentStoreSessionController::class)
        ->middleware(['permission:pos.access', 'permission:store_expenses.manage'])
        ->name('store-sessions.current');
    Route::get('store-sessions/current/close', [StoreSessionCloseController::class, 'show'])
        ->middleware(['permission:pos.access', 'permission:store.open_close', 'branch'])
        ->name('store-sessions.close.show');
    Route::post('store-sessions/current/close', [StoreSessionCloseController::class, 'store'])
        ->middleware(['permission:pos.access', 'permission:store.open_close', 'branch', 'throttle:10,1'])
        ->name('store-sessions.close.store');
    Route::post('store-sessions/current/expenses', [StoreSessionExpenseController::class, 'store'])
        ->middleware(['permission:store_expenses.manage', 'branch', 'throttle:20,1'])
        ->name('store-session-expenses.store');
    Route::post('store-sessions/current/inventory-adjustments', StoreSessionInventoryAdjustmentController::class)
        ->middleware(['permission:store_expenses.manage', 'branch', 'throttle:30,1'])
        ->name('store-session-inventory-adjustments.store');
    Route::get('store-session-expenses/{expense}/receipt', StoreSessionExpenseReceiptController::class)
        ->whereUuid('expense')
        ->middleware(['permission:store_expenses.manage', 'branch'])
        ->name('store-session-expenses.receipt');

    Route::get('workspaces/kitchen', KitchenWorkspaceController::class)
        ->middleware(['permission:kitchen.access', 'branch'])
        ->name('workspaces.kitchen');
    Route::get('workspaces/customer-display', CustomerDisplayController::class)
        ->middleware(['permission:customer_display.launch', 'branch'])
        ->name('workspaces.customer-display');
});

require __DIR__.'/settings.php';
