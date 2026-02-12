<?php

declare(strict_types=1);

namespace Tests\Sre;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class MigrationHotTableNotNullGateTest extends TestCase
{
    /**
     * @var list<string>
     */
    private const HOT_TABLES = [
        'attempts',
        'results',
        'orders',
        'payment_events',
        'benefit_grants',
        'report_snapshots',
        'attempt_answer_rows',
        'organization_members',
    ];

    /**
     * @var list<string>
     */
    private const COLUMN_METHODS = [
        'biginteger',
        'binary',
        'boolean',
        'char',
        'date',
        'datetime',
        'datetimetz',
        'decimal',
        'double',
        'enum',
        'float',
        'integer',
        'ipaddress',
        'json',
        'jsonb',
        'longtext',
        'macaddress',
        'mediuminteger',
        'mediumtext',
        'set',
        'smallinteger',
        'softdeletes',
        'softdeletestz',
        'string',
        'text',
        'time',
        'timetz',
        'timestamp',
        'timestamptz',
        'tinyinteger',
        'ulid',
        'unsignedbiginteger',
        'unsignedinteger',
        'unsignedmediuminteger',
        'unsignedsmallinteger',
        'unsignedtinyinteger',
        'uuid',
        'year',
    ];

    private const LOOKAHEAD_LINES = 6;

    #[Test]
    public function added_columns_on_hot_tables_must_define_nullable_or_default(): void
    {
        $violations = [];

        foreach ($this->migrationFiles() as $filePath) {
            if (str_contains(basename($filePath), 'create_')) {
                continue;
            }

            $source = file_get_contents($filePath);
            $this->assertIsString($source, 'unable to read migration file: ' . $filePath);

            $violations = array_merge($violations, $this->collectViolations($filePath, $source));
        }

        $this->assertSame(
            [],
            $violations,
            "hot table nullable/default gate violations:\n" . implode("\n", $violations)
        );
    }

    /**
     * @return list<string>
     */
    private function collectViolations(string $filePath, string $source): array
    {
        $constTables = $this->constantTableMap($source);
        $lines = preg_split('/\R/', $source);
        if (!is_array($lines)) {
            return [];
        }

        $violations = [];
        $inHotBlock = false;
        $currentTable = '';
        $braceDepth = 0;
        $lineCount = count($lines);

        for ($i = 0; $i < $lineCount; $i++) {
            $line = $lines[$i];

            if (!$inHotBlock) {
                [$tableName, $signatureEndLine, $signatureSource] = $this->detectBlockStart($lines, $i, $constTables);
                if ($tableName === null || $signatureSource === null) {
                    continue;
                }

                $inHotBlock = true;
                $currentTable = $tableName;
                $braceDepth = substr_count($signatureSource, '{') - substr_count($signatureSource, '}');
                $i = $signatureEndLine;
                continue;
            }

            if ($this->isColumnDeclarationLine($line)) {
                $statement = $this->statementSnippet($lines, $i);
                $hasNullable = preg_match('/->nullable\s*\(\s*\)/i', $statement) === 1;
                $hasDefault = preg_match('/->default\s*\(/i', $statement) === 1;
                $hasPrimary = preg_match('/->primary\s*\(/i', $statement) === 1;
                $hasNullableFalse = preg_match('/->nullable\s*\(\s*false\s*\)/i', $statement) === 1;
                $hasDefaultNull = preg_match('/->default\s*\(\s*null\s*\)/i', $statement) === 1;

                if (!$hasPrimary && ((!$hasNullable && !$hasDefault) || $hasNullableFalse || $hasDefaultNull)) {
                    $violations[] = sprintf(
                        'file=%s table=%s line=%d snippet=%s',
                        basename($filePath),
                        $currentTable,
                        $i + 1,
                        $this->truncateSnippet($statement)
                    );
                }
            }

            $braceDepth += substr_count($line, '{') - substr_count($line, '}');
            if ($braceDepth <= 0) {
                $inHotBlock = false;
                $currentTable = '';
                $braceDepth = 0;
            }
        }

        return $violations;
    }

    /**
     * @param array<int, string> $lines
     * @param array<string, string> $constTables
     * @return array{0: ?string, 1: int, 2: ?string}
     */
    private function detectBlockStart(array $lines, int $startLine, array $constTables): array
    {
        $lineCount = count($lines);
        $endLine = $startLine;
        $signature = $lines[$startLine];

        while (
            $endLine + 1 < $lineCount
            && !str_contains($signature, '{')
            && ($endLine - $startLine + 1) < self::LOOKAHEAD_LINES
        ) {
            $endLine++;
            $signature .= "\n" . $lines[$endLine];
        }

        $tableName = null;

        if (preg_match(
            '/Schema::table\s*\(\s*[\'"]([A-Za-z0-9_]+)[\'"]\s*,\s*(?:static\s+)?function\s*\([^)]*\)\s*(?:use\s*\([^)]*\)\s*)?(?::\s*void\s*)?\s*\{/',
            $signature,
            $matches
        ) === 1) {
            $tableName = strtolower($matches[1]);
        } elseif (preg_match(
            '/Schema::table\s*\(\s*self::([A-Z_][A-Z0-9_]*)\s*,\s*(?:static\s+)?function\s*\([^)]*\)\s*(?:use\s*\([^)]*\)\s*)?(?::\s*void\s*)?\s*\{/',
            $signature,
            $matches
        ) === 1) {
            $constName = $matches[1];
            $tableName = $constTables[$constName] ?? null;
        }

        if ($tableName === null || !in_array($tableName, self::HOT_TABLES, true)) {
            return [null, $startLine, null];
        }

        return [$tableName, $endLine, $signature];
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

    private function isColumnDeclarationLine(string $line): bool
    {
        if (preg_match('/\$table->([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $line, $matches) !== 1) {
            return false;
        }

        $method = strtolower($matches[1]);

        return in_array($method, self::COLUMN_METHODS, true);
    }

    /**
     * @return array<string, string>
     */
    private function constantTableMap(string $source): array
    {
        $constTables = [];

        if (preg_match_all(
            '/const\s+([A-Z_][A-Z0-9_]*)\s*=\s*[\'"]([a-z0-9_]+)[\'"]/i',
            $source,
            $matches,
            PREG_SET_ORDER
        ) !== 1 && empty($matches)) {
            return $constTables;
        }

        foreach ($matches as $match) {
            $constTables[$match[1]] = strtolower($match[2]);
        }

        return $constTables;
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
