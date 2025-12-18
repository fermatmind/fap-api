<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\MbtiController;
use App\Http\Controllers\EventController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| 这里注册 API 路由，由 RouteServiceProvider 加载，并自动带上 /api 前缀。
| 所以 prefix('v0.2') 里的 /health 实际访问路径是：
|   /api/v0.2/health
|
*/

// 默认示例（目前用不到，可以保留，也可以删掉）
Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// FAP v0.2 · MBTI 最小骨架 API
Route::prefix('v0.2')->group(function () {

    // 1) 健康检查
    // GET /api/v0.2/health
    Route::get('/health', [MbtiController::class, 'health']);

    // 2) MBTI 量表元信息
    // GET /api/v0.2/scales/MBTI
    Route::get('/scales/MBTI', [MbtiController::class, 'scaleMeta']);

    // 3) MBTI 题目列表（Demo）
    // GET /api/v0.2/scales/MBTI/questions
    Route::get('/scales/MBTI/questions', [MbtiController::class, 'questions']);

    // 4) 接收一次测评作答（创建 attempt + result）
    // POST /api/v0.2/attempts
    Route::post('/attempts', [MbtiController::class, 'storeAttempt']);

    // 5) 查询某次测评的结果（会写 result_view，但由后端 10s 去抖）
    // GET /api/v0.2/attempts/{id}/result
    Route::get('/attempts/{id}/result', [MbtiController::class, 'getResult']);

    // 6) 获取分享模板数据（只返回文案骨架；不写 share_* 事件）
    // GET /api/v0.2/attempts/{id}/share
    Route::get('/attempts/{id}/share', [MbtiController::class, 'getShare']);

    // 7) ✅ 统一事件上报接口（M2：share_generate / share_click）
    // POST /api/v0.2/events
    Route::post('/events', [EventController::class, 'store']);
});