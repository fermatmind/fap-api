<?php

declare(strict_types=1);

namespace Tests\Sre;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class CareerCurrentGenerationPointerBootstrapWorkflowTest extends TestCase
{
    private string $root;

    private string $backendRoot;

    /** @var array<string, string> */
    private array $environment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/career-pointer-bootstrap-'.bin2hex(random_bytes(8));
        $release = $this->root.'/releases/release-001';
        $this->backendRoot = $release.'/backend';
        File::ensureDirectoryExists($this->backendRoot);
        File::ensureDirectoryExists($this->root.'/shared/storage/app/private/career_runtime_publish_projection/source-001');
        File::ensureDirectoryExists($this->root.'/shared/storage/app/private/career_release_ledger/source-001');
        self::assertTrue(symlink($this->root.'/shared/storage', $this->backendRoot.'/storage'));
        File::put($release.'/REVISION', str_repeat('b', 40)."\n");
        self::assertTrue(symlink($release, $this->root.'/current'));

        $projection = [
            'projection_kind' => 'career_runtime_publish_projection',
            'projection_version' => 'career.runtime_publish_projection.v1',
            'source_authority' => 'CareerFullReleaseLedger',
            'items' => [
                $this->projectionRow('actors', 'en', 'published'),
                $this->projectionRow('actors', 'zh', 'published'),
                $this->projectionRow('actuaries', 'en', 'blocked'),
                $this->projectionRow('actuaries', 'zh', 'blocked'),
            ],
        ];
        $ledger = [
            'ledger_kind' => 'career_full_release_ledger',
            'ledger_version' => 'career.full_release_ledger.v1',
            'members' => [
                ['canonical_slug' => 'actors'],
                ['canonical_slug' => 'actuaries'],
            ],
        ];
        $projectionPath = $this->backendRoot.'/storage/app/private/career_runtime_publish_projection/source-001/career-runtime-publish-projection.json';
        $ledgerPath = $this->backendRoot.'/storage/app/private/career_release_ledger/source-001/career-full-release-ledger.json';
        File::put($projectionPath, $this->encodeJson($projection));
        File::put($ledgerPath, $this->encodeJson($ledger));

