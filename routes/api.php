<?php

use App\Http\Controllers\LunasKreditSyncController;
use Illuminate\Support\Facades\Route;

Route::prefix('lunas-kredit')->group(function () {
    Route::post('send', [LunasKreditSyncController::class, 'send']);
});
