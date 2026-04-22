<?php

declare(strict_types=1);

namespace App\Services\Share;

use App\Models\Attempt;
use App\Models\Event;
use App\Models\Result;
use App\Models\Share;
use App\Services\Analytics\EventPayloadLimiter;
use App\Services\Commerce\EntitlementManager;
use App\Services\Report\ReportComposer;
use App\Services\Scale\ScaleRegistry;
use App\Services\Share\Contracts\ShareFlowAdapter;
use App\Support\OrgContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ShareFlowCoreService
{
    public function __construct(
        private readonly OrgContext $orgContext,
        private readonly ShareFlowAdapter $shareAdapter,
        private readonly ReportComposer $reportComposer,
        private readonly EventPayloadLimiter $eventPayloadLimiter,
        private readonly ScaleRegistry $scaleRegistry,
        private readonly EntitlementManager $entitlementManager,
        private readonly LoggerInterface $logger,
        private readonly string $eventCreateFailureCode = 'SHARE_EVENT_CREATE_FAILED',
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function getShareLinkForAttempt(string $attemptId, array $input): array
    {
        $attempt = $this->shareAdapter->resolveAttemptForAuth($attemptId, $this->orgContext);
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

        if (! $hasFullAccess && ! $this->canGeneratePublicShareSummary($attempt)) {
            throw new HttpException(402, 'PAYMENT_REQUIRED');
        }

        $data = $this->shareAdapter->getOrCreateShare($attemptId, $this->orgContext);
        $this->emitShareEvents($data, $input, $commercial);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $requestMeta
     * @return array<string, mixed>
     */
    public function clickAndComposeReport(string $shareId, array $input, array $requestMeta): array
    {
        return $this->recordClick($shareId, $input, $requestMeta);
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $requestMeta
     * @return array<string, mixed>
     */
    public function recordClick(string $shareId, array $input, array $requestMeta): array
    {
        $share = Share::withoutGlobalScopes()->where('id', $shareId)->first();
        if (! $share) {
            throw new NotFoundHttpException('share not found');
        }

        $attemptId = trim((string) ($share->attempt_id ?? ''));
        if ($attemptId === '') {
            throw (new ModelNotFoundException)->setModel(Share::class, [$shareId]);
        }

        $gen = Event::withoutGlobalScopes()
            ->where('event_code', 'share_generate')
            ->where('share_id', $shareId)
            ->orderByDesc('occurred_at')
            ->first();
        $genMeta = $gen ? $this->normalizeMeta($gen->meta_json ?? null) : [];

        $attempt = Attempt::withoutGlobalScopes()->where('id', $attemptId)->firstOrFail();
        $this->assertOrgIsolation($attempt);

        $result = Result::withoutGlobalScopes()
            ->where('org_id', (int) ($attempt->org_id ?? 0))
            ->where('attempt_id', (string) $attempt->id)
            ->first();

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
        $entryPage = trim((string) ($requestMeta['entry_page'] ?? ($genMeta['entry_page'] ?? '')));

        $typeCode = trim((string) ($genMeta['type_code'] ?? ($result?->type_code ?? '')));
        $packVersion = trim((string) (
            $genMeta['content_package_version']
            ?? ($attempt->content_package_version ?? $result?->content_package_version ?? '')
        ));
        $engineVersion = trim((string) ($genMeta['engine_version'] ?? ($genMeta['engine'] ?? '')));
        $profileVersion = trim((string) ($genMeta['profile_version'] ?? ($result?->profile_version ?? '')));

        $meta = is_array($input['meta_json'] ?? null) ? $input['meta_json'] : [];
        $meta['share_id'] = $shareId;
        $meta['attempt_id'] = $attemptId;

        if ($typeCode !== '' && ! isset($meta['type_code'])) {
            $meta['type_code'] = $typeCode;
        }
        if ($engineVersion !== '' && ! isset($meta['engine_version'])) {
            $meta['engine_version'] = $engineVersion;
        }
        if ($engineVersion !== '' && ! isset($meta['engine'])) {
            $meta['engine'] = $engineVersion;
        }
        if ($packVersion !== '' && ! isset($meta['content_package_version'])) {
            $meta['content_package_version'] = $packVersion;
        }
        if ($profileVersion !== '' && ! isset($meta['profile_version'])) {
            $meta['profile_version'] = $profileVersion;
        }

        $entrypoint = trim((string) ($meta['entrypoint'] ?? ''));
        if ($entrypoint === '') {
            $entrypoint = trim((string) ($entryPage !== '' ? $entryPage : ($genMeta['entrypoint'] ?? '')));
        }
        if ($entrypoint !== '') {
            $meta['entrypoint'] = $entrypoint;
        }

        if ($experiment !== '' && ! isset($meta['experiment'])) {
            $meta['experiment'] = $experiment;
        }
        if ($version !== '' && ! isset($meta['version'])) {
            $meta['version'] = $version;
        }

        if ($channel !== '' && ! isset($meta['channel'])) {
            $meta['channel'] = $channel;
        }
        if ($clientPlatform !== '' && ! isset($meta['client_platform'])) {
            $meta['client_platform'] = $clientPlatform;
        }
        if ($entryPage !== '' && ! isset($meta['entry_page'])) {
            $meta['entry_page'] = $entryPage;
        }

        if ($gen && ! isset($meta['share_generate_event_id'])) {
            $meta['share_generate_event_id'] = (string) ($gen->id ?? '');
        }
        if ($gen && ! isset($meta['share_generate_occurred_at'])) {
            $meta['share_generate_occurred_at'] = (string) ($gen->occurred_at ?? '');
        }

        if (! isset($meta['ua'])) {
            $meta['ua'] = trim((string) ($requestMeta['ua'] ?? ''));
        }
        if (! isset($meta['ip'])) {
            $meta['ip'] = trim((string) ($requestMeta['ip'] ?? ''));
        }
        if (! isset($meta['ref'])) {
            $meta['ref'] = trim((string) ($requestMeta['referer'] ?? ''));
        }
        if (! isset($meta['referrer'])) {
            $meta['referrer'] = trim((string) ($requestMeta['referer'] ?? ($input['ref'] ?? '')));
        }
        if (! isset($meta['landing_path'])) {
            $meta['landing_path'] = $this->extractLandingPath($input);
        }
        if (! isset($meta['compare_intent']) && array_key_exists('compare_intent', $genMeta)) {
            $meta['compare_intent'] = $genMeta['compare_intent'];
        }
        if (! isset($meta['utm']) && is_array($genMeta['utm'] ?? null)) {
            $meta['utm'] = $genMeta['utm'];
        }

        $meta['utm'] = $this->normalizeUtmMeta($meta['utm'] ?? null);
        $meta = $this->filterMeta($meta);
        $meta = $this->eventPayloadLimiter->limit($meta);

        $event = new Event;
        $event->id = $this->newUuid();
        $event->event_code = 'share_click';
        $event->org_id = (int) ($attempt->org_id ?? 0);
        $event->attempt_id = (string) $attempt->id;
        $event->share_id = $shareId;
        $event->anon_id = $this->resolveClickAnonId(
            $input,
            $gen,
            is_string($share->anon_id ?? null) ? (string) $share->anon_id : null
        );
        $event->meta_json = $meta;
        $event->occurred_at = ! empty($input['occurred_at'])
            ? Carbon::parse((string) $input['occurred_at'])
            : now();
        $event->save();

        return [
            'id' => (string) $event->id,
            'share_id' => $shareId,
            'recorded_at' => $event->occurred_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getShareView(string $shareId): array
    {
        $data = $this->shareAdapter->getShareView($shareId);

        $attemptId = (string) ($data['attempt_id'] ?? '');
        if ($attemptId !== '') {
            $attempt = Attempt::withoutGlobalScopes()->where('id', $attemptId)->firstOrFail();
            $this->assertOrgIsolation($attempt);
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $input
     * @param  array{report_benefit_code:string,subscription_benefit_code:string,default_sku:string}  $commercial
     */
    private function emitShareEvents(array $data, array $input, array $commercial): void
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

        $this->createShareEvent('share_generate', $attemptId, (int) ($data['org_id'] ?? 0), $this->orgContext->anonId(), $metaBase + [
            'generate_type' => 'text',
        ]);

        $this->createShareEvent('share_view', $attemptId, (int) ($data['org_id'] ?? 0), $this->orgContext->anonId(), $metaBase + [
            'view_type' => 'share_sheet',
        ]);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function createShareEvent(string $eventCode, string $attemptId, int $orgId, ?string $anonId, array $meta): void
    {
        try {
            $shareId = trim((string) ($meta['share_id'] ?? ''));
            if ($shareId === '' || strlen($shareId) > 64) {
                $shareId = null;
            }

            $shareChannel = trim((string) ($meta['share_channel'] ?? ($meta['channel'] ?? '')));
            if ($shareChannel === '') {
                $shareChannel = null;
            }

            $clientPlatform = trim((string) ($meta['client_platform'] ?? ''));
            if ($clientPlatform === '') {
                $clientPlatform = null;
            }

            Event::query()->create([
                'id' => (string) Str::uuid(),
                'event_code' => $eventCode,
                'attempt_id' => $attemptId,
                'org_id' => $orgId,
                'anon_id' => $anonId,
                'channel' => $meta['channel'] ?? null,
                'client_platform' => $clientPlatform,
                'share_id' => $shareId,
                'share_channel' => $shareChannel,
                'meta_json' => $meta,
                'occurred_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning($this->eventCreateFailureCode, [
                'event_code' => $eventCode,
                'attempt_id' => $attemptId,
                'org_id' => $orgId,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array{report_benefit_code:string,subscription_benefit_code:string,default_sku:string}
     */
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
        if (! is_array($row)) {
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
        if (! is_array($commercial)) {
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

    private function canGeneratePublicShareSummary(Attempt $attempt): bool
    {
        return in_array(
            strtoupper(trim((string) ($attempt->scale_code ?? ''))),
            ['MBTI', 'BIG5_OCEAN', 'RIASEC'],
            true
        );
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function resolveClickAnonId(array $input, ?Event $gen = null, ?string $shareAnonId = null): ?string
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

        if ($fromInput !== '' && ! $badAnon($fromInput)) {
            return $fromInput;
        }

        $fromShare = trim((string) ($shareAnonId ?? ''));
        if ($fromShare !== '' && ! $badAnon($fromShare)) {
            return $fromShare;
        }

        $fromGen = trim((string) ($gen->anon_id ?? ''));
        if ($fromGen !== '' && ! $badAnon($fromGen)) {
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
            throw (new ModelNotFoundException)->setModel(Attempt::class, [(string) ($attempt->id ?? '')]);
        }
    }

    /**
     * @return array<string, mixed>
     */
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

    /**
     * @return array<string, string>|null
     */
    private function normalizeUtmMeta(mixed $utm): ?array
    {
        if (! is_array($utm)) {
            return null;
        }

        $normalized = [];
        foreach (['source', 'medium', 'campaign', 'term', 'content'] as $key) {
            $value = trim((string) ($utm[$key] ?? ''));
            if ($value !== '') {
                $normalized[$key] = $value;
            }
        }

        return $normalized === [] ? null : $normalized;
    }

    private function extractLandingPath(array $input): ?string
    {
        $landingPath = trim((string) ($input['landing_path'] ?? ''));
        if ($landingPath !== '') {
            return $landingPath;
        }

        $url = trim((string) ($input['url'] ?? ''));
        if ($url === '') {
            return null;
        }

        $parsed = parse_url($url, PHP_URL_PATH);

        return is_string($parsed) && trim($parsed) !== '' ? trim($parsed) : null;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function filterMeta(array $meta): array
    {
        $filtered = [];

        foreach ($meta as $key => $value) {
            if (is_array($value)) {
                $value = $this->filterMeta($value);
                if ($value === []) {
                    continue;
                }

                $filtered[$key] = $value;

                continue;
            }

            if ($value === null || $value === '') {
                continue;
            }

            $filtered[$key] = $value;
        }

        return $filtered;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function filterReport(array $report): array
    {
        $allowed = $this->allowedReportKeys();
        $allowed = array_flip($allowed);

        $output = [];
        foreach ($report as $key => $value) {
            if (! isset($allowed[$key])) {
                continue;
            }

            if ($key === '_explain' && ! $this->shouldExposeExplain()) {
                continue;
            }

            $output[$key] = $value;
        }

        return $output;
    }

    /**
     * @return list<string>
     */
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

    /**
     * @return array<string, mixed>
     */
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
