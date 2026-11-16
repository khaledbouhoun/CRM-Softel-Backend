<?php

use App\Http\Controllers\Api\V1\ActivityController;
use App\Http\Controllers\Api\V1\AppointmentController;
use App\Http\Controllers\Api\V1\CalendarController;
use App\Http\Controllers\Api\V1\ConfigEntityController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\FollowUpController;
use App\Http\Controllers\Api\V1\InterventionStaffController;
use App\Http\Controllers\Api\V1\LossReasonController;
use App\Http\Controllers\Api\V1\PortalRequestStaffController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\ProspectActivityController;
use App\Http\Controllers\Api\V1\ProspectController;
use App\Http\Controllers\Api\V1\ProspectFollowUpController;
use App\Http\Controllers\Api\V1\ProspectFormOptionController;
use App\Http\Controllers\Api\V1\ProspectStageController;
use App\Http\Controllers\Api\V1\SalesStageController;
use App\Http\Controllers\Api\V1\SchedulingSettingsController;
use App\Http\Controllers\Api\V1\SystemController;
use App\Http\Controllers\Api\V1\TrialController;
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
        Route::post('activities/{activity}/link-follow-up', [ActivityController::class, 'linkFollowUp'])->name('activities.link-follow-up');
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

        Route::post('prospect-form-options/reorder', [ProspectFormOptionController::class, 'reorder'])->name('prospect-form-options.reorder');
        Route::apiResource('prospect-form-options', ProspectFormOptionController::class)->parameters(['prospect-form-options' => 'prospectFormOption']);

        // Customers (converted prospects), conversion and trials
        Route::get('customers', [CustomerController::class, 'index'])->name('customers.index');
        Route::get('customers/{prospect}', [CustomerController::class, 'show'])->name('customers.show');
        Route::post('prospects/{prospect}/convert', [CustomerController::class, 'convert'])->name('prospects.convert');
        Route::get('prospects/{prospect}/trials', [TrialController::class, 'index'])->name('prospects.trials.index');
        Route::post('prospects/{prospect}/trials', [TrialController::class, 'store'])->name('prospects.trials.store');
        Route::post('trials/{trial}/revoke', [TrialController::class, 'revoke'])->name('trials.revoke');

        // Appointments: demo holds, training, accompaniment series
        Route::get('calendar', CalendarController::class)->name('calendar');
        Route::get('appointments', [AppointmentController::class, 'index'])->name('appointments.index');
        Route::post('appointments', [AppointmentController::class, 'store'])->name('appointments.store');
        Route::get('appointments/{appointment}', [AppointmentController::class, 'show'])->name('appointments.show');
        Route::post('appointments/{appointment}/confirm', [AppointmentController::class, 'confirm'])->name('appointments.confirm');
        Route::post('appointments/{appointment}/reject', [AppointmentController::class, 'reject'])->name('appointments.reject');
        Route::post('appointments/{appointment}/cancel', [AppointmentController::class, 'cancel'])->name('appointments.cancel');
        Route::post('appointments/{appointment}/propose', [AppointmentController::class, 'propose'])->name('appointments.propose');
        Route::post('appointments/{appointment}/reschedule', [AppointmentController::class, 'reschedule'])->name('appointments.reschedule');
        Route::post('appointments/{appointment}/complete', [AppointmentController::class, 'complete'])->name('appointments.complete');
        Route::post('appointments/{appointment}/no-show', [AppointmentController::class, 'noShow'])->name('appointments.no-show');
        Route::post('appointment-series/{series}/cancel', [AppointmentController::class, 'cancelSeries'])->name('appointment-series.cancel');

        // Website requests
        Route::get('portal-requests', [PortalRequestStaffController::class, 'index'])->name('portal-requests.index');
        Route::get('portal-requests/{portalRequest}', [PortalRequestStaffController::class, 'show'])->name('portal-requests.show');
        Route::patch('portal-requests/{portalRequest}', [PortalRequestStaffController::class, 'update'])->name('portal-requests.update');

        // After-sales interventions
        Route::get('interventions', [InterventionStaffController::class, 'index'])->name('interventions.index');
        Route::post('interventions', [InterventionStaffController::class, 'store'])->name('interventions.store');
        Route::get('interventions/{intervention}', [InterventionStaffController::class, 'show'])->name('interventions.show');
        Route::patch('interventions/{intervention}', [InterventionStaffController::class, 'update'])->name('interventions.update');
        Route::get('interventions/{intervention}/transitions', [InterventionStaffController::class, 'allowedTransitions'])->name('interventions.transitions');
        Route::post('interventions/{intervention}/transition', [InterventionStaffController::class, 'transition'])->name('interventions.transition');
        Route::post('interventions/{intervention}/messages', [InterventionStaffController::class, 'message'])->name('interventions.messages');
        Route::post('interventions/{intervention}/publish', [InterventionStaffController::class, 'publish'])->name('interventions.publish');
        Route::post('interventions/{intervention}/unpublish', [InterventionStaffController::class, 'unpublish'])->name('interventions.unpublish');

        // Scheduling and workflow configuration
        Route::get('settings/scheduling', [SchedulingSettingsController::class, 'show'])->name('settings.scheduling.show');
        Route::put('settings/scheduling', [SchedulingSettingsController::class, 'update'])->name('settings.scheduling.update');
        Route::prefix('config/{entity}')->where(['entity' => 'appointment-types|holidays|intervention-types|intervention-statuses|intervention-transitions|intervention-priorities|message-templates|product-resources'])
            ->name('config.')->group(function () {
                Route::get('/', [ConfigEntityController::class, 'index'])->name('index');
                Route::post('/', [ConfigEntityController::class, 'store'])->name('store');
                Route::match(['put', 'patch'], '{id}', [ConfigEntityController::class, 'update'])->whereNumber('id')->name('update');
                Route::delete('{id}', [ConfigEntityController::class, 'destroy'])->whereNumber('id')->name('destroy');
            });
    });
