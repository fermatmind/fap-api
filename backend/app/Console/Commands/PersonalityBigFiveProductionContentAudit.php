<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PersonalityPublicContentAsset;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

final class PersonalityBigFiveProductionContentAudit extends Command
{
    private const V2_SOURCE_PACKAGE = 'big-five-cms-import-draft-polished.v2';

    protected $signature = 'personality:big-five-production-content-audit
        {--target-env=production : Required target environment; production for real audit}
        {--expected-existing-count=34 : Expected current production Big Five public asset count across supported locales}
        {--expected-existing-count-per-locale=17 : Expected current production Big Five public asset count per locale}
        {--expected-v2-count=0 : Expected v2 package row count in production}
        {--v2-source-package=big-five-cms-import-draft-polished.v2 : v2 source package marker to check}
        {--allow-testing : Permit APP_ENV=testing with sqlite for automated tests only}
        {--json : Emit the full JSON audit report}
        {--output= : Optional path to write the JSON audit report}
        {--markdown-output= : Optional path to write a human-readable markdown audit report}';

    protected $description = 'Read-only production audit for Big Five personality public content assets.';

    public function handle(): int
    {
        try {
            $summary = $this->guardedAudit();
        } catch (RuntimeException $exception) {
            $summary = $this->failureSummary($exception->getMessage());
        } catch (Throwable $exception) {
            $summary = $this->failureSummary($exception->getMessage(), 'unexpected_error');
        }

        $this->writeJsonOutput($summary);
        $this->writeMarkdownOutput($summary);
        $this->emitSummary($summary);

        return ($summary['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string,mixed>
     */
    private function guardedAudit(): array
    {
        $targetEnvironment = strtolower(trim((string) $this->option('target-env')));
        $this->guardRuntimeEnvironment($targetEnvironment);

        if (! Schema::hasTable((new PersonalityPublicContentAsset)->getTable())) {
            throw new RuntimeException('personality_public_content_assets table is missing; run migrations before audit.');
        }

        $expectedExistingCount = max(0, (int) $this->option('expected-existing-count'));
        $expectedExistingCountPerLocale = max(0, (int) $this->option('expected-existing-count-per-locale'));
        $expectedV2Count = max(0, (int) $this->option('expected-v2-count'));
        $v2SourcePackage = trim((string) $this->option('v2-source-package')) ?: self::V2_SOURCE_PACKAGE;

        $assets = PersonalityPublicContentAsset::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('framework', PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE)
            ->orderBy('locale')
            ->orderBy('entity_type')
            ->orderBy('entity_key')
            ->get();

        $rows = $assets
            ->map(fn (PersonalityPublicContentAsset $asset): array => $this->rowPayload($asset, $v2SourcePackage))
            ->values()
            ->all();

        $counts = $this->counts($rows, $v2SourcePackage);
        $errors = $this->errors($counts, $expectedExistingCount, $expectedExistingCountPerLocale, $expectedV2Count);
        $warnings = $this->warnings($counts);

        return [
            'artifact' => 'BIG5-PRODUCTION-CONTENT-AUDIT-C',
            'status' => $errors === [] ? 'pass' : 'fail',
            'ok' => $errors === [],
            'target_environment' => $targetEnvironment,
            'audited_table' => 'personality_public_content_assets',
            'framework' => PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE,
            'expected_existing_count' => $expectedExistingCount,
            'expected_v2_count' => $expectedV2Count,
            'expected_existing_count_per_locale' => $expectedExistingCountPerLocale,
            'v2_source_package' => $v2SourcePackage,
            'counts' => $counts,
            'production_observations' => [
                'existing_asset_count_matches_expected' => $counts['total_big_five_rows'] === $expectedExistingCount,
                'existing_asset_count_per_locale_matches_expected' => $this->localeCountsMatch((array) $counts['locale_counts'], $expectedExistingCountPerLocale),
                'v2_package_absent_from_production' => $counts['v2_source_package_rows'] === $expectedV2Count,
                'body_missing_rows_present' => $counts['renderable_body_missing_rows'] > 0,
                'public_api_visible_rows' => $counts['publicly_readable_rows'],
                'schema_runtime_enabled_rows' => $counts['schema_runtime_eligible_rows'],
            ],
            'release_safety' => [
                'writes_committed' => false,
                'cms_write_attempted' => false,
                'publish_attempted' => false,
                'index_attempted' => false,
                'sitemap_llms_release_attempted' => false,
                'jsonld_runtime_release_attempted' => false,
                'production_deploy_attempted' => false,
            ],
            'rows' => $rows,
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    private function guardRuntimeEnvironment(string $targetEnvironment): void
    {
        if ((bool) $this->option('allow-testing')) {
            if (app()->environment() !== 'testing' || config('database.default') !== 'sqlite') {
                throw new RuntimeException('--allow-testing is only valid for APP_ENV=testing with sqlite.');
            }

            return;
        }

        if ($targetEnvironment !== 'production') {
            throw new RuntimeException('--target-env must be production for this read-only audit.');
        }

        if (! app()->environment(['production', 'prod'])) {
            throw new RuntimeException('Production audit requires APP_ENV=production.');
        }
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return array<string,mixed>
     */
    private function counts(array $rows, string $v2SourcePackage): array
    {
        $localeCounts = [];
        $entityTypeCounts = [];
        $launchStateCounts = [];
        $sourcePackageCounts = [];
        $robotsCounts = [];

        foreach ($rows as $row) {
            $this->increment($localeCounts, (string) ($row['locale'] ?? ''));
            $this->increment($entityTypeCounts, (string) ($row['entity_type'] ?? ''));
            $this->increment($launchStateCounts, (string) ($row['launch_state'] ?? ''));
            $this->increment($sourcePackageCounts, (string) ($row['source_package'] ?? ''));
            $this->increment($robotsCounts, (string) ($row['robots'] ?? ''));
        }

        return [
            'total_big_five_rows' => count($rows),
            'v2_source_package_rows' => count(array_filter(
                $rows,
                static fn (array $row): bool => (string) ($row['source_package'] ?? '') === $v2SourcePackage
            )),
            'renderable_body_missing_rows' => count(array_filter(
                $rows,
                static fn (array $row): bool => (int) ($row['renderable_body_section_count'] ?? 0) === 0
            )),
            'empty_section_rows' => count(array_filter(
                $rows,
                static fn (array $row): bool => (int) ($row['empty_section_count'] ?? 0) > 0
            )),
            'publicly_readable_rows' => count(array_filter(
                $rows,
                static fn (array $row): bool => (bool) ($row['publicly_readable'] ?? false)
            )),
            'is_public_true_rows' => count(array_filter(
                $rows,
                static fn (array $row): bool => (bool) ($row['is_public'] ?? false)
            )),
            'index_eligible_true_rows' => count(array_filter(
                $rows,
                static fn (array $row): bool => (bool) ($row['index_eligible'] ?? false)
            )),
            'sitemap_eligible_true_rows' => count(array_filter(
                $rows,
                static fn (array $row): bool => (bool) ($row['sitemap_eligible'] ?? false)
            )),
            'llms_eligible_true_rows' => count(array_filter(
                $rows,
                static fn (array $row): bool => (bool) ($row['llms_eligible'] ?? false)
            )),
            'schema_runtime_eligible_rows' => count(array_filter(
                $rows,
                static fn (array $row): bool => (bool) ($row['schema_runtime_eligible'] ?? false)
            )),
            'locale_counts' => $localeCounts,
            'entity_type_counts' => $entityTypeCounts,
            'launch_state_counts' => $launchStateCounts,
            'source_package_counts' => $sourcePackageCounts,
            'robots_counts' => $robotsCounts,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function rowPayload(PersonalityPublicContentAsset $asset, string $v2SourcePackage): array
    {
        $sections = is_array($asset->content_sections_json) ? $asset->content_sections_json : [];
        $canonical = is_array($asset->canonical_json) ? $asset->canonical_json : [];
        $renderableBodySectionCount = 0;
        $emptySectionCount = 0;

        foreach ($sections as $section) {
            if (! is_array($section)) {
                $emptySectionCount++;

                continue;
            }

            $body = trim((string) ($section['body_md'] ?? $section['body'] ?? $section['body_html'] ?? $section['html'] ?? ''));
            if ($body === '') {
                $emptySectionCount++;
            } else {
                $renderableBodySectionCount++;
            }
        }

        $schemaRuntimeEligible = $this->schemaRuntimeEligible($asset);

        return [
            'id' => (int) $asset->id,
            'locale' => (string) $asset->locale,
            'entity_type' => (string) $asset->entity_type,
            'entity_key' => (string) $asset->entity_key,
            'slug' => (string) $asset->slug,
            'canonical_path' => (string) data_get($canonical, 'path', ''),
            'title' => (string) $asset->title,
            'source_package' => (string) ($asset->source_package ?? ''),
            'source_hash_present' => trim((string) ($asset->source_hash ?? '')) !== '',
            'is_v2_source_package' => (string) ($asset->source_package ?? '') === $v2SourcePackage,
            'section_count' => count($sections),
            'renderable_body_section_count' => $renderableBodySectionCount,
            'empty_section_count' => $emptySectionCount,
            'faq_count' => is_array($asset->faq_json) ? count($asset->faq_json) : 0,
            'robots' => PersonalityPublicContentAsset::normalizeRobots((string) $asset->robots),
            'is_public' => (bool) $asset->is_public,
            'index_eligible' => (bool) $asset->index_eligible,
            'sitemap_eligible' => (bool) $asset->sitemap_eligible,
            'llms_eligible' => (bool) $asset->llms_eligible,
            'launch_state' => (string) $asset->launch_state,
            'review_state' => (string) $asset->review_state,
            'publicly_readable' => $this->publiclyReadable($asset),
            'schema_runtime_eligible' => $schemaRuntimeEligible,
            'schema_payload_exposed_by_public_api' => $schemaRuntimeEligible && is_array($asset->schema_json) && $asset->schema_json !== [],
            'published_at' => $asset->published_at?->toAtomString(),
            'updated_at' => $asset->updated_at?->toAtomString(),
        ];
    }

    private function publiclyReadable(PersonalityPublicContentAsset $asset): bool
    {
        return (bool) $asset->is_public
            && in_array((string) $asset->launch_state, [
                PersonalityPublicContentAsset::LAUNCH_CONTENT_READY,
                PersonalityPublicContentAsset::LAUNCH_PUBLISHED,
            ], true)
            && ($asset->published_at === null || $asset->published_at->isPast());
    }

    private function schemaRuntimeEligible(PersonalityPublicContentAsset $asset): bool
    {
        return (string) $asset->launch_state === PersonalityPublicContentAsset::LAUNCH_PUBLISHED
            && (bool) $asset->index_eligible
            && PersonalityPublicContentAsset::normalizeRobots((string) $asset->robots) === PersonalityPublicContentAsset::ROBOTS_INDEX_FOLLOW;
    }

    /**
     * @param  array<string,int>  $counts
     */
    private function increment(array &$counts, string $key): void
    {
        $normalized = $key === '' ? '__empty__' : $key;
        $counts[$normalized] = ($counts[$normalized] ?? 0) + 1;
        ksort($counts);
    }

    /**
     * @param  array<string,int>  $localeCounts
     */
    private function localeCountsMatch(array $localeCounts, int $expectedExistingCountPerLocale): bool
    {
        foreach (PersonalityPublicContentAsset::SUPPORTED_LOCALES as $locale) {
            if ((int) ($localeCounts[$locale] ?? 0) !== $expectedExistingCountPerLocale) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string,mixed>  $counts
     * @return list<array<string,string>>
     */
    private function errors(array $counts, int $expectedExistingCount, int $expectedExistingCountPerLocale, int $expectedV2Count): array
    {
        $errors = [];

        if ((int) ($counts['total_big_five_rows'] ?? -1) !== $expectedExistingCount) {
            $errors[] = $this->issue('counts.total_big_five_rows', 'unexpected_existing_big_five_count', 'Production Big Five asset count did not match the expected current count.');
        }

        if (! $this->localeCountsMatch((array) ($counts['locale_counts'] ?? []), $expectedExistingCountPerLocale)) {
            $errors[] = $this->issue('counts.locale_counts', 'unexpected_existing_big_five_locale_count', 'Production Big Five asset count per locale did not match the expected current count.');
        }

        if ((int) ($counts['v2_source_package_rows'] ?? -1) !== $expectedV2Count) {
            $errors[] = $this->issue('counts.v2_source_package_rows', 'unexpected_v2_package_presence', 'v2 Big Five CMS import package presence did not match expectation.');
        }

        return $errors;
    }

    /**
     * @param  array<string,mixed>  $counts
     * @return list<array<string,string>>
     */
    private function warnings(array $counts): array
    {
        $warnings = [];

        if ((int) ($counts['renderable_body_missing_rows'] ?? 0) > 0) {
            $warnings[] = $this->issue('counts.renderable_body_missing_rows', 'body_missing_rows_present', 'Some production Big Five rows have no renderable section body; this explains title-only public page cards.');
        }

        if ((int) ($counts['schema_runtime_eligible_rows'] ?? 0) > 0) {
            $warnings[] = $this->issue('counts.schema_runtime_eligible_rows', 'schema_runtime_rows_present', 'Some production Big Five rows are schema-runtime eligible; verify this is intentional before SEO release.');
        }

        return $warnings;
    }

    /**
     * @return array<string,string>
     */
    private function issue(string $field, string $code, string $message): array
    {
        return [
            'field' => $field,
            'code' => $code,
            'message' => $message,
        ];
    }

    /**
     * @param  array<string,mixed>  $summary
     */
    private function emitSummary(array $summary): void
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $summary,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
            ));

            return;
        }

        $this->line('ok='.(($summary['ok'] ?? false) ? '1' : '0'));
        $this->line('status='.(string) ($summary['status'] ?? 'fail'));
        $this->line('target_environment='.(string) ($summary['target_environment'] ?? ''));
        $this->line('total_big_five_rows='.(string) data_get($summary, 'counts.total_big_five_rows', 0));
        $this->line('v2_source_package_rows='.(string) data_get($summary, 'counts.v2_source_package_rows', 0));
        $this->line('renderable_body_missing_rows='.(string) data_get($summary, 'counts.renderable_body_missing_rows', 0));
        $this->line('schema_runtime_eligible_rows='.(string) data_get($summary, 'counts.schema_runtime_eligible_rows', 0));
        $this->line('writes_committed=0');
        $this->line('errors_count='.(string) count((array) ($summary['errors'] ?? [])));
        $this->line('warnings_count='.(string) count((array) ($summary['warnings'] ?? [])));
    }

    /**
     * @param  array<string,mixed>  $summary
     */
    private function writeJsonOutput(array $summary): void
    {
        $output = trim((string) $this->option('output'));
        if ($output === '') {
            return;
        }

        $resolved = str_starts_with($output, '/') ? $output : base_path($output);
        File::ensureDirectoryExists(dirname($resolved));
        File::put($resolved, ((string) json_encode(
            $summary,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        )).PHP_EOL);
    }

    /**
     * @param  array<string,mixed>  $summary
     */
    private function writeMarkdownOutput(array $summary): void
    {
        $output = trim((string) $this->option('markdown-output'));
        if ($output === '') {
            return;
        }

        $resolved = str_starts_with($output, '/') ? $output : base_path($output);
        File::ensureDirectoryExists(dirname($resolved));
        File::put($resolved, $this->markdown($summary));
    }

    /**
     * @param  array<string,mixed>  $summary
     */
    private function markdown(array $summary): string
    {
        $counts = is_array($summary['counts'] ?? null) ? $summary['counts'] : [];
        $lines = [
            '# Big Five Production Content Audit',
            '',
            '- Artifact: `'.((string) ($summary['artifact'] ?? '')).'`',
            '- Status: `'.((string) ($summary['status'] ?? 'fail')).'`',
            '- Target environment: `'.((string) ($summary['target_environment'] ?? '')).'`',
            '- Total Big Five rows: `'.((string) ($counts['total_big_five_rows'] ?? 0)).'`',
            '- v2 source package rows: `'.((string) ($counts['v2_source_package_rows'] ?? 0)).'`',
            '- Rows with no renderable body: `'.((string) ($counts['renderable_body_missing_rows'] ?? 0)).'`',
            '- Schema runtime eligible rows: `'.((string) ($counts['schema_runtime_eligible_rows'] ?? 0)).'`',
            '',
            '## Safety',
            '',
            '- Writes committed: `false`',
            '- CMS write attempted: `false`',
            '- Publish attempted: `false`',
            '- Sitemap / llms release attempted: `false`',
            '- JSON-LD runtime release attempted: `false`',
            '- Production deploy attempted: `false`',
            '',
            '## Notes',
            '',
            '- This audit reads `personality_public_content_assets` only.',
            '- It does not import v2 content, publish content, release indexability, or trigger deploys.',
        ];

        $errors = is_array($summary['errors'] ?? null) ? $summary['errors'] : [];
        if ($errors !== []) {
            $lines[] = '';
            $lines[] = '## Errors';
            foreach ($errors as $error) {
                if (! is_array($error)) {
                    continue;
                }
                $lines[] = '- `'.((string) ($error['code'] ?? 'error')).'`: '.((string) ($error['message'] ?? ''));
            }
        }

        $warnings = is_array($summary['warnings'] ?? null) ? $summary['warnings'] : [];
        if ($warnings !== []) {
            $lines[] = '';
            $lines[] = '## Warnings';
            foreach ($warnings as $warning) {
                if (! is_array($warning)) {
                    continue;
                }
                $lines[] = '- `'.((string) ($warning['code'] ?? 'warning')).'`: '.((string) ($warning['message'] ?? ''));
            }
        }

        return implode(PHP_EOL, $lines).PHP_EOL;
    }

    /**
     * @return array<string,mixed>
     */
    private function failureSummary(string $message, string $code = 'runtime_error'): array
    {
        return [
            'artifact' => 'BIG5-PRODUCTION-CONTENT-AUDIT-C',
            'status' => 'fail',
            'ok' => false,
            'release_safety' => [
                'writes_committed' => false,
                'cms_write_attempted' => false,
                'publish_attempted' => false,
                'index_attempted' => false,
                'sitemap_llms_release_attempted' => false,
                'jsonld_runtime_release_attempted' => false,
                'production_deploy_attempted' => false,
            ],
            'errors' => [[
                'field' => 'command',
                'code' => $code,
                'message' => $message,
            ]],
            'warnings' => [],
        ];
    }
}
