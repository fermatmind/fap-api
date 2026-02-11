<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Middleware\NormalizeApiErrorContract;
use App\Http\Controllers\HealthzController;
use App\Http\Controllers\MbtiController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\LookupController;
use App\Http\Controllers\API\V0_2\AuthPhoneController;
use App\Http\Controllers\API\V0_2\AuthProviderController;
use App\Http\Controllers\API\V0_2\ClaimController;
use App\Http\Controllers\API\V0_2\ContentPacksController;
use App\Http\Controllers\API\V0_2\IdentityController;
use App\Http\Controllers\API\V0_2\MemoryController;
use App\Http\Controllers\API\V0_2\MeController;
use App\Http\Controllers\API\V0_2\NormsController;
use App\Http\Controllers\API\V0_2\PsychometricsController;
use App\Http\Controllers\API\V0_2\ShareController;
use App\Http\Controllers\API\V0_2\ValidityFeedbackController;
use App\Http\Controllers\API\V0_2\AgentController;
use App\Http\Controllers\API\V0_2\Admin\AdminOpsController;
use App\Http\Controllers\API\V0_2\Admin\AdminAuditController;
use App\Http\Controllers\API\V0_2\Admin\AdminAgentController;
use App\Http\Controllers\API\V0_2\Admin\AdminMigrationController;
use App\Http\Controllers\API\V0_2\Admin\AdminQueueController;
use App\Http\Controllers\API\V0_2\Admin\AdminEventsController;
use App\Http\Controllers\API\V0_2\Admin\AdminContentController;
use App\Http\Controllers\API\V0_3\AttemptProgressController;
use App\Http\Controllers\API\V0_3\AttemptReadController;
use App\Http\Controllers\API\V0_3\AttemptWriteController;
use App\Http\Controllers\API\V0_3\BootController as BootV0_3Controller;
use App\Http\Controllers\API\V0_3\OrgsController;
use App\Http\Controllers\API\V0_3\OrgInvitesController;
use App\Http\Controllers\API\V0_3\ScalesController;
use App\Http\Controllers\API\V0_3\ScalesLookupController;
use App\Http\Controllers\API\V0_3\Webhooks\PaymentWebhookController;
use App\Http\Controllers\API\V0_4\BootController;
use App\Http\Controllers\API\V0_4\AssessmentController;
use App\Http\Middleware\LimitWebhookPayloadSize;
use App\Http\Controllers\Integrations\ProvidersController;
use App\Http\Controllers\Webhooks\HandleProviderWebhook;

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

Route::middleware('throttle:api_public')->get("/healthz", [HealthzController::class, "show"]);

