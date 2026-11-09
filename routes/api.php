<?php

use App\Http\Controllers\Api\V1\ActivityController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\FollowUpController;
use App\Http\Controllers\Api\V1\LossReasonController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\ProspectActivityController;
use App\Http\Controllers\Api\V1\ProspectController;
use App\Http\Controllers\Api\V1\ProspectFollowUpController;
use App\Http\Controllers\Api\V1\ProspectStageController;
use App\Http\Controllers\Api\V1\SalesStageController;
use App\Http\Controllers\Api\V1\SystemController;
use App\Http\Middleware\RestrictToAllowedNetworks;
use Illuminate\Support\Facades\Route;

/*
| All routes are prefixed with /api (bootstrap/app.php) and /v1 here.
| No authentication: access is limited to allowed LAN addresses.
*/
Route::prefix('v1')
    ->name('api.v1.')
    ->middleware([RestrictToAllowedNetworks::class, 'throttle:crm-api'])
    ->group(function () {
        Route::get('health', [SystemController::class, 'health'])->name('health');
        Route::get('lookups', [SystemController::class, 'lookups'])->name('lookups');
        Route::post('system/heartbeat', [SystemController::class, 'heartbeat'])->name('system.heartbeat');
        Route::get('system/employees', [SystemController::class, 'employees'])->name('system.employees');
        Route::get('dashboard', DashboardController::class)->name('dashboard');

        // Prospects
        Route::apiResource('prospects', ProspectController::class);
        Route::prefix('prospects/{prospect}')->name('prospects.')->group(function () {
            Route::get('activities', [ProspectActivityController::class, 'index'])->name('activities.index');
            Route::post('activities', [ProspectActivityController::class, 'store'])->name('activities.store');
            Route::get('follow-ups', [ProspectFollowUpController::class, 'index'])->name('follow-ups.index');
            Route::post('follow-ups', [ProspectFollowUpController::class, 'store'])->name('follow-ups.store');
            Route::post('change-stage', [ProspectStageController::class, 'change'])->name('change-stage');
            Route::get('stage-history', [ProspectStageController::class, 'history'])->name('stage-history');
        });

        // Activities: append-only history. Created from a prospect, converted from a
        // follow-up, or created on their own and linked to a prospect later.
        Route::get('activities', [ActivityController::class, 'index'])->name('activities.index');
        Route::post('activities', [ActivityController::class, 'store'])->name('activities.store');
        Route::get('activities/{activity}', [ActivityController::class, 'show'])->name('activities.show');
        Route::post('activities/{activity}/link-prospect', [ActivityController::class, 'linkProspect'])->name('activities.link-prospect');

        // Follow-ups
        Route::get('follow-ups', [FollowUpController::class, 'index'])->name('follow-ups.index');
        Route::get('follow-ups/{followUp}', [FollowUpController::class, 'show'])->name('follow-ups.show');
        Route::match(['put', 'patch'], 'follow-ups/{followUp}', [FollowUpController::class, 'update'])->name('follow-ups.update');
        Route::post('follow-ups/{followUp}/complete', [FollowUpController::class, 'complete'])->name('follow-ups.complete');
        Route::post('follow-ups/{followUp}/convert-to-activity', [FollowUpController::class, 'convertToActivity'])->name('follow-ups.convert-to-activity');
        Route::post('follow-ups/{followUp}/cancel', [FollowUpController::class, 'cancel'])->name('follow-ups.cancel');

        // Configuration
        Route::apiResource('products', ProductController::class);

        Route::post('sales-stages/reorder', [SalesStageController::class, 'reorder'])->name('sales-stages.reorder');
        Route::apiResource('sales-stages', SalesStageController::class)->parameters(['sales-stages' => 'salesStage']);

        Route::post('loss-reasons/reorder', [LossReasonController::class, 'reorder'])->name('loss-reasons.reorder');
        Route::apiResource('loss-reasons', LossReasonController::class)->parameters(['loss-reasons' => 'lossReason']);
    });
