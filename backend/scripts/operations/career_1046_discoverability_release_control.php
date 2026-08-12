<?php

declare(strict_types=1);

use App\Domain\Career\Publish\Career1046DiscoverabilityReleaseGate;
use App\Domain\Career\Publish\CareerGenerationCanonicalJson;
use App\Domain\Career\Publish\CareerVerifiedRolloutBatchSlugAuthority;
use App\Models\IndexState;
use App\Models\Occupation;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

final class Career1046DiscoverabilityReleaseFailure extends RuntimeException
{
    public function __construct(public readonly string $safeCode)
    {
        parent::__construct($safeCode);
    }
}

final class Career1046DiscoverabilityReleaseControl
{
    public const CONTRACT_VERSION = 'career.1046.discoverability_release_control.v1';

    /** @var array<string, int|bool|string> */
    private static array $writes = [];

    /** @param list<string> $argv */
    public static function main(array $argv): int
    {
        $mode = (string) ($argv[1] ?? getenv('CAREER_DISCOVERABILITY_MODE') ?: '');
        self::$writes = self::emptyWrites();
        try {
            if (! in_array($mode, ['preflight', 'apply'], true)) {
                throw new Career1046DiscoverabilityReleaseFailure('MODE_INVALID');
            }
            $expected = self::expected($mode);
            require_once $expected['backend_root'].'/vendor/autoload.php';
            $authority = self::inspectAuthority($expected);
            $database = self::databaseState($expected);
            $receipt = self::receipt($mode, 'PASS_'.strtoupper($mode).'_DISCOVERABILITY_RELEASE', null, $expected, $authority, $database);
            if ($mode === 'apply') {
                self::assertApply($expected, $receipt);
                self::writePermit($expected, $authority, $database);
                $receipt = self::receipt($mode, 'PASS_APPLY_DISCOVERABILITY_RELEASE', null, $expected, $authority, $database);
            }
            self::emit($receipt);

            return 0;
        } catch (Career1046DiscoverabilityReleaseFailure $failure) {
            self::emit(self::failure($mode, $failure->safeCode));
        } catch (Throwable) {
            self::emit(self::failure($mode, 'UNEXPECTED_DISCOVERABILITY_RELEASE_FAILURE'));
        }

        return 1;
    }

    /** @return array<string, mixed> */
    public static function buildPermit(array $authority, array $expected, array $database): array
    {
        $payload = [
            'generation_id' => $authority['generation_id'],
            'active_pointer_sha256' => $authority['active_pointer_sha256'],
            'immutable_pointer_sha256' => $authority['immutable_pointer_sha256'],
            'task7a_run_id' => $expected['task7a_run_id'],
            'task7a_run_attempt' => $expected['task7a_run_attempt'],
            'task7a_artifact_digest' => $expected['task7a_artifact_digest'],
            'task7a_receipt_sha256' => $expected['task7a_receipt_sha256'],
            'database_state_sha256' => $database['current_state_sha256'],
            'target_slug_set_sha256' => Career1046DiscoverabilityReleaseGate::TARGET_SLUG_SET_SHA256,
            'target_locale_row_set_sha256' => Career1046DiscoverabilityReleaseGate::TARGET_LOCALE_ROW_SET_SHA256,
            'slug_count' => Career1046DiscoverabilityReleaseGate::TARGET_SLUG_COUNT,
            'locale_row_count' => Career1046DiscoverabilityReleaseGate::TARGET_LOCALE_ROW_COUNT,
            'document_sha256' => $authority['document_sha256'],
            'released_locale_rows' => $authority['released_locale_rows'],
            'sitemap_released' => true,
            'llms_released' => true,
            'search_submission_enabled' => false,
        ];

        return [
            'schema_version' => Career1046DiscoverabilityReleaseGate::SCHEMA_VERSION,
            'payload' => $payload,
            'payload_sha256' => CareerGenerationCanonicalJson::sha256($payload),
        ];
    }