Route::prefix("v0.2")->middleware([
    'throttle:api_public',
    NormalizeApiErrorContract::class,
])->group(function () {

    // 1) Health
    Route::get("/health", [MbtiController::class, "health"]);
    Route::get("/healthz", [HealthzController::class, "show"]);
    Route::get("/v0.2/healthz", [HealthzController::class, "show"]); // ✅ 新增

    // 1.5) Content packs
    Route::get("/content-packs", [ContentPacksController::class, "index"]);
    Route::get("/content-packs/{pack_id}/{dir_version}/manifest", [ContentPacksController::class, "manifest"]);
    Route::get("/content-packs/{pack_id}/{dir_version}/questions", [ContentPacksController::class, "questions"]);

    // 1.6) Admin APIs (token or admin session)
    Route::prefix("admin")
        ->middleware(\App\Http\Middleware\AdminAuth::class)
        ->group(function () {
            // Ops snapshot
            Route::get("/healthz/snapshot", [AdminOpsController::class, "healthzSnapshot"]);
            Route::get("/migrations/observability", [AdminMigrationController::class, "observability"]);
            Route::get("/migrations/rollback-preview", [AdminMigrationController::class, "rollbackPreview"]);
            Route::get("/queue/dlq/metrics", [AdminQueueController::class, "metrics"]);
            Route::post("/queue/dlq/replay/{failed_job_id}", [AdminQueueController::class, "replay"])
                ->whereNumber('failed_job_id');

            // Audit logs (read-only)
            Route::get("/audit-logs", [AdminAuditController::class, "index"]);

            // Events (read-only)
            Route::get("/events", [AdminEventsController::class, "index"]);

            // Agent controls (admin-only)
            Route::post("/agent/disable-trigger", [AdminAgentController::class, "disableTrigger"]);
            Route::post("/agent/replay/{user_id}", [AdminAgentController::class, "replay"]);

            // Content releases (read-only + tools)
            Route::get("/content-releases", [AdminContentController::class, "index"]);
            Route::post("/content-releases/{id}/probe", [AdminContentController::class, "probe"]);
            Route::post("/cache/invalidate", [AdminOpsController::class, "invalidateCache"]);

            // Legacy admin content release ops
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
    Route::get("/scales/{scale}/norms", [PsychometricsController::class, "listNorms"]);

    // 3) Questions (demo)
    Route::get("/scales/MBTI/questions", [MbtiController::class, "questions"]);

    Route::middleware('throttle:api_attempt_submit')->group(function () {
        // 4) Submit attempt (anonymous allowed)
        Route::post("/attempts", [MbtiController::class, "storeAttempt"]);

        // 5) Start attempt (anonymous allowed)
        Route::post("/attempts/start", [MbtiController::class, "startAttempt"]);
    });

    // 6) Public share view (legacy)
    Route::get("/share/{id}", [ShareController::class, "getShareView"]);

    // 6.5) Lookups
    Route::get("/lookup/ticket/{code}", [LookupController::class, "lookupTicket"]);
    Route::post("/lookup/order", [LookupController::class, "lookupOrder"]);

    // 7) Share click (public)
    // share_id now supports 32-hex legacy ids, so remove uuid middleware.
    Route::post("/shares/{shareId}/click", [ShareController::class, "click"]);

    Route::middleware('throttle:api_auth')->group(function () {
        // Auth (public)
        Route::post("/auth/wx_phone", \App\Http\Controllers\API\V0_2\AuthWxPhoneController::class);
        Route::post("/auth/phone/send_code", [AuthPhoneController::class, "sendCode"]);
        Route::post("/auth/phone/verify", [AuthPhoneController::class, "verify"]);
        Route::post("/auth/provider", [AuthProviderController::class, "login"]);
        Route::get("/claim/report", [ClaimController::class, "report"]);
    });

    // Events ingestion (public for now)
    Route::post("/events", [EventController::class, "store"]);

    // Norms
    Route::get("/norms/percentile", [NormsController::class, "percentile"]);

    Route::middleware('throttle:api_webhook')->group(function () {
        // =========================================================
        // Integrations (mock OAuth + ingestion + replay)
        // =========================================================
        Route::prefix("integrations/{provider}")->group(function () {
            Route::get("/oauth/start", [ProvidersController::class, "oauthStart"]);
            Route::get("/oauth/callback", [ProvidersController::class, "oauthCallback"]);
            Route::post("/revoke", [ProvidersController::class, "revoke"]);
            Route::post("/ingest", [ProvidersController::class, "ingest"])
                ->middleware(\App\Http\Middleware\VerifyIntegrationSignature::class);
            Route::post("/replay/{batch_id}", [ProvidersController::class, "replay"]);
        });

        // =========================================================
        // Webhooks (provider push)
        // =========================================================
        Route::post("/webhooks/{provider}", [HandleProviderWebhook::class, "handle"])
            ->whereIn('provider', ['mock', 'apple_health', 'google_fit', 'calendar', 'screen_time']);
    });

    // =========================================================
    // AI Insights (async, budget guarded)
    // =========================================================
    Route::middleware('App\\Http\\Middleware\\CheckAiBudget')->group(function () {
        Route::post("/insights/generate", "App\\Http\\Controllers\\API\\V0_2\\InsightsController@generate");
    });
    Route::get("/insights/{id}", "App\\Http\\Controllers\\API\\V0_2\\InsightsController@show");
    Route::post("/insights/{id}/feedback", "App\\Http\\Controllers\\API\\V0_2\\InsightsController@feedback");

    // =========================================================
    // Optional token attach (no 401): allow anon access + enrich events.user_id when token exists
    // =========================================================
    Route::middleware(\App\Http\Middleware\FmTokenOptional::class)->group(function () {
        Route::get("/attempts/{id}/result", [MbtiController::class, "getResult"])
            ->middleware('uuid:id');
        Route::get("/attempts/{id}/report", [MbtiController::class, "getReport"])
            ->middleware('uuid:id');
        Route::get("/attempts/{id}/quality", [PsychometricsController::class, "quality"])
            ->middleware('uuid:id');
        Route::get("/attempts/{id}/stats", [PsychometricsController::class, "stats"])
            ->middleware('uuid:id');
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
        Route::get("/me/data/sleep", [MeController::class, "sleepData"]);
        Route::get("/me/data/mood", [MeController::class, "moodData"]);
        Route::get("/me/data/screen-time", [MeController::class, "screenTimeData"]);

        // Memory
        Route::post("/memory/propose", [MemoryController::class, "propose"]);
        Route::post("/memory/{id}/confirm", [MemoryController::class, "confirm"]);
        Route::delete("/memory/{id}", [MemoryController::class, "delete"]);
        Route::get("/memory/search", [MemoryController::class, "search"]);
        Route::get("/memory/export", [MemoryController::class, "export"]);

        // Agent (me)
        Route::get("/me/agent/settings", [AgentController::class, "settings"]);
        Route::post("/me/agent/settings", [AgentController::class, "updateSettings"]);
        Route::get("/me/agent/messages", [AgentController::class, "messages"]);
        Route::post("/me/agent/messages/{id}/feedback", [AgentController::class, "feedback"]);
        Route::post("/me/agent/messages/{id}/ack", [AgentController::class, "ack"]);

        // Attempts gated endpoints
        Route::post("/attempts/{id}/result", [MbtiController::class, "upsertResult"]);
        Route::get("/attempts/{id}/share", [ShareController::class, "getShare"]);

        Route::post("/attempts/{attempt_id}/feedback", [ValidityFeedbackController::class, "store"]);
        Route::post("/lookup/device", [LookupController::class, "lookupDevice"]);

    });
});

Route::prefix("v0.3")->middleware([
    'throttle:api_public',
    NormalizeApiErrorContract::class,
])->group(function () {

    // ✅ 关键修复：payment webhook 必须是“公共入口”，不能依赖 ResolveOrgContext / token
    Route::post(
        "/webhooks/payment/{provider}",
        [PaymentWebhookController::class, "handle"]
    )->whereIn('provider', ['stripe', 'billing'])
        ->middleware([LimitWebhookPayloadSize::class, 'throttle:api_webhook'])
        ->name('v0.3.webhooks.payment');

    Route::middleware(\App\Http\Middleware\ResolveOrgContext::class)->group(function () {
        // 0) Boot (flags + experiments)
        Route::get("/boot", [BootV0_3Controller::class, "show"]);
        Route::get("/flags", [BootV0_3Controller::class, "flags"]);
        Route::get("/experiments", [BootV0_3Controller::class, "experiments"]);

        // 1) Scale registry
        Route::get("/scales", [ScalesController::class, "index"]);
        Route::get("/scales/lookup", [ScalesLookupController::class, "lookup"]);
        Route::get("/scales/{scale_code}/questions", [ScalesController::class, "questions"]);
        Route::get("/scales/{scale_code}", [ScalesController::class, "show"]);

        // 2) Attempts lifecycle
        Route::middleware('throttle:api_attempt_submit')->group(function () {
            Route::post("/attempts/start", [AttemptWriteController::class, "start"]);
            Route::post("/attempts/submit", [AttemptWriteController::class, "submit"]);
        });
        Route::put("/attempts/{attempt_id}/progress", [AttemptProgressController::class, "upsert"])
            ->middleware('uuid:attempt_id');
        Route::get("/attempts/{attempt_id}/progress", [AttemptProgressController::class, "show"])
            ->middleware('uuid:attempt_id');
        Route::get("/attempts/{id}/result", [AttemptReadController::class, "result"])
            ->middleware('uuid:id');
        Route::get("/attempts/{id}/report", [AttemptReadController::class, "report"])
            ->middleware('uuid:id')
            ->name('v0.3.attempts.report');

        // 3) Commerce v2 (public with org context)
        Route::get("/skus", "App\\Http\\Controllers\\API\\V0_3\\CommerceController@listSkus");
        Route::post("/orders", "App\\Http\\Controllers\\API\\V0_3\\CommerceController@createOrder");
        Route::post("/orders/{provider}", "App\\Http\\Controllers\\API\\V0_3\\CommerceController@createOrder");
        Route::get("/orders/{order_no}", "App\\Http\\Controllers\\API\\V0_3\\CommerceController@getOrder");
    });

    Route::middleware([\App\Http\Middleware\FmTokenAuth::class, \App\Http\Middleware\ResolveOrgContext::class])
        ->group(function () {
            Route::post("/orgs", [OrgsController::class, "store"]);
            Route::get("/orgs/me", [OrgsController::class, "me"]);
            Route::post("/orgs/{org_id}/invites", [OrgInvitesController::class, "store"]);
            Route::post("/orgs/invites/accept", [OrgInvitesController::class, "accept"]);

            // Org wallets (admin/owner only)
            Route::get("/orgs/{org_id}/wallets", "App\\Http\\Controllers\\API\\V0_3\\OrgWalletController@wallets");
            Route::get(
                "/orgs/{org_id}/wallets/{benefit_code}/ledger",
                "App\\Http\\Controllers\\API\\V0_3\\OrgWalletController@ledger"
            );
        });
});

Route::prefix("v0.4")->middleware(NormalizeApiErrorContract::class)->group(function () {
    Route::get("/boot", [BootController::class, "show"])
        ->middleware('throttle:api_public');

    Route::middleware([
        \App\Http\Middleware\FmTokenAuth::class,
        \App\Http\Middleware\RequireOrgRole::class . ':owner,admin',
    ])->group(function () {
        Route::post("/orgs/{org_id}/assessments", [AssessmentController::class, "store"]);
        Route::post("/orgs/{org_id}/assessments/{id}/invite", [AssessmentController::class, "invite"]);
        Route::get("/orgs/{org_id}/assessments/{id}/progress", [AssessmentController::class, "progress"]);
        Route::get("/orgs/{org_id}/assessments/{id}/summary", [AssessmentController::class, "summary"]);
    });
});
