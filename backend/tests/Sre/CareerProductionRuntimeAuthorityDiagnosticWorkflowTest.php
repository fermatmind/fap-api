<?php

declare(strict_types=1);

namespace Tests\Sre;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class CareerProductionRuntimeAuthorityDiagnosticWorkflowTest extends TestCase
{
    public function test_diagnostic_is_exact_main_runtime_user_read_only_and_pointer_receipt_bound(): void
    {
        $workflow = $this->repoFile('.github/workflows/career-production-runtime-authority-diagnostic.yml');
        $runner = $this->repoFile('backend/scripts/operations/career_production_runtime_authority_diagnostic.php');
        $deploy = $this->repoFile('.github/workflows/deploy.yml');

        Yaml::parse($workflow);
        foreach ([
            'workflow_dispatch:',
            'environment: production',
            'test "$(git rev-parse origin/main)" = "$CONTROL_PLANE_SHA"',
            'actions/runs/31593321673',
            'sha256:101508066a741afd44d29b4c28bd866b1fa3d4772dfda14c71da71c320c545c7',
            'e0898f6fbb438495319cf0acc8bd6f808eba18c3f3beeb4a4e7d690312c27bc4',
            'sudo -n -u www-data',
            'test ! -e $q_path/.dep/deploy.lock',
            'permission_write_count == 0',
            'writes_committed == false',
            "jq -e '.status == \"PASS_RUNTIME_AUTHORITY_READABLE\"'",
        ] as $needle) {
            self::assertStringContainsString($needle, $workflow);
        }
        foreach ([
            'curl -k',
            '--insecure',
            'chmod -R',
            'chown ',
            'chgrp ',
            'workflow_dispatch deploy',
            'gh workflow run deploy',
            'queue:restart',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $workflow);
        }

        foreach ([
            'career.production_runtime_authority_diagnostic.v1',
            'career-current-342-30-bootstrap-v1',
            '1ebfd2826be9d3b63d810d33050034e3d424c95b3db81fa49b0822c5e6b2ec08',
            '($active[\'schema_version\'] ?? null) !== \'career.generation_pointer.v1\'',
            'posix_getpwuid(posix_geteuid())',
            'CareerGenerationAuthorityLoader::class)->loadStrict()',
            "preg_match('/^career_generation_[a-z0-9_]{1,120}$/D'",
            "'permission_write_count' => 0",
            "'writes_committed' => false",
        ] as $needle) {
            self::assertStringContainsString($needle, $runner);
        }
        foreach (['file_put_contents', 'mkdir(', 'rename(', 'unlink(', 'chmod(', 'chown(', 'chgrp('] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $runner);
        }

        foreach ([
            '.github/workflows/career-production-runtime-authority-diagnostic.yml',
            'backend/scripts/operations/career_production_runtime_authority_diagnostic.php',
            'backend/tests/Sre/CareerProductionRuntimeAuthorityDiagnosticWorkflowTest.php',
        ] as $ignored) {
            self::assertStringContainsString($ignored, $deploy);
        }
    }

    private function repoFile(string $path): string
    {
        $contents = file_get_contents(dirname(__DIR__, 3).'/'.$path);
        self::assertIsString($contents);

        return $contents;
    }
}
