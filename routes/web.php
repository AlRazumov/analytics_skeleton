<?php

use App\Http\Controllers\DemoWidgetsController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/demo/widgets', DemoWidgetsController::class);
