<?php

declare(strict_types=1);

namespace Tests\Sre;

use PHPUnit\Framework\TestCase;

final class MbtiZhResultAuthorityDeployContractTest extends TestCase
{
    public function test_trunk_deploy_binds_and_recovers_the_exact_authority_operation(): void
    {
        $root = dirname(__DIR__, 3);
        $workflow = file_get_contents($root.'/.github/workflows/deploy.yml');
        $manifest = json_decode(
            (string) file_get_contents($root.'/backend/content_assets/personality_public/mbti_zh_result_authority_release.v1.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertIsString($workflow);
        $this->assertSame('mbti.zh_result_authority_release.v1', $manifest['contract_version']);
        $this->assertSame('41755da0ed35dc35d8ef4de68582506a9615924bfbb3548c037c004f34b520c4', $manifest['package_hash']);
        $this->assertSame(32, $manifest['record_count']);
        $this->assertSame(224, $manifest['disabled_slot_count']);
        $this->assertSame(1, $manifest['admin_actor_user_id']);
        $this->assertNotContains(true, $manifest['negative_guarantees']);

        foreach ([
            'needs.production.result == \'success\'',
            'personality:mbti-zh-result-content-release',
            '--stage=dry-run',
            '--stage=draft',
            '--stage=promotion-dry-run',
            '--stage=promote',
            '--stage=readback',
            '--stage=rollback',
            'sudo -n -u www-data',
            'test ! -e $q_path/.dep/deploy.lock',
            'PASS_ALREADY_ACTIVE',
            'PASS_EXACT_AUTHORITY_PUBLISH',
            'public-pre-state.jsonl',
            'rollback-database-readback.json',
            'rollback-public-diff.txt',
        ] as $required) {
            $this->assertStringContainsString($required, $workflow);
        }

        foreach (['workflow_dispatch:', 'recovery.yml', 'mbti-zh-result-content-production-ops.yml'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $workflow);
        }
    }
}