        $this->environment = [
            'CAREER_POINTER_BACKEND_ROOT' => $this->backendRoot,
            'CAREER_POINTER_DEPLOY_PATH' => $this->root,
            'CAREER_POINTER_CONTROL_PLANE_SHA' => str_repeat('a', 40),
            'CAREER_POINTER_EXPECTED_RELEASE_SHA' => str_repeat('b', 40),
            'CAREER_POINTER_EXPECTED_RELEASE_NAME' => 'release-001',
            'CAREER_POINTER_WORKFLOW_RUN_ID' => '700',
            'CAREER_POINTER_WORKFLOW_RUN_ATTEMPT' => '1',
            'CAREER_POINTER_GENERATION_ID' => 'career-current-2-1-bootstrap-v1',
            'CAREER_POINTER_TIMESTAMP' => '2026-08-12T03:00:00Z',
            'CAREER_POINTER_FROZEN_MANIFEST_SHA256' => str_repeat('1', 64),
            'CAREER_POINTER_FREEZE_CONTRACT_PAYLOAD_SHA256' => str_repeat('2', 64),
            'CAREER_POINTER_RECEIPT_SET_SHA256' => str_repeat('3', 64),
            'CAREER_POINTER_PROJECTION_SHA256' => hash_file('sha256', $projectionPath),
            'CAREER_POINTER_LEDGER_SHA256' => hash_file('sha256', $ledgerPath),
            'CAREER_POINTER_SLUG_SET_SHA256' => $this->setHash(['actors', 'actuaries']),
            'CAREER_POINTER_LOCALE_ROW_SET_SHA256' => $this->setHash([
                'actors|en',
                'actors|zh',
                'actuaries|en',
                'actuaries|zh',
            ]),
            'CAREER_POINTER_SLUG_COUNT' => '2',
            'CAREER_POINTER_LOCALE_ROW_COUNT' => '4',
            'CAREER_POINTER_PUBLISHED_SLUG_COUNT' => '1',
            'CAREER_POINTER_PUBLISHED_LOCALE_ROW_COUNT' => '2',
            'CAREER_POINTER_APPLY_AUTHORIZED' => '0',
            'CAREER_POINTER_PREFLIGHT_RECEIPT_SHA256' => '',
            'CAREER_POINTER_EXPECTED_PROJECTION_PATH_SHA256' => '',
            'CAREER_POINTER_EXPECTED_LEDGER_PATH_SHA256' => '',
        ];
    }

    protected function tearDown(): void
    {
        if (is_link($this->root.'/current')) {
            unlink($this->root.'/current');
        }
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    public function test_preflight_is_select_only_and_apply_creates_only_exact_pointer_documents(): void
    {
        $before = $this->treeHash($this->backendRoot.'/storage/app/private');
        $preflight = $this->runControl('preflight');

        $preflight->mustRun();
        $receipt = $this->receipt($preflight);
        self::assertSame('PASS_PREFLIGHT_APPLY_ELIGIBLE', $receipt['status']);
        self::assertTrue($receipt['zero_write_guarantee']);
        self::assertFalse($receipt['production_write_execution']);
        self::assertSame(0, $receipt['pointer_write_count']);
        self::assertSame($before, $this->treeHash($this->backendRoot.'/storage/app/private'));

        $projectionPath = $this->backendRoot.'/storage/app/private/career_runtime_publish_projection/source-001/career-runtime-publish-projection.json';
        $ledgerPath = $this->backendRoot.'/storage/app/private/career_release_ledger/source-001/career-full-release-ledger.json';
        $projectionBefore = hash_file('sha256', $projectionPath);
        $ledgerBefore = hash_file('sha256', $ledgerPath);
        $apply = $this->runControl('apply', [
            'CAREER_POINTER_APPLY_AUTHORIZED' => '1',
            'CAREER_POINTER_PREFLIGHT_RECEIPT_SHA256' => str_repeat('4', 64),
            'CAREER_POINTER_EXPECTED_PROJECTION_PATH_SHA256' => $receipt['source_artifacts']['projection_path_sha256'],
            'CAREER_POINTER_EXPECTED_LEDGER_PATH_SHA256' => $receipt['source_artifacts']['ledger_path_sha256'],
        ]);

        $apply->mustRun();
        $applyReceipt = $this->receipt($apply);
        self::assertSame('PASS_APPLY_POINTER_BOOTSTRAPPED', $applyReceipt['status']);
        self::assertSame(2, $applyReceipt['candidate_file_write_count']);
        self::assertSame(2, $applyReceipt['pointer_write_count']);
        self::assertTrue($applyReceipt['writes_committed']);
        self::assertFalse($applyReceipt['zero_write_guarantee']);
        self::assertSame($projectionBefore, hash_file('sha256', $projectionPath));
        self::assertSame($ledgerBefore, hash_file('sha256', $ledgerPath));

        $authorityRoot = $this->backendRoot.'/storage/app/private/career_generation_authority';
        $activePath = $authorityRoot.'/active-generation.json';
        $immutablePath = $authorityRoot.'/generations/career-current-2-1-bootstrap-v1/generation-pointer.json';
        self::assertFileExists($activePath);
        self::assertFileExists($immutablePath);
        self::assertSame(hash_file('sha256', $immutablePath), hash_file('sha256', $activePath));
        $document = json_decode((string) file_get_contents($activePath), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('legacy_exact_bytes_v1', $document['payload']['artifact_format']);
        self::assertNull($document['payload']['lineage']['previous_generation_id']);
        self::assertFalse($document['payload']['rollback']['eligible']);
        self::assertSame(str_repeat('4', 64), $document['payload']['activation_receipt']['sha256']);
        self::assertSame(
            hash('sha256', $this->canonicalJson($document['payload'])),
            $document['payload_sha256'],
        );

        $activeBefore = hash_file('sha256', $activePath);
        $retry = $this->runControl('apply', [
            'CAREER_POINTER_APPLY_AUTHORIZED' => '1',
            'CAREER_POINTER_PREFLIGHT_RECEIPT_SHA256' => str_repeat('4', 64),
            'CAREER_POINTER_EXPECTED_PROJECTION_PATH_SHA256' => $receipt['source_artifacts']['projection_path_sha256'],
            'CAREER_POINTER_EXPECTED_LEDGER_PATH_SHA256' => $receipt['source_artifacts']['ledger_path_sha256'],
        ]);
        $retry->run();
        self::assertSame(1, $retry->getExitCode());
        self::assertSame('EXISTING_POINTER_CONFLICT', $this->receipt($retry)['failed_stage']);
        self::assertSame($activeBefore, hash_file('sha256', $activePath));
    }

    public function test_exact_hash_selection_uses_stable_relative_path_for_byte_identical_candidates(): void
    {
        $source = $this->backendRoot.'/storage/app/private/career_runtime_publish_projection/source-001/career-runtime-publish-projection.json';
        $duplicateDir = $this->backendRoot.'/storage/app/private/career_runtime_publish_projection/source-002';
        File::ensureDirectoryExists($duplicateDir);
        File::copy($source, $duplicateDir.'/career-runtime-publish-projection.json');

        $duplicate = $this->runControl('preflight');
        $duplicate->mustRun();
        $receipt = $this->receipt($duplicate);
        self::assertSame('PASS_PREFLIGHT_APPLY_ELIGIBLE', $receipt['status']);
        self::assertSame(2, $receipt['source_artifacts']['projection_candidate_count']);
        self::assertSame(1, $receipt['source_artifacts']['ledger_candidate_count']);
        self::assertSame('relative_path_bytewise_ascending_first_v1', $receipt['source_artifacts']['selection_rule']);
        self::assertSame(
            hash('sha256', 'career_runtime_publish_projection/source-001/career-runtime-publish-projection.json'),
            $receipt['source_artifacts']['projection_path_sha256'],
        );

        File::deleteDirectory($duplicateDir);
        $symlinkTarget = $this->backendRoot.'/storage/app/private/projection-symlink-target';
        self::assertTrue(rename(dirname($source), $symlinkTarget));
        self::assertTrue(symlink($symlinkTarget, dirname($source)));
        $symlinkOnly = $this->runControl('preflight');
        $symlinkOnly->run();
        self::assertSame(1, $symlinkOnly->getExitCode());
        self::assertSame('ARTIFACT_PATH_SAFETY_INVALID', $this->receipt($symlinkOnly)['failed_stage']);
    }

    public function test_apply_rejects_deterministic_selection_drift_from_preflight(): void
    {
        $preflight = $this->runControl('preflight');
        $preflight->mustRun();
        $receipt = $this->receipt($preflight);

        $source = $this->backendRoot.'/storage/app/private/career_runtime_publish_projection/source-001/career-runtime-publish-projection.json';
        $earlierDir = $this->backendRoot.'/storage/app/private/career_runtime_publish_projection/source-000';
        File::ensureDirectoryExists($earlierDir);
        File::copy($source, $earlierDir.'/career-runtime-publish-projection.json');

        $apply = $this->runControl('apply', [
            'CAREER_POINTER_APPLY_AUTHORIZED' => '1',
            'CAREER_POINTER_PREFLIGHT_RECEIPT_SHA256' => str_repeat('4', 64),
            'CAREER_POINTER_EXPECTED_PROJECTION_PATH_SHA256' => $receipt['source_artifacts']['projection_path_sha256'],
            'CAREER_POINTER_EXPECTED_LEDGER_PATH_SHA256' => $receipt['source_artifacts']['ledger_path_sha256'],
        ]);
        $apply->run();

        self::assertSame(1, $apply->getExitCode());
        self::assertSame('PREFLIGHT_ARTIFACT_PATH_DRIFT', $this->receipt($apply)['failed_stage']);
        self::assertFileDoesNotExist(
            $this->backendRoot.'/storage/app/private/career_generation_authority/active-generation.json',
        );
    }

    public function test_workflow_is_manual_latest_main_receipt_bound_and_never_self_dispatches(): void
    {
        $workflow = $this->repoFile('.github/workflows/career-current-generation-pointer-bootstrap-production-ops.yml');
        $runner = $this->repoFile('backend/scripts/operations/career_current_generation_pointer_bootstrap.php');

        foreach ([
            'workflow_dispatch:',
            'expected_control_plane_sha:',
            'expected_release_sha:',
            'expected_release_name:',
            'expected_preflight_receipt_sha256:',
            'expected_projection_path_sha256:',
            'expected_ledger_path_sha256:',
            'operator_approval_phrase:',
            'actions: read',
            'contents: read',
            'test "$(git rev-parse origin/main)" = "$EXPECTED_CONTROL_PLANE_SHA"',
            'gh run download "$PREFLIGHT_RUN_ID"',
            'PASS_PREFLIGHT_APPLY_ELIGIBLE',
            'PASS_APPLY_POINTER_BOOTSTRAPPED',
            'career-current-342-30-bootstrap-v1',
            '397f2a4ec284e9c0a6cd610447541ad4773fa7a7f3045008fab5efb334ec85c6',
            '975b311bb346a090f1add678d5a6d9f1be230f87b223e2c3c829f4c7fd7aac6e',
            '8b328b2e002875a9f92d4c406981f3c3724f066ee817d2d5bd1a61915e1eddf5',
            '607926991fa51c74d6d6c9606ab3b7f8f35918996006a39c68963c16765d5697',
            'SLUG_COUNT: "342"',
            'LOCALE_ROW_COUNT: "684"',
            'PUBLISHED_SLUG_COUNT: "30"',
            'PUBLISHED_LOCALE_ROW_COUNT: "60"',
            'group: deploy-${{ github.repository }}-production',
            'environment: production',
            'if: always()',
            'automatic_retry_allowed: false',
            'automatic_cleanup_allowed: false',
            'automatic_rollback_allowed: false',
            'relative_path_bytewise_ascending_first_v1',
            'write_state="indeterminate"',
        ] as $required) {
            self::assertStringContainsString($required, $workflow.$runner);
        }

        foreach ([
            'schedule:',
            'push:',
            'repository_dispatch:',
            'workflow_run:',
            'gh workflow run',
            'php artisan migrate',
            'queue:restart',
            'deploy:symlink',
            'indexnow',
            'googleapis',
            'mysql ',
            'psql ',
            'INSERT ',
            'UPDATE ',
            'DELETE ',
            'Storage::put(',
            'File::put(',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $workflow.$runner);
        }
    }

    /** @return array<string, mixed> */
    private function projectionRow(string $slug, string $locale, string $state): array
    {
        $published = $state === 'published';

        return [
            'slug' => $slug,
            'locale' => $locale,
            'public_resolution_type' => 'public_canonical_job',
            'runtime_publish_state' => $state,
            'release_gate_pass' => $published,
        ];
    }

    /** @param array<string, string> $overrides */
    private function runControl(string $mode, array $overrides = []): Process
    {
        return new Process(
            [PHP_BINARY, dirname(__DIR__, 2).'/scripts/operations/career_current_generation_pointer_bootstrap.php', $mode],
            null,
            array_merge($this->environment, $overrides),
        );
    }

    /** @return array<string, mixed> */
    private function receipt(Process $process): array
    {
        return json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function repoFile(string $path): string
    {
        $contents = file_get_contents(dirname(__DIR__, 3).'/'.$path);
        self::assertIsString($contents);

        return $contents;
    }

    /** @param list<string> $values */
    private function setHash(array $values): string
    {
        $values = array_values(array_unique($values));
        sort($values, SORT_STRING);

        return hash('sha256', $this->canonicalJson($values));
    }

    private function canonicalJson(mixed $value): string
    {
        $sort = function (mixed $item) use (&$sort): mixed {
            if (! is_array($item)) {
                return $item;
            }
            if (! array_is_list($item)) {
                ksort($item, SORT_STRING);
            }
            foreach ($item as $key => $child) {
                $item[$key] = $sort($child);
            }

            return $item;
        };

        return json_encode(
            $sort($value),
            JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    private function encodeJson(array $payload): string
    {
        return json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        )."\n";
    }

    private function treeHash(string $root): string
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && ! $file->isLink()) {
                $relative = substr($file->getPathname(), strlen($root) + 1);
                $files[$relative] = hash_file('sha256', $file->getPathname());
            }
        }
        ksort($files, SORT_STRING);

        return hash('sha256', $this->canonicalJson($files));
    }
}
