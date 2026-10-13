<?php

namespace App\Services\Scale;

use App\Filament\Ops\Resources\ScaleRegistryResource;
use App\Models\AuditLog;
use App\Models\ScaleRegistry as ScaleRegistryModel;
use App\Models\ScaleRegistryV2;
use App\Models\ScaleSlug;
use App\Services\Audit\AuditLogger;
use App\Support\CacheKeys;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ScaleRegistryWriter
{
    private const LEGACY_TABLE = 'scales_registry';

    private const V2_TABLE = 'scales_registry_v2';

    public function __construct(
        private PublicScaleCatalogCache $publicScaleCatalogCache,
    ) {}

    public function upsertScale(array $payload, ?array $faqEditContext = null): ScaleRegistryModel
    {
        if ($faqEditContext !== null) {
            return $this->saveSingleFaq($payload, $faqEditContext);
        }
        $code = strtoupper(trim((string) ($payload['code'] ?? '')));
        $orgId = (int) ($payload['org_id'] ?? 0);
        if ($code === '') {
            throw new \InvalidArgumentException('code is required');
        }

        $data = $payload;
        $data['code'] = $code;
        $data['org_id'] = $orgId;
        [$primarySlug, $authoritativeSlugs, $projectedSlugs] = $this->normalizeAuthoritativeSlugs(
            $data['primary_slug'] ?? null,
            $data['slugs_json'] ?? null,
        );
        $data['primary_slug'] = $primarySlug;
        $data['slugs_json'] = $authoritativeSlugs;

        [$scale, $affectedSlugs] = DB::transaction(function () use ($data, $orgId, $code, $primarySlug, $authoritativeSlugs, $projectedSlugs): array {
            $this->assertExistingEqIntroUnchanged($data);

            $affectedSlugs = array_values(array_unique([
                ...$this->existingSlugs($orgId, $code),
                ...$this->existingProjectedSlugs($orgId, $code),
                ...$authoritativeSlugs,
            ]));
            $this->assertSlugOwnershipAvailable($orgId, $code, $projectedSlugs);

            if ($this->useV2Table()) {
                DB::table(self::V2_TABLE)->upsert(
                    [$this->buildV2Row($data)],
                    ['org_id', 'code'],
                    [
                        'primary_slug',
                        'slugs_json',
                        'driver_type',
                        'assessment_driver',
                        'default_pack_id',
                        'default_region',
                        'default_locale',
                        'default_dir_version',
                        'capabilities_json',
                        'view_policy_json',
                        'commercial_json',
                        'seo_schema_json',
                        'seo_i18n_json',
                        'content_i18n_json',
                        'report_summary_i18n_json',
                        'is_public',
                        'is_active',
                        'is_indexable',
                        'updated_at',
                    ]
                );
            }

            $scale = $this->upsertLegacyIfEligible($data);
            if (! $scale) {
                $scale = new ScaleRegistryModel;
                $scale->forceFill($data);
            }

            $this->syncSlugProjection($orgId, $code, $primarySlug, $projectedSlugs);

            return [$scale, $affectedSlugs];
        });

        $this->invalidatePublicProjection($orgId, $code, $affectedSlugs);

        return $scale;
    }

    /** Precision branch of the existing authenticated editor; never accepts a JSON path. */
    private function saveSingleFaq(array $payload, array $context): ScaleRegistryModel
    {
        $this->assertExactKeys($context, ['target', 'baseline', 'form_before_json']);
        $target = $context['target'];
        $this->assertExactKeys($target, ['org_id', 'id', 'code', 'primary_slug']);
        $this->assertExactKeys($context['baseline'], ['v2', 'legacy']);
        if (! is_int($target['org_id']) || $target['org_id'] < 0 || ! is_int($target['id']) || $target['id'] <= 0
            || ! is_string($target['code']) || $target['code'] === '' || ! is_string($target['primary_slug'])
            || ($payload['org_id'] ?? null) !== $target['org_id'] || ($payload['code'] ?? null) !== $target['code']) {
            throw new \DomainException('FAQ target identity mismatch.');
        }
        foreach ($context['baseline'] as $hash) {
            if (! is_string($hash) || ! preg_match('/^[a-f0-9]{64}$/D', $hash)) {
                throw new \DomainException('Invalid FAQ baseline.');
            }
        }
        $record = ScaleRegistryV2::withoutGlobalScopes()->find($target['id']);
        if (! $record || ! ScaleRegistryResource::canEdit($record) || (int) $record->org_id !== $target['org_id']
            || $record->code !== $target['code'] || $record->primary_slug !== $target['primary_slug']) {
            throw new \DomainException('FAQ editing is outside the current authenticated write scope.');
        }
        if (! $this->useV2Table() || ! in_array(DB::connection()->getDriverName(), ['mysql', 'sqlite'], true)
            || ! Schema::hasTable(self::LEGACY_TABLE) || ! Schema::hasTable('audit_logs')) {
            throw new \DomainException('FAQ authority or audit storage is unavailable.');
        }
        if (DB::transactionLevel() !== 0) {
            throw new \DomainException('FAQ save requires its own top-level transaction.');
        }
        if (! is_string($context['form_before_json'])) {
            throw new \DomainException('Invalid FAQ form baseline.');
        }
        $beforeForm = json_decode($context['form_before_json'], false, 512, JSON_THROW_ON_ERROR);
        if (! $beforeForm instanceof \stdClass || ! ($beforeForm->content_i18n_json->zh ?? null) instanceof \stdClass) {
            throw new \DomainException('Invalid FAQ form baseline.');
        }
        $afterForm = $payload;
        unset($beforeForm->content_i18n_json->zh->faq, $afterForm['content_i18n_json']['zh']['faq']);
        if ($this->faqJson($beforeForm) !== $this->faqJson($afterForm)) {
            throw new \DomainException('Save one FAQ only; keep every other form field unchanged.');
        }
        $submitted = $payload['content_i18n_json']['zh']['faq'] ?? null;
        $submitted = json_decode(json_encode($submitted, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), false, 512, JSON_THROW_ON_ERROR);
        $this->assertFaqList($submitted);
        $result = DB::transaction(function () use ($target, $context, $submitted): array {
            $rows = [];
            foreach ([self::V2_TABLE, self::LEGACY_TABLE] as $table) {
                $found = DB::table($table)->where('org_id', $target['org_id'])->where('code', $target['code'])->lockForUpdate()->get();
                if ($found->count() !== 1) {
                    throw new \DomainException('FAQ save requires exactly one existing row in each authority table.');
                }
                $row = (array) $found->first();
                if ((int) $row['org_id'] !== $target['org_id'] || $row['code'] !== $target['code'] || $row['primary_slug'] !== $target['primary_slug']
                    || ($table === self::V2_TABLE && (int) $row['id'] !== $target['id'])) {
                    throw new \DomainException('FAQ authority identity mismatch.');
                }
                $rows[$table] = $row;
            }
            $fingerprints = $this->faqRowHashes($rows);
            if ($fingerprints !== $context['baseline']) {
                throw new \DomainException('The FAQ baseline changed. Reload before editing.');
            }
            if ($rows[self::V2_TABLE]['content_i18n_json'] !== $rows[self::LEGACY_TABLE]['content_i18n_json']) {
                throw new \DomainException('FAQ authority contents differ.');
            }
            $content = json_decode($rows[self::V2_TABLE]['content_i18n_json'], false, 512, JSON_THROW_ON_ERROR);
            $before = $content->zh->faq ?? null;
            $this->assertFaqList($before);
            if (count($submitted) !== count($before)) {
                throw new \DomainException('Keep the existing FAQ count and order.');
            }
            $changed = [];
            foreach ($before as $index => $item) {
                if ($submitted[$index]->id !== $item->id) {
                    throw new \DomainException('Keep every existing FAQ id and order.');
                }
                if ($this->faqJson($submitted[$index]) !== $this->faqJson($item)) {
                    $changed[] = $index;
                }
            }
            if (count($changed) !== 1) {
                throw new \DomainException('Change exactly one existing FAQ.');
            }
            $index = $changed[0];
            $candidate = $this->faqJson($submitted[$index]);
            $expected = json_decode(json_encode($content, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), false, 512, JSON_THROW_ON_ERROR);
            $expected->zh->faq[$index] = $submitted[$index];
            $path = '$.zh.faq['.$index.']'; // Derived integer index, never an input JSON path.
            $mysql = DB::connection()->getDriverName() === 'mysql';
            foreach ([self::V2_TABLE, self::LEGACY_TABLE] as $table) {
                $jsonValue = $mysql ? 'CAST(? AS JSON)' : 'json(?)';
                $count = DB::update("UPDATE {$table} SET content_i18n_json = JSON_SET(content_i18n_json, '{$path}', {$jsonValue}) WHERE org_id = ? AND code = ?", [$candidate, $target['org_id'], $target['code']]);
                if ($count !== 1) {
                    throw new \DomainException('FAQ dual-table update count mismatch.');
                }
            }
            $post = [];
            foreach ([self::V2_TABLE, self::LEGACY_TABLE] as $table) {
                $row = (array) DB::table($table)->where('org_id', $target['org_id'])->where('code', $target['code'])->lockForUpdate()->first();
                if ($this->faqJson(json_decode($row['content_i18n_json'], false, 512, JSON_THROW_ON_ERROR)) !== $this->faqJson($expected)) {
                    throw new \DomainException('FAQ content readback mismatch.');
                }
                $oldProtected = $rows[$table];
                $newProtected = $row;
                unset($oldProtected['content_i18n_json'], $newProtected['content_i18n_json']);
                if ($oldProtected !== $newProtected) {
                    throw new \DomainException('FAQ protected-column readback mismatch.');
                }
                $post[$table] = $row;
            }
            $operationId = (string) Str::uuid();
            $candidateHash = hash('sha256', $candidate);
            $actorId = (int) auth((string) config('admin.guard', 'admin'))->id();
            app(AuditLogger::class)->log(request(), 'scale_registry.faq.updated', 'scale_registry', (string) $target['id'], [
                'operation_id' => $operationId, 'target' => $target, 'faq_id' => $submitted[$index]->id,
                'baseline' => $fingerprints, 'post_baseline' => $this->faqRowHashes($post), 'candidate_sha256' => $candidateHash,
                'before_faq_json' => $this->faqJson($before[$index]), 'after_faq_json' => $candidate,
                'protected_fields_preserved' => true, 'cache_closeout' => 'after_content_commit', 'params_sanitized' => [],
            ], 'single FAQ edit through existing CMS Save', 'content_applied');
            $audit = AuditLog::withoutGlobalScopes()->where('org_id', $target['org_id'])->where('actor_admin_id', $actorId)
                ->where('action', 'scale_registry.faq.updated')->where('target_type', 'scale_registry')->where('target_id', (string) $target['id'])
                ->where('meta_json->operation_id', $operationId)->first();
            if (! $audit || ($audit->meta_json['candidate_sha256'] ?? null) !== $candidateHash
                || ($audit->meta_json['post_baseline'] ?? null) !== $this->faqRowHashes($post)) {
                throw new \DomainException('FAQ audit readback failed; content was not committed.');
            }

            return ['status' => 'APPLIED', 'audit_id' => $audit->getKey(), 'operation_id' => $operationId,
                'candidate_sha256' => $candidateHash, 'post_baseline' => $this->faqRowHashes($post)];
        });
        try {
            $this->invalidatePublicContentProjection($target['org_id'], $target['code'], array_values(array_unique([
                ...$this->existingSlugs($target['org_id'], $target['code']),
                ...$this->existingProjectedSlugs($target['org_id'], $target['code']),
            ])));
        } catch (\Throwable) {
            $result['status'] = 'PARTIAL_CACHE_CLOSEOUT';
        }
        $record->refresh();
        $record->setRelation('faqEditReceipt', $result);

        return $record;
    }

    private function assertFaqList(mixed $items): void
    {
        if (! is_array($items) || ! array_is_list($items)) {
            throw new \DomainException('FAQ must remain a JSON list.');
        }
        $ids = [];
        foreach ($items as $item) {
            if (! $item instanceof \stdClass || ! is_string($item->id ?? null) || trim($item->id) === ''
                || isset($ids[$item->id]) || ! is_string($item->q ?? null) || ! is_string($item->a ?? null)) {
                throw new \DomainException('FAQ contains an invalid or duplicate item.');
            }
            $ids[$item->id] = true;
        }
    }

    private function faqRowHashes(array $rows): array
    {
        return array_map(fn (array $row): string => hash('sha256', json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), [
            'v2' => $rows[self::V2_TABLE], 'legacy' => $rows[self::LEGACY_TABLE],
        ]);
    }

    private function faqJson(mixed $value): string
    {
        $sort = function (mixed $item) use (&$sort): mixed {
            if ($item instanceof \stdClass) {
                $fields = get_object_vars($item);
                ksort($fields, SORT_STRING);

                return (object) array_map($sort, $fields);
            }
            if (is_array($item)) {
                if (! array_is_list($item)) {
                    ksort($item, SORT_STRING);
                }

                return array_map($sort, $item);
            }

            return $item;
        };

        return json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    /** The single public EQ intro seam; never accepts a generic row or JSON patch. */
    public const EQ_INTRO_TARGET = [
        'org_id' => 0,
        'id' => 3,
        'code' => 'EQ_60',
        'primary_slug' => 'eq-test-emotional-intelligence-assessment',
        'field' => 'content_i18n_json.en.why_choose.intro',
    ];

    public function eqIntroBaseline(array $target): array
    {
        $this->assertEqIntroTarget($target);
        [$v2, $legacy, $content] = $this->eqIntroRows(false);

        return [
            'target' => self::EQ_INTRO_TARGET,
            'baseline' => $this->eqIntroFingerprint($v2, $legacy),
            'before' => $content['en']['why_choose']['intro'],
        ];
    }

    /** Plan and execute use exactly the same validation; execution rechecks under both locks. */
    public function updateEqIntro(array $request, bool $execute = false): array
    {
        $this->assertExactKeys($request, ['target', 'baseline', 'before', 'after']);
        $this->assertEqIntroTarget($request['target'] ?? null);
        $this->assertExactKeys($request['baseline'] ?? null, ['v2', 'legacy']);
        foreach ($request['baseline'] as $hash) {
            if (! is_string($hash) || ! preg_match('/^[a-f0-9]{64}$/D', $hash)) {
                throw new \DomainException('Invalid EQ intro baseline.');
            }
        }
        $request['baseline'] = ['v2' => $request['baseline']['v2'], 'legacy' => $request['baseline']['legacy']];
        $before = $request['before'];
        $after = $request['after'];
        if (! is_string($before) || ! is_string($after)
            || ! mb_check_encoding($after, 'UTF-8') || strlen($after) > 8192
            || ! str_starts_with($after, $before."\n\n")
            || trim(substr($after, strlen($before) + 2)) === '') {
            throw new \DomainException('EQ intro must append nonempty text to the exact original.');
        }
        // A final cache outcome cannot be truthfully returned before an outer transaction commits.
        if ($execute && DB::transactionLevel() !== 0) {
            throw new \DomainException('EQ intro execute requires its own top-level transaction.');
        }

        $operation = function () use ($request, $execute, $before, $after): array {
            [$v2, $legacy, $content] = $this->eqIntroRows($execute);
            if ($this->eqIntroFingerprint($v2, $legacy) !== $request['baseline']
                || $content['en']['why_choose']['intro'] !== $before) {
                throw new \DomainException('Stale EQ intro baseline; obtain a fresh plan.');
            }
            $result = ['status' => 'PLAN_READY', 'target' => self::EQ_INTRO_TARGET,
                'baseline' => $request['baseline'], 'before' => $before, 'after' => $after];
            if (! $execute) {
                return $result;
            }

            // Fixed, bound JSON leaf update: no model casts, row upsert, timestamp or slug writes.
            foreach ([self::V2_TABLE, self::LEGACY_TABLE] as $table) {
                $count = DB::update(
                    "UPDATE {$table} SET content_i18n_json = JSON_SET(content_i18n_json, '$.en.why_choose.intro', ?) WHERE org_id = ? AND code = ?",
                    [$after, 0, 'EQ_60'],
                );
                if ($count !== 1) {
                    throw new \DomainException('EQ intro dual-table update count mismatch.');
                }
            }
            [$newV2, $newLegacy, $newContent] = $this->eqIntroRows(true);
            $expected = $content;
            $expected['en']['why_choose']['intro'] = $after;
            // DB JSON serialization can change; every other value/type and column must be identical.
            if ($newContent !== $expected || ! $this->eqIntroContentMatches($v2, $newV2, $after)
                || ! $this->eqIntroProtectedColumnsMatch($v2, $newV2)
                || ! $this->eqIntroProtectedColumnsMatch($legacy, $newLegacy)) {
                throw new \DomainException('EQ intro protected readback mismatch.');
            }
            $result['post_baseline'] = $this->eqIntroFingerprint($newV2, $newLegacy);
            $result['status'] = 'APPLIED';

            return $result;
        };
        if (! $execute) {
            return $operation();
        }
        $result = DB::transaction($operation);
        try {
            $this->invalidateCache(0, 'EQ_EMOTIONAL_INTELLIGENCE');
            $this->invalidatePublicProjection(0, 'EQ_60', $this->existingSlugs(0, 'EQ_60'));
        } catch (\Throwable) {
            // The transaction has committed. Never pretend this is a rollback or retry the body write.
            $result['status'] = 'PARTIAL_CACHE_CLOSEOUT';
        }

        return $result;
    }

    private function assertEqIntroTarget(mixed $target): void
    {
        $this->assertExactKeys($target, array_keys(self::EQ_INTRO_TARGET));
        foreach (self::EQ_INTRO_TARGET as $key => $value) {
            if ($target[$key] !== $value) {
                throw new \DomainException('Unsupported EQ intro target.');
            }
        }
    }

    private function assertExactKeys(mixed $value, array $keys): void
    {
        if (! is_array($value)) {
            throw new \DomainException('Invalid EQ intro contract.');
        }
        $actual = array_keys($value);
        sort($actual);
        sort($keys);
        if ($actual !== $keys) {
            throw new \DomainException('Unexpected EQ intro contract fields.');
        }
    }

    private function eqIntroRows(bool $lock): array
    {
        if (! $this->useV2Table() || ! in_array(DB::connection()->getDriverName(), ['sqlite', 'mysql'], true)) {
            throw new \DomainException('Unsupported EQ intro authority or database.');
        }
        $rows = [];
        foreach ([self::V2_TABLE, self::LEGACY_TABLE] as $table) {
            $query = DB::table($table)->where('org_id', 0)->where('code', 'EQ_60');
            $found = ($lock ? $query->lockForUpdate() : $query)->get();
            if ($found->count() !== 1) {
                throw new \DomainException('EQ intro requires exactly one existing row per table.');
            }
            $row = (array) $found->first();
            if (($row['code'] ?? null) !== self::EQ_INTRO_TARGET['code']
                || ($row['primary_slug'] ?? null) !== self::EQ_INTRO_TARGET['primary_slug']
                || ($table === self::V2_TABLE && (int) ($row['id'] ?? 0) !== 3)) {
                throw new \DomainException('EQ intro identity mismatch.');
            }
            $rows[] = $row;
        }
        if ($rows[0]['content_i18n_json'] !== $rows[1]['content_i18n_json']) {
            throw new \DomainException('EQ intro dual-table content differs.');
        }
        try {
            $content = json_decode($rows[0]['content_i18n_json'], true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new \DomainException('Invalid EQ intro content JSON.');
        }
        if (! is_string($content['en']['why_choose']['intro'] ?? null)) {
            throw new \DomainException('Missing EQ intro string.');
        }

        return [$rows[0], $rows[1], $content];
    }

    private function eqIntroFingerprint(array $v2, array $legacy): array
    {
        return [
            'v2' => hash('sha256', json_encode($v2, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'legacy' => hash('sha256', json_encode($legacy, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        ];
    }

    private function eqIntroContentMatches(array $before, array $after, string $intro): bool
    {
        // Object-mode decoding distinguishes empty objects from lists; preserve numeric types too.
        $expected = json_decode($before['content_i18n_json'], false, 512, JSON_THROW_ON_ERROR);
        $actual = json_decode($after['content_i18n_json'], false, 512, JSON_THROW_ON_ERROR);
        $expected->en->why_choose->intro = $intro;
        $flags = JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

        return json_encode($expected, $flags) === json_encode($actual, $flags);
    }

    private function eqIntroProtectedColumnsMatch(array $before, array $after): bool
    {
        unset($before['content_i18n_json'], $after['content_i18n_json']);

        return $before === $after;
    }

    private function assertExistingEqIntroUnchanged(array $data): void
    {
        if ((int) $data['org_id'] !== 0 || $data['code'] !== 'EQ_60') {
            return;
        }
        foreach ([self::V2_TABLE, self::LEGACY_TABLE] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $row = DB::table($table)->where('org_id', 0)->where('code', 'EQ_60')->lockForUpdate()->first();
            if ($row === null) {
                continue;
            }
            $original = data_get(json_decode((string) $row->content_i18n_json, true), 'en.why_choose.intro');
            $proposed = data_get(json_decode((string) $this->encodeJson($data['content_i18n_json'] ?? null), true), 'en.why_choose.intro');
            if (is_string($original) && $proposed !== $original) {
                throw new \DomainException('Existing EQ_60 intro requires the restricted intro contract.');
            }
        }
    }

    public function syncSlugsForScale(ScaleRegistryModel $scale): void
    {
        $orgId = (int) $scale->org_id;
        $code = (string) $scale->code;
        [$primarySlug, $authoritativeSlugs, $projectedSlugs] = $this->normalizeAuthoritativeSlugs(
            $scale->primary_slug,
            $scale->slugs_json,
        );
        $affectedSlugs = array_values(array_unique([
            ...$this->existingProjectedSlugs($orgId, $code),
            ...$authoritativeSlugs,
        ]));

        DB::transaction(function () use ($orgId, $code, $primarySlug, $projectedSlugs): void {
            $this->assertSlugOwnershipAvailable($orgId, $code, $projectedSlugs);
            $this->syncSlugProjection($orgId, $code, $primarySlug, $projectedSlugs);
        });

        $this->invalidatePublicProjection($orgId, $code, $affectedSlugs);
    }

    /** @param iterable<ScaleRegistryModel> $scales */
    public function rebuildSlugProjections(iterable $scales): void
    {
        $prepared = [];
        foreach ($scales as $scale) {
            $orgId = (int) $scale->org_id;
            $code = strtoupper(trim((string) $scale->code));
            [$primarySlug, $authoritativeSlugs, $projectedSlugs] = $this->normalizeAuthoritativeSlugs(
                $scale->primary_slug,
                $scale->slugs_json,
            );
            $prepared[] = compact('orgId', 'code', 'primarySlug', 'authoritativeSlugs', 'projectedSlugs');
        }

        DB::transaction(function () use ($prepared): void {
            ScaleSlug::query()->withoutGlobalScopes()->delete();
            foreach ($prepared as $scale) {
                $this->assertSlugOwnershipAvailable($scale['orgId'], $scale['code'], $scale['projectedSlugs']);
                $this->syncSlugProjection(
                    $scale['orgId'],
                    $scale['code'],
                    $scale['primarySlug'],
                    $scale['projectedSlugs'],
                );
            }
        });

        foreach ($prepared as $scale) {
            $this->invalidatePublicProjection(
                $scale['orgId'],
                $scale['code'],
                $scale['authoritativeSlugs'],
            );
        }
    }

    public function invalidateCache(int $orgId = 0, ?string $code = null, ?string $slug = null): void
    {
        $keys = [CacheKeys::scaleRegistryActive($orgId)];
        if ($code !== null) {
            $keys[] = CacheKeys::scaleRegistryByCode($orgId, $code);
        }
        if ($slug !== null) {
            foreach ([$slug, 'compat:'.$slug, 'canonical:'.$slug] as $suffix) {
                $keys[] = CacheKeys::scaleRegistryBySlug($orgId, $suffix);
            }
        }
        $generation = $this->publicScaleCatalogCache->generation($orgId);
        foreach ($keys as $key) {
            Cache::forget($key);
            Cache::forget($key.':generation='.$generation);
        }
    }

    /** @param list<string> $slugs */
    public function invalidatePublicContentProjection(int $orgId, string $code, array $slugs): void
    {
        $this->invalidatePublicProjection($orgId, $code, $slugs);
    }

    /**
     * @param  list<mixed>  $slugs
     */
    private function invalidatePublicProjection(int $orgId, string $code, array $slugs): void
    {
        $this->invalidateCache($orgId, $code);
        foreach ($slugs as $slug) {
            $normalized = $this->normalizeSlug((string) $slug);
            if ($normalized !== '') {
                $this->invalidateCache($orgId, null, $normalized);
            }
        }
        $this->publicScaleCatalogCache->bumpGeneration($orgId);
    }

    /**
     * `primary_slug` and `slugs_json` are the write authority. `scale_slugs`
     * is rebuilt from them inside the same transaction.
     *
     * @return array{0:string,1:list<string>,2:list<string>}
     */
    private function normalizeAuthoritativeSlugs(mixed $primarySlug, mixed $slugs): array
    {
        $rawPrimarySlug = strtolower(trim((string) $primarySlug));
        $normalizedPrimarySlug = $this->normalizeSlug($rawPrimarySlug);
        if ($normalizedPrimarySlug === '') {
            if (! preg_match('/^[a-z0-9_-]{1,127}$/', $rawPrimarySlug)) {
                throw new \InvalidArgumentException('primary_slug must be a valid lowercase URL slug');
            }
            $normalizedPrimarySlug = $rawPrimarySlug;
        }

        $authoritative = [$normalizedPrimarySlug => true];
        $projected = [];
        if ($this->normalizeSlug($normalizedPrimarySlug) !== '') {
            $projected[$normalizedPrimarySlug] = true;
        }
        foreach ($this->decodeJsonArray($slugs) as $slug) {
            $rawSlug = strtolower(trim((string) $slug));
            if ($rawSlug === '') {
                continue;
            }

            if (! preg_match('/^[a-z0-9_-]{1,127}$/', $rawSlug)) {
                throw new \InvalidArgumentException('slugs_json contains an invalid URL slug');
            }
            $authoritative[$rawSlug] = true;

            $normalizedSlug = $this->normalizeSlug($rawSlug);
            if ($normalizedSlug !== '') {
                $projected[$normalizedSlug] = true;
            }
        }

        return [$normalizedPrimarySlug, array_keys($authoritative), array_keys($projected)];
    }

    /** @param list<string> $slugs */
    private function assertSlugOwnershipAvailable(int $orgId, string $code, array $slugs): void
    {
        if ($slugs === [] || ! Schema::hasTable('scale_slugs')) {
            return;
        }

        $conflict = ScaleSlug::queryByOrgWhitelist([$orgId])
            ->where('org_id', $orgId)
            ->whereIn('slug', $slugs)
            ->where('scale_code', '!=', $code)
            ->orderBy('slug')
            ->first(['slug', 'scale_code']);
        if ($conflict) {
            throw new \DomainException(sprintf(
                'Scale slug "%s" is already owned by %s in organization %d.',
                (string) $conflict->slug,
                (string) $conflict->scale_code,
                $orgId,
            ));
        }
    }

    /** @param list<string> $slugs */
    private function syncSlugProjection(int $orgId, string $code, string $primarySlug, array $slugs): void
    {
        if (! Schema::hasTable('scale_slugs')) {
            return;
        }

        ScaleSlug::queryByOrgWhitelist([$orgId])
            ->where('org_id', $orgId)
            ->where('scale_code', $code)
            ->delete();

        foreach ($slugs as $slug) {
            ScaleSlug::queryByOrgWhitelist([$orgId])->create([
                'org_id' => $orgId,
                'slug' => $slug,
                'scale_code' => $code,
                'is_primary' => $slug === $primarySlug,
            ]);
        }
    }

    /** @return list<string> */
    private function existingProjectedSlugs(int $orgId, string $code): array
    {
        if (! Schema::hasTable('scale_slugs')) {
            return [];
        }

        return ScaleSlug::queryByOrgWhitelist([$orgId])
            ->where('org_id', $orgId)
            ->where('scale_code', $code)
            ->pluck('slug')
            ->map(static fn (mixed $slug): string => (string) $slug)
            ->all();
    }

    /**
     * @return list<string>
     */
    private function existingSlugs(int $orgId, string $code): array
    {
        $table = $this->useV2Table() ? self::V2_TABLE : self::LEGACY_TABLE;
        if (! Schema::hasTable($table)) {
            return [];
        }

        $row = DB::table($table)
            ->where('org_id', $orgId)
            ->where('code', $code)
            ->first(['primary_slug', 'slugs_json']);
        if (! $row) {
            return [];
        }

        return array_values(array_filter([
            ...$this->decodeJsonArray($row->slugs_json ?? null),
            (string) ($row->primary_slug ?? ''),
        ], static fn (mixed $slug): bool => trim((string) $slug) !== ''));
    }

    private function normalizeSlug(string $slug): string
    {
        $slug = trim(strtolower($slug));
        if ($slug === '') {
            return '';
        }
        if (! preg_match('/^[a-z0-9-]{0,127}$/', $slug)) {
            return '';
        }

        return $slug;
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function buildV2Row(array $data): array
    {
        $now = now();

        return [
            'org_id' => (int) ($data['org_id'] ?? 0),
            'code' => strtoupper(trim((string) ($data['code'] ?? ''))),
            'primary_slug' => trim((string) ($data['primary_slug'] ?? '')),
            'slugs_json' => $this->encodeJson($data['slugs_json'] ?? []),
            'driver_type' => trim((string) ($data['driver_type'] ?? 'mbti')),
            'assessment_driver' => $this->nullableString($data['assessment_driver'] ?? null),
            'default_pack_id' => $this->nullableString($data['default_pack_id'] ?? null),
            'default_region' => $this->nullableString($data['default_region'] ?? null),
            'default_locale' => $this->nullableString($data['default_locale'] ?? null),
            'default_dir_version' => $this->nullableString($data['default_dir_version'] ?? null),
            'capabilities_json' => $this->encodeJson($data['capabilities_json'] ?? null),
            'view_policy_json' => $this->encodeJson($data['view_policy_json'] ?? null),
            'commercial_json' => $this->encodeJson($data['commercial_json'] ?? null),
            'seo_schema_json' => $this->encodeJson($data['seo_schema_json'] ?? null),
            'seo_i18n_json' => $this->encodeJson($data['seo_i18n_json'] ?? null),
            'content_i18n_json' => $this->encodeJson($data['content_i18n_json'] ?? null),
            'report_summary_i18n_json' => $this->encodeJson($data['report_summary_i18n_json'] ?? null),
            'is_public' => (bool) ($data['is_public'] ?? false),
            'is_active' => (bool) ($data['is_active'] ?? true),
            'is_indexable' => (bool) ($data['is_indexable'] ?? true),
            'created_at' => $data['created_at'] ?? $now,
            'updated_at' => $data['updated_at'] ?? $now,
        ];
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function upsertLegacyIfEligible(array $data): ?ScaleRegistryModel
    {
        if (! Schema::hasTable(self::LEGACY_TABLE)) {
            return null;
        }

        $code = strtoupper(trim((string) ($data['code'] ?? '')));
        $orgId = (int) ($data['org_id'] ?? 0);
        if ($code === '') {
            return null;
        }

        if ($orgId > 0) {
            $conflict = DB::table(self::LEGACY_TABLE)
                ->where('code', $code)
                ->where('org_id', '!=', $orgId)
                ->exists();
            if ($conflict) {
                return null;
            }
        }

        ScaleRegistryModel::queryByOrgWhitelist([$orgId])->updateOrCreate([
            'code' => $code,
            'org_id' => $orgId,
        ], $data);

        return ScaleRegistryModel::queryByOrgWhitelist([$orgId])
            ->where('code', $code)
            ->where('org_id', $orgId)
            ->first();
    }

    private function useV2Table(): bool
    {
        if (! (bool) config('fap.scales_registry.use_v2', true)) {
            return false;
        }

        return Schema::hasTable(self::V2_TABLE);
    }

    private function nullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private function encodeJson(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return null;
            }

            return $trimmed;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array<int,mixed>
     */
    private function decodeJsonArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }
}