    /** @return array<string, mixed> */
    private static function expected(string $mode): array
    {
        $expected = [
            'mode' => $mode,
            'backend_root' => self::absoluteDirectory('CAREER_DISCOVERABILITY_BACKEND_ROOT'),
            'authority_root' => self::absoluteDirectory('CAREER_DISCOVERABILITY_AUTHORITY_ROOT'),
            'control_plane_sha' => self::sha('CAREER_DISCOVERABILITY_CONTROL_PLANE_SHA', 40),
            'release_sha' => self::sha('CAREER_DISCOVERABILITY_RELEASE_SHA', 40),
            'release_name' => self::identity('CAREER_DISCOVERABILITY_RELEASE_NAME'),
            'generation_id' => self::generationId('CAREER_DISCOVERABILITY_GENERATION_ID'),
            'active_pointer_sha256' => self::sha('CAREER_DISCOVERABILITY_ACTIVE_POINTER_SHA256'),
            'task7a_run_id' => self::positiveInt('CAREER_DISCOVERABILITY_TASK7A_RUN_ID'),
            'task7a_run_attempt' => self::positiveInt('CAREER_DISCOVERABILITY_TASK7A_RUN_ATTEMPT'),
            'task7a_artifact_digest' => self::digest('CAREER_DISCOVERABILITY_TASK7A_ARTIFACT_DIGEST'),
            'task7a_receipt_sha256' => self::sha('CAREER_DISCOVERABILITY_TASK7A_RECEIPT_SHA256'),
            'workflow_run_id' => self::positiveInt('CAREER_DISCOVERABILITY_WORKFLOW_RUN_ID'),
            'workflow_run_attempt' => self::positiveInt('CAREER_DISCOVERABILITY_WORKFLOW_RUN_ATTEMPT'),
        ];
        if (! hash_equals($expected['control_plane_sha'], $expected['release_sha'])) {
            throw new Career1046DiscoverabilityReleaseFailure('RELEASE_CONTROL_PLANE_SHA_MISMATCH');
        }

        return $expected;
    }

    /** @param array<string, mixed> $expected @return array<string, mixed> */
    private static function inspectAuthority(array $expected): array
    {
        $root = $expected['authority_root'];
        $activeRaw = self::read($root, $root.'/active-generation.json');
        if (! hash_equals($expected['active_pointer_sha256'], hash('sha256', $activeRaw))) {
            throw new Career1046DiscoverabilityReleaseFailure('ACTIVE_POINTER_DRIFT');
        }
        $active = self::decode($activeRaw, 'ACTIVE_POINTER_JSON_INVALID');
        $payload = $active['payload'] ?? null;
        $discoverability = is_array($payload) && is_array($payload['discoverability'] ?? null)
            ? $payload['discoverability']
            : null;
        if (! is_array($payload) || ($payload['generation_id'] ?? null) !== $expected['generation_id']
            || ! is_array($discoverability)
            || ($discoverability['sitemap_mutated'] ?? null) !== false
            || ($discoverability['llms_mutated'] ?? null) !== false
            || ($discoverability['search_mutated'] ?? null) !== false) {
            throw new Career1046DiscoverabilityReleaseFailure('ACTIVE_POINTER_CONTRACT_DRIFT');
        }
        $generationRoot = $root.'/generations/'.$expected['generation_id'];
        $immutableRaw = self::read($root, $generationRoot.'/generation-pointer.json');
        if (! hash_equals($activeRaw, $immutableRaw)) {
            throw new Career1046DiscoverabilityReleaseFailure('IMMUTABLE_POINTER_DRIFT');
        }
        $documents = [];
        $rows = [];
        foreach (['generation_manifest' => 'generation-manifest.json', 'directory_en' => 'career-directory-en.json', 'directory_zh' => 'career-directory-zh.json', 'detail_en' => 'career-job-details-en.json', 'detail_zh' => 'career-job-details-zh.json'] as $key => $filename) {
            $artifacts = is_array($payload['artifacts'] ?? null) ? $payload['artifacts'] : [];
            $descriptor = $artifacts[$key] ?? null;
            $raw = self::read($root, $generationRoot.'/'.$filename);
            if (! is_array($descriptor) || ($descriptor['path'] ?? null) !== 'generations/'.$expected['generation_id'].'/'.$filename
                || ! is_string($descriptor['sha256'] ?? null) || ! hash_equals($descriptor['sha256'], hash('sha256', $raw))) {
                throw new Career1046DiscoverabilityReleaseFailure('GENERATION_DOCUMENT_DRIFT');
            }
            $documents[$filename] = hash('sha256', $raw);
            if (str_starts_with($filename, 'career-directory-')) {
                $document = self::decode($raw, 'DIRECTORY_JSON_INVALID');
                $locale = str_contains($filename, '-zh') ? 'zh' : 'en';
                foreach (($document['items'] ?? []) as $item) {
                    $slug = is_array($item) ? strtolower(trim((string) ($item['slug'] ?? ''))) : '';
                    if ($slug === '' || ! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
                        throw new Career1046DiscoverabilityReleaseFailure('DIRECTORY_SLUG_INVALID');
                    }
                    $rows[] = $slug.'|'.$locale;
                }
            }
        }
        sort($rows, SORT_STRING);
        if (count($rows) !== Career1046DiscoverabilityReleaseGate::TARGET_LOCALE_ROW_COUNT || count(array_unique($rows)) !== count($rows)
            || ! hash_equals(Career1046DiscoverabilityReleaseGate::TARGET_LOCALE_ROW_SET_SHA256, hash('sha256', implode("\n", $rows)."\n"))) {
            throw new Career1046DiscoverabilityReleaseFailure('LOCALE_ROW_SET_DRIFT');
        }
        $slugs = array_values(array_unique(array_map(static fn (string $row): string => strtok($row, '|'), $rows)));
        sort($slugs, SORT_STRING);
        if (count($slugs) !== Career1046DiscoverabilityReleaseGate::TARGET_SLUG_COUNT
            || ! hash_equals(Career1046DiscoverabilityReleaseGate::TARGET_SLUG_SET_SHA256, hash('sha256', implode("\n", $slugs)."\n"))) {
            throw new Career1046DiscoverabilityReleaseFailure('SLUG_SET_DRIFT');
        }

        return [
            'generation_id' => $expected['generation_id'],
            'active_pointer_sha256' => hash('sha256', $activeRaw),
            'immutable_pointer_sha256' => hash('sha256', $immutableRaw),
            'document_sha256' => $documents,
            'released_locale_rows' => $rows,
        ];
    }

