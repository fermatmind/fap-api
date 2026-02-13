<?php

namespace App\Services\Legacy;

use App\Models\Attempt;
use App\Models\Event;
use App\Models\Result;
use App\Models\Share;
use App\Services\Analytics\EventPayloadLimiter;
use App\Services\Commerce\EntitlementManager;
use App\Services\Report\ReportComposer;
use App\Services\Scale\ScaleRegistry;
use App\Support\OrgContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class LegacyShareFlowService
{
    public function __construct(
        private readonly OrgContext $orgContext,
        private readonly LegacyShareService $legacyShareService,
        private readonly ReportComposer $reportComposer,
        private readonly EventPayloadLimiter $eventPayloadLimiter,
        private readonly ScaleRegistry $scaleRegistry,
        private readonly EntitlementManager $entitlementManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getShareLinkForAttempt(string $attemptId, array $input): array
    {
        if (!config('features.enable_v0_2_report', false)) {
            throw (new ModelNotFoundException())->setModel(Attempt::class, [$attemptId]);
        }

        $attempt = $this->legacyShareService->resolveAttemptForAuth($attemptId, $this->orgContext);
        $commercial = $this->resolveCommercial($attempt);

        $benefitCode = $commercial['report_benefit_code'];
        if ($benefitCode === '') {
            $benefitCode = $commercial['subscription_benefit_code'];
        }

        $userId = $this->orgContext->userId();
        $anonId = $this->orgContext->anonId();

        $hasFullAccess = $this->entitlementManager->hasFullAccess(
            (int) ($attempt->org_id ?? 0),
            $userId !== null ? (string) $userId : null,
            $anonId !== null ? (string) $anonId : null,
            (string) $attempt->id,
            $benefitCode
        );

        if (!$hasFullAccess) {
            throw new HttpException(402, 'PAYMENT_REQUIRED');
        }

        $data = $this->legacyShareService->getOrCreateShare($attemptId, $this->orgContext);
        $this->emitLegacyShareEvents($data, $input, $commercial);

        return $data;
    }

    public function clickAndComposeReport(string $shareId, array $input, array $requestMeta): array
    {
        try {
            $share = Share::query()->where('id', $shareId)->first();
        } catch (\Throwable) {
            throw new NotFoundHttpException('Not Found');
        }

        if (!$share) {
            throw new NotFoundHttpException('Not Found');
        }

        $attemptId = trim((string) ($share->attempt_id ?? ''));
        if ($attemptId === '') {
            throw (new ModelNotFoundException())->setModel(Share::class, [$shareId]);
        }

        try {
            $gen = Event::query()
                ->where('event_code', 'share_generate')
                ->where('meta_json->share_id', $shareId)
                ->orderByDesc('occurred_at')
                ->first();
        } catch (\Throwable) {
            throw new NotFoundHttpException('Not Found');
        }

        if (!$gen) {
            throw new NotFoundHttpException('Not Found');
        }

        $genMeta = $this->normalizeMeta($gen->meta_json ?? null);

        $experiment = trim((string) ($requestMeta['experiment'] ?? ($input['experiment'] ?? '')));
        if ($experiment === '') {
            $experiment = trim((string) ($genMeta['experiment'] ?? ''));
        }

        $version = trim((string) ($requestMeta['version'] ?? ($input['version'] ?? '')));
        if ($version === '') {
            $version = trim((string) ($genMeta['version'] ?? ''));
        }

        $channel = trim((string) ($requestMeta['channel'] ?? ($genMeta['channel'] ?? '')));
        $clientPlatform = trim((string) ($requestMeta['client_platform'] ?? ($genMeta['client_platform'] ?? '')));
        $entryPage = trim((string) ($requestMeta['entry_page'] ?? ''));

        $typeCode = trim((string) ($genMeta['type_code'] ?? ''));
        $packVersion = trim((string) ($genMeta['content_package_version'] ?? ''));
        $engineVersion = trim((string) ($genMeta['engine_version'] ?? ($genMeta['engine'] ?? '')));
        $profileVersion = trim((string) ($genMeta['profile_version'] ?? ''));

        $meta = is_array($input['meta_json'] ?? null) ? $input['meta_json'] : [];

        if (!isset($meta['share_id'])) {
            $meta['share_id'] = $shareId;
        }
        $meta['attempt_id'] = $attemptId;

        if ($typeCode !== '' && !isset($meta['type_code'])) {
            $meta['type_code'] = $typeCode;
        }
        if ($engineVersion !== '' && !isset($meta['engine_version'])) {
            $meta['engine_version'] = $engineVersion;
        }
        if ($engineVersion !== '' && !isset($meta['engine'])) {
            $meta['engine'] = $engineVersion;
        }
        if ($packVersion !== '' && !isset($meta['content_package_version'])) {
            $meta['content_package_version'] = $packVersion;
        }
        if ($profileVersion !== '' && !isset($meta['profile_version'])) {
            $meta['profile_version'] = $profileVersion;
        }

        if ($experiment !== '' && !isset($meta['experiment'])) {
            $meta['experiment'] = $experiment;
        }
        if ($version !== '' && !isset($meta['version'])) {
            $meta['version'] = $version;
        }

        if ($channel !== '' && !isset($meta['channel'])) {
            $meta['channel'] = $channel;
        }
        if ($clientPlatform !== '' && !isset($meta['client_platform'])) {
            $meta['client_platform'] = $clientPlatform;
        }
        if ($entryPage !== '' && !isset($meta['entry_page'])) {
            $meta['entry_page'] = $entryPage;
        }

        if (!isset($meta['share_generate_event_id'])) {
            $meta['share_generate_event_id'] = (string) ($gen->id ?? '');
        }
        if (!isset($meta['share_generate_occurred_at'])) {
            $meta['share_generate_occurred_at'] = (string) ($gen->occurred_at ?? '');
        }

        if (!isset($meta['ua'])) {
            $meta['ua'] = trim((string) ($requestMeta['ua'] ?? ''));
        }
        if (!isset($meta['ip'])) {
            $meta['ip'] = trim((string) ($requestMeta['ip'] ?? ''));
        }
        if (!isset($meta['ref'])) {
            $meta['ref'] = trim((string) ($requestMeta['referer'] ?? ''));
        }

        $meta = array_filter($meta, static fn (mixed $v): bool => !($v === null || $v === ''));
        $meta = $this->eventPayloadLimiter->limit($meta);

        $attempt = Attempt::query()->where('id', $attemptId)->firstOrFail();
        $this->assertOrgIsolation($attempt);

        $result = Result::query()
            ->where('org_id', (int) ($attempt->org_id ?? 0))
            ->where('attempt_id', (string) $attempt->id)
            ->firstOrFail();

        $event = new Event();
        $event->id = $this->newUuid();
        $event->event_code = 'share_click';
        $event->org_id = (int) ($attempt->org_id ?? 0);
        $event->attempt_id = (string) $attempt->id;
        $event->anon_id = $this->resolveClickAnonId($input, $gen);
        $event->meta_json = $meta;
        $event->occurred_at = !empty($input['occurred_at'])
            ? Carbon::parse((string) $input['occurred_at'])
            : now();
        $event->save();

        $ctx = [
            'share_id' => $shareId,
            'attempt_id' => (string) $attempt->id,
            'experiment' => $experiment,
            'version' => $version,
            'type_code' => $typeCode,
            'engine' => $engineVersion,
            'profile_version' => $profileVersion,
            'content_package_version' => $packVersion,
            'org_id' => (int) ($attempt->org_id ?? 0),
        ];

        try {
            $composed = $this->reportComposer->compose($attempt, $ctx, $result);
        } catch (\Throwable $e) {
            $this->logger->error('[share] report compose failed', [
                'share_id' => $shareId,
                'attempt_id' => (string) $attempt->id,
                'org_id' => (int) ($attempt->org_id ?? 0),
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }

        $report = is_array($composed['report'] ?? null) ? $composed['report'] : $this->toArray($composed);
        if (!is_array($report)) {
            $report = [];
        }

        if (!isset($report['_meta']) || !is_array($report['_meta'])) {
            $report['_meta'] = [];
        }

        $metaFill = [
            'share_id' => $shareId,
            'attempt_id' => (string) $attempt->id,
            'type_code' => $typeCode,
            'engine' => $engineVersion,
            'profile_version' => $profileVersion,
            'content_package_version' => $packVersion,
            'experiment' => $experiment,
            'version' => $version,
            'generated_at' => now()->toISOString(),
        ];

        foreach ($metaFill as $key => $value) {
            if ($value === '' || $value === null) {
                continue;
            }
            if (!array_key_exists($key, $report['_meta'])) {
                $report['_meta'][$key] = $value;
            }
        }

        return [
            'id' => (string) $event->id,
            'share_id' => $shareId,
            'attempt_id' => (string) $attempt->id,
            'report' => $this->filterReport($report),
        ];
    }

    public function getShareView(string $shareId): array
    {
        $data = $this->legacyShareService->getShareView($shareId);

        $attemptId = (string) ($data['attempt_id'] ?? '');
        if ($attemptId !== '') {
            $attempt = Attempt::query()->where('id', $attemptId)->firstOrFail();
            $this->assertOrgIsolation($attempt);
        }

        return $data;
    }

    private function emitLegacyShareEvents(array $data, array $input, array $commercial): void
    {
        $shareId = trim((string) ($data['share_id'] ?? ''));
        $attemptId = trim((string) ($data['attempt_id'] ?? ''));
        if ($shareId === '' || $attemptId === '') {
            return;
        }

        $experiment = trim((string) ($input['experiment'] ?? ''));
        $version = trim((string) ($input['version'] ?? ''));
        $channel = trim((string) ($input['channel'] ?? ''));
        $clientPlatform = trim((string) ($input['client_platform'] ?? ''));
        $entryPage = trim((string) ($input['entry_page'] ?? ''));

        $metaBase = [
            'share_id' => $shareId,
            'type_code' => (string) ($data['type_code'] ?? ''),
            'content_package_version' => (string) ($data['content_package_version'] ?? ''),
            'engine_version' => 'v1.2',
            'engine' => 'v1.2',
            'experiment' => $experiment !== '' ? $experiment : null,
            'version' => $version !== '' ? $version : null,
            'channel' => $channel !== '' ? $channel : null,
            'client_platform' => $clientPlatform !== '' ? $clientPlatform : null,
            'entry_page' => $entryPage !== '' ? $entryPage : null,
            'report_benefit_code' => $commercial['report_benefit_code'] !== '' ? $commercial['report_benefit_code'] : null,
            'subscription_benefit_code' => $commercial['subscription_benefit_code'] !== '' ? $commercial['subscription_benefit_code'] : null,
            'default_sku' => $commercial['default_sku'] !== '' ? $commercial['default_sku'] : null,
        ];

        $this->createLegacyEvent('share_generate', $attemptId, (int) ($data['org_id'] ?? 0), $this->orgContext->anonId(), $metaBase + [
            'generate_type' => 'text',
        ]);

        $this->createLegacyEvent('share_view', $attemptId, (int) ($data['org_id'] ?? 0), $this->orgContext->anonId(), $metaBase + [
            'view_type' => 'share_sheet',
        ]);
    }

    private function createLegacyEvent(string $eventCode, string $attemptId, int $orgId, ?string $anonId, array $meta): void
    {
        try {
            Event::query()->create([
                'id' => (string) Str::uuid(),
                'event_code' => $eventCode,
                'attempt_id' => $attemptId,
                'org_id' => $orgId,
                'anon_id' => $anonId,
                'channel' => $meta['channel'] ?? null,
                'client_platform' => $meta['client_platform'] ?? null,
                'meta_json' => $meta,
                'occurred_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('SHARE_LEGACY_EVENT_CREATE_FAILED', [
                'event_code' => $eventCode,
                'attempt_id' => $attemptId,
                'org_id' => $orgId,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function resolveCommercial(Attempt $attempt): array
    {
        $scaleCode = trim((string) ($attempt->scale_code ?? ''));
        if ($scaleCode === '') {
            return [
                'report_benefit_code' => '',
                'subscription_benefit_code' => '',
                'default_sku' => '',
            ];
        }

        $row = $this->scaleRegistry->getByCode($scaleCode, (int) ($attempt->org_id ?? 0));
        if (!is_array($row)) {
            return [
                'report_benefit_code' => '',
                'subscription_benefit_code' => '',
                'default_sku' => '',
            ];
        }

        $commercial = $row['commercial_json'] ?? null;
        if (is_string($commercial)) {
            $decoded = json_decode($commercial, true);
            $commercial = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($commercial)) {
            $commercial = [];
        }

        $reportBenefitCode = strtoupper(trim((string) ($commercial['report_benefit_code'] ?? '')));
        if ($reportBenefitCode === '') {
            $reportBenefitCode = strtoupper(trim((string) ($commercial['credit_benefit_code'] ?? '')));
        }

        return [
            'report_benefit_code' => $reportBenefitCode,
            'subscription_benefit_code' => strtoupper(trim((string) ($commercial['subscription_benefit_code'] ?? ''))),
            'default_sku' => trim((string) ($commercial['default_sku'] ?? '')),
        ];
    }

    private function resolveClickAnonId(array $input, Event $gen): ?string
    {
        $badAnon = static function (?string $value): bool {
            if ($value === null) {
                return true;
            }

            $candidate = trim($value);
            if ($candidate === '') {
                return true;
            }

            $lower = mb_strtolower($candidate, 'UTF-8');
            $blacklist = [
                'todo',
                'placeholder',
                'fixme',
                'tbd',
                '把你查到的anon_id填这里',
                '把你查到的 anon_id 填这里',
                '填这里',
            ];

            foreach ($blacklist as $bad) {
                $needle = mb_strtolower(trim($bad), 'UTF-8');
                if ($needle !== '' && mb_strpos($lower, $needle) !== false) {
                    return true;
                }
            }

            return false;
        };

        $fromInput = isset($input['anon_id']) && is_string($input['anon_id'])
            ? trim((string) $input['anon_id'])
            : '';

        if ($fromInput !== '' && !$badAnon($fromInput)) {
            return $fromInput;
        }

        $fromGen = trim((string) ($gen->anon_id ?? ''));
        if ($fromGen !== '' && !$badAnon($fromGen)) {
            return $fromGen;
        }

        return null;
    }

    private function assertOrgIsolation(Attempt $attempt): void
    {
        $ctxOrgId = max(0, (int) $this->orgContext->orgId());
        if ($ctxOrgId === 0) {
            return;
        }

        if ((int) ($attempt->org_id ?? 0) !== $ctxOrgId) {
            throw (new ModelNotFoundException())->setModel(Attempt::class, [(string) ($attempt->id ?? '')]);
        }
    }

    private function normalizeMeta(mixed $meta): array
    {
        if (is_array($meta)) {
            return $meta;
        }

        if (is_string($meta)) {
            $decoded = json_decode($meta, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    private function filterReport(array $report): array
    {
        $allowed = $this->allowedReportKeys();
        $allowed = array_flip($allowed);

        $output = [];
        foreach ($report as $key => $value) {
            if (!isset($allowed[$key])) {
                continue;
            }

            if ($key === '_explain' && !$this->shouldExposeExplain()) {
                continue;
            }

            $output[$key] = $value;
        }

        return $output;
    }

    private function allowedReportKeys(): array
    {
        $keys = config('report.share_report_allowed_keys', []);
        return is_array($keys) ? $keys : [];
    }

    private function shouldExposeExplain(): bool
    {
        if (app()->environment('local', 'development')) {
            return true;
        }

        return (bool) config('report.expose_explain', false);
    }

    private function toArray(mixed $payload): array
    {
        if (is_array($payload)) {
            return $payload;
        }

        if (is_object($payload)) {
            if (method_exists($payload, 'toArray')) {
                $array = $payload->toArray();
                return is_array($array) ? $array : [];
            }

            if (method_exists($payload, 'jsonSerialize')) {
                $array = $payload->jsonSerialize();
                return is_array($array) ? $array : [];
            }
        }

        return [];
    }

    private function newUuid(): string
    {
        return method_exists(Str::class, 'uuid7') ? (string) Str::uuid7() : (string) Str::uuid();
    }
}
