<?php

declare(strict_types=1);

namespace FermatMind\Operations;

use App\Domain\Career\Publish\Career1046ImmutableCandidateGenerator;
use App\Domain\Career\Publish\CareerFullReleaseLedgerProjectionService;
use App\Domain\Career\Publish\CareerGenerationCanonicalJson;
use App\Domain\Career\Publish\CareerRuntimePublishProjectionService;
use App\Http\Resources\Career\CareerJobDetailResource;
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
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        $task3b = self::task3bFromEnvironment();
        DB::statement('SET TRANSACTION READ ONLY');

        return DB::connection()->transaction(static function () use ($task3b): array {
            $manifestPath = base_path('docs/seo/generated/detail-ready-1046-rollout-manifest.v1.json');
            $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($manifest) || ! is_array($manifest['baseline_slugs'] ?? null) || ! is_array($manifest['delta_slugs'] ?? null)) {
                throw new Career1046ImmutableCandidateArtifactFailure('FROZEN_MANIFEST_INVALID');
            }
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
            $rows = is_array(data_get($ledger, 'public_resolution.rows')) ? data_get($ledger, 'public_resolution.rows') : [];
            $bySlug = [];
            foreach ($rows as $row) {
                if (is_array($row) && is_string($row['source_slug'] ?? null)) {
                    $bySlug[strtolower($row['source_slug'])] = true;
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
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    try {
        echo CareerGenerationCanonicalJson::encode(Career1046ImmutableCandidateArtifactProducer::produceFromDatabase())."\n";
    } catch (Career1046ImmutableCandidateArtifactFailure $failure) {
        fwrite(STDERR, $failure->safeCode."\n");
        exit(1);
    } catch (Throwable) {
        fwrite(STDERR, "UNEXPECTED_CONTROL_FAILURE\n");
        exit(1);
    }
}
