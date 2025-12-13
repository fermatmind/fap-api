<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\MbtiController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

Route::prefix('v0.2')->group(function () {
    // 健康检查
    Route::get('/health', [MbtiController::class, 'health']);

    // MBTI 量表元信息
    Route::get('/scales/MBTI', [MbtiController::class, 'scaleMeta']);

    // MBTI 题目列表（Demo）
    Route::get('/scales/MBTI/questions', [MbtiController::class, 'questions']);

    // 接收一次作答
    Route::post('/attempts', [MbtiController::class, 'createAttempt']);

    // 查询作答结果
    Route::get('/attempts/{id}/result', [MbtiController::class, 'getResult']);
});