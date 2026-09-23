<?php

use App\Http\Controllers\ActiveBranchController;
use App\Http\Controllers\AuditTrailController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\BranchProductController;
use App\Http\Controllers\BranchQrSettingsController;
use App\Http\Controllers\BranchSelectionController;
use App\Http\Controllers\CashierWorkspaceController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CommittedOrderEditController;
use App\Http\Controllers\CurrentStoreSessionController;
use App\Http\Controllers\CustomerDisplayController;
use App\Http\Controllers\CustomerQrController;
use App\Http\Controllers\CustomerQrOrderController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\KitchenStatusController;
use App\Http\Controllers\KitchenWorkspaceController;
use App\Http\Controllers\ModifierGroupController;
use App\Http\Controllers\ModifierOptionController;
use App\Http\Controllers\OpenStoreSessionController;
use App\Http\Controllers\PaymentInvoiceProofController;
use App\Http\Controllers\PosDraftOrderController;
use App\Http\Controllers\PosOrderReservationController;
use App\Http\Controllers\PosPayLaterController;
use App\Http\Controllers\PosPayLaterSettlementController;
use App\Http\Controllers\PosPaymentController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductImageController;
use App\Http\Controllers\ReceiptShareController;
use App\Http\Controllers\SetVoidAuthorizationPinController;
use App\Http\Controllers\StaffQrOrderController;
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

    Route::inertia('workspaces/super-admin', 'workspaces/show', [
        'workspace' => 'Super Admin',
        'eyebrow' => 'PONGSKILOG Control Center',
        'description' => 'Business-wide system administration workspace.',
    ])->middleware('permission:access_control.manage')->name('workspaces.super-admin');

    Route::get('workspaces/audit-trail', AuditTrailController::class)
        ->middleware('permission:audit.view')
        ->name('workspaces.audit-trail');

    Route::get('workspaces/void-orders', VoidOrdersController::class)
        ->middleware('permission:void_orders.manage')
        ->name('workspaces.void-orders');
    Route::put('workspaces/void-orders/pin', SetVoidAuthorizationPinController::class)
        ->middleware(['permission:void_orders.manage', 'throttle:5,1'])
        ->name('workspaces.void-orders.pin.update');

    Route::inertia('workspaces/owner', 'workspaces/show', [
        'workspace' => 'Owner',
        'eyebrow' => 'Business Operations',
        'description' => 'Business-wide owner workspace.',
    ])->middleware('permission:reports.view')->name('workspaces.owner');

    Route::get('workspaces/cashier', CashierWorkspaceController::class)
        ->middleware(['permission:pos.access', 'branch'])->name('workspaces.cashier');

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
        ->middleware('permission:pos.access')
        ->name('store-sessions.current');

    Route::get('workspaces/kitchen', KitchenWorkspaceController::class)
        ->middleware(['permission:kitchen.access', 'branch'])
        ->name('workspaces.kitchen');
    Route::get('workspaces/customer-display', CustomerDisplayController::class)
        ->middleware(['permission:customer_display.launch', 'branch'])
        ->name('workspaces.customer-display');
});

require __DIR__.'/settings.php';
