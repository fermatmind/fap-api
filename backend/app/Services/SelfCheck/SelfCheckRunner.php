<?php

declare(strict_types=1);

namespace App\Services\SelfCheck;

final class SelfCheckRunner
{
    public function __construct(private readonly SelfCheckIo $io)
    {
    }

    /**
     * @param array<int, object> $checks
     * @return array<int, SelfCheckResult>
     */
    public function runAll(SelfCheckContext $ctx, array $checks): array
    {
        $this->io->applyContext($ctx);

        $results = [];
        foreach ($checks as $check) {
            if (!is_object($check) || !method_exists($check, 'run') || !method_exists($check, 'name')) {
                continue;
            }

            $section = (string) $check->name();
            $result = $check->run($ctx, $this->io);

            if (!$result instanceof SelfCheckResult) {
                $fallback = new SelfCheckResult($section);
                $fallback->addError('invalid check result object');
                $results[] = $fallback;
                continue;
            }

            $results[] = $result;
        }

        return $results;
    }

    /** @param array<int, SelfCheckResult> $results */
    public function isOverallOk(array $results): bool
    {
        foreach ($results as $result) {
            if (!$result->isOk()) {
                return false;
            }
        }

        return true;
    }
}
