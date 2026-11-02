<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\RecipeController;
use App\Http\Controllers\SavedRecipesController;
use App\Http\Controllers\SaveRecipeController;
use App\Http\Controllers\SharedWithMeController;
use App\Http\Controllers\SitemapController;
use App\Http\Middleware\SetLocale;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/en');
Route::get('/media/{media}', [MediaController::class, 'show'])->whereNumber('media')->name('media.show');
Route::get('/sitemap.xml', SitemapController::class)->name('sitemap');
Route::pattern('locale', 'en|el');

Route::prefix('{locale}')->middleware(SetLocale::class)->group(function (): void {
    Route::view('/', 'home')->name('home.localized');
    Route::view('/what-can-i-cook', 'what-can-i-cook')->name('recipes.what-can-i-cook');
    Route::get('/recipes/{slug}', [RecipeController::class, 'show'])->name('recipes.show');
    Route::get('/recipes/{slug}/print', [RecipeController::class, 'print'])->name('recipes.print');
    Route::get('/categories/{slug}', [CategoryController::class, 'show'])->name('categories.show');

    Route::middleware(['auth', 'verified'])->group(function (): void {
        Route::get('/shared-with-me', SharedWithMeController::class)->name('recipes.shared');
        Route::view('/recipes/{recipe}/sharing', 'recipes.sharing')->whereNumber('recipe')->name('recipes.sharing');
        Route::view('/contributor', 'contributor.show')->name('contributor.show');
        Route::get('/saved-recipes', SavedRecipesController::class)->name('recipes.saved');
        Route::view('/planner', 'planner.show')->name('planner.index');
        Route::view('/planner/templates', 'planner.templates')->name('planner.templates');
        Route::view('/households/{household}/planner/templates', 'planner.templates')->whereNumber('household')->name('households.templates');
        Route::view('/planner/batches', 'planner.batches')->name('planner.batches');
        Route::view('/households/{household}/planner/batches', 'planner.batches')->whereNumber('household')->name('households.batches');
        Route::view('/planner/shopping', 'planner.shopping')->name('planner.shopping');
        Route::view('/households/{household}/planner/shopping', 'planner.shopping')->whereNumber('household')->name('households.shopping');
        Route::view('/households/{household}/planner', 'planner.show')->whereNumber('household')->name('households.planner');
        Route::view('/households', 'households.index')->name('households.index');
        Route::view('/households/invitations/{token}', 'households.invitation')->middleware('throttle:20,1')->name('households.invitations.show');
        Route::view('/households/{household}', 'households.show')->whereNumber('household')->name('households.show');
        Route::post('/recipes/{recipe}/save', SaveRecipeController::class)->name('recipes.save');
    });
});

Route::middleware('guest')->group(function () {
    Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('/register', [RegisteredUserController::class, 'store'])->middleware('throttle:6,1');
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:6,1');
});

Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware('auth')->name('logout');

Route::middleware('auth')->group(function () {
    Route::get('/email/verify', EmailVerificationPromptController::class)->name('verification.notice');
    Route::get('/email/verify/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1'])->name('verification.verify');
    Route::post('/email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
        ->middleware('throttle:6,1')->name('verification.send');
});
