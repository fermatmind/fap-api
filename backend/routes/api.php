<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\MbtiController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\LookupController;
use App\Http\Controllers\API\V0_2\ShareController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
| 由 RouteServiceProvider 加载，并自动带上 /api 前缀
| 所以 prefix("v0.2") 下的 /health 实际路径是 /api/v0.2/health
|--------------------------------------------------------------------------
*/

Route::middleware("auth:sanctum")->get("/user", function (Request $request) {
    return $request->user();
});

Route::prefix("v0.2")->group(function () {

    // 1) 健康检查
    Route::get("/health", [MbtiController::class, "health"]);

    // 2) MBTI 量表元信息
    Route::get("/scales/MBTI", [MbtiController::class, "scaleMeta"]);

    // 3) MBTI 题目列表（Demo）
    Route::get("/scales/MBTI/questions", [MbtiController::class, "questions"]);

    // 4) 接收一次测评作答（创建 attempt + result）
    Route::post("/attempts", [MbtiController::class, "storeAttempt"]);

    // 5) 开始一次 attempt（你已有）
    Route::post("/attempts/start", [MbtiController::class, "startAttempt"]);

    // 6) 查询某次测评的结果 / 报告 / 分享信息
    Route::get("/attempts/{id}/result", [MbtiController::class, "getResult"]);
    Route::get("/attempts/{id}/report", [MbtiController::class, "getReport"]);
    Route::get("/attempts/{id}/share",  [MbtiController::class, "getShare"]);

    // ✅ 6.5) Ticket Code Lookup（Phase A P0）
    // GET /api/v0.2/lookup/ticket/FMT-XXXXXXXX
    Route::get("/lookup/ticket/{code}", [LookupController::class, "lookupTicket"]);

    // ✅ 6.6) Device Resume Lookup（Phase A P0）
    // POST /api/v0.2/lookup/device
    // Body: { "attempt_ids": ["uuid1","uuid2", ...] }
    Route::post("/lookup/device", [LookupController::class, "lookupDevice"]);

    // ✅ 7) Share Click：你要的入口（命中 ShareController@click）
    // 访问路径：POST /api/v0.2/shares/{shareId}/click
    Route::post("/shares/{shareId}/click", [ShareController::class, "click"]);

    // 8) 事件上报
    Route::post("/events", [EventController::class, "store"]);
});