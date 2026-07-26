<?php

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
