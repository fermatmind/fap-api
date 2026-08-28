<?php

declare(strict_types=1);

namespace App\Domain\Career\Display;

use App\Models\ContentMaterialDecision;
use App\Services\Career\Review\CareerJobDetailReaderSafeReviewProjector;
use App\Services\SeoIntel\MaterialFingerprint\MaterialFingerprintV1;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final class CareerMaterialDecisionService
{
    public const FAMILY = 'career';

    public const AUTHORITY_REVISION_KIND = 'career_sharded_current';

    /** @var list<string> */
    private const CURRENT_MODULES = [
        'identity', 'definition', 'salary', 'geo', 'ai-impact', 'fit-personality',
        'risk', 'compare-links', 'faq', 'page-meta',
    ];

    public function __construct(
        private readonly CareerCurrentAuthorityPackage $package,
        private readonly CareerJobDetailReaderSafeReviewProjector $readerSafeProjector,
        private readonly MaterialFingerprintV1 $fingerprint,
    ) {}

    /**
     * @param  array<string,mixed>  $currentRow
     * @param  array<string,mixed>  $previousRow
     * @return array{decision:ContentMaterialDecision,previous_decision_id:int|null,created:bool}
     */
    public function recordPublished(
        string $slug,
        string $locale,
        array $currentRow,
        array $previousRow,
        string $authorityRevision,
        CarbonInterface $effectiveAt,
        string $evidenceRef,
    ): array {
        $this->assertInsideTransaction();
        $this->assertIdentity($slug, $locale, $currentRow, $previousRow, $authorityRevision, $evidenceRef);

        $latest = $this->latestForUpdate($slug, $locale);
        $materialFingerprint = $this->fingerprint->fingerprint($this->materialPayload($currentRow, $slug, $locale));
        if ($latest instanceof ContentMaterialDecision
            && (string) $latest->publication_state === 'published'
            && (string) $latest->authority_revision === $authorityRevision
            && hash_equals((string) $latest->material_fingerprint, $materialFingerprint)) {
            return ['decision' => $latest, 'previous_decision_id' => $latest->id, 'created' => false];
        }

        $previousDecisionId = $latest?->id;
        $knownPublished = $latest instanceof ContentMaterialDecision
            && (string) $latest->publication_state === 'published'
            && preg_match('/\A[a-f0-9]{64}\z/', (string) $latest->material_fingerprint) === 1;
        $previousFingerprint = $knownPublished
            ? (string) $latest->material_fingerprint
            : $this->fingerprint->fingerprint($this->materialPayload($previousRow, $slug, $locale));
        $sameFingerprint = hash_equals($previousFingerprint, $materialFingerprint);
        $materialChangedAt = $sameFingerprint
            ? ($knownPublished ? $latest?->material_changed_at : null)
            : $effectiveAt;

        $decision = ContentMaterialDecision::query()->create([
            ...$this->identity($slug, $locale),
            'previous_public_identity' => $latest?->public_identity ?? $this->publicIdentity($slug, $locale),
            'authority_revision_kind' => self::AUTHORITY_REVISION_KIND,
            'authority_revision' => $authorityRevision,
            'material_fingerprint' => $materialFingerprint,
            'previous_material_fingerprint' => $previousFingerprint,
            'publication_state' => 'published',
            'operation' => 'publish',
            'decision_code' => match (true) {
                ! $latest instanceof ContentMaterialDecision && $sameFingerprint => 'unchanged_legacy_baseline',
                ! $latest instanceof ContentMaterialDecision => 'initial_material_change',
                $sameFingerprint => 'unchanged_republish',
                default => 'material_change',
            },
            'material_changed' => ! $sameFingerprint,
            'material_changed_at' => $materialChangedAt,
            'evidence_ref' => $evidenceRef,
            'decision_key' => $this->decisionKey(
                $slug, $locale, $previousDecisionId, $authorityRevision, $materialFingerprint, 'publish',
            ),
        ]);

        return ['decision' => $decision, 'previous_decision_id' => $previousDecisionId, 'created' => true];
    }

    public function recordCompensated(
        int $publishedDecisionId,
        ?int $previousDecisionId,
        CarbonInterface $effectiveAt,
    ): ContentMaterialDecision {
        $this->assertInsideTransaction();
        $published = ContentMaterialDecision::query()->whereKey($publishedDecisionId)->lockForUpdate()->firstOrFail();
        $slug = $this->slugFromPublicIdentity((string) $published->public_identity, (string) $published->locale);
        $latest = $this->latestForUpdate($slug, (string) $published->locale);
        if (! $latest instanceof ContentMaterialDecision || (int) $latest->id !== (int) $published->id) {
            throw new RuntimeException('Career material decision compensation state drifted.');
        }

        $previous = $previousDecisionId === null
            ? null
            : ContentMaterialDecision::query()->whereKey($previousDecisionId)->lockForUpdate()->first();
        if ($previousDecisionId !== null && ! $previous instanceof ContentMaterialDecision) {
            throw new RuntimeException('Career material decision compensation predecessor is missing.');
        }
        $restoredFingerprint = $previous instanceof ContentMaterialDecision
            ? $previous->material_fingerprint
            : $published->previous_material_fingerprint;

        return ContentMaterialDecision::query()->create([
            ...$this->identity($slug, (string) $published->locale),
            'previous_public_identity' => $published->public_identity,
            'authority_revision_kind' => self::AUTHORITY_REVISION_KIND,
            'authority_revision' => (string) ($previous?->authority_revision ?? 'legacy-baseline'),
            'material_fingerprint' => $restoredFingerprint,
            'previous_material_fingerprint' => $published->material_fingerprint,
            'publication_state' => (string) ($previous?->publication_state ?? 'published'),
            'operation' => 'rollback',
            'decision_code' => $previous instanceof ContentMaterialDecision
                ? 'publish_compensated'
                : 'publish_compensated_to_legacy_baseline',
            'material_changed' => false,
            'material_changed_at' => $previous?->material_changed_at,
            'evidence_ref' => 'career-current:compensation:'.$published->id,
            'decision_key' => $this->decisionKey(
                $slug,
                (string) $published->locale,
                (int) $published->id,
                (string) ($previous?->authority_revision ?? 'legacy-baseline'),
                is_string($restoredFingerprint) ? $restoredFingerprint : 'unknown',
                'rollback',
            ),
            'created_at' => $effectiveAt,
            'updated_at' => $effectiveAt,
        ]);
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    public function materialPayload(array $row, string $slug, string $locale): array
    {
        $projection = $this->readerSafeProjector->project($this->package->publicProjection($row, $locale));
        $localizedKey = $locale === 'en' ? 'en' : 'zh';
        $structuredData = (array) ($row['structured_data_json'] ?? []);
        $faqPages = is_array($structuredData['faq_page'] ?? null) ? $structuredData['faq_page'] : [];
        $structuredData['faq_page'] = $faqPages[$localizedKey] ?? null;

        return [
            'family' => self::FAMILY,
            'locale' => $locale,
            'public_identity' => $this->publicIdentity($slug, $locale),
            'authority_revision_kind' => self::AUTHORITY_REVISION_KIND,
            'visible_content' => [
                'page' => data_get($projection, 'page.content', []),
                'presentation_v1' => $projection['presentation_v1'] ?? null,
                'presentation_v2' => $projection['presentation_v2'] ?? null,
            ],
            'claims_and_sources' => [
                'claim_bindings' => data_get(
                    $row,
                    'metadata_json.structured_components_v1.locales.'.$locale,
                    [],
                ),
                'visible_sources' => $projection['sources'] ?? [],
            ],
            'search_surface' => [
                'seo' => data_get($row, 'seo_payload_json.'.$localizedKey, []),
                'structured_data' => $structuredData,
            ],
            'locale_linkage' => [
                'counterpart_public_identity' => $this->publicIdentity(
                    $slug,
                    $locale === 'en' ? 'zh-CN' : 'en',
                ),
            ],
            'public_structure' => [
                'asset_role' => $projection['asset_role'] ?? null,
                'asset_type' => $projection['asset_type'] ?? null,
                'authority_modules' => self::CURRENT_MODULES,
                'available_locales' => $projection['available_locales'] ?? [],
                'component_order' => $projection['component_order'] ?? [],
                'implementation_contract' => $projection['implementation_contract'] ?? [],
                'status' => $projection['status'] ?? null,
                'surface_version' => $projection['surface_version'] ?? null,
            ],
            'non_material_context' => [
                'import_run_id' => $row['import_run_id'] ?? null,
                'metadata' => $row['metadata_json'] ?? null,
            ],
        ];
    }

    /** @return array{org_id:int,family:string,locale:string,authority_subject_key:string,public_identity:string} */
    private function identity(string $slug, string $locale): array
    {
        return [
            'org_id' => 0,
            'family' => self::FAMILY,
            'locale' => $locale,
            'authority_subject_key' => hash('sha256', $slug),
            'public_identity' => $this->publicIdentity($slug, $locale),
        ];
    }

    private function publicIdentity(string $slug, string $locale): string
    {
        return '/'.($locale === 'en' ? 'en' : 'zh').'/career/jobs/'.$slug;
    }

    private function latestForUpdate(string $slug, string $locale): ?ContentMaterialDecision
    {
        return ContentMaterialDecision::query()
            ->where('org_id', 0)
            ->where('family', self::FAMILY)
            ->where('locale', $locale)
            ->where('authority_subject_key', hash('sha256', $slug))
            ->latest('id')
            ->lockForUpdate()
            ->first();
    }

    /** @param array<string,mixed> $currentRow @param array<string,mixed> $previousRow */
    private function assertIdentity(
        string $slug,
        string $locale,
        array $currentRow,
        array $previousRow,
        string $authorityRevision,
        string $evidenceRef,
    ): void {
        if (preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $slug) !== 1
            || ! in_array($locale, CareerCurrentAuthorityPackage::LOCALES, true)
            || ($currentRow['canonical_slug'] ?? null) !== $slug
            || ($previousRow['canonical_slug'] ?? null) !== $slug
            || preg_match('/\A[a-f0-9]{64}\z/', $authorityRevision) !== 1
            || preg_match('/\A[A-Za-z0-9:._-]+\z/', $evidenceRef) !== 1
            || mb_strlen($evidenceRef) > 191) {
            throw new InvalidArgumentException('Career material decision identity or evidence is invalid.');
        }
    }

    private function assertInsideTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException('Career material decisions must be recorded inside the Current publish transaction.');
        }
    }

    private function slugFromPublicIdentity(string $publicIdentity, string $locale): string
    {
        $localePath = $locale === 'en' ? 'en' : 'zh';
        if (preg_match(
            '/\A\/'.$localePath.'\/career\/jobs\/([a-z0-9]+(?:-[a-z0-9]+)*)\z/',
            $publicIdentity,
            $matches,
        ) !== 1) {
            throw new RuntimeException('Career material decision public identity is invalid.');
        }

        return $matches[1];
    }

    private function decisionKey(
        string $slug,
        string $locale,
        ?int $previousDecisionId,
        string $authorityRevision,
        string $materialFingerprint,
        string $operation,
    ): string {
        return hash('sha256', implode('|', [
            '0', self::FAMILY, $locale, $this->publicIdentity($slug, $locale),
            (string) ($previousDecisionId ?? 'initial'), $authorityRevision,
            $materialFingerprint, 'published', $operation,
        ]));
    }
}
