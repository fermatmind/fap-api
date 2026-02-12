<?php

declare(strict_types=1);

namespace App\Services\SelfCheck\Checks;

use App\Services\SelfCheck\SelfCheckResult;

abstract class BaseSelfCheck
{
    /**
     * @param array{0:bool,1:array<int,string>} $legacy
     */
    protected function absorbLegacy(SelfCheckResult $result, string $label, array $legacy): void
    {
        $ok = (bool) ($legacy[0] ?? false);
        $messages = $legacy[1] ?? [];
        $messages = is_array($messages) ? $messages : [(string) $messages];

        foreach ($messages as $message) {
            $msg = (string) $message;
            $prefixed = $label === '' ? $msg : "{$label}: {$msg}";

            if ($this->looksLikeWarning($msg)) {
                $result->addWarning($prefixed);
                continue;
            }

            if ($ok) {
                $result->addNote($prefixed);
            } else {
                $result->addError($prefixed);
            }
        }

        if (!$ok && $messages === []) {
            $result->addError($label === '' ? 'check failed' : "{$label}: check failed");
        }
    }

    /**
     * @param array<string, bool> $declaredBasenames
     * @param callable(): array{0:bool,1:array<int,string>} $runner
     */
    protected function runIfDeclared(
        SelfCheckResult $result,
        array $declaredBasenames,
        string $sectionName,
        string $basename,
        callable $runner
    ): void {
        if (!isset($declaredBasenames[$basename])) {
            $result->addNote("{$sectionName}: SKIPPED (not declared in manifest.assets): {$basename}");
            return;
        }

        $this->absorbLegacy($result, $sectionName, $runner());
    }

    protected function looksLikeWarning(string $message): bool
    {
        $m = ltrim($message);
        return str_starts_with($m, 'WARN')
            || str_starts_with($m, '-- warnings --')
            || str_contains($m, ' WARN ')
            || str_contains($m, 'warning');
    }
}
