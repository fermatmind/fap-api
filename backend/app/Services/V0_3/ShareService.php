<?php

namespace App\Services\V0_3;

use App\Models\Attempt;
use App\Models\PersonalityProfile;
use App\Models\Result;
use App\Models\Share;
use App\Services\Cms\PersonalityProfileService;
use App\Services\Mbti\MbtiPublicProjectionService;
use App\Services\Mbti\MbtiPublicSummaryV1Builder;
use App\Services\Report\ReportAccess;
use App\Services\Report\ReportComposer;
use App\Services\Scale\ScaleIdentityWriteProjector;
use App\Services\Scale\ScaleRegistry;
use App\Support\OrgContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;

class ShareService
{
    public function __construct(
        private readonly ScaleIdentityWriteProjector $identityProjector,
        private readonly ReportComposer $reportComposer,
        private readonly ScaleRegistry $scaleRegistry,
        private readonly PersonalityProfileService $personalityProfileService,
        private readonly MbtiPublicProjectionService $mbtiPublicProjectionService,
        private readonly MbtiPublicSummaryV1Builder $mbtiPublicSummaryV1Builder,
    ) {}

    public function getOrCreateShare(string $attemptId, OrgContext $ctx): array
    {
        $attempt = $this->findAccessibleAttempt($attemptId, $ctx);

        $result = Result::query()
            ->where('attempt_id', $attemptId)
            ->where('org_id', $ctx->orgId())
            ->firstOrFail();

        $share = Share::query()
            ->where('attempt_id', $attemptId)
            ->first();

        if (! $share) {
            $share = $this->createShare($attempt, $ctx->anonId());
        } else {
            $this->backfillShareScaleIdentityIfNeeded($share, $attempt);
        }

        return $this->buildSharePayload($share, $attempt, $result);
    }

    public function getShareView(string $shareId): array
    {
        $share = Share::query()->where('id', $shareId)->firstOrFail();

        $attempt = Attempt::query()
            ->where('id', $share->attempt_id)
            ->firstOrFail();

        $orgId = (int) ($attempt->org_id ?? 0);
        $ctxOrgId = max(0, (int) app(OrgContext::class)->orgId());
        if ($ctxOrgId > 0 && $ctxOrgId !== $orgId) {
            throw (new ModelNotFoundException)->setModel(Share::class, [$shareId]);
        }

        $result = Result::query()
            ->where('attempt_id', $attempt->id)
            ->where('org_id', $orgId)
            ->firstOrFail();

        return $this->buildSharePayload($share, $attempt, $result);
    }

    public function buildPublicSummaryPayload(
        Attempt $attempt,
        Result $result,
        ?string $shareId = null,
        ?string $createdAt = null
    ): array {
        return $this->buildCanonicalPayload(null, $attempt, $result, $shareId, $createdAt);
    }

    public function resolveAttemptForAuth(string $attemptId, OrgContext $ctx): Attempt
    {
        return $this->findAccessibleAttempt($attemptId, $ctx);
    }

    private function findAccessibleAttempt(string $attemptId, OrgContext $ctx): Attempt
    {
        $query = Attempt::query()
            ->where('id', $attemptId)
            ->where('org_id', $ctx->orgId());

        if (! $this->isAdmin($ctx)) {
            $userId = $ctx->userId();
            $anonId = $ctx->anonId();

            if ($userId === null && ($anonId === null || $anonId === '')) {
                throw (new ModelNotFoundException)->setModel(Attempt::class, [$attemptId]);
            }

            $query->where(function (Builder $sub) use ($userId, $anonId): void {
                if ($userId !== null) {
                    $sub->orWhere('user_id', (string) $userId);
                }
                if ($anonId !== null && $anonId !== '') {
                    $sub->orWhere('anon_id', $anonId);
                }
            });
        }

        return $query->firstOrFail();
    }

    private function isAdmin(OrgContext $ctx): bool
    {
        return strtolower((string) ($ctx->role() ?? '')) === 'admin';
    }

