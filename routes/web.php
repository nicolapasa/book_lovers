<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\LibraryController;
use App\Http\Controllers\AdminController;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\ActiveAccount;
use App\Http\Controllers\BookManagementController;
use App\Http\Controllers\LibraryPageController;

Route::get('/', [LibraryPageController::class, 'index'])->middleware(SetLocale::class);

Route::view('/demo', 'welcome', ['demo' => true]);

Route::prefix('app')->middleware(SetLocale::class)->group(function (): void {
    Route::get('/view', [LibraryPageController::class, 'screen']);
    Route::get('/dialog', [LibraryPageController::class, 'dialog']);
    Route::get('/state', [LibraryController::class, 'state']);
    Route::get('/libraries', [LibraryController::class, 'explore']);
    Route::get('/libraries/{library}', [LibraryController::class, 'show']);
    Route::get('/entries/{entry}/cover', [BookManagementController::class, 'cover']);
    Route::post('/register', [SessionController::class, 'register'])->middleware(['guest', 'throttle:5,1']);
    Route::post('/login', [SessionController::class, 'login'])->middleware(['guest', 'throttle:6,1']);
    Route::post('/logout', [SessionController::class, 'logout'])->middleware('auth');

    Route::middleware(['auth', ActiveAccount::class])->group(function (): void {
        Route::post('/libraries', [LibraryController::class, 'store']);
        Route::patch('/libraries/{library}', [LibraryController::class, 'update']);
        Route::post('/libraries/{library}/import', [LibraryController::class, 'import'])->middleware('throttle:30,1');
        Route::get('/books/search', [BookManagementController::class, 'search'])->middleware('throttle:20,1');
        Route::post('/libraries/{library}/select', [BookManagementController::class, 'select'])->middleware('throttle:30,1');
        Route::post('/libraries/{library}/books', [BookManagementController::class, 'store']);
        Route::post('/entries/{entry}/metadata', [BookManagementController::class, 'update']);
        Route::patch('/entries/{entry}/progress', [LibraryController::class, 'progress']);
        Route::put('/entries/{entry}/review', [LibraryController::class, 'review']);
        Route::put('/entries/{entry}/like', [LibraryController::class, 'like']);
        Route::put('/reviews/{review}/like', [LibraryController::class, 'reviewLike']);
        Route::post('/libraries/{library}/affiliations', [LibraryController::class, 'affiliate']);
        Route::patch('/affiliations/{affiliation}', [LibraryController::class, 'respond']);
        Route::get('/admin', [AdminController::class, 'index']);
        Route::patch('/admin/users/{user}', [AdminController::class, 'user']);
        Route::patch('/admin/libraries/{library}', [AdminController::class, 'library']);
    });
});
