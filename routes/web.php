<?php

use App\Http\Controllers\PrivacyTermsUse;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});

Route::get('/facebook/callback', [App\Http\Controllers\FacebookAuthController::class, 'callback']);
Route::get('/facebook/redirect/auth', [App\Http\Controllers\FacebookAuthController::class, 'redirectAuth'])->name('integration.facebook_ads');
route::get('/privacy-policy', [PrivacyTermsUse::class, 'render'])->name('privacy.policy');