    private function createShare(Attempt $attempt, ?string $anonId): Share
    {
        $share = new Share;
        $share->id = bin2hex(random_bytes(16));
        $share->attempt_id = (string) $attempt->id;
        $share->anon_id = $anonId;
        $share->scale_code = (string) ($attempt->scale_code ?? '');
        $share->scale_version = (string) ($attempt->scale_version ?? '');
        $share->content_package_version = (string) ($attempt->content_package_version ?? '');
        if ($this->shouldWriteScaleIdentityColumns()) {
            [$scaleCodeV2, $scaleUid] = $this->resolveScaleIdentityValues($attempt);
            $share->scale_code_v2 = $scaleCodeV2;
            $share->scale_uid = $scaleUid;
        }

        try {
            $share->save();

            return $share;
        } catch (QueryException $e) {
            return Share::query()
                ->where('attempt_id', (string) $attempt->id)
                ->firstOrFail();
        }
    }

    private function backfillShareScaleIdentityIfNeeded(Share $share, Attempt $attempt): void
    {
        if (! $this->shouldWriteScaleIdentityColumns()) {
            return;
        }

        [$scaleCodeV2, $scaleUid] = $this->resolveScaleIdentityValues($attempt);
        $dirty = false;

        if (trim((string) ($share->scale_code_v2 ?? '')) === '' && $scaleCodeV2 !== null && $scaleCodeV2 !== '') {
            $share->scale_code_v2 = $scaleCodeV2;
            $dirty = true;
        }
        if (trim((string) ($share->scale_uid ?? '')) === '' && $scaleUid !== null && $scaleUid !== '') {
            $share->scale_uid = $scaleUid;
            $dirty = true;
        }

        if (! $dirty) {
            return;
        }

        try {
            $share->save();
        } catch (QueryException $e) {
            // best effort backfill; keep read path unaffected on contention.
        }
    }

    /**
     * @return array{0:?string,1:?string}
     */
    private function resolveScaleIdentityValues(Attempt $attempt): array
    {
        $identity = $this->identityProjector->projectFromAttempt($attempt);

        return [
            $identity['scale_code_v2'],
            $identity['scale_uid'],
        ];
    }

    private function shouldWriteScaleIdentityColumns(): bool
    {
        $mode = strtolower(trim((string) config('scale_identity.write_mode', 'legacy')));

        return in_array($mode, ['dual', 'v2'], true);
    }

    private function buildSharePayload(Share $share, Attempt $attempt, Result $result): array
    {
        return $this->buildCanonicalPayload(
            $share,
            $attempt,
            $result,
            (string) $share->id,
            $share->created_at?->toISOString()
        );
    }

