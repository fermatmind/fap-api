<?php

declare(strict_types=1);

namespace FermatMind\Operations;

use App\Domain\Career\Publish\Career1046ImmutableCandidateGenerator;
use App\Domain\Career\Publish\CareerFullReleaseLedgerProjectionService;
use App\Domain\Career\Publish\CareerGenerationCanonicalJson;
use App\Domain\Career\Publish\CareerRuntimePublishProjectionService;
use App\Http\Resources\Career\CareerJobDetailResource;
use App\Models\IndexState;
use App\Models\Occupation;
use App\Services\Career\Bundles\CareerJobDetailBundleBuilder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class Career1046ImmutableCandidateArtifactFailure extends RuntimeException
{
    public function __construct(public readonly string $safeCode)
    {
        parent::__construct($safeCode);
    }
}

/**
 * SELECT-only producer for the Task 5 candidate-artifact contract.
 * Its stdout is intentionally the one candidate JSON document consumed by GitHub.
 */
final class Career1046ImmutableCandidateArtifactProducer
{
    public const CONTRACT_VERSION = 'career.1046.immutable_candidate_artifact_producer.v1';

    public static function emitStreamedRunner(): void
    {
        $root = dirname(__DIR__, 2);
        $sources = [
            $root.'/app/Domain/Career/Publish/Career1046ImmutableCandidateGenerator.php',
            $root.'/scripts/operations/career_publication_index_reconciliation_apply.php',
            __FILE__,
        ];
        $bundle = "<?php\ndeclare(strict_types=1);\n";
        foreach ($sources as $sourcePath) {
            $source = file_get_contents($sourcePath);
            if (! is_string($source)) {
                throw new Career1046ImmutableCandidateArtifactFailure('STREAMED_RUNNER_SOURCE_UNREADABLE');
            }
            $source = preg_replace('/\\A<\\?php\\s+declare\\(strict_types=1\\);\\s*/', "\n", $source, 1, $openingReplacements);
            if (! is_string($source) || $openingReplacements !== 1) {
                throw new Career1046ImmutableCandidateArtifactFailure('STREAMED_RUNNER_SOURCE_INVALID');
            }
            if ($sourcePath === __FILE__) {
                $source = preg_replace('/\\nif \\(realpath\\(\\(string\\) \\(\\$_SERVER\\[\'SCRIPT_FILENAME\'\\] \\?\\? \'\'\\)\\) === __FILE__ \\|\\| getenv\\(\'CAREER_1046_STREAMED_EXECUTION\'\\) === \'1\'\\) \\{[\\s\\S]*\\z/', "\n", $source, 1, $entrypointReplacements);
            } elseif (str_ends_with($sourcePath, 'career_publication_index_reconciliation_apply.php')) {
                $source = preg_replace('/\\nif \\(realpath\\(\\(string\\) \\(\\$_SERVER\\[\'SCRIPT_FILENAME\'\\] \\?\\? \'\'\\)\\) === __FILE__ \\|\\| __FILE__ === \'\\/dev\\/stdin\'\\) \\{\\n    exit\\(CareerPublicationIndexReconciliationApply::main\\(\\$argv\\)\\);\\n\\}\\s*\\z/', "\n", $source, 1, $entrypointReplacements);
            } else {
                $entrypointReplacements = 1;
            }
            if (! is_string($source) || $entrypointReplacements !== 1) {
                throw new Career1046ImmutableCandidateArtifactFailure('STREAMED_RUNNER_SOURCE_INVALID');
            }
            $bundle .= "\n".$source;
        }

        echo $bundle."\nexit(\\FermatMind\\Operations\\Career1046ImmutableCandidateArtifactProducer::main());\n";
    }

