<?php

use App\Http\Controllers\Wedding\WeddingClientEventController;
use App\Http\Controllers\Wedding\WeddingController;
use App\Http\Controllers\Wedding\WeddingGalleryController;
use App\Http\Controllers\Wedding\WeddingHlsController;
use App\Http\Controllers\Wedding\WeddingUploadController;
use App\Http\Middleware\EnsureWeddingGuest;
use Illuminate\Support\Facades\Route;

// Home page
Route::get('/', function () {
    return view('welcome');
});

// Blog (placeholder — will become a markdown-based photo/video blog)
Route::get('/blog', function () {
    return view('blog');
});

// Contact (placeholder)
Route::get('/contact', function () {
    return view('contact');
});

// Katherine & Jack Wedding Hub. The email prompt is the only open route; the
// rest require an email in the session (EnsureWeddingGuest).
Route::prefix('wedding')->name('wedding.')->group(function () {
    Route::get('/', [WeddingController::class, 'show'])->name('show');
    Route::post('/enter', [WeddingController::class, 'enter'])
        ->middleware('throttle:wedding-enter')
        ->name('enter');
    Route::post('/leave', [WeddingController::class, 'leave'])->name('leave');

    Route::middleware(EnsureWeddingGuest::class)->group(function () {
        Route::get('/hls/{source}/{path?}', [WeddingHlsController::class, 'stream'])
            ->where('path', '.*')
            ->name('hls');
        Route::get('/media/{upload}/{variant}', [WeddingGalleryController::class, 'media'])->name('media');

        Route::prefix('api')->group(function () {
            Route::get('/gallery', [WeddingGalleryController::class, 'index'])->name('gallery');
            Route::get('/gallery/{upload}/similar', [WeddingGalleryController::class, 'similar'])->name('gallery.similar');
            Route::post('/client-events', [WeddingClientEventController::class, 'store'])
                ->middleware('throttle:wedding-client-events')
                ->name('client-events');

            Route::middleware('throttle:wedding-uploads')->group(function () {
                Route::post('/uploads/check', [WeddingUploadController::class, 'check'])->name('uploads.check');
                Route::post('/uploads', [WeddingUploadController::class, 'store'])->name('uploads.store');
                Route::post('/uploads/{upload}/complete', [WeddingUploadController::class, 'complete'])->name('uploads.complete');
                Route::post('/uploads/{upload}/multipart', [WeddingUploadController::class, 'initMultipart'])->name('uploads.multipart.init');
                Route::post('/uploads/{upload}/multipart/parts', [WeddingUploadController::class, 'presignParts'])->name('uploads.multipart.parts');
                Route::post('/uploads/{upload}/multipart/complete', [WeddingUploadController::class, 'completeMultipart'])->name('uploads.multipart.complete');
                Route::post('/uploads/{upload}/multipart/abort', [WeddingUploadController::class, 'abortMultipart'])->name('uploads.multipart.abort');
                Route::delete('/uploads/{upload}', [WeddingUploadController::class, 'destroy'])->name('uploads.destroy');
            });
        });
    });
});