    /**
     * @return array{
     *   scale_code:string,
     *   locale:string,
     *   title:string,
     *   subtitle:?string,
     *   summary:?string,
     *   type_code:string,
     *   type_name:string,
     *   tagline:?string,
     *   rarity:string|int|float|null,
     *   tags:list<string>,
     *   dimensions:list<array<string,mixed>>,
     *   primary_cta_label:string,
     *   primary_cta_path:string
     * }
     */
    private function buildShareSummary(?Share $share, Attempt $attempt, Result $result, ?array $report = null): array
    {
        $resultJson = $this->normalizeArray($result->result_json ?? null);
        $report = is_array($report) ? $report : $this->buildPublicSafeReportSnapshot($attempt, $result);
        $reportProfile = $this->normalizeArray($report['profile'] ?? null);
        $identityCard = $this->normalizeArray($report['identity_card'] ?? null);
        $identityLayer = $this->normalizeArray(data_get($report, 'layers.identity'));

        $scaleCode = strtoupper(trim((string) ($attempt->scale_code ?? $result->scale_code ?? ($share?->scale_code ?? '') ?? '')));
        if ($scaleCode === '') {
            $scaleCode = 'MBTI';
        }

        $locale = $this->normalizeLocale((string) ($attempt->locale ?? config('content_packs.default_locale', 'zh-CN')));
        $typeCode = trim((string) ($result->type_code ?? $resultJson['type_code'] ?? ''));
        $typeName = $this->firstNonEmpty(
            $this->stringOrNull($resultJson['type_name'] ?? null),
            $this->stringOrNull($resultJson['type'] ?? null),
            $this->stringOrNull($reportProfile['type_name'] ?? null),
            $typeCode
        ) ?? $typeCode;

        $publicProfile = $this->resolvePublicProfile(
            $typeCode,
            (int) ($attempt->org_id ?? 0),
            $scaleCode,
            $locale
        );

        $dimensions = $this->buildDimensions(
            is_array($result->scores_pct ?? null) ? $result->scores_pct : $this->normalizeArray($report['scores_pct'] ?? null),
            is_array($result->axis_states ?? null) ? $result->axis_states : $this->normalizeArray($report['axis_states'] ?? null),
            $locale
        );

        $tags = $this->resolvePublicTags($resultJson, $reportProfile, $identityCard, $dimensions);

        return [
            'scale_code' => $scaleCode,
            'locale' => $locale,
            'title' => $this->firstNonEmpty(
                $publicProfile?->title,
                $this->stringOrNull($identityCard['title'] ?? null),
                $this->stringOrNull($identityLayer['title'] ?? null),
                $typeName,
                $typeCode
            ) ?? $typeCode,
            'subtitle' => $this->firstNonEmpty(
                $publicProfile?->subtitle,
                $this->stringOrNull($resultJson['subtitle'] ?? null),
                $this->stringOrNull($identityCard['subtitle'] ?? null),
                $this->stringOrNull($identityLayer['subtitle'] ?? null)
            ),
            'summary' => $this->firstNonEmpty(
                $this->stringOrNull($resultJson['summary'] ?? null),
                $this->stringOrNull($resultJson['short_summary'] ?? null),
                $this->stringOrNull($identityCard['summary'] ?? null),
                $this->stringOrNull($reportProfile['short_summary'] ?? null),
                $publicProfile?->excerpt,
                $this->stringOrNull($identityLayer['one_liner'] ?? null)
            ),
            'type_code' => $typeCode,
            'type_name' => $typeName,
            'tagline' => $this->firstNonEmpty(
                $this->stringOrNull($resultJson['tagline'] ?? null),
                $this->stringOrNull($identityCard['tagline'] ?? null),
                $this->stringOrNull($reportProfile['tagline'] ?? null),
                $this->extractTaglineFromTitle($publicProfile?->title)
            ),
            'rarity' => $this->scalarOrNull($resultJson['rarity'] ?? null)
                ?? $this->scalarOrNull($reportProfile['rarity'] ?? null),
            'tags' => $tags,
            'dimensions' => $dimensions,
            'primary_cta_label' => $this->resolvePrimaryCtaLabel($locale),
            'primary_cta_path' => $this->resolvePrimaryCtaPath($scaleCode, $locale, (int) ($attempt->org_id ?? 0)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPublicSafeReportSnapshot(Attempt $attempt, Result $result): array
    {
        if (strtoupper(trim((string) ($attempt->scale_code ?? ''))) !== 'MBTI') {
            return [];
        }

        try {
            $payload = $this->reportComposer->composeVariant(
                $attempt,
                ReportAccess::VARIANT_FREE,
                [
                    'org_id' => (int) ($attempt->org_id ?? 0),
                    'persist' => false,
                    'modules_allowed' => ReportAccess::defaultModulesAllowedForLocked($attempt->scale_code),
                ],
                $result
            );
        } catch (\Throwable) {
            return [];
        }

        if (($payload['ok'] ?? false) !== true || ! is_array($payload['report'] ?? null)) {
            return [];
        }

        return $payload['report'];
    }

    private function resolvePublicProfile(
        string $typeCode,
        int $orgId,
        string $scaleCode,
        string $locale
    ): ?PersonalityProfile {
        $baseTypeCode = $this->baseTypeCode($typeCode);
        if ($baseTypeCode === '') {
            return null;
        }

        return $this->personalityProfileService->getPublicProfileByType(
            $baseTypeCode,
            $orgId,
            $scaleCode,
            $locale
        );
    }

    private function buildShareUrl(string $shareId): string
    {
        return rtrim((string) config('app.frontend_url', 'http://localhost'), '/').'/share/'.$shareId;
    }

    private function normalizeLocale(string $locale): string
    {
        return trim($locale) === 'zh-CN' ? 'zh-CN' : 'en';
    }

    private function baseTypeCode(string $typeCode): string
    {
        return strtoupper(trim((string) preg_replace('/-[A-Z]$/', '', $typeCode)));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeArray(mixed $payload): array
    {
        if (is_array($payload)) {
            return $payload;
        }

        if (! is_string($payload) || trim($payload) === '') {
            return [];
        }

        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private function scalarOrNull(mixed $value): string|int|float|null
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value)) {
            $normalized = trim($value);

            return $normalized === '' ? null : $normalized;
        }

        return null;
    }

    private function firstNonEmpty(?string ...$candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if ($candidate === null) {
                continue;
            }

            $normalized = trim($candidate);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return null;
    }

    private function extractTaglineFromTitle(?string $title): ?string
    {
        $normalized = trim((string) $title);
        if ($normalized === '') {
            return null;
        }

        $parts = preg_split('/\s[-–—]\s/u', $normalized);
        if (! is_array($parts) || count($parts) < 2) {
            return null;
        }

        $tagline = trim((string) end($parts));

        return $tagline === '' ? null : $tagline;
    }

    /**
     * @param  array<string, mixed>  $resultJson
     * @param  array<string, mixed>  $reportProfile
     * @param  array<string, mixed>  $identityCard
     * @param  list<array<string, mixed>>  $dimensions
     * @return list<string>
     */
    private function resolvePublicTags(
        array $resultJson,
        array $reportProfile,
        array $identityCard,
        array $dimensions
    ): array {
        $sources = [
            $this->sanitizeTags($resultJson['tags'] ?? null),
            $this->sanitizeTags($resultJson['keywords'] ?? null),
            $this->sanitizeTags($reportProfile['keywords'] ?? null),
            $this->sanitizeTags($identityCard['tags'] ?? null),
        ];

        foreach ($sources as $tags) {
            if ($tags !== []) {
                return $tags;
            }
        }

        $derived = [];
        foreach ($dimensions as $dimension) {
            $label = $this->stringOrNull($dimension['side_label'] ?? null);
            if ($label !== null) {
                $derived[] = $label;
            }
        }

        return array_values(array_slice(array_unique($derived), 0, 5));
    }

    /**
     * @return list<string>
     */
    private function sanitizeTags(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $tags = [];
        foreach ($value as $item) {
            if (! is_scalar($item)) {
                continue;
            }

            $tag = trim((string) $item);
            if ($tag === '' || str_contains($tag, ':')) {
                continue;
            }

            $tags[$tag] = true;
            if (count($tags) >= 5) {
                break;
            }
        }

        return array_keys($tags);
    }

    /**
     * @param  array<string, mixed>  $scoresPct
     * @param  array<string, mixed>  $axisStates
     * @return list<array<string, mixed>>
     */
    private function buildDimensions(array $scoresPct, array $axisStates, string $locale): array
    {
        $definitions = $this->axisDefinitions($locale);
        $dimensions = [];

        foreach ($definitions as $code => $definition) {
            if (! isset($scoresPct[$code]) || ! is_numeric($scoresPct[$code])) {
                continue;
            }

            $rawPct = max(0, min(100, (int) round((float) $scoresPct[$code])));
            $primarySide = $rawPct >= 50 ? $definition['first_code'] : $definition['second_code'];
            $primaryPct = $rawPct >= 50 ? $rawPct : (100 - $rawPct);

            $dimensions[] = [
                'code' => $code,
                'label' => $definition['axis_label'],
                'side' => $primarySide,
                'side_label' => $definition['side_labels'][$primarySide],
                'pct' => $primaryPct,
                'state' => $this->stringOrNull($axisStates[$code] ?? null) ?? 'moderate',
            ];
        }

        return $dimensions;
    }

    /**
     * @return array<string, array{
     *   axis_label:string,
     *   first_code:string,
     *   second_code:string,
     *   side_labels:array<string, string>
     * }>
     */
    private function axisDefinitions(string $locale): array
    {
        $zh = $locale === 'zh-CN';

        return [
            'EI' => [
                'axis_label' => $zh ? '能量方向' : 'Energy',
                'first_code' => 'E',
                'second_code' => 'I',
                'side_labels' => [
                    'E' => $zh ? '外倾' : 'Extraversion',
                    'I' => $zh ? '内倾' : 'Introversion',
                ],
            ],
            'SN' => [
                'axis_label' => $zh ? '信息偏好' : 'Information',
                'first_code' => 'S',
                'second_code' => 'N',
                'side_labels' => [
                    'S' => $zh ? '实感' : 'Sensing',
                    'N' => $zh ? '直觉' : 'Intuition',
                ],
            ],
            'TF' => [
                'axis_label' => $zh ? '决策偏好' : 'Decision',
                'first_code' => 'T',
                'second_code' => 'F',
                'side_labels' => [
                    'T' => $zh ? '思考' : 'Thinking',
                    'F' => $zh ? '情感' : 'Feeling',
                ],
            ],
            'JP' => [
                'axis_label' => $zh ? '生活方式' : 'Lifestyle',
                'first_code' => 'J',
                'second_code' => 'P',
                'side_labels' => [
                    'J' => $zh ? '判断' : 'Judging',
                    'P' => $zh ? '感知' : 'Perceiving',
                ],
            ],
            'AT' => [
                'axis_label' => $zh ? '稳定度' : 'Identity',
                'first_code' => 'A',
                'second_code' => 'T',
                'side_labels' => [
                    'A' => $zh ? '果断' : 'Assertive',
                    'T' => $zh ? '敏感' : 'Turbulent',
                ],
            ],
        ];
    }

    private function resolvePrimaryCtaLabel(string $locale): string
    {
        return $locale === 'zh-CN' ? '开始测试' : 'Take the test';
    }

    private function resolvePrimaryCtaPath(string $scaleCode, string $locale, int $orgId): string
    {
        $row = $this->scaleRegistry->getByCode($scaleCode, $orgId);
        if (! is_array($row) && $orgId > 0) {
            $row = $this->scaleRegistry->getByCode($scaleCode, 0);
        }

        $slug = trim((string) ($row['primary_slug'] ?? ''));
        if ($slug === '') {
            $slug = strtolower(trim($scaleCode)) ?: 'mbti-personality-test-16-personality-types';
        }

        $segment = $locale === 'zh-CN' ? 'zh' : 'en';

        return '/'.$segment.'/tests/'.rawurlencode($slug);
    }

    private function buildCanonicalPayload(
        ?Share $share,
        Attempt $attempt,
        Result $result,
        ?string $shareId,
        ?string $createdAt
    ): array {
        $publicSafeReport = $this->buildPublicSafeReportSnapshot($attempt, $result);
        $summary = $this->buildShareSummary($share, $attempt, $result, $publicSafeReport);
        $resolvedShareId = trim((string) $shareId);
        $locale = (string) ($summary['locale'] ?? 'zh-CN');
        $compareEnabled = strtoupper((string) ($summary['scale_code'] ?? '')) === 'MBTI';
        $payload = [
            'share_id' => $resolvedShareId !== '' ? $resolvedShareId : null,
            'share_url' => $resolvedShareId !== '' ? $this->buildLocalizedShareUrl($resolvedShareId, $locale) : null,
            'attempt_id' => (string) $attempt->id,
            'created_at' => $createdAt,
            'org_id' => (int) ($attempt->org_id ?? 0),
            'content_package_version' => (string) ($attempt->content_package_version ?? $result->content_package_version ?? ''),
            'type_code' => $summary['type_code'],
            'type_name' => $summary['type_name'],
            'id' => $resolvedShareId !== '' ? $resolvedShareId : null,
            'scale_code' => $summary['scale_code'],
            'locale' => $locale,
            'title' => $summary['title'],
            'subtitle' => $summary['subtitle'],
            'summary' => $summary['summary'],
            'tagline' => $summary['tagline'],
            'rarity' => $summary['rarity'],
            'tags' => $summary['tags'],
            'dimensions' => $summary['dimensions'],
            'primary_cta_label' => $summary['primary_cta_label'],
            'primary_cta_path' => $summary['primary_cta_path'],
            'compare_enabled' => $compareEnabled,
            'compare_cta_label' => $compareEnabled
                ? $this->resolveCompareCtaLabel($locale)
                : null,
        ];

        if ($compareEnabled) {
            $payload['mbti_public_summary_v1'] = $this->mbtiPublicSummaryV1Builder->buildFromSharePayload(
                $payload,
                $publicSafeReport,
                $locale
            );
            $payload['mbti_public_projection_v1'] = $this->mbtiPublicProjectionService->buildForSharePayload(
                $payload,
                $locale,
                (int) ($attempt->org_id ?? 0),
                $publicSafeReport,
                $this->normalizeArray($result->result_json ?? null)
            );
            $payload = $this->applyMbtiProjectionAliases($payload);
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function applyMbtiProjectionAliases(array $payload): array
    {
        $projection = is_array($payload['mbti_public_projection_v1'] ?? null)
            ? $payload['mbti_public_projection_v1']
            : [];

        if ($projection === []) {
            return $payload;
        }

        $publicTags = is_array(data_get($projection, 'summary_card.public_tags'))
            ? array_values(data_get($projection, 'summary_card.public_tags'))
            : [];
        $profileKeywords = is_array(data_get($projection, 'profile.keywords'))
            ? array_values(data_get($projection, 'profile.keywords'))
            : [];

        $payload['type_code'] = (string) (data_get($projection, 'display_type') ?? $payload['type_code'] ?? '');
        $payload['type_name'] = data_get($projection, 'profile.type_name') ?? $payload['type_name'] ?? null;
        $payload['title'] = data_get($projection, 'summary_card.title') ?? $payload['title'] ?? null;
        $payload['subtitle'] = data_get($projection, 'summary_card.subtitle') ?? $payload['subtitle'] ?? null;
        $payload['summary'] = data_get($projection, 'summary_card.summary') ?? $payload['summary'] ?? null;
        $payload['tagline'] = data_get($projection, 'summary_card.tagline') ?? $payload['tagline'] ?? null;
        $payload['rarity'] = data_get($projection, 'profile.rarity') ?? $payload['rarity'] ?? null;
        $payload['tags'] = $publicTags !== [] ? $publicTags : $profileKeywords;
        $payload['dimensions'] = is_array($projection['dimensions'] ?? null)
            ? array_values($projection['dimensions'])
            : [];

        return $payload;
    }

    private function buildLocalizedShareUrl(string $shareId, string $locale): string
    {
        $segment = $locale === 'zh-CN' ? 'zh' : 'en';

        return rtrim((string) config('app.frontend_url', 'http://localhost'), '/').'/'.$segment.'/share/'.$shareId;
    }

    private function resolveCompareCtaLabel(string $locale): string
    {
        return $locale === 'zh-CN' ? '邀请朋友来测并对比' : 'Invite a friend to compare';
    }
}