    /** @return array<string, mixed> */
    public static function produceFromSource(array $source, array $task3b): array
    {
        self::assertTask3bAuthority($task3b);
        foreach (['manifest_path', 'baseline_authority_slugs', 'database_matching_receipt_slugs', 'ledger', 'projection', 'detail_rows'] as $field) {
            if (! array_key_exists($field, $source)) {
                throw new Career1046ImmutableCandidateArtifactFailure('SOURCE_'.$field.'_MISSING');
            }
        }

        $candidate = (new Career1046ImmutableCandidateGenerator)->generate(
            (string) $source['manifest_path'],
            is_array($source['baseline_authority_slugs']) ? $source['baseline_authority_slugs'] : [],
            is_array($source['database_matching_receipt_slugs']) ? $source['database_matching_receipt_slugs'] : [],
            is_array($source['ledger']) ? $source['ledger'] : [],
            is_array($source['projection']) ? $source['projection'] : [],
            is_array($source['detail_rows']) ? $source['detail_rows'] : [],
        );

        $binding = [
            'contract_version' => self::CONTRACT_VERSION,
            'task_3b_apply_run_id' => $task3b['run_id'],
            'task_3b_apply_run_attempt' => $task3b['run_attempt'],
            'task_3b_artifact_digest' => $task3b['artifact_digest'],
            'task_3b_receipt_sha256' => $task3b['receipt_sha256'],
            'control_plane_sha' => $task3b['control_plane_sha'],
            'active_release_sha' => $task3b['release_sha'],
            'active_release_name_sha256' => $task3b['release_name_sha256'],
            'database_state_sha256' => $task3b['database_state_sha256'],
            'receipt_covered_publication_index_authority' => true,
            'receipt_covered_slug_count' => Career1046ImmutableCandidateGenerator::RECEIPT_COUNT,
            'baseline_slug_count' => Career1046ImmutableCandidateGenerator::BASELINE_COUNT,
            'target_slug_count' => Career1046ImmutableCandidateGenerator::TARGET_COUNT,
            'target_locale_row_count' => Career1046ImmutableCandidateGenerator::TARGET_LOCALE_ROW_COUNT,
            'forbidden_slug_count' => count(Career1046ImmutableCandidateGenerator::FORBIDDEN_SLUGS),
            'production_read_only' => true,
            'database_write_count' => 0,
            'cms_write_count' => 0,
            'cache_write_count' => 0,
            'artifact_tree_write_count' => 0,
            'pointer_write_count' => 0,
            'sitemap_write_count' => 0,
            'llms_write_count' => 0,
            'search_submission_count' => 0,
        ];
        $candidate['candidate_receipt']['producer_authority'] = $binding;
        $candidate['documents']['candidate-receipt.json'] = $candidate['candidate_receipt'];

        return $candidate;
    }

