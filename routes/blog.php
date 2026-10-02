<?php

/*
 * The turnkey blog (`xerads.content.mode = turnkey`), under
 * `xerads.content.turnkey.prefix` and its middleware.
 *
 * Registered in this order so the fixed paths win over `{slug}`: an article
 * whose slug is "category" is still reachable, at /blog/category.
 */

use Illuminate\Routing\Middleware\ValidateSignature;
use Illuminate\Support\Facades\Route;
use XerAds\Laravel\Content\Http\BlogController;
use XerAds\Laravel\Content\Http\PreviewController;
use XerAds\Laravel\Content\Models\Article;

Route::prefix(Article::prefix())
    ->middleware((array) config('xerads.content.turnkey.middleware', ['web']))
    ->name('xerads.blog.')
    ->group(function () {
        Route::get('/', [BlogController::class, 'index'])->name('index');
        Route::get('category/{slug}', [BlogController::class, 'category'])->where('slug', '[^/]+')->name('category');
        Route::get('tag/{slug}', [BlogController::class, 'tag'])->where('slug', '[^/]+')->name('tag');
        Route::get('preview/{article}', PreviewController::class)
            ->where('article', '[0-9A-Za-z]{26}')
            ->middleware(ValidateSignature::class)
            ->name('preview');
        Route::get('{slug}', [BlogController::class, 'show'])->where('slug', '[^/]+')->name('show');
    });
