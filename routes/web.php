<?php

use App\Http\Controllers\ActiveBranchController;
use App\Http\Controllers\BranchSelectionController;
use App\Http\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth'])->group(function () {
    Route::get('workspace', WorkspaceController::class)->name('workspace');
    Route::redirect('dashboard', '/workspace')->name('dashboard');

    Route::get('branches/select', BranchSelectionController::class)->name('branches.select');
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

    Route::inertia('workspaces/cashier', 'workspaces/show', [
        'workspace' => 'Cashier / POS',
        'eyebrow' => 'Branch Operations',
        'description' => 'Branch-scoped cashier and point-of-sale workspace.',
    ])->middleware(['permission:pos.access', 'branch'])->name('workspaces.cashier');

    Route::inertia('workspaces/kitchen', 'workspaces/show', [
        'workspace' => 'Kitchen',
        'eyebrow' => 'Branch Operations',
        'description' => 'Branch-scoped kitchen workspace.',
    ])->middleware(['permission:kitchen.access', 'branch'])->name('workspaces.kitchen');
});

require __DIR__.'/settings.php';