    /** @param array<string, mixed> $expected @return array<string, mixed> */
    private static function databaseState(array $expected): array
    {
        require_once $expected['backend_root'].'/vendor/autoload.php';
        $app = require $expected['backend_root'].'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        require_once $expected['backend_root'].'/scripts/operations/career_publication_index_reconciliation_preflight.php';
        $verbs = [];
        DB::listen(static function (QueryExecuted $query) use (&$verbs): void {
            $verbs[] = strtolower((string) strtok(ltrim($query->sql), " \t\r\n"));
        });
        $manifestRaw = self::read($expected['backend_root'], $expected['backend_root'].'/docs/seo/generated/detail-ready-1046-rollout-manifest.v1.json');
        $manifest = self::decode($manifestRaw, 'DATABASE_MANIFEST_INVALID');
        $delta = $manifest['delta_slugs'] ?? null;
        if (! is_array($delta)) {
            throw new Career1046DiscoverabilityReleaseFailure('DATABASE_DELTA_INVALID');
        }
        $occupations = Occupation::query()->whereIn('canonical_slug', $delta)->get(['id', 'canonical_slug'])->map(static fn (Occupation $row): array => ['id' => (string) $row->id, 'canonical_slug' => (string) $row->canonical_slug])->all();
        $states = IndexState::query()->whereIn('occupation_id', array_column($occupations, 'id'))->get(['id', 'occupation_id', 'index_state', 'index_eligible', 'canonical_path', 'canonical_target', 'reason_codes', 'changed_at', 'created_at'])->map(static fn (IndexState $row): array => ['id' => (string) $row->id, 'occupation_id' => (string) $row->occupation_id, 'index_state' => (string) $row->index_state, 'index_eligible' => (bool) $row->index_eligible, 'canonical_path' => (string) $row->canonical_path, 'canonical_target' => $row->canonical_target === null ? '' : (string) $row->canonical_target, 'reason_codes' => is_array($row->reason_codes) ? $row->reason_codes : [], 'changed_at' => (string) $row->changed_at, 'created_at' => (string) $row->created_at])->all();
        $analysis = CareerPublicationIndexReconciliationPreflight::analyze($manifest, $app->make(CareerVerifiedRolloutBatchSlugAuthority::class)->slugs(), $occupations, $states);
        $database = $analysis['database_latest_index_state'] ?? null;
        if ($verbs === [] || array_values(array_unique($verbs)) !== ['select'] || ! is_array($database)
            || ($database['receipt_covered_count'] ?? null) !== 1016 || ($database['matching_count'] ?? null) !== 1016
            || ($database['missing_or_mismatching_count'] ?? null) !== 0 || ($database['full_delta_match'] ?? null) !== true
            || ! is_string($database['current_state_sha256'] ?? null)) {
            throw new Career1046DiscoverabilityReleaseFailure('DATABASE_AUTHORITY_DRIFT');
        }

        return ['current_state_sha256' => $database['current_state_sha256'], 'query_count' => count($verbs)];
    }

