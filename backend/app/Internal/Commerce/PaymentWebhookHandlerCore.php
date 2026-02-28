<?php

namespace App\Internal\Commerce;

use App\Jobs\GenerateReportPdfJob;
use App\Jobs\GenerateReportSnapshotJob;
use App\Models\Attempt;
use App\Services\Analytics\EventRecorder;
use App\Services\Commerce\BenefitWalletService;
use App\Services\Commerce\EntitlementManager;
use App\Services\Commerce\OrderManager;
use App\Services\Commerce\PaymentGateway\AlipayGateway;
use App\Services\Commerce\PaymentGateway\BillingGateway;
use App\Services\Commerce\PaymentGateway\LemonSqueezyGateway;
use App\Services\Commerce\PaymentGateway\PaymentGatewayInterface;
use App\Services\Commerce\PaymentGateway\StripeGateway;
use App\Services\Commerce\PaymentGateway\WechatPayGateway;
use App\Services\Commerce\SkuCatalog;
use App\Services\Commerce\Webhook\WebhookEntitlementService;
use App\Services\Commerce\Webhook\WebhookPostCommitService;
use App\Services\Commerce\Webhook\WebhookPrecheckService;
use App\Services\Commerce\Webhook\WebhookTransitionService;
use App\Services\Email\EmailOutboxService;
use App\Services\Observability\BigFiveTelemetry;
use App\Services\Observability\ClinicalComboTelemetry;
use App\Services\Observability\Sds20Telemetry;
use App\Services\Report\ReportAccess;
use App\Services\Report\ReportSnapshotStore;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class PaymentWebhookHandlerCore
{
    private const DEFAULT_WEBHOOK_LOCK_TTL_SECONDS = 10;

    private const DEFAULT_WEBHOOK_LOCK_BLOCK_SECONDS = 5;

    private const DEFAULT_WEBHOOK_LOCK_CONTENTION_BUDGET_MS = 3000;

    private const TRANSIENT_DB_RETRY_MAX_ATTEMPTS = 3;

    private const TRANSIENT_DB_RETRY_BASE_USLEEP = 100000;

    /** @var array<string, PaymentGatewayInterface> */
    private array $gateways = [];

    private WebhookPrecheckService $precheckStage;

    private WebhookTransitionService $transitionStage;

    private WebhookEntitlementService $entitlementStage;

    private WebhookPostCommitService $postCommitStage;

    public function __construct(
        private OrderManager $orders,
        private SkuCatalog $skus,
        private BenefitWalletService $wallets,
        private EntitlementManager $entitlements,
        private ReportSnapshotStore $reportSnapshots,
        private EventRecorder $events,
        private ?EmailOutboxService $emailOutbox = null,
        private ?BigFiveTelemetry $bigFiveTelemetry = null,
    ) {
        $stripe = new StripeGateway;
        if ($this->isProviderEnabled($stripe->provider())) {
            $this->gateways[$stripe->provider()] = $stripe;
        }
        $billing = new BillingGateway;
        if ($this->isProviderEnabled($billing->provider())) {
            $this->gateways[$billing->provider()] = $billing;
        }

        $lemonsqueezy = new LemonSqueezyGateway;
        if ($this->isProviderEnabled($lemonsqueezy->provider())) {
            $this->gateways[$lemonsqueezy->provider()] = $lemonsqueezy;
        }

        $wechatpay = new WechatPayGateway;
        if ($this->isProviderEnabled($wechatpay->provider())) {
            $this->gateways[$wechatpay->provider()] = $wechatpay;
        }

        $alipay = new AlipayGateway;
        if ($this->isProviderEnabled($alipay->provider())) {
            $this->gateways[$alipay->provider()] = $alipay;
        }

        if ($this->isStubEnabled()) {
            $stubGatewayClass = \App\Services\Commerce\PaymentGateway\StubGateway::class;
            if (class_exists($stubGatewayClass)) {
                $stub = new $stubGatewayClass;
                if ($stub instanceof PaymentGatewayInterface) {
                    $this->gateways[$stub->provider()] = $stub;
                }
            }
        }

        $this->precheckStage = new WebhookPrecheckService($this);
        $this->transitionStage = new WebhookTransitionService($this);
        $this->entitlementStage = new WebhookEntitlementService($this);
        $this->postCommitStage = new WebhookPostCommitService($this);
    }

    public function handle(
        string $provider,
        array $payload,
        int $orgId = 0,
        ?string $userId = null,
        ?string $anonId = null,
        bool $signatureOk = true,
        array $payloadMeta = [],
        string $rawPayloadSha256 = '',
        int $rawPayloadBytes = -1
    ): array {
        $requestId = $this->resolveRequestIdFromRuntimeContext();

        $ctx = $this->precheckStage->handle(
            $provider,
            $payload,
            $orgId,
            $userId,
            $anonId,
            $signatureOk,
            $payloadMeta,
            $rawPayloadSha256,
            $rawPayloadBytes,
            $requestId
        );

        if (isset($ctx['result']) && is_array($ctx['result'])) {
            return $ctx['result'];
        }

        $ctx = $this->transitionStage->handle($ctx);

        try {
            $ctx = $this->entitlementStage->handle($ctx);
        } catch (LockTimeoutException $e) {
            $this->observeWebhookLockWait(
                (string) $ctx['provider'],
                (int) $ctx['normalized_org_id'],
                (string) $ctx['provider_event_id'],
                (string) $ctx['lock_key'],
                $this->resolveLockWaitMs((float) $ctx['lock_wait_started_at']),
                (int) $ctx['lock_block'],
                (int) $ctx['contention_budget_ms'],
                true
            );

            return $this->serverError('WEBHOOK_BUSY', 'payment webhook is busy, retry later.');
        }

        $ctx = $this->postCommitStage->handle($ctx);
        $normalizedResult = is_array($ctx['normalized_result'] ?? null)
            ? $ctx['normalized_result']
            : $this->normalizeResultStatus((array) ($ctx['result'] ?? []));

        try {
            $this->emitBigFiveWebhookTelemetry(
                $normalizedResult,
                is_array($ctx['post_commit_ctx'] ?? null) ? $ctx['post_commit_ctx'] : null,
                (int) $ctx['org_id'],
                (string) $ctx['provider'],
                (string) $ctx['provider_event_id'],
                (string) $ctx['order_no']
            );
        } catch (\Throwable $e) {
            Log::warning('PAYMENT_WEBHOOK_TELEMETRY_FAILED', [
                'provider' => (string) $ctx['provider'],
                'provider_event_id' => (string) $ctx['provider_event_id'],
                'order_no' => (string) $ctx['order_no'],
                'error_message' => $e->getMessage(),
            ]);
        }

        return $normalizedResult;
    }


    public function gatewayFor(string $provider): ?PaymentGatewayInterface
    {
        return $this->gateways[$provider] ?? null;
    }

    public function orderManager(): OrderManager
    {
        return $this->orders;
    }

    public function skuCatalog(): SkuCatalog
    {
        return $this->skus;
    }

    public function entitlementManager(): EntitlementManager
    {
        return $this->entitlements;
    }

    public function runWithTransientDbRetry(callable $callback): mixed
    {
        $attempt = 0;

        while (true) {
            try {
                return $callback();
            } catch (\Throwable $e) {
                $attempt++;

                if (! $this->isTransientDatabaseFailure($e) || $attempt >= self::TRANSIENT_DB_RETRY_MAX_ATTEMPTS) {
                    throw $e;
                }

                usleep(self::TRANSIENT_DB_RETRY_BASE_USLEEP * $attempt);
            }
        }
    }

    private function isTransientDatabaseFailure(\Throwable $e): bool
    {
        $message = strtolower(trim($e->getMessage()));
        if ($message === '') {
            return false;
        }

        foreach ([
            'database is locked',
            'database table is locked',
            'deadlock found',
            'lock wait timeout exceeded',
            'try restarting transaction',
            'sqlstate[40001]',
            'sqlstate[40p01]',
            'sqlstate[hy000]',
        ] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function resolveRequestIdFromRuntimeContext(): ?string
    {
        try {
            $request = request();
        } catch (\Throwable) {
            return null;
        }

        if (! $request instanceof \Illuminate\Http\Request) {
            return null;
        }

        foreach ([
            (string) ($request->attributes->get('request_id') ?? ''),
            (string) $request->header('X-Request-Id', ''),
            (string) $request->header('X-Request-ID', ''),
            (string) $request->input('request_id', ''),
        ] as $candidate) {
            $value = trim($candidate);
            if ($value !== '') {
                return substr($value, 0, 128);
            }
        }

        return null;
    }

    public function evaluateDryRun(
        string $provider,
        array $payload,
        bool $signatureOk = true
    ): array {
        $provider = strtolower(trim($provider));
        $gateway = $this->gateways[$provider] ?? null;

        if (! $gateway) {
            return $this->errorResult(400, 'PROVIDER_NOT_SUPPORTED', 'provider not supported.', null, [
                'dry_run' => true,
            ]);
        }

        $normalized = $gateway->normalizePayload($payload);
        $eventType = $this->normalizeEventType($normalized);
        $providerEventId = trim((string) ($normalized['provider_event_id'] ?? ''));
        $orderNo = trim((string) ($normalized['order_no'] ?? ''));

        if ($providerEventId === '' || $orderNo === '') {
            return $this->errorResult(400, 'PAYLOAD_INVALID', 'provider_event_id and order_no are required.', $normalized, [
                'dry_run' => true,
                'normalized' => $normalized,
            ]);
        }

        if ($signatureOk !== true) {
            return $this->errorResult(400, 'INVALID_SIGNATURE', 'invalid signature.', null, [
                'dry_run' => true,
                'provider_event_id' => $providerEventId,
                'order_no' => $orderNo,
                'event_type' => $eventType,
            ]);
        }

        $isRefund = $this->isRefundEvent($eventType, $normalized);
        if (! $isRefund && ! $this->isAllowedSuccessEventType($provider, $eventType)) {
            return $this->errorResult(404, 'EVENT_TYPE_NOT_ALLOWED', 'event type not allowed.', null, [
                'dry_run' => true,
                'provider_event_id' => $providerEventId,
                'order_no' => $orderNo,
                'event_type' => $eventType,
            ]);
        }

        return [
            'ok' => true,
            'dry_run' => true,
            'provider' => $provider,
            'provider_event_id' => $providerEventId,
            'order_no' => $orderNo,
            'event_type' => $eventType,
            'is_refund' => $isRefund,
        ];
    }

    public function runWebhookPostCommitSideEffects(array $ctx): array
    {
        $kind = strtolower(trim((string) ($ctx['kind'] ?? '')));
        $orgId = (int) ($ctx['org_id'] ?? 0);
        $provider = strtolower(trim((string) ($ctx['provider'] ?? '')));
        $providerEventId = trim((string) ($ctx['provider_event_id'] ?? ''));
        $orderNo = trim((string) ($ctx['order_no'] ?? ''));
        $eventUserId = $this->numericUserId(
            is_string($ctx['event_user_id'] ?? null) ? (string) $ctx['event_user_id'] : null
        );
        $eventMeta = is_array($ctx['event_meta'] ?? null) ? $ctx['event_meta'] : [];
        $eventContext = is_array($ctx['event_context'] ?? null) ? $ctx['event_context'] : [];
        $receivedEventMeta = is_array($ctx['received_event_meta'] ?? null) ? $ctx['received_event_meta'] : [];
        $receivedEventContext = is_array($ctx['received_event_context'] ?? null) ? $ctx['received_event_context'] : [];
        $outcome = [
            'ok' => true,
            'snapshot_job_ctx' => null,
            'error_code' => null,
            'error_message' => null,
        ];

        if ($receivedEventMeta !== [] || $receivedEventContext !== []) {
            try {
                $this->events->record('payment_webhook_received', $eventUserId, $receivedEventMeta, $receivedEventContext);
            } catch (\Throwable $e) {
                Log::error('PAYMENT_WEBHOOK_POST_COMMIT_EVENT_FAILED', [
                    'event' => 'payment_webhook_received',
                    'provider' => $provider,
                    'provider_event_id' => $providerEventId,
                    'order_no' => $orderNo,
                    'error_message' => $e->getMessage(),
                ]);
            }
        }

        if ($kind === 'credit_pack') {
            $benefitCode = strtoupper(trim((string) ($ctx['benefit_code'] ?? '')));
            $topupDelta = (int) ($ctx['topup_delta'] ?? 0);
            if ($benefitCode === '' || $topupDelta <= 0) {
                $outcome['ok'] = false;
                $outcome['error_code'] = 'TOPUP_CONTEXT_INVALID';
                $outcome['error_message'] = 'topup context invalid.';
            } else {
                try {
                    $topupKey = "TOPUP:{$provider}:{$providerEventId}";
                    $wallet = $this->wallets->topUp(
                        $orgId,
                        $benefitCode,
                        $topupDelta,
                        $topupKey,
                        [
                            'order_no' => $orderNo,
                            'provider_event_id' => $providerEventId,
                            'provider' => $provider,
                        ]
                    );

                    if (! ($wallet['ok'] ?? false)) {
                        Log::warning('PAYMENT_WEBHOOK_POST_COMMIT_TOPUP_FAILED', [
                            'provider' => $provider,
                            'provider_event_id' => $providerEventId,
                            'order_no' => $orderNo,
                            'org_id' => $orgId,
                            'benefit_code' => $benefitCode,
                            'wallet_error_code' => $wallet['error'] ?? 'WALLET_TOPUP_FAILED',
                            'message' => $wallet['message'] ?? 'wallet topup failed.',
                        ]);
                        $outcome['ok'] = false;
                        $outcome['error_code'] = (string) ($wallet['error'] ?? 'WALLET_TOPUP_FAILED');
                        $outcome['error_message'] = (string) ($wallet['message'] ?? 'wallet topup failed.');
                    } else {
                        try {
                            $this->events->record('wallet_topped_up', $eventUserId, $eventMeta, $eventContext);
                        } catch (\Throwable $e) {
                            Log::error('PAYMENT_WEBHOOK_POST_COMMIT_EVENT_FAILED', [
                                'event' => 'wallet_topped_up',
                                'provider' => $provider,
                                'provider_event_id' => $providerEventId,
                                'order_no' => $orderNo,
                                'error_message' => $e->getMessage(),
                            ]);
                        }
                    }
                } catch (\Throwable $e) {
                    Log::error('PAYMENT_WEBHOOK_POST_COMMIT_TOPUP_EXCEPTION', [
                        'provider' => $provider,
                        'provider_event_id' => $providerEventId,
                        'order_no' => $orderNo,
                        'org_id' => $orgId,
                        'benefit_code' => $benefitCode,
                        'error_message' => $e->getMessage(),
                    ]);
                    $outcome['ok'] = false;
                    $outcome['error_code'] = 'WALLET_TOPUP_EXCEPTION';
                    $outcome['error_message'] = $e->getMessage();
                }
            }
        } elseif ($kind === 'report_unlock') {
            try {
                $this->events->record('entitlement_granted', $eventUserId, $eventMeta, $eventContext);
            } catch (\Throwable $e) {
                Log::error('PAYMENT_WEBHOOK_POST_COMMIT_EVENT_FAILED', [
                    'event' => 'entitlement_granted',
                    'provider' => $provider,
                    'provider_event_id' => $providerEventId,
                    'order_no' => $orderNo,
                    'error_message' => $e->getMessage(),
                ]);
            }

            $attemptId = trim((string) ($ctx['attempt_id'] ?? ''));
            if ($attemptId === '') {
                $outcome['ok'] = false;
                $outcome['error_code'] = 'ATTEMPT_REQUIRED';
                $outcome['error_message'] = 'target_attempt_id is required for report_unlock.';
            } else {
                $snapshotMeta = is_array($ctx['snapshot_meta'] ?? null) ? $ctx['snapshot_meta'] : [];
                try {
                    $this->reportSnapshots->seedPendingSnapshot($orgId, $attemptId, $orderNo !== '' ? $orderNo : null, [
                        'scale_code' => (string) ($snapshotMeta['scale_code'] ?? ''),
                        'scale_code_v2' => (string) ($snapshotMeta['scale_code_v2'] ?? ''),
                        'scale_uid' => (string) ($snapshotMeta['scale_uid'] ?? ''),
                        'pack_id' => (string) ($snapshotMeta['pack_id'] ?? ''),
                        'dir_version' => (string) ($snapshotMeta['dir_version'] ?? ''),
                        'scoring_spec_version' => (string) ($snapshotMeta['scoring_spec_version'] ?? ''),
                    ]);

                    $outcome['snapshot_job_ctx'] = [
                        'org_id' => $orgId,
                        'attempt_id' => $attemptId,
                        'trigger_source' => 'payment',
                        'order_no' => $orderNo !== '' ? $orderNo : null,
                    ];
                    $outcome['pdf_job_ctx'] = [
                        'org_id' => $orgId,
                        'attempt_id' => $attemptId,
                        'trigger_source' => 'payment_unlock',
                        'order_no' => $orderNo !== '' ? $orderNo : null,
                    ];

                    try {
                        $this->emitScaleUnlockTelemetry(
                            $orgId,
                            $attemptId,
                            $provider,
                            $providerEventId,
                            $orderNo !== '' ? $orderNo : null
                        );
                    } catch (\Throwable $e) {
                        Log::warning('PAYMENT_WEBHOOK_UNLOCK_TELEMETRY_FAILED', [
                            'provider' => $provider,
                            'provider_event_id' => $providerEventId,
                            'order_no' => $orderNo,
                            'org_id' => $orgId,
                            'attempt_id' => $attemptId,
                            'error_message' => $e->getMessage(),
                        ]);
                    }

                    if (! $this->isCrisisAttempt($orgId, $attemptId)) {
                        $this->queueBigFiveUnlockEmail(
                            $orgId,
                            $attemptId,
                            $orderNo,
                            is_string($ctx['event_user_id'] ?? null) ? (string) $ctx['event_user_id'] : null,
                            is_array($ctx['event_meta'] ?? null) ? (array) $ctx['event_meta'] : []
                        );
                    }
                } catch (\Throwable $e) {
                    Log::error('PAYMENT_WEBHOOK_POST_COMMIT_SEED_SNAPSHOT_FAILED', [
                        'provider' => $provider,
                        'provider_event_id' => $providerEventId,
                        'order_no' => $orderNo,
                        'org_id' => $orgId,
                        'attempt_id' => $attemptId,
                        'error_message' => $e->getMessage(),
                    ]);
                    $outcome['ok'] = false;
                    $outcome['error_code'] = 'SEED_SNAPSHOT_FAILED';
                    $outcome['error_message'] = $e->getMessage();
                }
            }
        } else {
            $outcome['ok'] = false;
            $outcome['error_code'] = 'POST_COMMIT_KIND_INVALID';
            $outcome['error_message'] = 'unsupported post commit kind.';
        }

        if ($eventMeta !== [] || $eventContext !== []) {
            try {
                $this->events->record('purchase_success', $eventUserId, $eventMeta, $eventContext);
            } catch (\Throwable $e) {
                Log::error('PAYMENT_WEBHOOK_POST_COMMIT_EVENT_FAILED', [
                    'event' => 'purchase_success',
                    'provider' => $provider,
                    'provider_event_id' => $providerEventId,
                    'order_no' => $orderNo,
                    'error_message' => $e->getMessage(),
                ]);
            }
        }

        return $outcome;
    }

    /**
     * @param  array<string,mixed>  $result
     * @param  array<string,mixed>|null  $postCommitCtx
     */
    private function emitBigFiveWebhookTelemetry(
        array $result,
        ?array $postCommitCtx,
        int $orgId,
        string $provider,
        string $providerEventId,
        string $orderNo
    ): void {
        if (! $this->bigFiveTelemetry instanceof BigFiveTelemetry) {
            return;
        }

        $meta = is_array($postCommitCtx['event_meta'] ?? null) ? $postCommitCtx['event_meta'] : [];
        $snapshotMeta = is_array($postCommitCtx['snapshot_meta'] ?? null) ? $postCommitCtx['snapshot_meta'] : [];

        $scaleCode = strtoupper(trim((string) ($snapshotMeta['scale_code'] ?? ($meta['scale_code'] ?? ''))));
        $attemptId = trim((string) ($postCommitCtx['attempt_id'] ?? ($meta['attempt_id'] ?? '')));
        $orgId = (int) ($postCommitCtx['org_id'] ?? $orgId);
        $skuCode = strtoupper(trim((string) ($meta['sku'] ?? '')));
        $anonId = null;
        $locale = '';
        $region = '';
        $orderRow = null;

        if ($orderNo !== '') {
            $orderQuery = DB::table('orders')
                ->where('order_no', $orderNo);
            if ($orgId > 0) {
                $orderQuery->where('org_id', $orgId);
            }
            $orderRow = $orderQuery->first();
            if (! $orderRow && $orgId > 0) {
                $orderRow = DB::table('orders')
                    ->where('order_no', $orderNo)
                    ->first();
            }
            if ($orderRow) {
                if ($attemptId === '') {
                    $attemptId = trim((string) ($orderRow->target_attempt_id ?? ''));
                    if ($attemptId === '') {
                        $attemptId = trim((string) ($orderRow->attempt_id ?? ''));
                    }
                }
                if ($skuCode === '') {
                    $skuCode = strtoupper(trim((string) (
                        $orderRow->effective_sku
                        ?? $orderRow->sku
                        ?? $orderRow->item_sku
                        ?? ''
                    )));
                }
            }
        }

        if ($skuCode === '' && $providerEventId !== '') {
            $eventRow = DB::table('payment_events')
                ->where('provider', $provider)
                ->where('provider_event_id', $providerEventId)
                ->first();
            if ($eventRow) {
                $skuCode = strtoupper(trim((string) (
                    $eventRow->effective_sku
                    ?? $eventRow->requested_sku
                    ?? ''
                )));
            }
        }

        if ($attemptId !== '') {
            $attemptRow = DB::table('attempts')->where('id', $attemptId)->first();
            if ($attemptRow) {
                $anonId = $attemptRow->anon_id ? (string) $attemptRow->anon_id : null;
                $locale = (string) ($attemptRow->locale ?? '');
                $region = (string) ($attemptRow->region ?? '');
                if ($orgId <= 0) {
                    $orgId = (int) ($attemptRow->org_id ?? 0);
                }
                if ($scaleCode === '') {
                    $scaleCode = strtoupper(trim((string) ($attemptRow->scale_code ?? '')));
                }
            }
        }

        if ($scaleCode !== 'BIG5_OCEAN') {
            return;
        }

        $status = 'failed';
        if (($result['ok'] ?? false) === true) {
            $status = ($result['duplicate'] ?? false) === true ? 'duplicate' : 'processed';
        } elseif (is_string($result['error_code'] ?? null) && trim((string) $result['error_code']) !== '') {
            $status = strtolower(trim((string) $result['error_code']));
        }

        $this->bigFiveTelemetry->recordPaymentWebhookProcessed(
            $orgId,
            $this->numericUserId(is_string($postCommitCtx['event_user_id'] ?? null) ? $postCommitCtx['event_user_id'] : null),
            $anonId,
            $attemptId !== '' ? $attemptId : null,
            $locale,
            $region,
            $status,
            $skuCode,
            $skuCode,
            $provider,
            $providerEventId,
            $orderNo
        );
    }

    private function emitScaleUnlockTelemetry(
        int $orgId,
        string $attemptId,
        string $provider,
        string $providerEventId,
        ?string $orderNo = null
    ): void {
        $attemptId = trim($attemptId);
        if ($attemptId === '') {
            return;
        }

        $attemptQuery = Attempt::withoutGlobalScopes()->where('id', $attemptId);
        if ($orgId > 0) {
            $attemptQuery->where('org_id', $orgId);
        }

        $attempt = $attemptQuery->first();
        if (! $attempt instanceof Attempt) {
            return;
        }

        $scaleCode = strtoupper(trim((string) ($attempt->scale_code ?? '')));
        $meta = [
            'provider' => strtolower(trim($provider)),
            'provider_event_id' => trim($providerEventId),
            'order_no' => $orderNo !== null ? trim($orderNo) : '',
            'unlock_source' => 'payment_webhook',
            'variant' => 'full',
            'locked' => false,
        ];

        if ($scaleCode === 'CLINICAL_COMBO_68') {
            app(ClinicalComboTelemetry::class)->unlocked($attempt, $meta);

            return;
        }

        if ($scaleCode === 'SDS_20') {
            app(Sds20Telemetry::class)->unlocked($attempt, $meta);
        }
    }

    private function numericUserId(?string $userId): ?int
    {
        $userId = $userId !== null ? trim($userId) : '';
        if ($userId === '' || ! preg_match('/^\d+$/', $userId)) {
            return null;
        }

        return (int) $userId;
    }

    /**
     * @return array<string,mixed>
     */
    public function decodeMeta(mixed $meta): array
    {
        if (is_array($meta)) {
            return $meta;
        }

        if (is_string($meta) && $meta !== '') {
            $decoded = json_decode($meta, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    /**
     * @return list<string>
     */
    public function normalizeModulesIncluded(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : null;
        }
        if (! is_array($raw)) {
            return [];
        }

        return ReportAccess::normalizeModules($raw);
    }

    public function buildEventMeta(array $orderMeta, array $extra): array
    {
        $attempt = $orderMeta['attempt'] ?? [];

        return array_merge([
            'scale_code' => $attempt['scale_code'] ?? null,
            'attempt_id' => $attempt['attempt_id'] ?? null,
            'pack_id' => $attempt['pack_id'] ?? null,
            'dir_version' => $attempt['dir_version'] ?? null,
            'sku' => $orderMeta['sku'] ?? null,
            'benefit_code' => $orderMeta['benefit_code'] ?? null,
        ], $extra);
    }

    public function buildEventContext(array $orderMeta, ?string $anonId): array
    {
        $attempt = $orderMeta['attempt'] ?? [];

        return [
            'org_id' => $orderMeta['org_id'] ?? 0,
            'anon_id' => $anonId,
            'attempt_id' => $attempt['attempt_id'] ?? null,
            'pack_id' => $attempt['pack_id'] ?? null,
            'dir_version' => $attempt['dir_version'] ?? null,
        ];
    }

    public function resolveOrderMeta(int $orgId, string $orderNo, ?object $order = null): array
    {
        $sku = '';
        $benefitCode = '';
        $orderUserId = null;
        $orderOrgId = $orgId;
        $attempt = [];

        if (! $order) {
            $order = DB::table('orders')
                ->where('order_no', $orderNo)
                ->where('org_id', $orgId)
                ->first();
        }

        if ($order) {
            $orderOrgId = (int) ($order->org_id ?? $orgId);
            $orderUserId = $order->user_id ? (string) $order->user_id : null;
            $sku = strtoupper((string) ($order->sku ?? $order->item_sku ?? ''));
            if ($sku !== '') {
                $skuRow = $this->skus->getActiveSku($sku, null, $orderOrgId);
                if ($skuRow) {
                    $benefitCode = strtoupper((string) ($skuRow->benefit_code ?? ''));
                }
            }
            $attempt = $this->resolveAttemptMeta($orderOrgId, (string) ($order->target_attempt_id ?? ''));
        }

        return [
            'order' => $order,
            'org_id' => $orderOrgId,
            'user_id' => $orderUserId,
            'sku' => $sku,
            'benefit_code' => $benefitCode,
            'attempt' => $attempt,
        ];
    }

    public function normalizeOrderSkuMeta(?object $order): array
    {
        $requestedSku = '';
        $effectiveSku = '';

        if ($order) {
            $requestedSku = strtoupper((string) ($order->requested_sku ?? ''));
            if ($requestedSku === '') {
                $requestedSku = strtoupper((string) ($order->sku ?? $order->item_sku ?? ''));
            }

            $effectiveSku = strtoupper((string) ($order->effective_sku ?? ''));
            if ($effectiveSku === '') {
                $effectiveSku = strtoupper((string) ($order->sku ?? $order->item_sku ?? ''));
            }
        }

        $skuToResolve = $requestedSku !== '' ? $requestedSku : $effectiveSku;
        $resolved = [];
        if ($skuToResolve !== '') {
            $orderOrgId = $order ? (int) ($order->org_id ?? 0) : 0;
            $resolved = $this->skus->resolveSkuMeta(
                $skuToResolve,
                null,
                $orderOrgId
            );
        }

        $resolvedRequested = $resolved['requested_sku'] ?? null;
        $resolvedEffective = $resolved['effective_sku'] ?? null;

        return [
            'requested_sku' => $resolvedRequested ?? ($requestedSku !== '' ? $requestedSku : null),
            'effective_sku' => $resolvedEffective ?? ($effectiveSku !== '' ? $effectiveSku : null),
            'entitlement_id' => $resolved['entitlement_id'] ?? ($order?->entitlement_id ?? null),
        ];
    }

    public function buildPayloadSummary(array $normalized, string $eventType, string $rawSha256, int $rawBytes): array
    {
        $providerEventId = trim((string) ($normalized['provider_event_id'] ?? ''));
        $orderNo = trim((string) ($normalized['order_no'] ?? ''));
        $externalTradeNo = trim((string) ($normalized['external_trade_no'] ?? ''));

        $amount = $normalized['amount_cents'] ?? null;
        if (! is_numeric($amount)) {
            $amount = null;
        } else {
            $amount = (int) $amount;
        }

        $currency = strtoupper(trim((string) ($normalized['currency'] ?? '')));
        if ($currency === '') {
            $currency = null;
        }

        return [
            'provider_event_id' => $providerEventId !== '' ? $providerEventId : null,
            'order_no' => $orderNo !== '' ? $orderNo : null,
            'event_type' => $eventType !== '' ? $eventType : null,
            'amount_cents' => $amount,
            'currency' => $currency,
            'external_trade_no' => $externalTradeNo !== '' ? $externalTradeNo : null,
            'raw_sha256' => $rawSha256,
            'raw_bytes' => $rawBytes,
        ];
    }

    public function encodePayloadSummary(array $summary): string
    {
        $encoded = json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($encoded) || $encoded === '') {
            return '{}';
        }

        return $encoded;
    }

    public function buildPayloadExcerpt(string $payloadSummaryJson, int $maxBytes = 8192): string
    {
        if ($maxBytes <= 0) {
            return '';
        }

        if (strlen($payloadSummaryJson) <= $maxBytes) {
            return $payloadSummaryJson;
        }

        return substr($payloadSummaryJson, 0, $maxBytes);
    }

    public function resolvePayloadMeta(
        array $payload,
        array $payloadMeta,
        string $rawPayloadSha256,
        int $rawPayloadBytes
    ): array {
        $rawFallback = $this->resolvePayloadRawFallback($payload);

        $size = $rawPayloadBytes >= 0 ? $rawPayloadBytes : ($payloadMeta['size_bytes'] ?? null);
        if (! is_numeric($size)) {
            $size = strlen($rawFallback);
        }
        $size = max(0, (int) $size);

        $sha = strtolower(trim($rawPayloadSha256));
        if (! preg_match('/^[a-f0-9]{64}$/', $sha)) {
            $sha = strtolower(trim((string) ($payloadMeta['sha256'] ?? '')));
        }
        if (! preg_match('/^[a-f0-9]{64}$/', $sha)) {
            $sha = hash('sha256', $rawFallback);
        }

        $s3Key = trim((string) ($payloadMeta['s3_key'] ?? ''));
        if ($s3Key === '') {
            $s3Key = null;
        } else {
            $s3Key = substr($s3Key, 0, 255);
        }

        return [
            'size_bytes' => $size,
            'sha256' => $sha,
            's3_key' => $s3Key,
        ];
    }

    private function resolvePayloadRawFallback(array $payload): string
    {
        $raw = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($raw)) {
            return '';
        }

        return $raw;
    }

    public function isEventProcessed(object $eventRow): bool
    {
        $status = strtolower((string) ($eventRow->status ?? ''));
        if ($status === 'processed') {
            return true;
        }

        return false;
    }

    public function buildWebhookLockKey(string $provider, int $orgId, string $providerEventId): string
    {
        return 'webhook_pay:'.$provider.':org_'.$orgId.':'.$providerEventId;
    }

    public function resolveLockWaitMs(float $lockWaitStartedAt): int
    {
        return max(0, (int) round((microtime(true) - $lockWaitStartedAt) * 1000));
    }

    public function observeWebhookLockWait(
        string $provider,
        int $orgId,
        string $providerEventId,
        string $lockKey,
        int $lockWaitMs,
        int $lockBlock,
        int $contentionBudgetMs,
        bool $timedOut
    ): void {
        $context = [
            'provider' => $provider,
            'org_id' => max(0, $orgId),
            'provider_event_id' => $providerEventId,
            'lock_key' => $lockKey,
            'lock_wait_ms' => max(0, $lockWaitMs),
            'lock_block_seconds' => max(0, $lockBlock),
            'contention_budget_ms' => max(1, $contentionBudgetMs),
            'budget_exceeded' => $lockWaitMs >= $contentionBudgetMs,
        ];

        if ($timedOut) {
            Log::warning('PAYMENT_WEBHOOK_LOCK_CONTENTION', $context);

            return;
        }

        Log::info('PAYMENT_WEBHOOK_LOCK_ACQUIRED', $context);
    }

    public function updatePaymentEvent(string $provider, string $providerEventId, array $updates): void
    {
        if ($provider === '' || $providerEventId === '') {
            return;
        }

        $this->runWithTransientDbRetry(function () use ($provider, $providerEventId, $updates): bool {
            DB::table('payment_events')
                ->where('provider', $provider)
                ->where('provider_event_id', $providerEventId)
                ->update($updates);

            return true;
        });
    }

    public function markEventProcessed(string $provider, string $providerEventId): void
    {
        $now = now();
        $this->updatePaymentEvent($provider, $providerEventId, [
            'status' => 'processed',
            'processed_at' => $now,
            'handled_at' => $now,
            'handle_status' => 'processed',
            'last_error_code' => null,
            'last_error_message' => null,
            'reason' => null,
            'updated_at' => $now,
        ]);
    }

    public function markEventError(string $provider, string $providerEventId, string $status, string $code, string $message): void
    {
        $now = now();
        $normalizedCode = substr($this->normalizeErrorCode($code), 0, 64);
        $this->updatePaymentEvent($provider, $providerEventId, [
            'status' => $status,
            'handled_at' => $now,
            'handle_status' => $status,
            'last_error_code' => $code,
            'last_error_message' => $message,
            'reason' => $normalizedCode,
            'updated_at' => $now,
        ]);
    }

    public function semanticReject(string $code, string $message): array
    {
        $normalizedCode = $this->normalizeErrorCode($code);

        return $this->errorResult(200, $normalizedCode, $message, null, [
            'acknowledged' => true,
            'rejected' => true,
            'reject_reason' => $normalizedCode,
        ]);
    }

    /**
     * @param  array<string,mixed>  $attemptMeta
     * @return array{ok:bool,error?:string,message?:string}
     */
    public function validateAttemptOwnershipForOrder(object $order, array $attemptMeta): array
    {
        $attemptId = trim((string) ($attemptMeta['attempt_id'] ?? ''));
        if ($attemptId === '') {
            return [
                'ok' => false,
                'error' => 'ATTEMPT_NOT_FOUND',
                'message' => 'target attempt not found.',
            ];
        }

        $orderUserId = trim((string) ($order->user_id ?? ''));
        $orderAnonId = trim((string) ($order->anon_id ?? ''));
        $attemptUserId = trim((string) ($attemptMeta['user_id'] ?? ''));
        $attemptAnonId = trim((string) ($attemptMeta['anon_id'] ?? ''));

        if ($orderUserId !== '') {
            if ($attemptUserId !== '' && $attemptUserId === $orderUserId) {
                return ['ok' => true];
            }

            return [
                'ok' => false,
                'error' => 'ATTEMPT_OWNER_MISMATCH',
                'message' => 'attempt owner user_id does not match order user_id.',
            ];
        }

        if ($orderAnonId !== '') {
            if ($attemptAnonId !== '' && $attemptAnonId === $orderAnonId) {
                return ['ok' => true];
            }

            return [
                'ok' => false,
                'error' => 'ATTEMPT_OWNER_MISMATCH',
                'message' => 'attempt owner anon_id does not match order anon_id.',
            ];
        }

        return ['ok' => true];
    }

    /**
     * @param  array<string,mixed>  $attemptMeta
     * @return array{ok:bool,error?:string,message?:string}
     */
    public function validateAttemptScaleForSku(object $skuRow, array $attemptMeta): array
    {
        $skuScaleCode = strtoupper(trim((string) ($skuRow->scale_code ?? '')));
        $attemptScaleCode = strtoupper(trim((string) ($attemptMeta['scale_code'] ?? '')));

        if ($attemptScaleCode === '') {
            return [
                'ok' => false,
                'error' => 'ATTEMPT_NOT_FOUND',
                'message' => 'target attempt not found.',
            ];
        }

        if ($skuScaleCode === '' || $skuScaleCode === $attemptScaleCode) {
            return ['ok' => true];
        }

        return [
            'ok' => false,
            'error' => 'ATTEMPT_SCALE_MISMATCH',
            'message' => 'attempt scale_code does not match sku scale_code.',
        ];
    }

    public function resolveAttemptMeta(int $orgId, ?string $attemptId): array
    {
        $attemptId = $attemptId !== null ? trim($attemptId) : '';
        if ($attemptId === '') {
            return [
                'attempt_id' => null,
                'scale_code' => null,
                'scale_code_v2' => null,
                'scale_uid' => null,
                'pack_id' => null,
                'dir_version' => null,
                'scoring_spec_version' => null,
                'user_id' => null,
                'anon_id' => null,
            ];
        }

        $row = DB::table('attempts')
            ->where('id', $attemptId)
            ->where('org_id', $orgId)
            ->first();
        if (! $row) {
            return [
                'attempt_id' => null,
                'scale_code' => null,
                'scale_code_v2' => null,
                'scale_uid' => null,
                'pack_id' => null,
                'dir_version' => null,
                'scoring_spec_version' => null,
                'user_id' => null,
                'anon_id' => null,
            ];
        }

        return [
            'attempt_id' => (string) ($row->id ?? $attemptId),
            'scale_code' => (string) ($row->scale_code ?? ''),
            'scale_code_v2' => (string) ($row->scale_code_v2 ?? ''),
            'scale_uid' => (string) ($row->scale_uid ?? ''),
            'pack_id' => (string) ($row->pack_id ?? ''),
            'dir_version' => (string) ($row->dir_version ?? ''),
            'scoring_spec_version' => (string) ($row->scoring_spec_version ?? ''),
            'user_id' => isset($row->user_id) ? (string) ($row->user_id ?? '') : null,
            'anon_id' => isset($row->anon_id) ? (string) ($row->anon_id ?? '') : null,
        ];
    }

    /**
     * @param  array<string,mixed>  $attemptMeta
     */
    public function writePaymentEventScaleIdentity(string $provider, string $providerEventId, array $attemptMeta): void
    {
        if (! $this->shouldWriteScaleIdentityColumns()) {
            return;
        }

        if (! Schema::hasTable('payment_events')
            || ! Schema::hasColumn('payment_events', 'scale_code_v2')
            || ! Schema::hasColumn('payment_events', 'scale_uid')) {
            return;
        }

        $identity = $this->resolveScaleIdentityForPaymentEvent($attemptMeta);
        $scaleCodeV2 = trim((string) ($identity['scale_code_v2'] ?? ''));
        $scaleUid = trim((string) ($identity['scale_uid'] ?? ''));

        if ($scaleCodeV2 === '' && $scaleUid === '') {
            return;
        }

        DB::table('payment_events')
            ->where('provider', $provider)
            ->where('provider_event_id', $providerEventId)
            ->update([
                'scale_code_v2' => $scaleCodeV2 !== '' ? $scaleCodeV2 : null,
                'scale_uid' => $scaleUid !== '' ? $scaleUid : null,
                'updated_at' => now(),
            ]);
    }

    private function shouldWriteScaleIdentityColumns(): bool
    {
        $mode = strtolower(trim((string) config('scale_identity.write_mode', 'legacy')));

        return in_array($mode, ['dual', 'v2'], true);
    }

    /**
     * @param  array<string,mixed>  $attemptMeta
     * @return array{scale_code_v2:string|null,scale_uid:string|null}
     */
    private function resolveScaleIdentityForPaymentEvent(array $attemptMeta): array
    {
        $scaleCodeV2 = strtoupper(trim((string) ($attemptMeta['scale_code_v2'] ?? '')));
        $scaleUid = trim((string) ($attemptMeta['scale_uid'] ?? ''));

        if ($scaleCodeV2 !== '' && $scaleUid !== '') {
            return [
                'scale_code_v2' => $scaleCodeV2,
                'scale_uid' => $scaleUid,
            ];
        }

        $scaleCodeV1 = strtoupper(trim((string) ($attemptMeta['scale_code'] ?? '')));
        if ($scaleCodeV1 === '') {
            return [
                'scale_code_v2' => $scaleCodeV2 !== '' ? $scaleCodeV2 : null,
                'scale_uid' => $scaleUid !== '' ? $scaleUid : null,
            ];
        }

        $v1ToV2 = (array) config('scale_identity.code_map_v1_to_v2', []);
        $uidMap = (array) config('scale_identity.scale_uid_map', []);

        if ($scaleCodeV2 === '') {
            $mappedV2 = strtoupper(trim((string) ($v1ToV2[$scaleCodeV1] ?? '')));
            if ($mappedV2 !== '') {
                $scaleCodeV2 = $mappedV2;
            }
        }

        if ($scaleUid === '') {
            $mappedUid = trim((string) ($uidMap[$scaleCodeV1] ?? ''));
            if ($mappedUid !== '') {
                $scaleUid = $mappedUid;
            }
        }

        return [
            'scale_code_v2' => $scaleCodeV2 !== '' ? $scaleCodeV2 : null,
            'scale_uid' => $scaleUid !== '' ? $scaleUid : null,
        ];
    }

    /**
     * @param  array<string,mixed>  $eventMeta
     */
    private function queueBigFiveUnlockEmail(
        int $orgId,
        string $attemptId,
        string $orderNo,
        ?string $eventUserId,
        array $eventMeta
    ): void {
        $attemptId = trim($attemptId);
        if ($attemptId === '') {
            return;
        }

        if (! \App\Support\SchemaBaseline::hasTable('users')
            || ! \App\Support\SchemaBaseline::hasColumn('users', 'email')) {
            return;
        }

        $attempt = DB::table('attempts')
            ->where('id', $attemptId)
            ->where('org_id', $orgId)
            ->first();
        if (! $attempt) {
            return;
        }
        if (strtoupper((string) ($attempt->scale_code ?? '')) !== 'BIG5_OCEAN') {
            return;
        }

        $email = '';
        $resolvedUserId = '';

        $candidateUserIds = [];
        $fromEvent = trim((string) ($eventUserId ?? ''));
        if ($fromEvent !== '') {
            $candidateUserIds[] = $fromEvent;
        }
        $fromAttempt = trim((string) ($attempt->user_id ?? ''));
        if ($fromAttempt !== '') {
            $candidateUserIds[] = $fromAttempt;
        }

        if ($orderNo !== '' && \App\Support\SchemaBaseline::hasTable('orders')) {
            $orderRow = DB::table('orders')
                ->where('order_no', $orderNo)
                ->where('org_id', $orgId)
                ->first();
            if ($orderRow) {
                $fromOrder = trim((string) ($orderRow->user_id ?? ''));
                if ($fromOrder !== '') {
                    $candidateUserIds[] = $fromOrder;
                }
            }
        }

        foreach (array_values(array_unique($candidateUserIds)) as $candidateUserId) {
            $candidateEmail = $this->resolveUserEmailById($candidateUserId);
            if ($candidateEmail === '') {
                continue;
            }
            $resolvedUserId = $candidateUserId;
            $email = $candidateEmail;
            break;
        }

        if ($email === '') {
            return;
        }

        $outboxUserId = $this->resolveOutboxUserId($resolvedUserId, (string) ($attempt->anon_id ?? ''), $attemptId);
        if ($outboxUserId === '') {
            return;
        }

        try {
            $service = $this->emailOutbox instanceof EmailOutboxService
                ? $this->emailOutbox
                : app(EmailOutboxService::class);

            $productSummary = trim((string) ($eventMeta['sku'] ?? $eventMeta['benefit_code'] ?? ''));
            $service->queuePaymentSuccess(
                $outboxUserId,
                $email,
                $attemptId,
                $orderNo !== '' ? $orderNo : null,
                $productSummary !== '' ? $productSummary : null
            );
        } catch (\Throwable $e) {
            Log::warning('PAYMENT_WEBHOOK_POST_COMMIT_QUEUE_EMAIL_FAILED', [
                'org_id' => $orgId,
                'attempt_id' => $attemptId,
                'order_no' => $orderNo,
                'error_message' => $e->getMessage(),
            ]);
        }
    }

    private function isCrisisAttempt(int $orgId, string $attemptId): bool
    {
        $attemptId = trim($attemptId);
        if ($attemptId === '') {
            return false;
        }

        $result = DB::table('results')
            ->where('org_id', $orgId)
            ->where('attempt_id', $attemptId)
            ->first();
        if (! $result) {
            return false;
        }

        $payload = $result->result_json ?? null;
        if (! is_array($payload)) {
            if (! is_string($payload) || trim($payload) === '') {
                return false;
            }
            $decoded = json_decode($payload, true);
            $payload = is_array($decoded) ? $decoded : [];
        }

        $candidates = [
            $payload['normed_json'] ?? null,
            $payload['breakdown_json']['score_result'] ?? null,
            $payload['axis_scores_json']['score_result'] ?? null,
            $payload,
        ];
        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }
            $quality = is_array($candidate['quality'] ?? null) ? $candidate['quality'] : [];
            if ((bool) ($quality['crisis_alert'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    private function resolveUserEmailById(string $userId): string
    {
        $userId = trim($userId);
        if ($userId === '') {
            return '';
        }

        $email = trim((string) DB::table('users')->where('id', $userId)->value('email'));
        if ($email !== '') {
            return $email;
        }

        return '';
    }

    private function resolveOutboxUserId(string $userId, string $anonId, string $attemptId): string
    {
        $userId = trim($userId);
        if ($userId !== '') {
            return substr($userId, 0, 64);
        }

        $anonId = trim($anonId);
        if ($anonId !== '') {
            return 'anon_'.substr(hash('sha256', $anonId), 0, 24);
        }

        $attemptId = trim($attemptId);
        if ($attemptId !== '') {
            return 'attempt_'.substr(hash('sha256', $attemptId), 0, 24);
        }

        return '';
    }

    public function isStubEnabled(): bool
    {
        return app()->environment(['local', 'testing']) && config('payments.allow_stub') === true;
    }

    private function isProviderEnabled(string $provider): bool
    {
        $provider = strtolower(trim($provider));
        if ($provider === '') {
            return false;
        }

        if ($provider === 'stub') {
            return $this->isStubEnabled();
        }

        $configured = config("payments.providers.{$provider}.enabled");
        if ($configured !== null) {
            return (bool) $configured;
        }

        return in_array($provider, ['stripe', 'billing'], true);
    }

    public function normalizeResultStatus(array $result): array
    {
        $isOk = ($result['ok'] ?? false) === true;
        if (! $isOk) {
            $result = $this->canonicalizeErrorResult($result);
        }

        if (array_key_exists('status', $result)) {
            $candidate = (int) $result['status'];
            if ($candidate >= 100 && $candidate <= 599) {
                $result['status'] = $candidate;

                return $result;
            }
        }

        $result['status'] = $isOk ? 200 : 500;

        return $result;
    }

    public function badRequest(string $code, string $message): array
    {
        return $this->errorResult(400, $code, $message);
    }

    public function serverError(string $code, string $message): array
    {
        return $this->errorResult(500, $code, $message);
    }

    public function notFound(string $code, string $message): array
    {
        return $this->errorResult(404, $code, $message);
    }

    private function errorResult(
        int $status,
        string $errorCode,
        string $message,
        mixed $details = null,
        array $extra = []
    ): array {
        $base = [
            'ok' => false,
            'error_code' => $this->normalizeErrorCode($errorCode),
            'message' => trim($message) !== '' ? trim($message) : 'request failed',
            'details' => $this->normalizeDetailsValue($details),
            'status' => $status,
        ];

        return array_merge($base, $extra);
    }

    private function canonicalizeErrorResult(array $result): array
    {
        $errorCode = $this->firstNonEmptyString([
            $result['error_code'] ?? null,
            $result['error'] ?? null,
            $result['message'] ?? null,
        ]);
        $message = $this->firstNonEmptyString([
            $result['message'] ?? null,
            $result['error'] ?? null,
        ]);

        $result['ok'] = false;
        $result['error_code'] = $this->normalizeErrorCode($errorCode);
        $result['message'] = $message !== '' ? $message : 'request failed';
        $details = array_key_exists('details', $result) ? $result['details'] : ($result['errors'] ?? null);
        $result['details'] = $this->normalizeDetailsValue($details);

        unset($result['error'], $result['errors']);

        return $result;
    }

    private function normalizeErrorCode(string $raw): string
    {
        $code = trim($raw);
        if ($code === '') {
            return 'HTTP_ERROR';
        }

        $code = str_replace(['-', ' '], '_', $code);
        $code = (string) preg_replace('/[^A-Za-z0-9_]+/', '_', $code);
        $code = trim($code, '_');

        return $code !== '' ? strtoupper($code) : 'HTTP_ERROR';
    }

    private function normalizeDetailsValue(mixed $details): mixed
    {
        if (is_array($details) && $details === []) {
            return null;
        }

        if (is_object($details) && count((array) $details) === 0) {
            return null;
        }

        return $details;
    }

    /**
     * @param  array<int, mixed>  $candidates
     */
    private function firstNonEmptyString(array $candidates): string
    {
        foreach ($candidates as $candidate) {
            if (! is_string($candidate) && ! is_numeric($candidate)) {
                continue;
            }

            $value = trim((string) $candidate);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    public function normalizeEventType(array $normalized): string
    {
        $eventType = strtolower(trim((string) ($normalized['event_type'] ?? '')));

        return $eventType !== '' ? $eventType : 'payment_succeeded';
    }

    public function isRefundEvent(string $eventType, array $normalized): bool
    {
        if ($eventType !== '' && str_contains($eventType, 'refund')) {
            return true;
        }

        $refundAmount = (int) ($normalized['refund_amount_cents'] ?? 0);

        return $refundAmount > 0;
    }

    public function validatePaidEventGuard(string $provider, string $eventType, array $normalized, object $order): array
    {
        if (! $this->isAllowedSuccessEventType($provider, $eventType)) {
            return [
                'ok' => false,
                'code' => 'EVENT_TYPE_NOT_ALLOWED',
                'message' => 'event type not allowed.',
            ];
        }

        $normalizedAmount = (int) ($normalized['amount_cents'] ?? 0);
        $orderAmount = (int) ($order->amount_cents ?? 0);
        if ($normalizedAmount !== $orderAmount) {
            return [
                'ok' => false,
                'code' => 'AMOUNT_MISMATCH',
                'message' => 'amount mismatch.',
            ];
        }

        $normalizedCurrency = $this->normalizeCurrency($normalized['currency'] ?? null);
        $orderCurrency = $this->normalizeCurrency($order->currency ?? null);
        if ($normalizedCurrency === '' || $orderCurrency === '' || $normalizedCurrency !== $orderCurrency) {
            return [
                'ok' => false,
                'code' => 'CURRENCY_MISMATCH',
                'message' => 'currency mismatch.',
            ];
        }

        return ['ok' => true];
    }

    private function normalizeCurrency(mixed $currency): string
    {
        return strtoupper(trim((string) $currency));
    }

    /**
     * @return array<int, string>
     */
    private function allowedSuccessEventTypes(string $provider): array
    {
        $provider = strtolower(trim($provider));
        $configured = config("services.payment_webhook.success_event_types.{$provider}");
        $types = is_array($configured) ? $configured : [];
        if (count($types) === 0) {
            $types = match ($provider) {
                'stripe' => [
                    'payment_succeeded',
                    'payment_intent.succeeded',
                    'charge.succeeded',
                    'checkout.session.completed',
                    'invoice.payment_succeeded',
                ],
                'billing' => [
                    'payment_succeeded',
                    'payment.success',
                    'payment_completed',
                    'paid',
                ],
                'lemonsqueezy' => [
                    'order_created',
                    'subscription_payment_success',
                    'payment_succeeded',
                ],
                'wechatpay' => [
                    'payment_succeeded',
                    'success',
                    'trade_success',
                ],
                'alipay' => [
                    'payment_succeeded',
                    'trade_success',
                    'trade_finished',
                ],
                default => ['payment_succeeded'],
            };
        }

        $normalized = [];
        foreach ($types as $type) {
            $value = strtolower(trim((string) $type));
            if ($value !== '') {
                $normalized[] = $value;
            }
        }

        return array_values(array_unique($normalized));
    }

    private function isAllowedSuccessEventType(string $provider, string $eventType): bool
    {
        $eventType = strtolower(trim($eventType));
        if ($eventType === '') {
            return false;
        }

        return in_array($eventType, $this->allowedSuccessEventTypes($provider), true);
    }

    public function handleRefund(
        string $orderNo,
        object $order,
        array $normalized,
        string $providerEventId,
        int $orgId
    ): array {
        $now = now();
        $refundAmount = (int) ($normalized['refund_amount_cents'] ?? 0);
        $refundReason = trim((string) ($normalized['refund_reason'] ?? ''));

        $updates = [
            'updated_at' => $now,
            'refund_amount_cents' => $refundAmount > 0 ? $refundAmount : ($order->refund_amount_cents ?? null),
        ];
        if (empty($order->refunded_at)) {
            $updates['refunded_at'] = $now;
        }
        if ($refundReason !== '') {
            $updates['refund_reason'] = $refundReason;
        }

        if (count($updates) > 1) {
            DB::table('orders')
                ->where('order_no', $orderNo)
                ->update($updates);
        }

        $transition = $this->orders->transition($orderNo, 'refunded', $orgId);
        if (! ($transition['ok'] ?? false)) {
            return $transition;
        }

        $revoked = $this->entitlements->revokeByOrderNo($orgId, $orderNo);
        if (! ($revoked['ok'] ?? false)) {
            return $revoked;
        }

        return [
            'ok' => true,
            'order_no' => $orderNo,
            'provider_event_id' => $providerEventId,
            'refunded' => true,
            'revoked' => $revoked['revoked'] ?? 0,
        ];
    }
}
