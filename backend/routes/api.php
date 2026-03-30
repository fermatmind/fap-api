<?php

use App\Http\Controllers\API\V0_3\AttemptProgressController;
use App\Http\Controllers\API\V0_3\AttemptReadController;
use App\Http\Controllers\API\V0_3\AttemptWriteController;
use App\Http\Controllers\API\V0_3\AuthGuestController as AuthGuestV03Controller;
use App\Http\Controllers\API\V0_3\AuthPhoneController as AuthPhoneV03Controller;
use App\Http\Controllers\API\V0_3\AuthWxPhoneController as AuthWxPhoneV03Controller;
use App\Http\Controllers\API\V0_3\BigFiveOpsController;
use App\Http\Controllers\API\V0_3\BootController as BootV0_3Controller;
use App\Http\Controllers\API\V0_3\ClaimController as ClaimV03Controller;
use App\Http\Controllers\API\V0_3\ComplianceDsarController;
use App\Http\Controllers\API\V0_3\EmailCaptureController;
use App\Http\Controllers\API\V0_3\EmailPreferenceController;
use App\Http\Controllers\API\V0_3\MbtiCompareInviteController;
use App\Http\Controllers\API\V0_3\MeController as MeV03Controller;
use App\Http\Controllers\API\V0_3\OrgInvitesController;
use App\Http\Controllers\API\V0_3\OrgsController;
use App\Http\Controllers\API\V0_3\PublicGatewaySurfaceController;
use App\Http\Controllers\API\V0_3\ScalesController;
use App\Http\Controllers\API\V0_3\ScalesLookupController;
use App\Http\Controllers\API\V0_3\ScalesSitemapSourceController;
use App\Http\Controllers\API\V0_3\ShareController as ShareV03Controller;
use App\Http\Controllers\API\V0_3\Webhooks\PaymentWebhookController;
use App\Http\Controllers\API\V0_4\AssessmentController;
use App\Http\Controllers\API\V0_4\BootController;
use App\Http\Controllers\API\V0_4\ExperimentGovernanceController;
use App\Http\Controllers\API\V0_4\PartnerController;
use App\Http\Controllers\API\V0_4\RotationAuditController;
use App\Http\Controllers\API\V0_5\Cms\ArticleController;
use App\Http\Controllers\API\V0_5\Cms\CareerGuideController;
use App\Http\Controllers\API\V0_5\Cms\CareerJobController;
use App\Http\Controllers\API\V0_5\Cms\CareerRecommendationController;
use App\Http\Controllers\API\V0_5\Cms\PersonalityController;
use App\Http\Controllers\API\V0_5\Cms\TopicController;
use App\Http\Controllers\HealthzController;
use App\Http\Middleware\AdminAuth;
use App\Http\Middleware\EncryptCookies;
use App\Http\Middleware\EnsureCmsAdminAuthorized;
use App\Http\Middleware\ForcePublicAttemptRealm;
use App\Http\Middleware\HealthzAccessControl;
use App\Http\Middleware\LimitWebhookPayloadSize;
use App\Http\Middleware\NormalizeApiErrorContract;
use App\Http\Middleware\PartnerApiKeyAuth;
use App\Http\Middleware\ResolveOrgContext;
use App\Http\Middleware\SetOpsRequestContext;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
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
    $payProviders = ['stripe', 'billing', 'lemonsqueezy', 'wechatpay', 'alipay'];
    if (app()->environment(['local', 'testing']) && config('payments.allow_stub') === true) {
        $payProviders[] = 'stub';
    }
    if (! app()->environment(['local', 'testing'])) {
        $payProviders = array_values(array_filter(
            $payProviders,
            static fn (string $provider): bool => $provider !== 'stub'
        ));
    }
    $payProviders = array_values(array_unique($payProviders));

    // ✅ 关键修复：payment webhook 必须是“公共入口”，不能依赖 ResolveOrgContext / token
    Route::post(
        '/webhooks/payment/{provider}',
        [PaymentWebhookController::class, 'handle']
    )->whereIn('provider', $payProviders)
        ->middleware([LimitWebhookPayloadSize::class, 'throttle:api_webhook'])
        ->name('api.v0_3.webhooks.payment');

    Route::middleware('throttle:api_auth')->group(function () {
        Route::post('/auth/guest', AuthGuestV03Controller::class)
            ->middleware(\App\Http\Middleware\ResolveAnonId::class);

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

    Route::get('/claim/report', [ClaimV03Controller::class, 'report'])
        ->name('api.v0_3.claim.report');

    Route::middleware(\App\Http\Middleware\FmTokenAuth::class)->group(function () {
        Route::get('/me/attempts', [MeV03Controller::class, 'attempts']);
        Route::get('/me/relationships/mbti', [MbtiCompareInviteController::class, 'indexPrivate']);
        Route::get('/me/relationships/mbti/{inviteId}', [MbtiCompareInviteController::class, 'showPrivate'])
            ->middleware('uuid:inviteId');
        Route::post('/me/relationships/mbti/{inviteId}/consent', [MbtiCompareInviteController::class, 'mutatePrivateConsent'])
            ->middleware('uuid:inviteId');
        Route::post('/me/relationships/mbti/{inviteId}/journey', [MbtiCompareInviteController::class, 'mutatePrivateJourney'])
            ->middleware('uuid:inviteId');
    });

    Route::middleware([
        \App\Http\Middleware\FmTokenAuth::class,
        ResolveOrgContext::class,
        \App\Http\Middleware\RequireTenantContext::class,
    ])->group(function () {
        Route::post('/compliance/dsar/requests', [ComplianceDsarController::class, 'store'])
            ->middleware('throttle:api_auth');
        Route::get('/compliance/dsar/requests/{id}', [ComplianceDsarController::class, 'show'])
            ->middleware(['uuid:id', 'throttle:api_auth']);
        Route::post('/compliance/dsar/requests/{id}/execute', [ComplianceDsarController::class, 'execute'])
            ->middleware(['uuid:id', 'throttle:api_auth']);
    });

    Route::middleware([
        \App\Http\Middleware\ResolveAnonId::class,
        ResolveOrgContext::class,
    ])->group(function () use ($payProviders) {
        // 0) Boot (flags + experiments)
        Route::get('/boot', [BootV0_3Controller::class, 'show']);
        Route::get('/flags', [BootV0_3Controller::class, 'flags']);
        Route::get('/experiments', [BootV0_3Controller::class, 'experiments']);

        // 1) Scale registry
        Route::get('/scales', [ScalesController::class, 'index']);
        Route::get('/scales/lookup', [ScalesLookupController::class, 'lookup']);
        Route::get('/scales/sitemap-source', [ScalesSitemapSourceController::class, 'index']);
        Route::get('/public-gateways/home', [PublicGatewaySurfaceController::class, 'home']);
        Route::get('/public-gateways/tests', [PublicGatewaySurfaceController::class, 'tests']);
        Route::get('/public-gateways/help', [PublicGatewaySurfaceController::class, 'help']);
        Route::get('/public-gateways/help/{slug}', [PublicGatewaySurfaceController::class, 'helpDetail']);
        Route::get('/scales/{scale_code}/questions', [ScalesController::class, 'questions']);
        Route::get('/scales/{scale_code}', [ScalesController::class, 'show']);

        // 2) Attempts lifecycle
        Route::middleware(ForcePublicAttemptRealm::class)->group(function () {
            Route::middleware('throttle:api_attempt_submit')->group(function () {
                Route::post('/attempts/start', [AttemptWriteController::class, 'start'])
                    ->defaults('public_realm', true)
                    ->name('api.v0_3.attempts.start');
                Route::post('/attempts/submit', [AttemptWriteController::class, 'submit'])
                    ->middleware(\App\Http\Middleware\FmTokenAuth::class)
                    ->defaults('public_realm', true)
                    ->name('api.v0_3.attempts.submit');
            });
            Route::put('/attempts/{attempt_id}/progress', [AttemptProgressController::class, 'upsert'])
                ->middleware('uuid:attempt_id');
            Route::get('/attempts/{attempt_id}/progress', [AttemptProgressController::class, 'show'])
                ->middleware('uuid:attempt_id');
            Route::get('/attempts/{attempt_id}/submission', [AttemptReadController::class, 'submission'])
                ->middleware('uuid:attempt_id')
                ->defaults('public_realm', true)
                ->name('api.v0_3.attempts.submission');
            Route::get('/attempts/{id}', [AttemptReadController::class, 'show'])
                ->middleware('uuid:id')
                ->defaults('public_realm', true)
                ->name('api.v0_3.attempts.show');
            Route::get('/attempts/{id}/result', [AttemptReadController::class, 'result'])
                ->middleware('uuid:id')
                ->defaults('public_realm', true)
                ->name('api.v0_3.attempts.result');
            Route::get('/attempts/{id}/report', [AttemptReadController::class, 'report'])
                ->middleware('uuid:id')
                ->defaults('public_realm', true)
                ->name('api.v0_3.attempts.report');
            Route::get('/attempts/{id}/report-access', [AttemptReadController::class, 'reportAccess'])
                ->middleware('uuid:id')
                ->defaults('public_realm', true)
                ->name('api.v0_3.attempts.report_access');
            Route::get('/attempts/{id}/report.pdf', [AttemptReadController::class, 'reportPdf'])
                ->middleware('uuid:id')
                ->defaults('public_realm', true)
                ->name('api.v0_3.attempts.report_pdf');
        });
        // Share contract routes stay fixed; summary/click semantics are implemented in ShareController/services.
        Route::match(['GET', 'POST'], '/attempts/{id}/share', [ShareV03Controller::class, 'getShare'])
            ->middleware(\App\Http\Middleware\FmTokenAuth::class);

        // 3) Commerce v2 (public with org context)
        Route::get('/skus', 'App\\Http\\Controllers\\API\\V0_3\\CommerceController@listSkus')
            ->name('api.v0_3.skus');
        Route::post('/orders/checkout', 'App\\Http\\Controllers\\API\\V0_3\\CommerceController@checkout')
            ->middleware(\App\Http\Middleware\FmTokenOptional::class)
            ->name('api.v0_3.orders.checkout');
        Route::post('/orders/lookup', 'App\\Http\\Controllers\\API\\V0_3\\CommerceController@lookup')
            ->middleware([\App\Http\Middleware\FmTokenOptional::class, 'throttle:api_order_lookup'])
            ->name('api.v0_3.orders.lookup');
        Route::post('/orders/{order_no}/resend', 'App\\Http\\Controllers\\API\\V0_3\\CommerceController@resend')
            ->middleware(\App\Http\Middleware\FmTokenOptional::class);
        Route::post('/claim/report', [ClaimV03Controller::class, 'requestReport'])
            ->middleware(\App\Http\Middleware\FmTokenOptional::class)
            ->name('api.v0_3.claim.report.request');
        Route::post('/email/capture', [EmailCaptureController::class, 'store'])
            ->middleware(\App\Http\Middleware\FmTokenOptional::class)
            ->name('api.v0_3.email.capture');
        Route::get('/email/preferences', [EmailPreferenceController::class, 'show'])
            ->name('api.v0_3.email.preferences.show');
        Route::post('/email/preferences', [EmailPreferenceController::class, 'update'])
            ->name('api.v0_3.email.preferences.update');
        Route::post('/email/unsubscribe', [EmailPreferenceController::class, 'unsubscribe'])
            ->name('api.v0_3.email.unsubscribe');
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
        Route::get('/orders/{order_no}/pay/alipay', 'App\\Http\\Controllers\\API\\V0_3\\CommerceController@launchAlipay')
            ->middleware(\App\Http\Middleware\FmTokenOptional::class);
        Route::get('/orders/{order_no}', 'App\\Http\\Controllers\\API\\V0_3\\CommerceController@getOrder')
            ->middleware(\App\Http\Middleware\FmTokenOptional::class);
        Route::get('/shares/{id}', [ShareV03Controller::class, 'getShareView']);
        Route::post('/shares/{shareId}/click', [ShareV03Controller::class, 'click'])
            ->middleware([
                \App\Http\Middleware\FmTokenOptional::class,
                \App\Http\Middleware\LimitApiPublicPayloadSize::class,
                'throttle:api_track',
            ])
            ->where('shareId', '[A-Za-z0-9_-]{6,128}');
        Route::post('/shares/{shareId}/compare-invites', [MbtiCompareInviteController::class, 'store'])
            ->middleware([
                \App\Http\Middleware\FmTokenOptional::class,
                \App\Http\Middleware\LimitApiPublicPayloadSize::class,
                'throttle:api_track',
            ])
            ->where('shareId', '[A-Za-z0-9_-]{6,128}');
        Route::get('/compare/mbti/{inviteId}', [MbtiCompareInviteController::class, 'show'])
            ->middleware([
                \App\Http\Middleware\FmTokenOptional::class,
                'throttle:api_track',
                'uuid:inviteId',
            ]);
    });

    Route::middleware([\App\Http\Middleware\FmTokenAuth::class, ResolveOrgContext::class])
        ->group(function () {
            Route::post('/orgs', [OrgsController::class, 'store']);
            Route::get('/orgs/me', [OrgsController::class, 'me']);
            Route::post('/orgs/invites/accept', [OrgInvitesController::class, 'accept']);

            Route::middleware(\App\Http\Middleware\RequireTenantContext::class)->group(function () {
                Route::post('/orgs/{org_id}/invites', [OrgInvitesController::class, 'store']);
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
});

Route::prefix('v0.4')->middleware(NormalizeApiErrorContract::class)->group(function () {
    Route::get('/boot', [BootController::class, 'show'])
        ->middleware('throttle:api_public');

    Route::prefix('partners')
        ->middleware([
            'throttle:api_public',
            PartnerApiKeyAuth::class,
        ])
        ->group(function () {
            Route::post('/sessions', [PartnerController::class, 'createSession']);
            Route::get('/sessions/{attempt_id}/status', [PartnerController::class, 'status'])
                ->middleware('uuid:attempt_id');
            Route::post('/webhooks/sign', [PartnerController::class, 'signWebhook']);
        });

    Route::middleware([
        \App\Http\Middleware\FmTokenAuth::class,
        \App\Http\Middleware\RequireOrgRole::class.':owner,admin',
    ])->group(function () {
        Route::post('/orgs/{org_id}/experiments/rollouts/{rollout_id}/approve', [
            ExperimentGovernanceController::class,
            'approve',
        ])->middleware('uuid:rollout_id');
        Route::post('/orgs/{org_id}/experiments/rollouts/{rollout_id}/pause', [
            ExperimentGovernanceController::class,
            'pause',
        ])->middleware('uuid:rollout_id');
        Route::post('/orgs/{org_id}/experiments/rollouts/{rollout_id}/rollback', [
            ExperimentGovernanceController::class,
            'rollback',
        ])->middleware('uuid:rollout_id');
        Route::put('/orgs/{org_id}/experiments/rollouts/{rollout_id}/guardrails', [
            ExperimentGovernanceController::class,
            'upsertGuardrail',
        ])->middleware('uuid:rollout_id');
        Route::post('/orgs/{org_id}/experiments/rollouts/{rollout_id}/guardrails/evaluate', [
            ExperimentGovernanceController::class,
            'evaluateGuardrails',
        ])->middleware('uuid:rollout_id');
        Route::get('/orgs/{org_id}/compliance/rotation/audits', [
            RotationAuditController::class,
            'index',
        ]);
        Route::get('/orgs/{org_id}/compliance/rotation/audits/{id}', [
            RotationAuditController::class,
            'show',
        ])->middleware('uuid:id');

        Route::post('/orgs/{org_id}/assessments', [AssessmentController::class, 'store']);
        Route::post('/orgs/{org_id}/assessments/{id}/invite', [AssessmentController::class, 'invite']);
        Route::get('/orgs/{org_id}/assessments/{id}/progress', [AssessmentController::class, 'progress']);
        Route::get('/orgs/{org_id}/assessments/{id}/summary', [AssessmentController::class, 'summary']);
    });
});

Route::prefix('v0.5')->group(function () {
    Route::get('/articles', [ArticleController::class, 'index']);
    Route::get('/articles/{slug}', [ArticleController::class, 'show']);
    Route::get('/articles/{slug}/seo', [ArticleController::class, 'seo']);
    Route::get('/career-guides', [CareerGuideController::class, 'index']);
    Route::get('/career-guides/{slug}/seo', [CareerGuideController::class, 'seo']);
    Route::get('/career-guides/{slug}', [CareerGuideController::class, 'show']);
    Route::get('/career-jobs', [CareerJobController::class, 'index']);
    Route::get('/career-jobs/{slug}/seo', [CareerJobController::class, 'seo']);
    Route::get('/career-jobs/{slug}', [CareerJobController::class, 'show']);
    Route::get('/career-recommendations/mbti', [CareerRecommendationController::class, 'index']);
    Route::get('/career-recommendations/mbti/{type}', [CareerRecommendationController::class, 'show']);
    Route::get('/personality', [PersonalityController::class, 'index']);
    Route::get('/personality/{type}/seo', [PersonalityController::class, 'seo']);
    Route::get('/personality/{type}', [PersonalityController::class, 'show']);
    Route::get('/topics', [TopicController::class, 'index']);
    Route::get('/topics/{slug}/seo', [TopicController::class, 'seo']);
    Route::get('/topics/{slug}', [TopicController::class, 'show']);

    $cmsAdminMiddleware = [
        EncryptCookies::class,
        AddQueuedCookiesToResponse::class,
        StartSession::class,
        SetOpsRequestContext::class,
        AdminAuth::class,
        ResolveOrgContext::class,
    ];

    Route::middleware([
        ...$cmsAdminMiddleware,
        EnsureCmsAdminAuthorized::class.':write',
    ])->group(function () {
        Route::post('/cms/articles', [ArticleController::class, 'store']);
        Route::put('/cms/articles/{id}', [ArticleController::class, 'update']);
        Route::post('/cms/articles/{id}/seo', [ArticleController::class, 'generateSeo']);
    });

    Route::middleware([
        ...$cmsAdminMiddleware,
        EnsureCmsAdminAuthorized::class.':release',
    ])->group(function () {
        Route::post('/cms/articles/{id}/publish', [ArticleController::class, 'publish']);
        Route::post('/cms/articles/{id}/unpublish', [ArticleController::class, 'unpublish']);
    });
});
