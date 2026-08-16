<?php

use App\Http\Controllers\Api\LicenseController;
use Illuminate\Support\Facades\Route;

/*
 | The licence server our own WordPress plugins talk to.
 |
 | Public by design: the licence key IS the authentication, so there are no
 | tokens and no session — which is also why these routes live here and not in
 | web.php, where every request would be handed a session and a CSRF check the
 | plugin cannot satisfy.
 |
 | The contract is docs/license-api.md. Shops we do not control and cannot
 | redeploy are on the other side of it, so the shape of these responses is
 | frozen: additions are safe, changes are not.
 */
Route::prefix('license/v1')
    ->middleware('throttle:'.max(1, (int) config('licensing.rate_limit', 30)).',1')
    ->group(function (): void {
        Route::post('/activate', [LicenseController::class, 'activate'])->name('license.activate');
        Route::post('/deactivate', [LicenseController::class, 'deactivate'])->name('license.deactivate');
        Route::post('/check', [LicenseController::class, 'check'])->name('license.check');
        Route::post('/update', [LicenseController::class, 'update'])->name('license.update');

        // WordPress follows this itself, carrying only what the signed link put
        // in the address — see DownloadLink.
        Route::get('/download', [LicenseController::class, 'download'])->name('license.download');
    });
