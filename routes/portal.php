<?php

use App\Http\Controllers\Api\Portal\PortalAuthController;
use App\Http\Controllers\Api\Portal\PortalController;
use App\Http\Controllers\Api\Portal\PortalInterventionController;
use App\Http\Middleware\AuthenticatePortalAccount;
use App\Http\Middleware\PortalServiceKey;
use Illuminate\Support\Facades\Route;

/*
| Customer portal API: /api/portal/v1. Called server-to-server by the public website.
|  - Requires the shared X-Portal-Key (never sent to browsers).
|  - Everything except sign-in/up and the public catalogue also requires the customer's bearer token.
| It is intentionally separate from the LAN-only staff API in routes/api.php.
*/
Route::prefix('api/portal/v1')->name('portal.')
    ->middleware(['api', PortalServiceKey::class, 'throttle:portal-api'])
    ->group(function () {
        Route::get('catalog/products', [PortalController::class, 'products'])->name('catalog.products');
        Route::get('availability', [PortalController::class, 'availability'])->name('availability');

        Route::middleware('throttle:portal-login')->group(function () {
            Route::post('auth/register', [PortalAuthController::class, 'register'])->name('auth.register');
            Route::post('auth/login', [PortalAuthController::class, 'login'])->name('auth.login');
            Route::post('auth/forgot-password', [PortalAuthController::class, 'forgotPassword'])->name('auth.forgot');
            Route::post('auth/reset-password', [PortalAuthController::class, 'resetPassword'])->name('auth.reset');
        });

        Route::middleware(AuthenticatePortalAccount::class)->group(function () {
            Route::post('auth/logout', [PortalAuthController::class, 'logout'])->name('auth.logout');
            Route::get('me', [PortalController::class, 'me'])->name('me');
            Route::patch('me', [PortalController::class, 'updateMe'])->name('me.update');
            Route::get('overview', [PortalController::class, 'overview'])->name('overview');

            Route::get('requests', [PortalController::class, 'requestsIndex'])->name('requests.index');
            Route::post('requests', [PortalController::class, 'submit'])->name('requests.store');
            Route::get('requests/{id}', [PortalController::class, 'requestShow'])->whereNumber('id')->name('requests.show');
            Route::post('requests/{id}/cancel', [PortalController::class, 'requestCancel'])->whereNumber('id')->name('requests.cancel');

            Route::get('appointments', [PortalController::class, 'appointmentsIndex'])->name('appointments.index');
            Route::post('appointments/{id}/accept', [PortalController::class, 'appointmentAccept'])->whereNumber('id')->name('appointments.accept');
            Route::post('appointments/{id}/decline', [PortalController::class, 'appointmentDecline'])->whereNumber('id')->name('appointments.decline');

            Route::get('trials', [PortalController::class, 'trials'])->name('trials');
            Route::get('products', [PortalController::class, 'ownedProducts'])->name('products');
            Route::get('resources', [PortalController::class, 'resources'])->name('resources');
            Route::get('updates', [PortalController::class, 'updates'])->name('updates');

            Route::get('interventions/options', [PortalInterventionController::class, 'options'])->name('interventions.options');
            Route::get('interventions', [PortalInterventionController::class, 'index'])->name('interventions.index');
            Route::post('interventions', [PortalInterventionController::class, 'store'])->name('interventions.store');
            Route::prefix('interventions/{id}')->whereNumber('id')->group(function () {
                Route::get('/', [PortalInterventionController::class, 'show'])->name('interventions.show');
                Route::post('messages', [PortalInterventionController::class, 'message'])->name('interventions.messages');
                Route::get('transitions', [PortalInterventionController::class, 'allowedTransitions'])->name('interventions.transitions');
                Route::post('transition', [PortalInterventionController::class, 'transition'])->name('interventions.transition');
                Route::post('attachments', [PortalInterventionController::class, 'attach'])->name('interventions.attach');
                Route::get('attachments/{attachmentId}', [PortalInterventionController::class, 'download'])->whereNumber('attachmentId')->name('interventions.download');
            });
        });
    });
