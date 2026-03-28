<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\StudyController;


Route::post('/register', [App\Http\Controllers\Api\AuthController::class, 'register']);
Route::post('/login', [App\Http\Controllers\Api\AuthController::class, 'login']);

Route::middleware(['auth:sanctum'])->group(function () {
    Route::post('/logout', [App\Http\Controllers\Api\AuthController::class, 'logout']);
    Route::get('/user', function ($request) {
        return $request->user();
    });

    Route::prefix('studies')->group(function () {
        Route::post('/',           [StudyController::class, 'store']);
        Route::get('/',            [StudyController::class, 'index']);
        Route::get('/{id}',        [StudyController::class, 'show']);
        Route::get('/{id}/status', [StudyController::class, 'status']);
    });
});



