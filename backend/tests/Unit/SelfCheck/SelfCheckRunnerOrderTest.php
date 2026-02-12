<?php

declare(strict_types=1);

namespace Tests\Unit\SelfCheck;

use App\Services\SelfCheck\SelfCheckContext;
use App\Services\SelfCheck\SelfCheckIo;
use App\Services\SelfCheck\SelfCheckResult;
use App\Services\SelfCheck\SelfCheckRunner;
use Tests\TestCase;

final class SelfCheckRunnerOrderTest extends TestCase
{
    public function test_runner_preserves_order_and_marks_overall_failure(): void
    {
        $ctx = SelfCheckContext::fromCommandOptions([]);
        $runner = new SelfCheckRunner(new SelfCheckIo());

        $checks = [
            new class {
                public function name(): string
                {
                    return 'first';
                }

                public function run(SelfCheckContext $ctx, SelfCheckIo $io): SelfCheckResult
                {
                    $result = new SelfCheckResult('first');
                    $result->addNote('ok');
                    return $result;
                }
            },
            new class {
                public function name(): string
                {
                    return 'second';
                }

                public function run(SelfCheckContext $ctx, SelfCheckIo $io): SelfCheckResult
                {
                    $result = new SelfCheckResult('second');
                    $result->addError('boom');
                    return $result;
                }
            },
        ];

        $results = $runner->runAll($ctx, $checks);

        $this->assertCount(2, $results);
        $this->assertSame('first', $results[0]->section);
        $this->assertSame('second', $results[1]->section);
        $this->assertFalse($runner->isOverallOk($results));
        $this->assertNotEmpty($results[1]->errors);
    }
}
