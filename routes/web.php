<?php

use App\Http\Controllers\Admin\CandidateController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\FailedJobController;
use App\Http\Controllers\Admin\MaintenanceRunController;
use App\Http\Controllers\Admin\PartyColorController;
use App\Http\Controllers\Admin\PushNotificationController;
use App\Http\Controllers\Admin\SiteNoticeController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\CandidatePhotoController;
use App\Http\Controllers\ElectionController;
use App\Http\Controllers\MapController;
use App\Http\Controllers\PushFollowController;
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
Route::get('/notificacoes', [PushFollowController::class, 'index'])->name('notifications.index');
Route::get('/notificacoes/pendentes', [PushFollowController::class, 'pending'])->middleware('throttle:60,1')->name('notifications.pending');

Route::middleware('throttle:30,1')->group(function () {
    Route::post('/push/inscricoes', [PushSubscriptionController::class, 'store'])->name('push-subscriptions.store');
    Route::delete('/push/inscricoes', [PushSubscriptionController::class, 'destroy'])->name('push-subscriptions.destroy');
    Route::post('/notificacoes/seguindo', [PushFollowController::class, 'following'])->name('notifications.following');
    Route::put('/notificacoes/seguir', [PushFollowController::class, 'update'])->name('notifications.follow');
    Route::delete('/notificacoes/seguir', [PushFollowController::class, 'destroy'])->name('notifications.unfollow');
});

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:login')->name('login.store');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    Route::prefix('admin')->name('admin.')->group(function () {
        Route::get('/', [DashboardController::class, 'index'])->name('index');
        Route::post('/tarefas', [MaintenanceRunController::class, 'store'])->name('maintenance-runs.store');
        Route::post('/tarefas/{maintenanceRun}/cancelar', [MaintenanceRunController::class, 'cancel'])->name('maintenance-runs.cancel');
        Route::post('/fila/falhas/tentar-de-novo', [FailedJobController::class, 'retry'])->name('failed-jobs.retry');
        Route::delete('/fila/falhas', [FailedJobController::class, 'destroy'])->name('failed-jobs.destroy');

        Route::post('/notificacoes/aviso', [PushNotificationController::class, 'announce'])->name('notifications.announce');
        Route::post('/notificacoes/teste', [PushNotificationController::class, 'test'])->name('notifications.test');

        Route::get('/avisos', [SiteNoticeController::class, 'index'])->name('site-notices.index');
        Route::post('/avisos', [SiteNoticeController::class, 'store'])->name('site-notices.store');
        Route::put('/avisos/{siteNotice}', [SiteNoticeController::class, 'update'])->name('site-notices.update');
        Route::delete('/avisos/{siteNotice}', [SiteNoticeController::class, 'destroy'])->name('site-notices.destroy');

        Route::get('/candidatos', [CandidateController::class, 'index'])->name('candidates.index');
        Route::patch('/candidatos/{candidate}', [CandidateController::class, 'update'])->name('candidates.update');

        Route::get('/partidos', [PartyColorController::class, 'index'])->name('parties.index');
        Route::put('/partidos', [PartyColorController::class, 'update'])->name('parties.update');

        Route::get('/usuarios', [UserController::class, 'index'])->name('users.index');
        Route::post('/usuarios', [UserController::class, 'store'])->name('users.store');
        Route::delete('/usuarios/{user}', [UserController::class, 'destroy'])->name('users.destroy');
    });
});
