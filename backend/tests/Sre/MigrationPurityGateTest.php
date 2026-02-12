<?php

declare(strict_types=1);

namespace Tests\Sre;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class MigrationPurityGateTest extends TestCase
{
    /**
     * @var list<array{keyword: string, regex: string}>
     */
    private const FORBIDDEN_PATTERNS = [
        ['keyword' => 'DB::table(', 'regex' => '/\bDB::table\s*\(/'],
        ['keyword' => '->update(', 'regex' => '/->update\s*\(/'],
        ['keyword' => '->insert(', 'regex' => '/->insert\s*\(/'],
        ['keyword' => '->delete(', 'regex' => '/->delete\s*\(/'],
        ['keyword' => 'upsert(', 'regex' => '/\bupsert\s*\(/'],
        ['keyword' => 'insertOrIgnore(', 'regex' => '/\binsertOrIgnore\s*\(/'],
        ['keyword' => 'truncate(', 'regex' => '/\btruncate\s*\(/'],
        ['keyword' => 'DB::statement(', 'regex' => '/\bDB::statement\s*\(/'],
        ['keyword' => 'DB::select(', 'regex' => '/\bDB::select\s*\(/'],
        ['keyword' => 'groupBy(', 'regex' => '/\bgroupBy\s*\(/'],
        ['keyword' => 'having(', 'regex' => '/\bhaving\s*\(/'],
        ['keyword' => 'join(', 'regex' => '/\bjoin\s*\(/'],
        ['keyword' => 'cursor()', 'regex' => '/\bcursor\s*\(/'],
        ['keyword' => 'chunkById(', 'regex' => '/\bchunkById\s*\(/'],
        ['keyword' => '::query()', 'regex' => '/::query\s*\(/'],
        ['keyword' => '::where(', 'regex' => '/::where\s*\(/'],
        ['keyword' => 'Model::', 'regex' => '/\bModel::/'],
        ['keyword' => 'use App\\Models\\', 'regex' => '/^\s*use\s+App\\\\Models\\\\/'],
        ['keyword' => 'App\\Models\\', 'regex' => '/\bApp\\\\Models\\\\/'],
        ['keyword' => 'new App\\Models\\...(', 'regex' => '/\bnew\s+\\\\?App\\\\Models\\\\[A-Za-z_][A-Za-z0-9_]*\s*\(/'],
    ];

    /**
     * @var list<string>
     */
    private const DB_GUARD_ALLOWLIST = [
        '/\bSHOW\s+INDEX\b/i',
        '/\binformation_schema\b/i',
        '/\bpg_indexes\b/i',
        '/\bPRAGMA\s+index_list\s*\(/i',
        '/\bDB::raw\s*\(/i',
    ];

    private const LOOKAHEAD_LINES = 6;

    #[Test]
    public function migrations_must_remain_schema_only_and_not_contain_data_backfills(): void
    {
        $violations = [];
        $seen = [];

        foreach ($this->migrationFiles() as $filePath) {
            $source = file_get_contents($filePath);
            $this->assertIsString($source, 'unable to read migration file: ' . $filePath);

            $cleanSource = $this->stripCommentsPreservingLineNumbers($source);
            $lines = preg_split('/\R/', $cleanSource);
            if (!is_array($lines)) {
                continue;
            }

            $modelAliases = $this->modelAliases($lines);
            $violations = array_merge(
                $violations,
                $this->collectForbiddenPatternViolations($filePath, $lines, $seen)
            );
            $violations = array_merge(
                $violations,
                $this->collectModelAliasViolations($filePath, $lines, $modelAliases, $seen)
            );
        }

        $this->assertSame(
            [],
            $violations,
            "migration purity gate violations:\n" . implode("\n", $violations)
        );
    }

