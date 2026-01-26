<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\MbtiController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\LookupController;
use App\Http\Controllers\API\V0_2\AuthPhoneController;
use App\Http\Controllers\API\V0_2\AuthProviderController;
use App\Http\Controllers\API\V0_2\ClaimController;
use App\Http\Controllers\API\V0_2\ContentPacksController;
use App\Http\Controllers\API\V0_2\IdentityController;
use App\Http\Controllers\API\V0_2\MeController;
use App\Http\Controllers\API\V0_2\NormsController;
use App\Http\Controllers\API\V0_2\PaymentsController;
use App\Http\Controllers\API\V0_2\ShareController;
use App\Http\Controllers\API\V0_2\ValidityFeedbackController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
| RouteServiceProvider loads this file with "/api" prefix.
| So "/v0.2/health" becomes "/api/v0.2/health".
|--------------------------------------------------------------------------
*/

Route::middleware("auth:sanctum")->get("/user", function (Request $request) {
    return $request->user();
});

Route::prefix("v0.2")->group(function () {

    // 1) Health
    Route::get("/health", [MbtiController::class, "health"]);

    // 1.5) Content packs
    Route::get("/content-packs", [ContentPacksController::class, "index"]);
    Route::get("/content-packs/{pack_id}/{dir_version}/manifest", [ContentPacksController::class, "manifest"]);
    Route::get("/content-packs/{pack_id}/{dir_version}/questions", [ContentPacksController::class, "questions"]);

    // 1.6) Admin content releases
    Route::prefix("admin")->group(function () {
        Route::post(
            "/content-releases/upload",
            [\App\Http\Controllers\API\V0_2\Admin\ContentReleaseController::class, "upload"]
        );
        Route::post(
            "/content-releases/publish",
            [\App\Http\Controllers\API\V0_2\Admin\ContentReleaseController::class, "publish"]
        );
        Route::post(
            "/content-releases/rollback",
            [\App\Http\Controllers\API\V0_2\Admin\ContentReleaseController::class, "rollback"]
        );
    });

    // 2) Scale meta
    Route::get("/scales/MBTI", [MbtiController::class, "scaleMeta"]);

    // 3) Questions (demo)
    Route::get("/scales/MBTI/questions", [MbtiController::class, "questions"]);

    // 4) Submit attempt (anonymous allowed)
    Route::post("/attempts", [MbtiController::class, "storeAttempt"]);

    // 5) Start attempt (anonymous allowed)
    Route::post("/attempts/start", [MbtiController::class, "startAttempt"]);

    // 6) Public share info
    Route::get("/attempts/{id}/share", [MbtiController::class, "getShare"]);

    // 6.5) Lookups
    Route::get("/lookup/ticket/{code}", [LookupController::class, "lookupTicket"]);
    Route::post("/lookup/order", [LookupController::class, "lookupOrder"]);

    // 7) Share click (public)
    Route::post("/shares/{shareId}/click", [ShareController::class, "click"]);

    // Auth (public)
    Route::post("/auth/wx_phone", \App\Http\Controllers\API\V0_2\AuthWxPhoneController::class);
    Route::post("/auth/phone/send_code", [AuthPhoneController::class, "sendCode"]);
    Route::post("/auth/phone/verify", [AuthPhoneController::class, "verify"]);
    Route::post("/auth/provider", [AuthProviderController::class, "login"]);
    Route::get("/claim/report", [ClaimController::class, "report"]);

    // Events ingestion (public for now)
    Route::post("/events", [EventController::class, "store"]);

    // Norms (stub)
    Route::get("/norms/percentile", [NormsController::class, "percentile"]);

    // Payments webhook (mock, public)
    Route::post("/payments/webhook/mock", [PaymentsController::class, "webhookMock"]);

    // =========================================================
    // Optional token attach (no 401): allow anon access + enrich events.user_id when token exists
    // =========================================================
    Route::middleware(\App\Http\Middleware\FmTokenOptional::class)->group(function () {
        Route::get("/attempts/{id}/result", [MbtiController::class, "getResult"]);
        Route::get("/attempts/{id}/report", [MbtiController::class, "getReport"]);
    });

    // =========================================================
    // Strict token gate: endpoints needing identity
    // =========================================================
    Route::middleware(\App\Http\Middleware\FmTokenAuth::class)->group(function () {

        // /me/*
        Route::get("/me/attempts", [MeController::class, "attempts"]);
        Route::post("/me/email/bind", [MeController::class, "bindEmail"]);
        Route::post("/me/identities/bind", [IdentityController::class, "bind"]);
        Route::get("/me/identities", [IdentityController::class, "index"]);

        // Attempts gated endpoints
        Route::post("/attempts/{id}/result", [MbtiController::class, "upsertResult"]);

        Route::post("/attempts/{attempt_id}/feedback", [ValidityFeedbackController::class, "store"]);
        Route::post("/lookup/device", [LookupController::class, "lookupDevice"]);

        // Payments (v0.2)
        Route::prefix("payments")->group(function () {
            Route::post("/orders", [PaymentsController::class, "createOrder"]);
            Route::post("/orders/{id}/mark_paid", [PaymentsController::class, "markPaid"]);
            Route::post("/orders/{id}/fulfill", [PaymentsController::class, "fulfill"]);
            Route::get("/me/benefits", [PaymentsController::class, "meBenefits"]);
        });
    });
});