    /** @param array<string, mixed> $expected @param array<string, mixed> $receipt */
    private static function assertApply(array $expected, array $receipt): void
    {
        if (getenv('CAREER_DISCOVERABILITY_APPLY_AUTHORIZED') !== '1') {
            throw new Career1046DiscoverabilityReleaseFailure('APPLY_NOT_AUTHORIZED');
        }
        $preflight = self::sha('CAREER_DISCOVERABILITY_PREFLIGHT_RECEIPT_SHA256');
        $phrase = 'I explicitly approve Career 1046 discoverability release for generation '.$expected['generation_id'].' from preflight receipt '.$preflight.'; release exactly 2092 sitemap locale URLs and 1046 llms slugs, keep Search, IndexNow, GSC, and URL Inspection disabled.';
        if (! hash_equals($phrase, self::required('CAREER_DISCOVERABILITY_OPERATOR_APPROVAL_PHRASE'))) {
            throw new Career1046DiscoverabilityReleaseFailure('APPLY_APPROVAL_INVALID');
        }
    }

    /** @param array<string, mixed> $expected @param array<string, mixed> $authority @param array<string, mixed> $database */
    private static function writePermit(array $expected, array $authority, array $database): void
    {
        $root = $expected['authority_root'];
        $finalDir = $root.'/discoverability-releases/'.$authority['generation_id'];
        if (file_exists($finalDir) || is_link($finalDir)) {
            throw new Career1046DiscoverabilityReleaseFailure('DISCOVERABILITY_RELEASE_ALREADY_EXISTS');
        }
        $parent = dirname($finalDir);
        if (! is_dir($parent) && ! mkdir($parent, 0750, true) && ! is_dir($parent)) {
            throw new Career1046DiscoverabilityReleaseFailure('DISCOVERABILITY_RELEASE_PARENT_CREATE_FAILED');
        }
        $temporary = $parent.'/.'.$authority['generation_id'].'.'.bin2hex(random_bytes(8));
        if (! mkdir($temporary, 0750)) {
            throw new Career1046DiscoverabilityReleaseFailure('DISCOVERABILITY_RELEASE_TEMP_CREATE_FAILED');
        }
        $bytes = CareerGenerationCanonicalJson::encode(self::buildPermit($authority, $expected, $database))."\n";
        try {
            if (file_put_contents($temporary.'/release.json', $bytes, LOCK_EX) !== strlen($bytes) || ! hash_equals(hash('sha256', $bytes), hash_file('sha256', $temporary.'/release.json'))) {
                throw new Career1046DiscoverabilityReleaseFailure('DISCOVERABILITY_RELEASE_WRITE_FAILED');
            }
            if (! rename($temporary, $finalDir)) {
                throw new Career1046DiscoverabilityReleaseFailure('DISCOVERABILITY_RELEASE_FINALIZE_FAILED');
            }
            self::$writes['discoverability_release_write_count'] = 1;
            self::$writes['writes_committed'] = true;
        } catch (Throwable $failure) {
            @unlink($temporary.'/release.json');
            @rmdir($temporary);
            throw $failure;
        }
    }

    /** @return array<string, mixed> */
    private static function receipt(string $mode, string $status, ?string $failedStage, array $expected, array $authority, array $database): array
    {
        return [
            'contract_version' => self::CONTRACT_VERSION, 'mode' => $mode, 'status' => $status, 'failed_stage' => $failedStage,
            'control_plane_sha' => $expected['control_plane_sha'], 'release_sha' => $expected['release_sha'], 'release_name_sha256' => hash('sha256', $expected['release_name']),
            'generation_id' => $authority['generation_id'], 'active_pointer_sha256' => $authority['active_pointer_sha256'], 'immutable_pointer_sha256' => $authority['immutable_pointer_sha256'],
            'task7a_run_id' => $expected['task7a_run_id'], 'task7a_run_attempt' => $expected['task7a_run_attempt'], 'task7a_artifact_digest' => $expected['task7a_artifact_digest'], 'task7a_receipt_sha256' => $expected['task7a_receipt_sha256'],
            'workflow_run_id' => $expected['workflow_run_id'], 'workflow_run_attempt' => $expected['workflow_run_attempt'],
            'database_state_sha256' => $database['current_state_sha256'], 'slug_count' => 1046, 'locale_row_count' => 2092, 'document_sha256' => $authority['document_sha256'],
            ...self::$writes,
        ];
    }