    /**
     * @param array<int, string> $lines
     * @param array<string, bool> $seen
     * @return list<string>
     */
    private function collectForbiddenPatternViolations(string $filePath, array $lines, array &$seen): array
    {
        $violations = [];

        foreach ($lines as $lineIndex => $line) {
            foreach (self::FORBIDDEN_PATTERNS as $rule) {
                if (preg_match($rule['regex'], $line) !== 1) {
                    continue;
                }

                $snippet = $this->statementSnippet($lines, $lineIndex);
                if ($this->isAllowlisted($rule['keyword'], $snippet)) {
                    continue;
                }

                $violationKey = sprintf('%s|%d|%s', $filePath, $lineIndex + 1, $rule['keyword']);
                if (isset($seen[$violationKey])) {
                    continue;
                }
                $seen[$violationKey] = true;

                $violations[] = sprintf(
                    'file=%s line=%d keyword=%s snippet=%s',
                    basename($filePath),
                    $lineIndex + 1,
                    $rule['keyword'],
                    $this->truncateSnippet($snippet)
                );
            }
        }

        return $violations;
    }

    /**
     * @param array<int, string> $lines
     * @param list<string> $modelAliases
     * @param array<string, bool> $seen
     * @return list<string>
     */
    private function collectModelAliasViolations(
        string $filePath,
        array $lines,
        array $modelAliases,
        array &$seen
    ): array {
        if ($modelAliases === []) {
            return [];
        }

        $violations = [];

        foreach ($lines as $lineIndex => $line) {
            foreach ($modelAliases as $alias) {
                $quoted = preg_quote($alias, '/');
                $isNewModel = preg_match('/\bnew\s+' . $quoted . '\s*\(/', $line) === 1;
                $isStaticModelCall = preg_match('/\b' . $quoted . '::/', $line) === 1;

                if (!$isNewModel && !$isStaticModelCall) {
                    continue;
                }

                $keyword = $isNewModel ? "new {$alias}(" : "{$alias}::";
                $violationKey = sprintf('%s|%d|%s', $filePath, $lineIndex + 1, $keyword);
                if (isset($seen[$violationKey])) {
                    continue;
                }
                $seen[$violationKey] = true;

                $violations[] = sprintf(
                    'file=%s line=%d keyword=%s snippet=%s',
                    basename($filePath),
                    $lineIndex + 1,
                    $keyword,
                    $this->truncateSnippet($this->statementSnippet($lines, $lineIndex))
                );
            }
        }

        return $violations;
    }

    /**
     * @param array<int, string> $lines
     * @return list<string>
     */
    private function modelAliases(array $lines): array
    {
        $aliases = [];

        foreach ($lines as $line) {
            if (preg_match(
                '/^\s*use\s+App\\\\Models\\\\([A-Za-z_][A-Za-z0-9_]*)(?:\s+as\s+([A-Za-z_][A-Za-z0-9_]*))?\s*;/',
                $line,
                $matches
            ) !== 1) {
                continue;
            }

            $aliases[] = $matches[2] !== '' ? $matches[2] : $matches[1];
        }

        return array_values(array_unique($aliases));
    }

    private function isAllowlisted(string $keyword, string $snippet): bool
    {
        if (!in_array($keyword, ['DB::select(', 'DB::statement('], true)) {
            return false;
        }

        foreach (self::DB_GUARD_ALLOWLIST as $allowPattern) {
            if (preg_match($allowPattern, $snippet) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, string> $lines
     */
    private function statementSnippet(array $lines, int $startLine): string
    {
        $lineCount = count($lines);
        $endLine = min($lineCount - 1, $startLine + self::LOOKAHEAD_LINES - 1);
        $parts = [];

        for ($i = $startLine; $i <= $endLine; $i++) {
            $parts[] = trim($lines[$i]);
            if (str_contains($lines[$i], ';')) {
                break;
            }
        }

        return preg_replace('/\s+/', ' ', trim(implode(' ', $parts))) ?? '';
    }

    private function stripCommentsPreservingLineNumbers(string $source): string
    {
        $tokens = token_get_all($source);
        $clean = '';

        foreach ($tokens as $token) {
            if (is_string($token)) {
                $clean .= $token;
                continue;
            }

            $tokenId = $token[0];
            $tokenText = $token[1];

            if ($tokenId === T_COMMENT || $tokenId === T_DOC_COMMENT) {
                $clean .= str_repeat("\n", substr_count($tokenText, "\n"));
                continue;
            }

            $clean .= $tokenText;
        }

        return $clean;
    }

    private function truncateSnippet(string $snippet): string
    {
        if (strlen($snippet) <= 220) {
            return $snippet;
        }

        return substr($snippet, 0, 217) . '...';
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
}
