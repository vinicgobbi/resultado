<?php

use App\Http\Controllers\Admin\CandidateController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\CandidatePhotoController;
use App\Http\Controllers\ElectionController;
use App\Http\Controllers\MapController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\ResultsController;
use Illuminate\Support\Facades\Route;

Route::get('/', [ResultsController::class, 'home'])->name('home');
Route::get('/eleicoes', [ElectionController::class, 'index'])->name('elections.index');
Route::get('/eleicoes/{election}', [ResultsController::class, 'show'])->name('elections.show');
Route::get('/eleicoes/{election}/municipios/{municipality:ibge_code}', [MapController::class, 'municipality'])
    ->withoutScopedBindings()
    ->name('municipalities.show');
Route::get('/resumo.json', [ResultsController::class, 'summary'])->name('results.summary');
Route::get('/mapa/geometria.json', [MapController::class, 'geometry'])->name('map.geometry');
Route::get('/candidatos/{candidate}/foto', CandidatePhotoController::class)->name('candidates.photo');

Route::middleware('throttle:30,1')->group(function () {
    Route::post('/push/inscricoes', [PushSubscriptionController::class, 'store'])->name('push-subscriptions.store');
    Route::delete('/push/inscricoes', [PushSubscriptionController::class, 'destroy'])->name('push-subscriptions.destroy');
});

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:5,1')->name('login.store');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    Route::prefix('admin')->name('admin.')->group(function () {
        Route::redirect('/', '/admin/candidatos')->name('index');

        Route::get('/candidatos', [CandidateController::class, 'index'])->name('candidates.index');
        Route::patch('/candidatos/{candidate}', [CandidateController::class, 'update'])->name('candidates.update');

        Route::get('/usuarios', [UserController::class, 'index'])->name('users.index');
        Route::post('/usuarios', [UserController::class, 'store'])->name('users.store');
        Route::delete('/usuarios/{user}', [UserController::class, 'destroy'])->name('users.destroy');
    });
});