    /** @return array<string, mixed> */
    private static function failure(string $mode, string $code): array
    {
        return ['contract_version' => self::CONTRACT_VERSION, 'mode' => $mode, 'status' => 'FAIL_DISCOVERABILITY_RELEASE_CONTROL', 'failed_stage' => $code, ...self::$writes];
    }

    /** @return array<string, int|bool|string> */
    private static function emptyWrites(): array
    {
        return ['production_write_execution' => false, 'discoverability_release_write_count' => 0, 'pointer_write_count' => 0, 'database_write_count' => 0, 'cms_write_count' => 0, 'cache_write_count' => 0, 'sitemap_write_count' => 0, 'llms_write_count' => 0, 'search_submission_count' => 0, 'deploy_count' => 0, 'migration_count' => 0, 'restart_count' => 0, 'warm_count' => 0, 'writes_committed' => false, 'automatic_retry_allowed' => false];
    }

    private static function emit(array $receipt): void
    {
        echo json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    }

    private static function required(string $name): string
    {
        $value = getenv($name);
        if (! is_string($value) || trim($value) === '') {
            throw new Career1046DiscoverabilityReleaseFailure('ENVIRONMENT_INVALID');
        }

        return trim($value);
    }

    private static function sha(string $name, int $length = 64): string
    {
        $value = self::required($name);
        if (preg_match('/^[0-9a-f]{'.$length.'}$/', $value) !== 1) {
            throw new Career1046DiscoverabilityReleaseFailure('ENVIRONMENT_INVALID');
        }

        return $value;
    }

    private static function digest(string $name): string
    {
        $value = self::required($name);
        if (preg_match('/^sha256:[0-9a-f]{64}$/', $value) !== 1) {
            throw new Career1046DiscoverabilityReleaseFailure('ENVIRONMENT_INVALID');
        }

        return $value;
    }

    private static function positiveInt(string $name): int
    {
        $value = self::required($name);
        if (preg_match('/^[1-9][0-9]*$/', $value) !== 1) {
            throw new Career1046DiscoverabilityReleaseFailure('ENVIRONMENT_INVALID');
        }

        return (int) $value;
    }

    private static function generationId(string $name): string
    {
        $value = self::required($name);
        if (preg_match('/^career-1046-[0-9a-f]{32}$/', $value) !== 1) {
            throw new Career1046DiscoverabilityReleaseFailure('ENVIRONMENT_INVALID');
        }

        return $value;
    }

    private static function identity(string $name): string
    {
        $value = self::required($name);
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $value) !== 1) {
            throw new Career1046DiscoverabilityReleaseFailure('ENVIRONMENT_INVALID');
        }

        return $value;
    }

    private static function absoluteDirectory(string $name): string
    {
        $value = self::required($name);
        if ($value[0] !== '/' || str_contains($value, '..') || is_link($value) || ! is_dir($value)) {
            throw new Career1046DiscoverabilityReleaseFailure('ENVIRONMENT_INVALID');
        } $real = realpath($value);
        if (! is_string($real)) {
            throw new Career1046DiscoverabilityReleaseFailure('ENVIRONMENT_INVALID');
        }

        return $real;
    }

    /** @return array<string, mixed> */
    private static function decode(string $raw, string $code): array
    {
        try {
            $value = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new Career1046DiscoverabilityReleaseFailure($code);
        } if (! is_array($value)) {
            throw new Career1046DiscoverabilityReleaseFailure($code);
        }

        return $value;
    }

    private static function read(string $root, string $path): string
    {
        $rootReal = realpath($root);
        $pathReal = realpath($path);
        if (! is_string($rootReal) || ! is_string($pathReal) || is_link($path) || ! str_starts_with($pathReal, $rootReal.'/')) {
            throw new Career1046DiscoverabilityReleaseFailure('AUTHORITY_PATH_INVALID');
        } $raw = file_get_contents($pathReal);
        if (! is_string($raw)) {
            throw new Career1046DiscoverabilityReleaseFailure('AUTHORITY_READ_FAILED');
        }

        return $raw;
    }
}

exit(Career1046DiscoverabilityReleaseControl::main($argv));
