<?php

use App\Http\Controllers\API\V0_3\AttemptProgressController;
use App\Http\Controllers\API\V0_3\AttemptReadController;
use App\Http\Controllers\API\V0_3\AttemptWriteController;
use App\Http\Controllers\API\V0_3\AuthPhoneController as AuthPhoneV03Controller;
use App\Http\Controllers\API\V0_3\AuthWxPhoneController as AuthWxPhoneV03Controller;
use App\Http\Controllers\API\V0_3\BigFiveOpsController;
use App\Http\Controllers\API\V0_3\BootController as BootV0_3Controller;
use App\Http\Controllers\API\V0_3\ClaimController as ClaimV03Controller;
use App\Http\Controllers\API\V0_3\MeController as MeV03Controller;
use App\Http\Controllers\API\V0_3\OrgInvitesController;
use App\Http\Controllers\API\V0_3\OrgsController;
use App\Http\Controllers\API\V0_3\ScalesController;
use App\Http\Controllers\API\V0_3\ScalesLookupController;
use App\Http\Controllers\API\V0_3\ScalesSitemapSourceController;
use App\Http\Controllers\API\V0_3\ShareController as ShareV03Controller;
use App\Http\Controllers\API\V0_3\Webhooks\PaymentWebhookController;
use App\Http\Controllers\API\V0_4\AssessmentController;
use App\Http\Controllers\API\V0_4\BootController;
use App\Http\Controllers\HealthzController;
use App\Http\Middleware\HealthzAccessControl;
use App\Http\Middleware\LimitWebhookPayloadSize;
use App\Http\Middleware\NormalizeApiErrorContract;
use App\Http\Middleware\ResolveOrgContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
| RouteServiceProvider loads this file with "/api" prefix.
| So "/v0.3/scales" becomes "/api/v0.3/scales".
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::middleware([HealthzAccessControl::class, 'throttle:api_public'])
    ->get('/healthz', [HealthzController::class, 'show'])
    ->name('healthz');

Route::prefix('v0.2')->middleware([
    'throttle:api_public',
    NormalizeApiErrorContract::class,
])->group(function () {
    Route::any('/{any?}', static function () {
        return response()->json([
            'ok' => false,
            'error_code' => 'API_VERSION_DEPRECATED',
            'message' => 'API v0.2 has been retired. Use /api/v0.3.',
        ], 410);
    })->where('any', '.*');
});

