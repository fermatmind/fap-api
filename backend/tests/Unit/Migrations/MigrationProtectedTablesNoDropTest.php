<?php

declare(strict_types=1);

namespace Tests\Unit\Migrations;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class MigrationProtectedTablesNoDropTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $protectedTables = [
        'attempts',
        'results',
        'events',
        'shares',
        'orders',
        'payment_events',
        'benefit_grants',
        'benefit_wallets',
        'benefit_wallet_ledgers',
        'benefit_consumptions',
        'fm_tokens',
    ];

    #[Test]
    public function protected_business_tables_must_not_be_dropped_in_any_down_method(): void
    {
        $files = $this->migrationFiles();
        $this->assertNotEmpty($files, 'database/migrations must not be empty');

        foreach ($files as $filePath) {
            $source = (string) file_get_contents($filePath);
            $downBody = $this->methodBody($source, 'down');

            if ($downBody === null) {
                continue;
            }

            $clean = $this->stripComments($downBody);
            preg_match_all('/Schema::drop(?:IfExists)?\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', $clean, $matches);

            $tables = $matches[1] ?? [];
            foreach ($tables as $tableName) {
                $table = strtolower(trim((string) $tableName));
                $this->assertNotContains(
                    $table,
                    $this->protectedTables,
                    "Protected table must not be dropped in down(): {$filePath} -> {$table}"
                );
            }
        }
    }

    /**
     * @return list<string>
     */
    private function migrationFiles(): array
    {
        $files = glob(base_path('database/migrations/*.php'));
        if (!is_array($files)) {
            return [];
        }

        sort($files);

        return array_values($files);
    }

    private function methodBody(string $source, string $methodName): ?string
    {
        $tokens = token_get_all($source);
        $count = count($tokens);

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

            if ($name !== $methodName) {
                continue;
            }

            while ($i < $count && $this->tokenText($tokens[$i]) !== '{') {
                $i++;
            }

            if ($i >= $count || $this->tokenText($tokens[$i]) !== '{') {
                return null;
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
                        return $body;
                    }
                    $body .= $text;
                    continue;
                }

                $body .= $text;
            }

            return null;
        }

        return null;
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

    private function stripComments(string $source): string
    {
        $code = str_starts_with(ltrim($source), '<?php') ? $source : "<?php\n{$source}";
        $tokens = token_get_all($code);
        $output = '';

        foreach ($tokens as $token) {
            if (is_string($token)) {
                $output .= $token;
                continue;
            }

            $tokenId = $token[0];
            if ($tokenId === T_COMMENT || $tokenId === T_DOC_COMMENT || $tokenId === T_OPEN_TAG) {
                continue;
            }

            $output .= $token[1];
        }

        return $output;
    }
}
