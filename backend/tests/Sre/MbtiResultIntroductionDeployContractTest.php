<?php

declare(strict_types=1);

namespace Tests\Sre;

use PHPUnit\Framework\TestCase;

final class MbtiResultIntroductionDeployContractTest extends TestCase
{
    public function test_exact_classification_selects_both_environment_publications(): void
    {
        $root = dirname(__DIR__, 3);
        $workflow = file_get_contents($root.'/.github/workflows/deploy.yml');
        $recipe = file_get_contents($root.'/deploy.php');
        $this->assertStringContainsString('.classification.operations.mbti_result_introductions_publish // false', $workflow);
        $this->assertSame(2, substr_count($workflow, "-o mbti_result_introductions_publish='\${{ needs.policy.outputs.mbti_result_introductions_publish }}'"));
        $this->assertStringContainsString("set('mbti_result_introductions_publish', false)", $recipe);
        $start = strpos($recipe, "task('mbti:publish-result-introductions'");
        $end = strpos($recipe, "task('eq60:rollback-private-result-authority", $start);
        $task = substr($recipe, $start, $end - $start);
        $this->assertStringContainsString("get('mbti_result_introductions_publish', false)", $task);
        $this->assertStringContainsString("hash_file('sha256', \$path)", $task);
        $this->assertStringContainsString("hash('sha256', implode('', \$fileHashes))", $task);
        $this->assertStringContainsString('--expected-hash=', $task);
        $this->assertLessThan(strpos($task, "run(\$command.' --write')"), strpos($task, 'run($command)'));
        $this->assertStringContainsString("after('eq60:publish-private-result-authority', 'mbti:publish-result-introductions')", $recipe);
        $this->assertStringContainsString("after('mbti:publish-result-introductions', 'guard:career-runtime-projection-authority')", $recipe);
    }
}
