<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Filament\Ops\Support\EditorialReviewAudit;
use App\Models\AdminUser;
use App\Models\Article;
use App\Models\CareerGuide;
use App\Models\CareerJob;
use App\Models\ContentGovernance;
use App\Models\DataPage;
use App\Models\IntentRegistry;
use App\Models\MethodPage;
use App\Models\PersonalityProfile;
use App\Models\TopicProfile;
use Illuminate\Database\Eloquent\Model;

final class ContentGovernanceService
{
    public const PAGE_TYPE_HUB = 'hub';

    public const PAGE_TYPE_ENTITY = 'entity';

    public const PAGE_TYPE_METHOD = 'method';

    public const PAGE_TYPE_DATA = 'data';

    public const PAGE_TYPE_GUIDE = 'guide';

    public const PAGE_TYPE_TEST = 'test';

    public const CTA_STAGE_DISCOVER = 'discover';

    public const CTA_STAGE_COMPARE = 'compare';

    public const CTA_STAGE_DECIDE = 'decide';

    public const PUBLISH_GATE_DRAFT = 'draft';

    /**
     * @return array<string, string>
     */
    public static function pageTypeOptions(): array
    {
        return [
            self::PAGE_TYPE_HUB => 'Hub',
            self::PAGE_TYPE_ENTITY => 'Entity / Type',
            self::PAGE_TYPE_METHOD => 'Method',
            self::PAGE_TYPE_DATA => 'Data',
            self::PAGE_TYPE_GUIDE => 'Guide',
            self::PAGE_TYPE_TEST => 'Test',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function publishGateStateOptions(): array
    {
        return [
            self::PUBLISH_GATE_DRAFT => 'Draft',
            EditorialReviewAudit::STATE_READY => EditorialReviewAudit::label(EditorialReviewAudit::STATE_READY),
            EditorialReviewAudit::STATE_IN_REVIEW => EditorialReviewAudit::label(EditorialReviewAudit::STATE_IN_REVIEW),
            EditorialReviewAudit::STATE_APPROVED => EditorialReviewAudit::label(EditorialReviewAudit::STATE_APPROVED),
            EditorialReviewAudit::STATE_CHANGES_REQUESTED => EditorialReviewAudit::label(EditorialReviewAudit::STATE_CHANGES_REQUESTED),
            EditorialReviewAudit::STATE_REJECTED => EditorialReviewAudit::label(EditorialReviewAudit::STATE_REJECTED),
            EditorialReviewAudit::STATE_NEEDS_ATTENTION => EditorialReviewAudit::label(EditorialReviewAudit::STATE_NEEDS_ATTENTION),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function ctaStageOptions(): array
    {
        return [
            self::CTA_STAGE_DISCOVER => 'Discover',
            self::CTA_STAGE_COMPARE => 'Compare',
            self::CTA_STAGE_DECIDE => 'Decide',
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function adminUserOptions(): array
    {
        return AdminUser::query()
            ->where('is_active', 1)
            ->orderByRaw('LOWER(COALESCE(name, email)) asc')
            ->get(['id', 'name', 'email'])
            ->mapWithKeys(static function (AdminUser $adminUser): array {
                $label = trim($adminUser->name !== '' ? $adminUser->name : (string) $adminUser->email);

                return [(int) $adminUser->id => $label];
            })
            ->all();
    }

    /**
     * @return array<string, int|string|null>
     */
    public static function defaultStateFor(Model|string $subject, ?int $actorAdminId = null): array
    {
        $defaultPageType = self::defaultPageType($subject);

        return self::normalizeState([
            'page_type' => $defaultPageType,
            'cta_stage' => self::defaultCtaStage($defaultPageType),
            'author_admin_user_id' => $actorAdminId,
            'publish_gate_state' => self::PUBLISH_GATE_DRAFT,
            'intent_exception_requested' => false,
            'intent_exception_reason' => null,
        ], $defaultPageType, $actorAdminId);
    }

    /**
     * @return array<string, int|string|null>
     */
    public static function stateFromRecord(Model $record, ?int $actorAdminId = null): array
    {
        $defaultPageType = self::defaultPageType($record);
        $authorAdminId = self::defaultAuthorAdminIdFromRecord($record) ?? $actorAdminId;
        $governance = $record->relationLoaded('governance')
            ? $record->getRelation('governance')
            : $record->governance()->first();

        if ($governance instanceof ContentGovernance) {
            $intentRegistry = $record->relationLoaded('intentRegistry')
                ? $record->getRelation('intentRegistry')
                : (method_exists($record, 'intentRegistry') ? $record->intentRegistry()->first() : null);

            return self::normalizeState([
                'page_type' => $governance->page_type,
                'primary_query' => $governance->primary_query,
                'canonical_target' => $governance->canonical_target,
                'hub_ref' => $governance->hub_ref,
                'test_binding' => $governance->test_binding,
                'method_binding' => $governance->method_binding,
                'cta_stage' => $governance->cta_stage,
                'author_admin_user_id' => $governance->author_admin_user_id,
                'reviewer_admin_user_id' => $governance->reviewer_admin_user_id,
                'publish_gate_state' => $governance->publish_gate_state,
                'intent_exception_requested' => $intentRegistry instanceof IntentRegistry
                    && (string) $intentRegistry->resolution_strategy === IntentRegistryService::RESOLUTION_EXCEPTION_REQUESTED,
                'intent_exception_reason' => $intentRegistry instanceof IntentRegistry
                    ? $intentRegistry->exception_reason
                    : null,
            ], $defaultPageType, $authorAdminId);
        }

        $editorialState = self::editorialStateFromRecord($record);

        return self::normalizeState([
            'page_type' => $defaultPageType,
            'canonical_target' => self::defaultCanonicalTargetFromRecord($record),
            'cta_stage' => self::defaultCtaStage($defaultPageType),
            'author_admin_user_id' => $authorAdminId,
            'reviewer_admin_user_id' => $editorialState['reviewer_admin_user_id'] ?? null,
            'publish_gate_state' => $editorialState['state'] ?? self::PUBLISH_GATE_DRAFT,
            'intent_exception_requested' => false,
            'intent_exception_reason' => null,
        ], $defaultPageType, $authorAdminId);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public static function sync(Model $record, array $state): ContentGovernance
    {
        $payload = self::normalizeState(
            $state,
            self::defaultPageType($record),
            self::defaultAuthorAdminIdFromRecord($record)
        );

        /** @var ContentGovernance|null $governance */
        $governance = $record->relationLoaded('governance')
            ? $record->getRelation('governance')
            : $record->governance()->first();

        if (! $governance instanceof ContentGovernance) {
            $governance = new ContentGovernance;
            $governance->governable()->associate($record);
        }

        $governance->forceFill([
            'org_id' => max(0, (int) data_get($record, 'org_id', 0)),
            'page_type' => (string) $payload['page_type'],
            'primary_query' => $payload['primary_query'],
            'canonical_target' => $payload['canonical_target'],
            'hub_ref' => $payload['hub_ref'],
            'test_binding' => $payload['test_binding'],
            'method_binding' => $payload['method_binding'],
            'cta_stage' => $payload['cta_stage'],
            'author_admin_user_id' => $payload['author_admin_user_id'],
            'reviewer_admin_user_id' => $payload['reviewer_admin_user_id'],
            'publish_gate_state' => (string) $payload['publish_gate_state'],
        ])->save();

        $record->setRelation('governance', $governance);

        return $governance;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function enforceReleaseManagedDraft(array $data): array
    {
        $data['status'] = 'draft';
        $data['is_public'] = false;
        $data['published_at'] = null;

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function preserveReleaseManagedState(Model $record, array $data): array
    {
        $data['status'] = $record->getAttribute('status');
        $data['is_public'] = (bool) $record->getAttribute('is_public');
        $data['published_at'] = $record->getAttribute('published_at');

        return $data;
    }

    private static function defaultPageType(Model|string $subject): string
    {
        return match (true) {
            $subject instanceof TopicProfile,
            $subject === TopicProfile::class => self::PAGE_TYPE_HUB,
            $subject instanceof PersonalityProfile,
            $subject instanceof CareerJob,
            $subject === PersonalityProfile::class,
            $subject === CareerJob::class => self::PAGE_TYPE_ENTITY,
            $subject instanceof MethodPage,
            $subject === MethodPage::class => self::PAGE_TYPE_METHOD,
            $subject instanceof DataPage,
            $subject === DataPage::class => self::PAGE_TYPE_DATA,
            $subject instanceof Article,
            $subject instanceof CareerGuide,
            $subject === Article::class,
            $subject === CareerGuide::class => self::PAGE_TYPE_GUIDE,
            default => self::PAGE_TYPE_GUIDE,
        };
    }

    private static function defaultAuthorAdminIdFromRecord(Model $record): ?int
    {
        foreach (['author_admin_user_id', 'created_by_admin_user_id', 'updated_by_admin_user_id'] as $key) {
            $value = data_get($record, $key);
            if (is_numeric($value) && (int) $value > 0) {
                return (int) $value;
            }
        }

        return null;
    }

    private static function defaultCanonicalTargetFromRecord(Model $record): ?string
    {
        $seoMeta = $record->relationLoaded('seoMeta')
            ? $record->getRelation('seoMeta')
            : (method_exists($record, 'seoMeta') ? $record->seoMeta()->first() : null);

        $canonical = is_object($seoMeta) ? data_get($seoMeta, 'canonical_url') : null;

        return self::normalizeNullableString($canonical);
    }

    /**
     * @return array{state?: string, reviewer_admin_user_id?: int|null}|null
     */
    private static function editorialStateFromRecord(Model $record): ?array
    {
        $type = match (true) {
            $record instanceof Article => 'article',
            $record instanceof CareerGuide => 'guide',
            $record instanceof CareerJob => 'job',
            $record instanceof MethodPage => 'method',
            $record instanceof DataPage => 'data',
            $record instanceof PersonalityProfile => 'personality',
            $record instanceof TopicProfile => 'topic',
            default => null,
        };

        if ($type === null || (int) data_get($record, 'id', 0) <= 0) {
            return null;
        }

        return EditorialReviewAudit::latestState($type, $record);
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, int|string|null>
     */
    private static function normalizeState(array $state, string $defaultPageType, ?int $fallbackAuthorAdminId = null): array
    {
        $pageType = strtolower(trim((string) ($state['page_type'] ?? $defaultPageType)));
        if (! array_key_exists($pageType, self::pageTypeOptions())) {
            $pageType = $defaultPageType;
        }

        $publishGateState = strtolower(trim((string) ($state['publish_gate_state'] ?? self::PUBLISH_GATE_DRAFT)));
        if (! array_key_exists($publishGateState, self::publishGateStateOptions())) {
            $publishGateState = self::PUBLISH_GATE_DRAFT;
        }

        $ctaStage = strtolower(trim((string) ($state['cta_stage'] ?? self::defaultCtaStage($pageType))));
        if (! array_key_exists($ctaStage, self::ctaStageOptions())) {
            $ctaStage = self::defaultCtaStage($pageType);
        }

        $authorAdminUserId = self::normalizeNullableInt($state['author_admin_user_id'] ?? $fallbackAuthorAdminId);
        $reviewerAdminUserId = self::normalizeNullableInt($state['reviewer_admin_user_id'] ?? null);

        return [
            'page_type' => $pageType,
            'primary_query' => self::normalizeNullableString($state['primary_query'] ?? null),
            'canonical_target' => self::normalizeNullableString($state['canonical_target'] ?? null),
            'hub_ref' => self::normalizeNullableString($state['hub_ref'] ?? null),
            'test_binding' => self::normalizeNullableString($state['test_binding'] ?? null),
            'method_binding' => self::normalizeNullableString($state['method_binding'] ?? null),
            'cta_stage' => $ctaStage,
            'author_admin_user_id' => $authorAdminUserId,
            'reviewer_admin_user_id' => $reviewerAdminUserId,
            'publish_gate_state' => $publishGateState,
        ];
    }

    private static function defaultCtaStage(string $pageType): string
    {
        return match ($pageType) {
            self::PAGE_TYPE_TEST => self::CTA_STAGE_DECIDE,
            self::PAGE_TYPE_METHOD,
            self::PAGE_TYPE_DATA,
            self::PAGE_TYPE_GUIDE => self::CTA_STAGE_COMPARE,
            default => self::CTA_STAGE_DISCOVER,
        };
    }

    private static function normalizeNullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private static function normalizeNullableInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $normalized = (int) $value;

        return $normalized > 0 ? $normalized : null;
    }
}
