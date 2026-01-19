<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\MbtiController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\LookupController;
use App\Http\Controllers\API\V0_2\AuthPhoneController;
use App\Http\Controllers\API\V0_2\MeController;
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
    // （匿名允许：测完再登录也行）
    Route::post("/attempts", [MbtiController::class, "storeAttempt"]);

    // 5) 开始一次 attempt（匿名允许）
    Route::post("/attempts/start", [MbtiController::class, "startAttempt"]);

    // 6) 查询某次测评的分享信息（按你需求：可保持公开/或后续再门禁）
    Route::get("/attempts/{id}/share",  [MbtiController::class, "getShare"]);

    // ✅ 写入/更新 result（如果你用于内部写入，也可先不门禁；后续再收紧）
    Route::post("/attempts/{id}/result", [MbtiController::class, "upsertResult"]);

    // ✅ 6.5) Ticket Code Lookup（你后续可以选择门禁或不门禁）
    Route::get("/lookup/ticket/{code}", [LookupController::class, "lookupTicket"]);

    // ✅ 7) Share Click（保持公开）
    Route::post("/shares/{shareId}/click", [ShareController::class, "click"]);

    // ✅ 登录接口（必须公开，否则无法拿 token）
    Route::post("/auth/wx_phone", \App\Http\Controllers\API\V0_2\AuthWxPhoneController::class);
    Route::post("/auth/phone/send_code", [AuthPhoneController::class, "sendCode"]);
    Route::post("/auth/phone/verify", [AuthPhoneController::class, "verify"]);

    // 8) 事件上报（目前你前端已带 Authorization，但事件是否门禁你可后续决定）
    // 这里先保持公开（不影响主链路）
    Route::post("/events", [EventController::class, "store"]);

    // =========================================================
    // ✅ Step 1：最小后端鉴权（只 gate 你指定的 3 个接口）
    // 关键：Laravel 12 里你 alias 还没注册成功，所以这里直接用类名
    // =========================================================
    Route::middleware(\App\Http\Middleware\FmTokenAuth::class)->group(function () {

    // ✅ GET /api/v0.2/me/attempts
    Route::get("/me/attempts", [MeController::class, "attempts"]);

    Route::get("/attempts/{id}/result", [MbtiController::class, "getResult"]);
    Route::get("/attempts/{id}/report", [MbtiController::class, "getReport"]);
    Route::post("/lookup/device", [LookupController::class, "lookupDevice"]);
});
});
