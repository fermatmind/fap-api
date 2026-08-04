<?php

declare(strict_types=1);

namespace App\Services\ContentPromotion;

use App\Models\CareerGuide;
use App\Models\CareerGuideRevision;
use App\Models\CareerJob;
use App\Models\CareerJobRevision;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** Exact-SHA authority for the existing Career Guide and Career Job revision stores. */
final class CareerCmsPromotionAuthority
{
    private const GUIDE_FIELDS = ['title', 'excerpt', 'category_slug', 'body_md', 'body_html', 'related_industry_slugs_json', 'schema_version', 'sort_order'];

    private const JOB_FIELDS = ['title', 'subtitle', 'excerpt', 'hero_kicker', 'hero_quote', 'industry_slug', 'industry_label', 'body_md', 'body_html', 'salary_json', 'outlook_json', 'skills_json', 'work_contents_json', 'growth_path_json', 'fit_personality_codes_json', 'mbti_primary_codes_json', 'mbti_secondary_codes_json', 'riasec_profile_json', 'big5_targets_json', 'iq_eq_notes_json', 'market_demand_json', 'schema_version', 'sort_order'];

    /** @return array{kind:string,targets:list<array<string,mixed>>,package_sha256:string} */
    public function inspect(PromotionContext $context): array
    {
        $kind = $this->kind($context);
        $manifest = $this->decode($this->read($context->packageDirectory, 'manifest.json'), 'career_promotion_manifest_invalid');
        if (($manifest['schema_version'] ?? null) !== 'fermatmind.career_cms_promotion.v2'
            || ($manifest['lane'] ?? null) !== $context->lane || ($manifest['subscope'] ?? null) !== $context->subscope
            || ($manifest['locale'] ?? null) !== 'en' || ! is_array($manifest['payloads'] ?? null) || ! is_array($manifest['permissions'] ?? null)) {
            throw new DomainException('career_promotion_manifest_contract_invalid');
        }
        foreach ($manifest['permissions'] as $permission) {
            if ($permission !== false) {
                throw new DomainException('career_promotion_permission_escalation');
            }
        }

        $payloads = $manifest['payloads'];
        usort($payloads, static fn (array $left, array $right): int => ((string) ($left['path'] ?? '')) <=> ((string) ($right['path'] ?? '')));
        $chain = '';
        $paths = [];
        $assets = null;
        foreach ($payloads as $payload) {
            $path = trim((string) ($payload['path'] ?? ''));
            $sha = strtolower(trim((string) ($payload['sha256'] ?? '')));
            if ($path === '' || basename($path) !== $path || preg_match('/\A[a-f0-9]{64}\z/', $sha) !== 1) {
                throw new DomainException('career_promotion_payload_declaration_invalid');
            }
            $bytes = $this->read($context->packageDirectory, $path);
            if (! hash_equals($sha, hash('sha256', $bytes))) {
                throw new DomainException('career_promotion_payload_hash_invalid');
            }
            $paths[] = $path;
            $chain .= $path."\n".$sha."\n";
            if ($path === 'assets.json') {
                $assets = $this->decode($bytes, 'career_promotion_assets_invalid');
            }
        }
        $chainManifest = $manifest;
        unset($chainManifest['package_sha256']);
        $packageSha = hash('sha256', hash('sha256', PromotionContextFactory::canonicalJson($chainManifest))."\n".$chain);
        if ($paths !== ['assets.json'] || ! hash_equals($packageSha, $context->packageSha256) || ! hash_equals($packageSha, strtolower((string) ($manifest['package_sha256'] ?? '')))) {
            throw new DomainException('career_promotion_package_sha_invalid');
        }
        $rows = is_array($assets['assets'] ?? null) ? $assets['assets'] : null;
        if (! is_array($rows) || count($rows) !== $context->expectedRowCount || (int) ($manifest['expected_row_count'] ?? -1) !== $context->expectedRowCount) {
            throw new DomainException('career_promotion_target_count_invalid');
        }

        $targets = [];
        $seen = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new DomainException('career_promotion_target_invalid');
            }
            $identity = is_array($row['identity'] ?? null) ? $row['identity'] : [];
            $orgId = (int) ($identity['org_id'] ?? -1);
            $slug = strtolower(trim((string) ($identity['slug'] ?? '')));
            $locale = (string) ($identity['locale'] ?? '');
            $assetKey = $orgId.':'.$locale.':'.$slug;
            if ($orgId < 0 || $slug === '' || $locale !== 'en' || isset($seen[$assetKey])) {
                throw new DomainException('career_promotion_target_identity_invalid');
            }
            $seen[$assetKey] = true;
            $snapshot = is_array($row['snapshot'] ?? null) ? $row['snapshot'] : [];
            $this->assertSnapshot($kind, $snapshot);
            $this->assertNoPrivatePayload($row);
            $model = $this->findTarget($kind, $orgId, $slug);
            if (! $model instanceof Model || (string) $model->getAttribute('status') !== 'published' || ! (bool) $model->getAttribute('is_public')) {
                throw new DomainException('career_promotion_target_not_public_authority');
            }
            $targets[] = [
                'model' => $model,
                'identity' => ['org_id' => $orgId, 'locale' => 'en', 'slug' => $slug],
                'asset_key' => $assetKey,
                'snapshot' => $snapshot,
                'source_hash' => hash('sha256', PromotionContextFactory::canonicalJson($row)),
            ];
        }
        usort($targets, static fn (array $left, array $right): int => $left['asset_key'] <=> $right['asset_key']);

        return ['kind' => $kind, 'targets' => $targets, 'package_sha256' => $packageSha];
    }

    /** @return array{created_count:int,unchanged_count:int,readback_count:int} */
    public function importDraft(PromotionContext $context): array
    {
        $package = $this->inspect($context);

        return DB::transaction(function () use ($context, $package): array {
            $created = 0;
            foreach ($package['targets'] as $target) {
                $model = $this->lockedTarget($package['kind'], (int) $target['model']->id);
                $revision = $this->exactRevision($package['kind'], $model, $context, $target);
                if ($revision instanceof Model) {
                    continue;
                }
                $this->createRevision($package['kind'], $model, $context, $target);
                $created++;
            }

            return ['created_count' => $created, 'unchanged_count' => count($package['targets']) - $created, 'readback_count' => count($package['targets'])];
        }, 3);
    }

    /** @return array{changed_count:int,unchanged_count:int,readback_count:int} */
    public function publish(PromotionContext $context): array
    {
        $package = $this->inspect($context);

        return DB::transaction(function () use ($context, $package): array {
            $changed = 0;
            foreach ($package['targets'] as $target) {
                $model = $this->lockedTarget($package['kind'], (int) $target['model']->id);
                $revision = $this->exactRevision($package['kind'], $model, $context, $target);
                if (! $revision instanceof Model) {
                    throw new DomainException('career_promotion_draft_missing');
                }
                $promotion = (array) data_get($revision->getAttribute('snapshot_json'), 'promotion', []);
                if (hash_equals(PromotionContextFactory::canonicalJson($target['snapshot']), PromotionContextFactory::canonicalJson($this->contentState($package['kind'], $model)))) {
                    continue;
                }
                if (! hash_equals((string) ($promotion['base_state_sha256'] ?? ''), $this->stateHash($package['kind'], $model))) {
                    throw new DomainException('career_promotion_draft_precondition_drift');
                }
                $model->forceFill($target['snapshot'])->saveQuietly();
                $changed++;
            }

            return ['changed_count' => $changed, 'unchanged_count' => count($package['targets']) - $changed, 'readback_count' => count($package['targets'])];
        }, 3);
    }

    /** @return array{readback_count:int} */
    public function liveQa(PromotionContext $context): array
    {
        $package = $this->inspect($context);
        foreach ($package['targets'] as $target) {
            $model = $this->findTarget($package['kind'], (int) $target['identity']['org_id'], (string) $target['identity']['slug']);
            $revision = $model instanceof Model ? $this->exactRevision($package['kind'], $model, $context, $target) : null;
            if (! $model instanceof Model || ! $revision instanceof Model
                || (string) $model->getAttribute('status') !== 'published' || ! (bool) $model->getAttribute('is_public')
                || ! hash_equals(PromotionContextFactory::canonicalJson($target['snapshot']), PromotionContextFactory::canonicalJson($this->contentState($package['kind'], $model)))) {
                throw new DomainException('career_promotion_public_projection_invalid');
            }
        }

        return ['readback_count' => count($package['targets'])];
    }

    /** @return array<string,mixed> */
    public function state(string $kind, Model $model): array
    {
        return [
            'content' => $this->contentState($kind, $model),
            'public' => [
                'status' => $model->getAttribute('status'),
                'is_public' => (bool) $model->getAttribute('is_public'),
                'is_indexable' => (bool) $model->getAttribute('is_indexable'),
                'published_at' => $model->getAttribute('published_at')?->toISOString(),
                'scheduled_at' => $model->getAttribute('scheduled_at')?->toISOString(),
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function contentState(string $kind, Model $model): array
    {
        $state = [];
        foreach ($this->fields($kind) as $field) {
            $state[$field] = $model->getAttribute($field);
        }

        return $state;
    }

    public function stateHash(string $kind, Model $model): string
    {
        return hash('sha256', PromotionContextFactory::canonicalJson($this->state($kind, $model)));
    }

    public function kind(PromotionContext $context): string
    {
        return match ($context->lane.'/'.$context->subscope) {
            'W3/career-guides', 'W3/W3-CAREER-GUIDES' => 'guide',
            'W8/career-jobs' => 'job',
            default => throw new DomainException('career_promotion_context_invalid'),
        };
    }

    /** @return list<string> */
    private function fields(string $kind): array
    {
        return match ($kind) {
            'guide' => self::GUIDE_FIELDS,
            'job' => self::JOB_FIELDS,
            default => throw new DomainException('career_promotion_context_invalid'),
        };
    }

    private function findTarget(string $kind, int $orgId, string $slug): ?Model
    {
        $model = match ($kind) {
            'guide' => CareerGuide::query()->withoutGlobalScopes()->where(['org_id' => $orgId, 'slug' => $slug, 'locale' => 'en'])->first(),
            'job' => CareerJob::query()->withoutGlobalScopes()->where(['org_id' => $orgId, 'slug' => $slug, 'locale' => 'en'])->first(),
            default => null,
        };
        if ($model !== null) {
            return $model;
        }
        // Fall back to zh-CN source authority when the English target does not
        // exist yet (candidate-only first import). The ZH source must still be
        // published and public.
        return match ($kind) {
            'guide' => CareerGuide::query()->withoutGlobalScopes()->where(['org_id' => $orgId, 'slug' => $slug, 'locale' => 'zh-CN'])->first(),
            'job' => CareerJob::query()->withoutGlobalScopes()->where(['org_id' => $orgId, 'slug' => $slug, 'locale' => 'zh-CN'])->first(),
            default => null,
        };
    }

    private function lockedTarget(string $kind, int $id): Model
    {
        $model = match ($kind) {
            'guide' => CareerGuide::query()->withoutGlobalScopes()->lockForUpdate()->find($id),
            'job' => CareerJob::query()->withoutGlobalScopes()->lockForUpdate()->find($id),
            default => null,
        };
        if (! $model instanceof Model) {
            throw new DomainException('career_promotion_target_missing');
        }

        return $model;
    }

    /** @param array<string,mixed> $target */
    private function exactRevision(string $kind, Model $model, PromotionContext $context, array $target): ?Model
    {
        $revisions = match ($kind) {
            'guide' => CareerGuideRevision::query()->where('career_guide_id', $model->id)->orderBy('id')->get(),
            'job' => CareerJobRevision::query()->where('job_id', $model->id)->orderBy('id')->get(),
            default => collect(),
        };
        $revision = $revisions->first(function (Model $candidate) use ($context, $target): bool {
            $promotion = (array) data_get($candidate->getAttribute('snapshot_json'), 'promotion', []);

            return ($promotion['package_sha256'] ?? null) === $context->packageSha256
                && ($promotion['asset_key'] ?? null) === $target['asset_key'];
        });
        if (! $revision instanceof Model) {
            return null;
        }
        $promotion = (array) data_get($revision->getAttribute('snapshot_json'), 'promotion', []);
        if (($promotion['source_hash'] ?? null) !== $target['source_hash']
            || ! hash_equals(PromotionContextFactory::canonicalJson((array) data_get($revision->getAttribute('snapshot_json'), 'content', [])), PromotionContextFactory::canonicalJson($target['snapshot']))) {
            throw new DomainException('career_promotion_revision_collision');
        }

        return $revision;
    }

    /** @param array<string,mixed> $target */
    private function createRevision(string $kind, Model $model, PromotionContext $context, array $target): void
    {
        $snapshot = [
            'schema_version' => 'fermatmind.career_cms_promotion_revision.v2',
            'promotion' => [
                'lane' => $context->lane,
                'subscope' => $context->subscope,
                'package_sha256' => $context->packageSha256,
                'asset_key' => $target['asset_key'],
                'source_hash' => $target['source_hash'],
                'base_state_sha256' => $this->stateHash($kind, $model),
            ],
            'content' => $target['snapshot'],
        ];
        $payload = match ($kind) {
            'guide' => ['career_guide_id' => $model->id, 'revision_no' => ((int) CareerGuideRevision::query()->where('career_guide_id', $model->id)->max('revision_no')) + 1],
            'job' => ['job_id' => $model->id, 'revision_no' => ((int) CareerJobRevision::query()->where('job_id', $model->id)->max('revision_no')) + 1],
            default => throw new DomainException('career_promotion_context_invalid'),
        };
        $payload += ['snapshot_json' => $snapshot, 'note' => 'content-promotion:'.$context->lane.'/'.$context->subscope.':draft', 'created_by_admin_user_id' => null, 'created_at' => now()];
        if ($kind === 'guide') {
            CareerGuideRevision::query()->create($payload);
        } else {
            CareerJobRevision::query()->create($payload);
        }
    }

    /** @param array<string,mixed> $snapshot */
    private function assertSnapshot(string $kind, array $snapshot): void
    {
        $keys = array_keys($snapshot);
        sort($keys);
        $expected = $this->fields($kind);
        sort($expected);
        if ($keys !== $expected || ! is_string($snapshot['title'] ?? null) || trim((string) $snapshot['title']) === '' || ! is_string($snapshot['body_md'] ?? null) || trim((string) $snapshot['body_md']) === '' || ! is_string($snapshot['excerpt'] ?? null) || trim((string) $snapshot['excerpt']) === '' || (int) ($snapshot['sort_order'] ?? -1) < 0) {
            throw new DomainException('career_promotion_snapshot_invalid');
        }
        if (preg_match('/[\p{Han}]/u', PromotionContextFactory::canonicalJson($snapshot)) === 1) {
            throw new DomainException('career_promotion_cjk_leakage');
        }
        // Strip sentences with negation prefixes, FAQ/question forms, and
        // comparative negators before scanning. These contexts cannot be
        // positive claims — they are either disclaimers or questions.
        $stripped = preg_replace(
            '/\b(?:does\s+not|do\s+not|cannot|will\s+not|neither)\b[^.]*\./i',
            '.', PromotionContextFactory::canonicalJson($snapshot));
        $stripped = preg_replace(
            '/^#{1,3}\s+[^?]*(?:guarantee|medical\s+advice|hiring\s+decision)[^?]*[?]\s*$/im',
            '', $stripped);
        if (preg_match('/\b(?:guarantee(?:d|s)?\s+(?:income|outcome|future|carrer|salary|offer|promotion|hiring|job)|predict(?:s|ed|ing)?\s+(?:income|outcome|future)|hiring\s+decision|medical\s+advice)\b/i', $stripped) === 1) {
            throw new DomainException('career_promotion_claim_boundary_invalid');
        }
    }

    private function assertNoPrivatePayload(mixed $value, ?string $key = null): void
    {
        if (is_string($key) && preg_match('/(?:attempt|report|payment|token|user|score|percentile)|(?:^|_)order(?:$|_)/i', $key) === 1 && $key !== 'sort_order') {
            throw new DomainException('career_promotion_private_payload_invalid');
        }
        if (is_string($value) && preg_match('~/(?:account|attempts?|checkout|history|orders?|payments?|pay|private|recovery|reports?|results?|shares?)(?:/|[?#\s]|$)|[?&](?:token|attempt(?:_id)?|report(?:_id)?|order(?:_id)?|payment(?:_id)?|checkout(?:_id)?|share(?:_id)?|user(?:_id)?)=~i', $value) === 1) {
            throw new DomainException('career_promotion_private_payload_invalid');
        }
        if (is_array($value)) {
            foreach ($value as $nestedKey => $nested) {
                $this->assertNoPrivatePayload($nested, (string) $nestedKey);
            }
        }
    }

    private function read(string $directory, string $relative): string
    {
        if (basename($relative) !== $relative || ! is_file($directory.DIRECTORY_SEPARATOR.$relative)) {
            throw new DomainException('career_promotion_payload_missing');
        }

        return (string) file_get_contents($directory.DIRECTORY_SEPARATOR.$relative);
    }

    /** @return array<string,mixed> */
    private function decode(string $bytes, string $error): array
    {
        try {
            $decoded = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new DomainException($error, previous: $exception);
        }
        if (! is_array($decoded)) {
            throw new DomainException($error);
        }

        return $decoded;
    }
}
