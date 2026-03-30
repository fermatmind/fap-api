<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\ContentGovernance;
use App\Models\IntentRegistry;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

final class IntentRegistryService
{
    public const RESOLUTION_CANONICAL = 'canonical';

    public const RESOLUTION_MERGE_TO_CANONICAL = 'merge_to_canonical';

    public const RESOLUTION_EXCEPTION_REQUESTED = 'exception_requested';

    private const SIMILARITY_THRESHOLD = 0.82;

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $signals
     */
    public static function assertNoConflict(
        Model|string $subject,
        array $state,
        array $signals,
        int $orgId,
        ?Model $record = null,
    ): void {
        $pageType = trim((string) ($state['page_type'] ?? ContentGovernanceService::PAGE_TYPE_GUIDE));
        $primaryQuery = self::normalizeText($state['primary_query'] ?? null);
        if ($primaryQuery === '') {
            return;
        }

        $subjectSignals = self::candidateSignalsFromInput($subject, $state, $signals);
        $bestMatch = null;

        $candidates = IntentRegistry::query()
            ->withoutGlobalScopes()
            ->where('org_id', max(0, $orgId))
            ->where('primary_query', '<>', '')
            ->orderBy('id')
            ->get();

        foreach ($candidates as $candidate) {
            if (! $candidate instanceof IntentRegistry) {
                continue;
            }

            if (
                $record instanceof Model
                && (string) $candidate->governable_type === $record::class
                && (int) $candidate->governable_id === (int) $record->getKey()
            ) {
                continue;
            }

            $candidateRecord = self::resolveGovernableRecord($candidate);
            if (! $candidateRecord instanceof Model) {
                continue;
            }

            self::loadCandidateRelations($candidateRecord);
            $candidateGovernance = self::candidateGovernance($candidateRecord, $candidate);
            $candidateSignals = self::candidateSignalsFromRecord($candidateRecord, $candidateGovernance);
            $score = self::similarityScore($subjectSignals, $candidateSignals, $pageType);

            if ($bestMatch === null || $score > $bestMatch['score']) {
                $bestMatch = [
                    'score' => $score,
                    'candidate' => $candidate,
                    'record' => $candidateRecord,
                    'governance' => $candidateGovernance,
                ];
            }
        }

        if (! is_array($bestMatch) || (float) $bestMatch['score'] < self::SIMILARITY_THRESHOLD) {
            return;
        }

        if (
            $record instanceof Model
            && self::canonicalTargetMatchesCandidate($record, $state, $bestMatch['candidate'])
        ) {
            return;
        }

        if (self::exceptionApproved($state)) {
            return;
        }

        /** @var IntentRegistry $candidate */
        $candidate = $bestMatch['candidate'];
        /** @var Model $candidateRecord */
        $candidateRecord = $bestMatch['record'];

        $typeLabel = class_basename((string) $candidate->governable_type);
        $title = trim((string) data_get($candidateRecord, 'title', 'Untitled'));
        $scoreLabel = number_format((float) $bestMatch['score'], 3);

        throw new InvalidArgumentException(
            'intent_conflict_detected: '
            .'existing='.$typeLabel.'#'.(int) $candidate->governable_id
            .' title="'.$title.'"'
            .' similarity='.$scoreLabel
            .'; actions=merge_into_canonical|convert_to_h2'
            .'|request_exception'
            .' using workspace_governance.intent_exception_requested=true'
            .' and workspace_governance.intent_exception_reason.'
        );
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public static function sync(Model $record, array $state): ?IntentRegistry
    {
        $primaryQuery = self::normalizeText($state['primary_query'] ?? null);

        /** @var IntentRegistry|null $registry */
        $registry = $record->relationLoaded('intentRegistry')
            ? $record->getRelation('intentRegistry')
            : $record->intentRegistry()->first();

        if ($primaryQuery === '') {
            if ($registry instanceof IntentRegistry) {
                $registry->delete();
            }

            return null;
        }

        if (! $registry instanceof IntentRegistry) {
            $registry = new IntentRegistry;
            $registry->governable()->associate($record);
        }

        $pageType = trim((string) ($state['page_type'] ?? ContentGovernanceService::defaultStateFor($record)['page_type']));
        if ($pageType === '') {
            $pageType = ContentGovernanceService::PAGE_TYPE_GUIDE;
        }

        $canonicalRecord = self::resolveCanonicalTargetRecord($record, $state);

        $resolutionStrategy = self::exceptionApproved($state)
            ? self::RESOLUTION_EXCEPTION_REQUESTED
            : (
                $canonicalRecord instanceof Model && (int) $canonicalRecord->getKey() !== (int) $record->getKey()
                    ? self::RESOLUTION_MERGE_TO_CANONICAL
                    : self::RESOLUTION_CANONICAL
            );

        $registry->forceFill([
            'org_id' => max(0, (int) data_get($record, 'org_id', 0)),
            'page_type' => $pageType,
            'primary_query' => $primaryQuery,
            'resolution_strategy' => $resolutionStrategy,
            'exception_reason' => $resolutionStrategy === self::RESOLUTION_EXCEPTION_REQUESTED
                ? self::normalizeFreeText($state['intent_exception_reason'] ?? null)
                : null,
            'latest_similarity_score' => null,
        ]);
        $registry->canonicalGovernable()->associate($canonicalRecord instanceof Model ? $canonicalRecord : $record);
        $registry->save();

        $record->setRelation('intentRegistry', $registry);

        return $registry;
    }

    /**
     * @return array<string, bool|string|null>
     */
    public static function stateFromRecord(Model $record): array
    {
        /** @var IntentRegistry|null $registry */
        $registry = $record->relationLoaded('intentRegistry')
            ? $record->getRelation('intentRegistry')
            : $record->intentRegistry()->first();

        return [
            'intent_exception_requested' => $registry instanceof IntentRegistry
                && (string) $registry->resolution_strategy === self::RESOLUTION_EXCEPTION_REQUESTED,
            'intent_exception_reason' => $registry instanceof IntentRegistry
                ? self::normalizeFreeText($registry->exception_reason)
                : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private static function exceptionApproved(array $state): bool
    {
        $requested = filter_var($state['intent_exception_requested'] ?? false, FILTER_VALIDATE_BOOL);
        $reason = self::normalizeFreeText($state['intent_exception_reason'] ?? null);

        return $requested && mb_strlen($reason) >= 12;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private static function canonicalTargetMatchesCandidate(Model $record, array $state, IntentRegistry $candidate): bool
    {
        $target = self::resolveCanonicalTargetRecord($record, $state);
        if (! $target instanceof Model) {
            return false;
        }

        return (string) $candidate->canonical_governable_type === $target::class
            && (int) $candidate->canonical_governable_id === (int) $target->getKey();
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private static function resolveCanonicalTargetRecord(Model $record, array $state): ?Model
    {
        $canonicalTarget = self::normalizeCanonicalTarget($state['canonical_target'] ?? null);
        if ($canonicalTarget === '') {
            return null;
        }

        $governanceQuery = ContentGovernance::query()
            ->withoutGlobalScopes()
            ->where('org_id', max(0, (int) data_get($record, 'org_id', 0)))
            ->whereNotNull('canonical_target');

        /** @var ContentGovernance|null $governance */
        $governance = $governanceQuery
            ->get()
            ->first(static function (ContentGovernance $candidate) use ($canonicalTarget): bool {
                $candidateTarget = self::normalizeCanonicalTarget($candidate->canonical_target);

                return $candidateTarget !== '' && $candidateTarget === $canonicalTarget;
            });

        if (! $governance instanceof ContentGovernance) {
            return null;
        }

        $class = trim((string) $governance->governable_type);
        if ($class === '' || ! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return null;
        }

        /** @var class-string<Model> $class */
        return $class::query()
            ->withoutGlobalScopes()
            ->find((int) $governance->governable_id);
    }

    private static function resolveGovernableRecord(IntentRegistry $registry): ?Model
    {
        $class = trim((string) $registry->governable_type);
        if ($class === '' || ! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return null;
        }

        /** @var class-string<Model> $class */
        return $class::query()
            ->withoutGlobalScopes()
            ->find((int) $registry->governable_id);
    }

    private static function loadCandidateRelations(Model $record): void
    {
        if (! method_exists($record, 'loadMissing')) {
            return;
        }

        $relations = [];
        if (method_exists($record, 'governance')) {
            $relations[] = 'governance';
        }
        if (method_exists($record, 'sections')) {
            $relations[] = 'sections';
        }
        if (method_exists($record, 'entries')) {
            $relations[] = 'entries';
        }

        if ($relations !== []) {
            $record->loadMissing($relations);
        }
    }

    private static function candidateGovernance(Model $record, IntentRegistry $registry): ?ContentGovernance
    {
        $governance = method_exists($record, 'governance')
            ? ($record->relationLoaded('governance') ? $record->getRelation('governance') : $record->governance()->first())
            : null;

        return $governance instanceof ContentGovernance
            ? $governance
            : new ContentGovernance([
                'page_type' => $registry->page_type,
                'primary_query' => $registry->primary_query,
            ]);
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $signals
     * @return array<string, mixed>
     */
    private static function candidateSignalsFromInput(Model|string $subject, array $state, array $signals): array
    {
        $body = self::stringValue($signals, ['content_md', 'body_md', 'hero_summary_md', 'body_html']);
        if ($body === '') {
            $body = self::sectionsToText($signals['sections'] ?? []);
        }

        return [
            'type' => is_string($subject) ? class_basename($subject) : class_basename($subject::class),
            'title' => self::normalizeText($signals['title'] ?? null),
            'slug' => self::normalizeText($signals['slug'] ?? null),
            'primary_query' => self::normalizeText($state['primary_query'] ?? null),
            'page_type' => self::normalizeText($state['page_type'] ?? null),
            'headings' => self::extractHeadings($body),
            'hub_ref' => self::normalizeText($state['hub_ref'] ?? null),
            'test_binding' => self::normalizeText($state['test_binding'] ?? null),
            'method_binding' => self::normalizeText($state['method_binding'] ?? null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function candidateSignalsFromRecord(Model $record, ?ContentGovernance $governance): array
    {
        $body = self::stringValue((array) $record->getAttributes(), ['content_md', 'body_md', 'hero_summary_md', 'body_html']);
        if ($body === '') {
            $body = self::sectionsToText(data_get($record, 'sections', []));
        }

        return [
            'type' => class_basename($record::class),
            'title' => self::normalizeText(data_get($record, 'title')),
            'slug' => self::normalizeText(data_get($record, 'slug')),
            'primary_query' => self::normalizeText(data_get($governance, 'primary_query')),
            'page_type' => self::normalizeText(data_get($governance, 'page_type')),
            'headings' => self::extractHeadings($body),
            'hub_ref' => self::normalizeText(data_get($governance, 'hub_ref')),
            'test_binding' => self::normalizeText(data_get($governance, 'test_binding')),
            'method_binding' => self::normalizeText(data_get($governance, 'method_binding')),
        ];
    }

    /**
     * @param  array<string, mixed>  $subject
     * @param  array<string, mixed>  $candidate
     */
    private static function similarityScore(array $subject, array $candidate, string $pageType): float
    {
        $query = self::tokenSimilarity((string) $subject['primary_query'], (string) $candidate['primary_query']);
        $title = self::tokenSimilarity((string) $subject['title'], (string) $candidate['title']);
        $slug = self::tokenSimilarity((string) $subject['slug'], (string) $candidate['slug']);
        $outline = self::tokenSimilarity(
            implode(' ', (array) $subject['headings']),
            implode(' ', (array) $candidate['headings'])
        );
        $bindingOverlap = self::bindingOverlapScore($subject, $candidate);
        $pageTypeBoost = (string) $candidate['page_type'] === $pageType ? 1.0 : 0.0;

        $score = ($query * 0.42)
            + ($title * 0.24)
            + ($slug * 0.10)
            + ($outline * 0.10)
            + ($bindingOverlap * 0.10)
            + ($pageTypeBoost * 0.04);

        return round(min(1.0, max(0.0, $score)), 3);
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     */
    private static function bindingOverlapScore(array $left, array $right): float
    {
        $leftBindings = array_values(array_filter([
            (string) ($left['hub_ref'] ?? ''),
            (string) ($left['test_binding'] ?? ''),
            (string) ($left['method_binding'] ?? ''),
        ]));
        $rightBindings = array_values(array_filter([
            (string) ($right['hub_ref'] ?? ''),
            (string) ($right['test_binding'] ?? ''),
            (string) ($right['method_binding'] ?? ''),
        ]));

        if ($leftBindings === [] || $rightBindings === []) {
            return 0.0;
        }

        $intersection = array_values(array_intersect($leftBindings, $rightBindings));
        $union = array_values(array_unique(array_merge($leftBindings, $rightBindings)));

        return $union === [] ? 0.0 : round(count($intersection) / count($union), 3);
    }

    private static function tokenSimilarity(string $left, string $right): float
    {
        $leftTokens = self::tokenize($left);
        $rightTokens = self::tokenize($right);

        if ($leftTokens === [] || $rightTokens === []) {
            return 0.0;
        }

        $intersection = array_values(array_intersect($leftTokens, $rightTokens));
        $score = (2 * count($intersection)) / (count($leftTokens) + count($rightTokens));

        return round($score, 3);
    }

    /**
     * @return list<string>
     */
    private static function tokenize(?string $value): array
    {
        $normalized = self::normalizeText($value);
        if ($normalized === '') {
            return [];
        }

        $stopWords = [
            'a',
            'an',
            'and',
            'for',
            'how',
            'in',
            'of',
            'on',
            'the',
            'to',
            'with',
        ];

        $tokens = preg_split('/[^a-z0-9]+/i', $normalized) ?: [];
        $tokens = array_values(array_filter(array_map(
            static function (string $token) use ($stopWords): string {
                $token = trim($token);
                if ($token === '' || in_array($token, $stopWords, true)) {
                    return '';
                }

                if (strlen($token) > 3 && str_ends_with($token, 's')) {
                    $token = rtrim($token, 's');
                }

                return $token;
            },
            $tokens
        )));

        return array_values(array_unique($tokens));
    }

    /**
     * @return list<string>
     */
    private static function extractHeadings(?string $value): array
    {
        $text = trim((string) $value);
        if ($text === '') {
            return [];
        }

        preg_match_all('/^#{1,6}\s+(.+)$/m', $text, $matches);
        $headings = $matches[1] ?? [];

        return array_values(array_filter(array_map(
            static fn (string $heading): string => self::normalizeText($heading),
            $headings
        )));
    }

    /**
     * @param  array<int, mixed>|mixed  $sections
     */
    private static function sectionsToText(mixed $sections): string
    {
        if (! is_iterable($sections)) {
            return '';
        }

        $parts = [];
        foreach ($sections as $section) {
            $parts[] = trim((string) data_get($section, 'title', ''));
            $parts[] = trim((string) data_get($section, 'body_md', ''));
            $parts[] = trim((string) data_get($section, 'body_html', ''));
        }

        return trim(implode("\n", array_filter($parts)));
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  list<string>  $keys
     */
    private static function stringValue(array $source, array $keys): string
    {
        foreach ($keys as $key) {
            $value = self::normalizeText($source[$key] ?? null);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private static function normalizeText(mixed $value): string
    {
        $text = trim((string) ($value ?? ''));
        if ($text === '') {
            return '';
        }

        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return mb_strtolower(trim($text));
    }

    private static function normalizeFreeText(mixed $value): string
    {
        $text = trim((string) ($value ?? ''));
        if ($text === '') {
            return '';
        }

        return trim((string) (preg_replace('/\s+/u', ' ', $text) ?? $text));
    }

    private static function normalizeCanonicalTarget(mixed $value): string
    {
        $text = self::normalizeFreeText($value);
        if ($text === '') {
            return '';
        }

        if (preg_match('/^https?:\/\//i', $text) === 1) {
            $path = parse_url($text, PHP_URL_PATH);
            $path = is_string($path) ? trim($path, '/') : '';

            return $path === '' ? '/' : '/'.$path;
        }

        $trimmed = trim($text);
        if ($trimmed === '') {
            return '';
        }

        if ($trimmed[0] !== '/') {
            $trimmed = '/'.$trimmed;
        }

        return rtrim($trimmed, '/') ?: '/';
    }
}