Route::prefix('v0.3')->middleware([
    'throttle:api_public',
    NormalizeApiErrorContract::class,
])->group(function () {
    $payProviders = ['stripe', 'billing'];
    if (app()->environment(['local', 'testing']) && config('payments.allow_stub') === true) {
        $payProviders[] = 'stub';
    }

    // ✅ 关键修复：payment webhook 必须是“公共入口”，不能依赖 ResolveOrgContext / token
    Route::post(
        '/webhooks/payment/{provider}',
        [PaymentWebhookController::class, 'handle']
    )->whereIn('provider', $payProviders)
        ->middleware([LimitWebhookPayloadSize::class, 'throttle:api_webhook'])
        ->name('api.v0_3.webhooks.payment');

    Route::middleware('throttle:api_auth')->group(function () {
        if (app()->environment(['local', 'testing', 'ci'])) {
            Route::post('/auth/wx_phone', AuthWxPhoneV03Controller::class);
        } else {
            Route::post('/auth/wx_phone', static function () {
                abort(404);
            });
        }
        Route::post('/auth/phone/send_code', [AuthPhoneV03Controller::class, 'sendCode']);
        Route::post('/auth/phone/verify', [AuthPhoneV03Controller::class, 'verify']);
    });

    Route::get('/claim/report', [ClaimV03Controller::class, 'report']);

    Route::middleware(\App\Http\Middleware\FmTokenAuth::class)->group(function () {
        Route::get('/me/attempts', [MeV03Controller::class, 'attempts']);
    });

    Route::middleware([\App\Http\Middleware\ResolveAnonId::class, ResolveOrgContext::class])->group(function () use ($payProviders) {
        // 0) Boot (flags + experiments)
        Route::get('/boot', [BootV0_3Controller::class, 'show']);
        Route::get('/flags', [BootV0_3Controller::class, 'flags']);
        Route::get('/experiments', [BootV0_3Controller::class, 'experiments']);

        // 1) Scale registry
        Route::get('/scales', [ScalesController::class, 'index']);
        Route::get('/scales/lookup', [ScalesLookupController::class, 'lookup']);
        Route::get('/scales/sitemap-source', [ScalesSitemapSourceController::class, 'index']);
        Route::get('/scales/{scale_code}/questions', [ScalesController::class, 'questions']);
        Route::get('/scales/{scale_code}', [ScalesController::class, 'show']);

        // 2) Attempts lifecycle
        Route::middleware('throttle:api_attempt_submit')->group(function () {
            Route::post('/attempts/start', [AttemptWriteController::class, 'start']);
            Route::post('/attempts/submit', [AttemptWriteController::class, 'submit'])
                ->middleware(\App\Http\Middleware\FmTokenAuth::class);
        });
        Route::put('/attempts/{attempt_id}/progress', [AttemptProgressController::class, 'upsert'])
            ->middleware('uuid:attempt_id');
        Route::get('/attempts/{attempt_id}/progress', [AttemptProgressController::class, 'show'])
            ->middleware('uuid:attempt_id');
        Route::get('/attempts/{id}', [AttemptReadController::class, 'show'])
            ->middleware('uuid:id')
            ->name('api.v0_3.attempts.show');
        Route::get('/attempts/{id}/result', [AttemptReadController::class, 'result'])
            ->middleware('uuid:id')
            ->name('api.v0_3.attempts.result');
        Route::get('/attempts/{id}/report', [AttemptReadController::class, 'report'])
            ->middleware('uuid:id')
            ->name('api.v0_3.attempts.report');
        Route::get('/attempts/{id}/report.pdf', [AttemptReadController::class, 'reportPdf'])
            ->middleware('uuid:id')
            ->name('api.v0_3.attempts.report_pdf');
        Route::get('/attempts/{id}/share', [ShareV03Controller::class, 'getShare'])
            ->middleware(\App\Http\Middleware\FmTokenAuth::class);

        // 3) Commerce v2 (public with org context)
        Route::get('/skus', 'App\\Http\\Controllers\\API\\V0_3\\CommerceController@listSkus')
            ->name('api.v0_3.skus');
        Route::post('/orders/checkout', 'App\\Http\\Controllers\\API\\V0_3\\CommerceController@checkout');
        Route::post('/orders/lookup', 'App\\Http\\Controllers\\API\\V0_3\\CommerceController@lookup');
        Route::post('/orders/{order_no}/resend', 'App\\Http\\Controllers\\API\\V0_3\\CommerceController@resend');
        Route::post('/orders', 'App\\Http\\Controllers\\API\\V0_3\\CommerceController@createOrder')
            ->middleware(\App\Http\Middleware\FmTokenAuth::class);
        Route::post('/orders/stub', static function (
            Request $request,
            \App\Http\Controllers\API\V0_3\CommerceController $controller
        ) {
            $stubEnabled = app()->environment(['local', 'testing']) && config('payments.allow_stub') === true;
            if (! $stubEnabled) {
                abort(404);
            }

            return app(\App\Http\Middleware\FmTokenAuth::class)
                ->handle($request, static fn (Request $authedRequest) => $controller->createOrder($authedRequest, 'stub'));
        });
        Route::post('/orders/{provider}', 'App\\Http\\Controllers\\API\\V0_3\\CommerceController@createOrder')
            ->middleware(\App\Http\Middleware\FmTokenAuth::class)
            ->whereIn('provider', $payProviders);
        Route::get('/orders/{order_no}', 'App\\Http\\Controllers\\API\\V0_3\\CommerceController@getOrder');
        Route::get('/shares/{id}', [ShareV03Controller::class, 'getShareView']);
        Route::post('/shares/{shareId}/click', [ShareV03Controller::class, 'click'])
            ->middleware([
                \App\Http\Middleware\FmTokenOptional::class,
                \App\Http\Middleware\LimitApiPublicPayloadSize::class,
                'throttle:api_public',
            ])
            ->where('shareId', '[A-Za-z0-9_-]{6,128}');
    });

    Route::middleware([\App\Http\Middleware\FmTokenAuth::class, ResolveOrgContext::class])
        ->group(function () {
            Route::post('/orgs', [OrgsController::class, 'store']);
            Route::get('/orgs/me', [OrgsController::class, 'me']);
            Route::post('/orgs/{org_id}/invites', [OrgInvitesController::class, 'store']);
            Route::post('/orgs/invites/accept', [OrgInvitesController::class, 'accept']);
            Route::get('/orgs/{org_id}/big5/releases', [BigFiveOpsController::class, 'releases'])
                ->middleware(\App\Http\Middleware\RequireOrgRole::class.':owner,admin');
            Route::get('/orgs/{org_id}/big5/audits', [BigFiveOpsController::class, 'audits'])
                ->middleware(\App\Http\Middleware\RequireOrgRole::class.':owner,admin');
            Route::get('/orgs/{org_id}/big5/audits/{audit_id}', [BigFiveOpsController::class, 'audit'])
                ->middleware(\App\Http\Middleware\RequireOrgRole::class.':owner,admin');
            Route::get('/orgs/{org_id}/big5/releases/latest', [BigFiveOpsController::class, 'latest'])
                ->middleware(\App\Http\Middleware\RequireOrgRole::class.':owner,admin');
            Route::get('/orgs/{org_id}/big5/releases/latest/audits', [BigFiveOpsController::class, 'latestAudits'])
                ->middleware(\App\Http\Middleware\RequireOrgRole::class.':owner,admin');
            Route::get('/orgs/{org_id}/big5/releases/{release_id}', [BigFiveOpsController::class, 'release'])
                ->middleware(\App\Http\Middleware\RequireOrgRole::class.':owner,admin');
            Route::post('/orgs/{org_id}/big5/releases/publish', [BigFiveOpsController::class, 'publish'])
                ->middleware(\App\Http\Middleware\RequireOrgRole::class.':owner,admin');
            Route::post('/orgs/{org_id}/big5/releases/rollback', [BigFiveOpsController::class, 'rollback'])
                ->middleware(\App\Http\Middleware\RequireOrgRole::class.':owner,admin');
            Route::post('/orgs/{org_id}/big5/norms/rebuild', [BigFiveOpsController::class, 'rebuildNorms'])
                ->middleware(\App\Http\Middleware\RequireOrgRole::class.':owner,admin');
            Route::post('/orgs/{org_id}/big5/norms/drift-check', [BigFiveOpsController::class, 'driftCheckNorms'])
                ->middleware(\App\Http\Middleware\RequireOrgRole::class.':owner,admin');
            Route::post('/orgs/{org_id}/big5/norms/activate', [BigFiveOpsController::class, 'activateNorms'])
                ->middleware(\App\Http\Middleware\RequireOrgRole::class.':owner,admin');

            // Org wallets (admin/owner only)
            Route::get('/orgs/{org_id}/wallets', 'App\\Http\\Controllers\\API\\V0_3\\OrgWalletController@wallets');
            Route::get(
                '/orgs/{org_id}/wallets/{benefit_code}/ledger',
                'App\\Http\\Controllers\\API\\V0_3\\OrgWalletController@ledger'
            );
        });
});

Route::prefix('v0.4')->middleware(NormalizeApiErrorContract::class)->group(function () {
    Route::get('/boot', [BootController::class, 'show'])
        ->middleware('throttle:api_public');

    Route::middleware([
        \App\Http\Middleware\FmTokenAuth::class,
        \App\Http\Middleware\RequireOrgRole::class.':owner,admin',
    ])->group(function () {
        Route::post('/orgs/{org_id}/assessments', [AssessmentController::class, 'store']);
        Route::post('/orgs/{org_id}/assessments/{id}/invite', [AssessmentController::class, 'invite']);
        Route::get('/orgs/{org_id}/assessments/{id}/progress', [AssessmentController::class, 'progress']);
        Route::get('/orgs/{org_id}/assessments/{id}/summary', [AssessmentController::class, 'summary']);
    });
});
