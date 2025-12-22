<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\MbtiController;
use App\Http\Controllers\Api\V0_2\ShareController;
use App\Http\Controllers\EventController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
| 由框架加载，并自动带上 /api 前缀
| 所以 prefix('v0.2') 下的 /health 实际路径是：
|   /api/v0.2/health
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

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

    // 5) 查询某次测评的结果（服务端可做去抖写 result_view）
    // GET /api/v0.2/attempts/{id}/result
    Route::get('/attempts/{id}/result', [MbtiController::class, 'getResult']);

    // 6) 获取分享模板数据（只返回文案骨架）
    // GET /api/v0.2/attempts/{id}/share
    Route::get('/attempts/{id}/share', [MbtiController::class, 'getShare']);

    // 6.1) ✅ share_click（打开分享落地页 / 点击分享链接时打点）
    // POST /api/v0.2/shares/{share_id}/click
    Route::post('/shares/{shareId}/click', [ShareController::class, 'click']);

    // 7) ✅ 统一事件上报接口
    // POST /api/v0.2/events
    Route::post('/events', [EventController::class, 'store']);

    // 8) ✅ v1.2 Report（M3-0：契约冻结）
    // GET /api/v0.2/attempts/{id}/report
    Route::get('/attempts/{id}/report', [MbtiController::class, 'getReport']);
});