    /** @return array<string, mixed> */
    public static function produceFromDatabase(): array
    {
        $applicationRoot = self::applicationRoot();
        $app = require $applicationRoot.'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        $task3b = self::task3bFromEnvironment();
        DB::statement('SET TRANSACTION READ ONLY');

        return DB::connection()->transaction(static function () use ($applicationRoot, $task3b): array {
            $manifestPath = $applicationRoot.'/docs/seo/generated/detail-ready-1046-rollout-manifest.v1.json';
            $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($manifest) || ! is_array($manifest['baseline_slugs'] ?? null) || ! is_array($manifest['delta_slugs'] ?? null)) {
                throw new Career1046ImmutableCandidateArtifactFailure('FROZEN_MANIFEST_INVALID');
            }
            self::assertTask3bDatabaseState($applicationRoot, $manifest, $task3b);
            $ledgerEnvelope = app(CareerFullReleaseLedgerProjectionService::class)->build();
            $ledger = $ledgerEnvelope[CareerFullReleaseLedgerProjectionService::LEDGER_FILENAME] ?? null;
            if (! is_array($ledger)) {
                throw new Career1046ImmutableCandidateArtifactFailure('LEDGER_UNAVAILABLE');
            }
            $projection = app(CareerRuntimePublishProjectionService::class)->buildFromLedgerArray($ledger);
            $details = [];
            $items = is_array($projection['items'] ?? null) ? $projection['items'] : [];
            foreach ($items as $item) {
                if (! is_array($item) || ! is_string($item['slug'] ?? null) || ! is_string($item['locale'] ?? null)) {
                    continue;
                }
                $locale = $item['locale'] === 'zh' ? 'zh-CN' : 'en';
                $bundle = app(CareerJobDetailBundleBuilder::class)->buildBySlug($item['slug'], $locale, $item);
                if ($bundle === null) {
                    throw new Career1046ImmutableCandidateArtifactFailure('DETAIL_SOURCE_UNAVAILABLE');
                }
                $details[] = [
                    'slug' => $item['slug'],
                    'locale' => $item['locale'],
                    'payload' => (new CareerJobDetailResource($bundle))->toArray(Request::create('/api/v0.5/career/jobs/'.$item['slug'], 'GET', ['locale' => $locale])),
                ];
            }
            $rows = data_get($ledger, 'public_resolution.rows');
            if (! is_array($rows)) {
                $rows = is_array($ledger['members'] ?? null) ? $ledger['members'] : [];
            }
            $bySlug = [];
            foreach ($rows as $row) {
                $slug = is_array($row) ? ($row['source_slug'] ?? $row['canonical_slug'] ?? null) : null;
                if (is_string($slug)) {
                    $bySlug[strtolower($slug)] = true;
                }
            }
            $baseline = array_values(array_filter($manifest['baseline_slugs'], static fn (mixed $slug): bool => is_string($slug) && isset($bySlug[strtolower($slug)])));
            $delta = array_values(array_filter($manifest['delta_slugs'], static fn (mixed $slug): bool => is_string($slug) && isset($bySlug[strtolower($slug)])));

            return self::produceFromSource([
                'manifest_path' => $manifestPath,
                'baseline_authority_slugs' => $baseline,
                'database_matching_receipt_slugs' => $delta,
                'ledger' => $ledger,
                'projection' => $projection,
                'detail_rows' => $details,
            ], $task3b);
        });
    }

    /** @param array<string, mixed> $task3b */
    private static function assertTask3bAuthority(array $task3b): void
    {
        foreach (['run_id', 'run_attempt'] as $field) {
            if (! is_int($task3b[$field] ?? null) || $task3b[$field] < 1) {
                throw new Career1046ImmutableCandidateArtifactFailure('TASK3B_RUN_IDENTITY_INVALID');
            }
        }
        foreach (['receipt_sha256', 'control_plane_sha', 'release_sha', 'release_name_sha256', 'database_state_sha256'] as $field) {
            if (! is_string($task3b[$field] ?? null) || preg_match('/^[0-9a-f]{64}$/D', $task3b[$field]) !== 1 && ! in_array($field, ['control_plane_sha', 'release_sha'], true)) {
                throw new Career1046ImmutableCandidateArtifactFailure('TASK3B_BINDING_INVALID');
            }
        }
        foreach (['control_plane_sha', 'release_sha'] as $field) {
            if (! is_string($task3b[$field] ?? null) || preg_match('/^[0-9a-f]{40}$/D', $task3b[$field]) !== 1) {
                throw new Career1046ImmutableCandidateArtifactFailure('TASK3B_BINDING_INVALID');
            }
        }
        if (! is_string($task3b['artifact_digest'] ?? null) || preg_match('/^sha256:[0-9a-f]{64}$/D', $task3b['artifact_digest']) !== 1) {
            throw new Career1046ImmutableCandidateArtifactFailure('TASK3B_ARTIFACT_INVALID');
        }
    }

    /** @param array<string, mixed> $manifest @param array<string, mixed> $task3b */
    private static function assertTask3bDatabaseState(string $applicationRoot, array $manifest, array $task3b): void
    {
        require_once $applicationRoot.'/scripts/operations/career_publication_index_reconciliation_apply.php';
        $targetSlugs = array_values(array_unique([...$manifest['baseline_slugs'], ...$manifest['delta_slugs']]));
        sort($targetSlugs, SORT_STRING);
        $occupations = Occupation::query()
            ->whereIn('canonical_slug', $targetSlugs)
            ->orderBy('canonical_slug')
            ->get(['id', 'canonical_slug'])
            ->map(static fn (Occupation $occupation): array => [
                'id' => (string) $occupation->id,
                'canonical_slug' => strtolower(trim((string) $occupation->canonical_slug)),
            ])
            ->all();
        $occupationIds = array_column($occupations, 'id');
        $states = IndexState::query()
            ->whereIn('occupation_id', $occupationIds)
            ->orderBy('occupation_id')
            ->orderBy('changed_at')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'occupation_id', 'index_state', 'index_eligible', 'canonical_path', 'canonical_target', 'reason_codes', 'changed_at', 'created_at'])
            ->map(static fn (IndexState $state): array => [
                'id' => (string) $state->id,
                'occupation_id' => (string) $state->occupation_id,
                'index_state' => (string) $state->index_state,
                'index_eligible' => (bool) $state->index_eligible,
                'canonical_path' => (string) $state->canonical_path,
                'canonical_target' => $state->canonical_target === null ? '' : (string) $state->canonical_target,
                'reason_codes' => is_array($state->reason_codes) ? $state->reason_codes : [],
                'changed_at' => $state->changed_at instanceof \DateTimeInterface
                    ? $state->changed_at->format('Y-m-d\\TH:i:s.uP')
                    : trim((string) $state->changed_at),
                'created_at' => $state->created_at instanceof \DateTimeInterface
                    ? $state->created_at->format('Y-m-d\\TH:i:s.uP')
                    : trim((string) $state->created_at),
            ])
            ->all();
        $analysis = CareerPublicationIndexReconciliationApply::analyze($manifest, $manifest['delta_slugs'], $occupations, $states);
        $database = $analysis['database_latest_index_state'] ?? null;
        if (! is_array($database)
            || ($database['matching_count'] ?? null) !== Career1046ImmutableCandidateGenerator::RECEIPT_COUNT
            || ($database['missing_or_mismatching_count'] ?? null) !== 0
            || ($database['latest_state_tie_count'] ?? null) !== 0
            || ! hash_equals((string) $task3b['database_state_sha256'], (string) ($database['current_state_sha256'] ?? ''))) {
            throw new Career1046ImmutableCandidateArtifactFailure('TASK3B_DATABASE_STATE_DRIFT');
        }
    }

    private static function applicationRoot(): string
    {
        $configured = trim((string) getenv('CAREER_1046_APPLICATION_ROOT'));
        $root = $configured === '' ? dirname(__DIR__, 2) : $configured;
        if (! str_starts_with($root, '/') || str_contains($root, '..') || is_link($root) || ! is_dir($root)) {
            throw new Career1046ImmutableCandidateArtifactFailure('APPLICATION_ROOT_INVALID');
        }
        $real = realpath($root);
        if (! is_string($real) || ! is_file($real.'/bootstrap/app.php')) {
            throw new Career1046ImmutableCandidateArtifactFailure('APPLICATION_ROOT_INVALID');
        }

        return $real;
    }

    /** @return array<string, mixed> */
    private static function task3bFromEnvironment(): array
    {
        $read = static fn (string $name): string => strtolower(trim((string) getenv($name)));

        return [
            'run_id' => (int) $read('CAREER_1046_TASK3B_RUN_ID'),
            'run_attempt' => (int) $read('CAREER_1046_TASK3B_RUN_ATTEMPT'),
            'artifact_digest' => $read('CAREER_1046_TASK3B_ARTIFACT_DIGEST'),
            'receipt_sha256' => $read('CAREER_1046_TASK3B_RECEIPT_SHA256'),
            'control_plane_sha' => $read('CAREER_1046_TASK3B_CONTROL_PLANE_SHA'),
            'release_sha' => $read('CAREER_1046_TASK3B_RELEASE_SHA'),
            'release_name_sha256' => $read('CAREER_1046_TASK3B_RELEASE_NAME_SHA256'),
            'database_state_sha256' => $read('CAREER_1046_TASK3B_DATABASE_STATE_SHA256'),
        ];
    }

    public static function main(): int
    {
        try {
            echo CareerGenerationCanonicalJson::encode(self::produceFromDatabase())."\n";

            return 0;
        } catch (Career1046ImmutableCandidateArtifactFailure $failure) {
            fwrite(STDERR, $failure->safeCode."\n");

            return 1;
        } catch (Throwable) {
            fwrite(STDERR, "UNEXPECTED_CONTROL_FAILURE\n");

            return 1;
        }
    }
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__ || getenv('CAREER_1046_STREAMED_EXECUTION') === '1') {
    if (($argv[1] ?? null) === '--emit-streamed-runner') {
        Career1046ImmutableCandidateArtifactProducer::emitStreamedRunner();
        exit(0);
    }
    exit(Career1046ImmutableCandidateArtifactProducer::main());
}
