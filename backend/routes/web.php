<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

if (!config('admin.panel_enabled')) {
    Route::get('/admin', function () {
        abort(404);
    });
}
