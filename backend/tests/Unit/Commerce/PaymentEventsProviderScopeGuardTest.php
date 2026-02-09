<?php

declare(strict_types=1);

namespace Tests\Unit\Commerce;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class PaymentEventsProviderScopeGuardTest extends TestCase
{
    #[Test]
    public function payment_events_queries_using_provider_event_id_must_include_provider_scope(): void
    {
        $violations = [];

        foreach ($this->appPhpFiles() as $filePath) {
            $source = (string) file_get_contents($filePath);

            foreach ($this->methodBodies($source) as $methodName => $body) {
                $hasProviderEventFilter = str_contains($body, "where('provider_event_id'")
                    || str_contains($body, 'where("provider_event_id"');
                if (!$hasProviderEventFilter) {
                    continue;
                }

                $referencesPaymentEvents = str_contains($body, "'payment_events'")
                    || str_contains($body, '"payment_events"');
                if (!$referencesPaymentEvents) {
                    continue;
                }

                $hasProviderScope = str_contains($body, "where('provider'")
                    || str_contains($body, 'where("provider"');
                if ($hasProviderScope) {
                    continue;
                }

                $violations[] = "{$filePath}::{$methodName}";
            }
        }

        $this->assertSame(
            [],
            $violations,
            "Found payment_events provider_event_id queries without provider scope:\n" . implode("\n", $violations)
        );
    }

    /**
     * @return list<string>
     */
    private function appPhpFiles(): array
    {
        $root = base_path('app');
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        $files = [];
        foreach ($iterator as $item) {
            if (!$item->isFile()) {
                continue;
            }

            $path = $item->getPathname();
            if (str_ends_with($path, '.php')) {
                $files[] = $path;
            }
        }

        sort($files);

        return $files;
    }

    /**
     * @return array<string, string>
     */
    private function methodBodies(string $source): array
    {
        $tokens = token_get_all($source);
        $count = count($tokens);
        $methods = [];

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token) || $token[0] !== T_FUNCTION) {
                continue;
            }

            $name = null;
            for ($j = $i + 1; $j < $count; $j++) {
                $next = $tokens[$j];
                if (is_array($next) && $next[0] === T_STRING) {
                    $name = $next[1];
                    $i = $j;
                    break;
                }
            }

            if ($name === null) {
                continue;
            }

            while ($i < $count && $this->tokenText($tokens[$i]) !== '{') {
                $i++;
            }

            if ($i >= $count || $this->tokenText($tokens[$i]) !== '{') {
                continue;
            }

            $braceDepth = 1;
            $i++;
            $body = '';

            for (; $i < $count; $i++) {
                $text = $this->tokenText($tokens[$i]);

                if ($text === '{') {
                    $braceDepth++;
                    $body .= $text;
                    continue;
                }

                if ($text === '}') {
                    $braceDepth--;
                    if ($braceDepth === 0) {
                        $methods[$name] = $body;
                        break;
                    }
                    $body .= $text;
                    continue;
                }

                $body .= $text;
            }
        }

        return $methods;
    }

    /**
     * @param string|array{int, string, int} $token
     */
    private function tokenText(string|array $token): string
    {
        if (is_string($token)) {
            return $token;
        }

        return $token[1];
    }
}
