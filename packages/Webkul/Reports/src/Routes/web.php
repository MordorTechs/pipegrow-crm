<?php

use Illuminate\Support\Facades\Route;
use Webkul\Reports\Http\Controllers\ReportsController;

Route::group([
    'prefix' => 'admin/reports',
    'middleware' => ['web', 'auth'],
], function () {
    Route::get('', [ReportsController::class, 'index'])->name('admin.reports.index');
});
