<?php

declare(strict_types=1);

namespace Tests\Sre;

use PHPUnit\Framework\TestCase;

final class MbtiTraitContentDeployContractTest extends TestCase
{
    public function test_exact_classification_selects_both_environment_publications(): void
    {
        $root = dirname(__DIR__, 3);
        $workflow = file_get_contents($root.'/.github/workflows/deploy.yml');
        $recipe = file_get_contents($root.'/deploy.php');
        $this->assertStringContainsString('.classification.operations.mbti_trait_content_publish // false', $workflow);
        $this->assertSame(2, substr_count($workflow, "-o mbti_trait_content_publish='\${{ needs.policy.outputs.mbti_trait_content_publish }}'"));
        $this->assertStringContainsString("set('mbti_trait_content_publish', false)", $recipe);
        $start = strpos($recipe, "task('mbti:publish-trait-content'");
        $end = strpos($recipe, "task('eq60:rollback-private-result-authority", $start);
        $task = substr($recipe, $start, $end - $start);
        $this->assertStringContainsString("get('mbti_trait_content_publish', false)", $task);
        $this->assertStringContainsString("hash_file('sha256', \$path)", $task);
        $this->assertStringContainsString("hash('sha256', implode('', \$fileHashes))", $task);
        $this->assertStringContainsString('--expected-hash=', $task);
        $this->assertLessThan(strpos($task, "run(\$command.' --write')"), strpos($task, 'run($command)'));
        $this->assertStringContainsString("after('mbti:publish-result-introductions', 'mbti:publish-trait-content')", $recipe);
        $this->assertStringContainsString("after('mbti:publish-trait-content', 'guard:career-runtime-projection-authority')", $recipe);
    }
}
