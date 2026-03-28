<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;


Route::post('/register', [App\Http\Controllers\Api\AuthController::class, 'register']);
Route::post('/login', [App\Http\Controllers\Api\AuthController::class, 'login']);

Route::middleware(['auth:sanctum'])->group(function () {
    Route::post('/logout', [App\Http\Controllers\Api\AuthController::class, 'logout']);
    Route::get('/user', function ($request) {
        return $request->user();
    });

    Route::prefix('studies')->group(function () {
        Route::post('/',           ['App\Http\Controllers\Api\StudyController', 'store']);
        Route::get('/',            ['App\Http\Controllers\Api\StudyController', 'index']);
        Route::get('/{id}',        ['App\Http\Controllers\Api\StudyController', 'show']);
        Route::get('/{id}/status', ['App\Http\Controllers\Api\StudyController', 'status']);
    });
});



