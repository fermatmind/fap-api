<?php

declare(strict_types=1);

namespace Tests\Unit\Migrations;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class MigrationDestructiveRetirementEvidenceTest extends TestCase
{
    private const EVIDENCE_PATH = 'docs/migrations/destructive-retirements.json';

    #[Test]
    public function destructive_migrations_without_bound_evidence_are_reported(): void
    {
        $migration = 'database/migrations/2099_01_01_000000_drop_untracked_table.php';
        $source = <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::drop('untracked_table');
    }

    public function down(): void
    {
        // forward-only
    }
};
PHP;

        $operations = $this->destructiveUpOperations($migration, $source);
        $missing = $this->missingEvidence($operations, []);

        $this->assertSame(
            ['database/migrations/2099_01_01_000000_drop_untracked_table.php drops untracked_table without retirement evidence'],
            $missing
        );
    }

    #[Test]
    public function array_drop_columns_require_bound_evidence_for_every_column(): void
    {
        $migration = 'database/migrations/2099_01_01_000000_drop_media_columns.php';
        $source = <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personality_public_content_assets', function (Blueprint $table): void {
            $table->dropColumn(['media_json', 'media_authority']);
        });
    }
};
PHP;
        $evidence = [
            $migration => [
                'operation' => 'drop_column',
                'table' => 'media_json',
                'runbook' => 'docs/migrations/personality-public-content-media-retirement-runbook.md',
                'production_archive_status' => 'not_asserted_by_repository',
            ],
        ];

        $operations = $this->destructiveUpOperations($migration, $source);

        $this->assertSame([
            ['migration' => $migration, 'operation' => 'drop_column', 'table' => 'media_json'],
            ['migration' => $migration, 'operation' => 'drop_column', 'table' => 'media_authority'],
        ], $operations);
        $this->assertSame(
            ["{$migration} has incomplete retirement evidence for media_authority"],
            $this->missingEvidence($operations, $evidence),
        );
    }

    #[Test]
    public function mixed_literal_and_dynamic_drop_columns_fail_closed(): void
    {
        $migration = 'database/migrations/2099_01_01_000000_drop_dynamic_media_column.php';
        $source = <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personality_public_content_assets', function (Blueprint $table): void {
            $column = 'media_authority';
            $table->dropColumn(['media_json', $column]);
        });
    }
};
PHP;
        $operations = $this->destructiveUpOperations($migration, $source);

        $this->assertSame([
            ['migration' => $migration, 'operation' => 'drop_column', 'table' => 'media_json'],
            ['migration' => $migration, 'operation' => 'drop_column', 'table' => '__dynamic_drop_column_expression__'],
        ], $operations);
        $this->assertSame(
            [
                "{$migration} drops media_json without retirement evidence",
                "{$migration} uses unresolved destructive target __dynamic_drop_column_expression__",
            ],
            $this->missingEvidence($operations, []),
        );
    }

    #[Test]
    public function dynamic_table_and_interpolated_column_drops_fail_closed(): void
    {
        $migration = 'database/migrations/2099_01_01_000000_drop_dynamic_targets.php';
        $source = <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = 'retired_table';
        Schema::drop($tableName);

        Schema::table('personality_public_content_assets', function (Blueprint $table): void {
            $suffix = 'json';
            $table->dropColumn(["media_$suffix"]);
        });
    }
};
PHP;
        $operations = $this->destructiveUpOperations($migration, $source);

        $this->assertSame([
            ['migration' => $migration, 'operation' => 'drop_table', 'table' => '__dynamic_drop_table_expression__'],
            ['migration' => $migration, 'operation' => 'drop_column', 'table' => 'media_$suffix'],
            ['migration' => $migration, 'operation' => 'drop_column', 'table' => '__dynamic_drop_column_expression__'],
        ], $operations);
        $this->assertCount(3, $this->missingEvidence($operations, []));
    }

    #[Test]
    public function dynamic_drop_targets_cannot_be_evidenced(): void
    {
        $migration = 'database/migrations/2099_01_01_000000_drop_dynamic_table.php';
        $source = <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = 'retired_table';
        Schema::drop($tableName);
    }
};
PHP;
        $operations = $this->destructiveUpOperations($migration, $source);
        $evidence = [
            $migration => [
                'operation' => 'drop_table',
                'table' => '__dynamic_drop_table_expression__',
                'runbook' => 'docs/migrations/attempt-quality-retirement-runbook.md',
                'production_archive_status' => 'not_asserted_by_repository',
            ],
        ];

        $this->assertSame(
            ["{$migration} uses unresolved destructive target __dynamic_drop_table_expression__"],
            $this->missingEvidence($operations, $evidence),
        );
    }

    #[Test]
    public function current_destructive_migrations_have_bound_retirement_evidence(): void
    {
        $evidenceByMigration = $this->evidenceByMigration();
        $operations = [];

        foreach ($this->migrationFiles() as $filePath) {
            $relativePath = $this->relativeBackendPath($filePath);
            $source = (string) file_get_contents($filePath);
            $operations = array_merge($operations, $this->destructiveUpOperations($relativePath, $source));
        }

        $this->assertNotEmpty($operations, 'Expected at least one destructive retirement migration to be evidence-gated.');
        $this->assertSame([], $this->missingEvidence($operations, $evidenceByMigration));

        foreach ($evidenceByMigration as $migration => $evidence) {
            $source = (string) file_get_contents(base_path($migration));
            $this->assertStringContainsString((string) $evidence['id'], $source);
        }
    }

    #[Test]
    public function evidence_bound_retirement_migrations_have_non_destructive_down_methods(): void
    {
        foreach ($this->migrationFiles() as $filePath) {
            $source = (string) file_get_contents($filePath);
            if (! str_contains($source, 'RETIREMENT_EVIDENCE_ID')) {
                continue;
            }

            $downBody = $this->methodBody($source, 'down');
            $this->assertIsString($downBody, "Missing down() method in {$filePath}");

            $clean = $this->stripComments($downBody);
            $this->assertDoesNotMatchRegularExpression(
                '/Schema\s*::\s*drop(?:IfExists)?\s*\(/',
                $clean,
                "Evidence-bound retirement must not drop a table in down(): {$filePath}",
            );
            $this->assertDoesNotMatchRegularExpression(
                '/->\s*dropColumn\s*\(/',
                $clean,
                "Evidence-bound retirement must not drop a column in down(): {$filePath}",
            );
        }
    }

    #[Test]
    public function attempt_quality_retirement_has_structured_evidence_and_runbook(): void
    {
        $migration = 'database/migrations/2026_03_26_120000_drop_attempt_quality_table.php';
        $evidence = $this->evidenceByMigration()[$migration] ?? null;

        $this->assertIsArray($evidence);
        $this->assertSame('attempt_quality_retirement_2026_03_26', $evidence['id'] ?? null);
        $this->assertSame('drop_table', $evidence['operation'] ?? null);
        $this->assertSame('attempt_quality', $evidence['table'] ?? null);
        $this->assertSame('not_asserted_by_repository', $evidence['production_archive_status'] ?? null);
        $this->assertFalse((bool) ($evidence['production_execution_allowed_by_repository'] ?? true));
        $this->assertTrue((bool) ($evidence['operator_checklist_required'] ?? false));

        $runbook = (string) ($evidence['runbook'] ?? '');
        $this->assertNotSame('', $runbook);
        $this->assertFileExists(base_path($runbook));

        $source = (string) file_get_contents(base_path($migration));
        $this->assertStringContainsString((string) $evidence['id'], $source);
        $this->assertStringContainsString("Schema::drop('attempt_quality')", $source);
    }

    #[Test]
    public function attempt_quality_retirement_down_is_forward_only_and_non_destructive(): void
    {
        $source = (string) file_get_contents(base_path('database/migrations/2026_03_26_120000_drop_attempt_quality_table.php'));
        $downBody = $this->methodBody($source, 'down');

        $this->assertIsString($downBody);
        $clean = $this->stripComments($downBody);
        $this->assertDoesNotMatchRegularExpression('/Schema\s*::\s*drop(?:IfExists)?\s*\(/', $clean);
        $this->assertDoesNotMatchRegularExpression('/->\s*dropColumn\s*\(/', $clean);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function evidenceByMigration(): array
    {
        $path = base_path(self::EVIDENCE_PATH);
        $this->assertFileExists($path);

        $payload = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($payload);
        $this->assertSame('migration_destructive_retirements.v1', $payload['schema_version'] ?? null);

        $entries = $payload['entries'] ?? null;
        $this->assertIsArray($entries);

        $byMigration = [];
        foreach ($entries as $entry) {
            $this->assertIsArray($entry);
            $migration = (string) ($entry['migration'] ?? '');
            $this->assertNotSame('', $migration);
            $this->assertIsString($entry['id'] ?? null);
            $this->assertNotSame('', $entry['id']);
            $this->assertArrayNotHasKey($migration, $byMigration, "Duplicate destructive migration evidence for {$migration}");
            $this->assertFalse((bool) ($entry['production_execution_allowed_by_repository'] ?? true));
            $this->assertTrue((bool) ($entry['operator_checklist_required'] ?? false));
            $byMigration[$migration] = $entry;
        }

        return $byMigration;
    }

    /**
     * @return list<string>
     */
    private function migrationFiles(): array
    {
        $files = glob(base_path('database/migrations/*.php'));
        if (! is_array($files)) {
            return [];
        }

        sort($files);

        return array_values($files);
    }

    /**
     * @return list<array{migration: string, operation: string, table: string}>
     */
    private function destructiveUpOperations(string $migration, string $source): array
    {
        $upBody = $this->methodBody($source, 'up');
        if ($upBody === null) {
            return [];
        }

        $clean = $this->stripComments($upBody);
        $operations = [];

        if (preg_match_all('/Schema\s*::\s*drop(?:IfExists)?\s*\(\s*([^)]*)\)/s', $clean, $matches) > 0) {
            foreach ($matches[1] as $arguments) {
                foreach ($this->destructiveArgumentValues(
                    (string) $arguments,
                    '__dynamic_drop_table_expression__',
                ) as $table) {
                    $operations[] = [
                        'migration' => $migration,
                        'operation' => 'drop_table',
                        'table' => strtolower($table),
                    ];
                }
            }
        }

        if (preg_match_all('/->\s*dropColumn\s*\(\s*([^)]*)\)/s', $clean, $matches) > 0) {
            foreach ($matches[1] as $arguments) {
                foreach ($this->destructiveArgumentValues(
                    (string) $arguments,
                    '__dynamic_drop_column_expression__',
                ) as $column) {
                    $operations[] = [
                        'migration' => $migration,
                        'operation' => 'drop_column',
                        'table' => strtolower($column),
                    ];
                }
            }
        }

        return $operations;
    }

    /**
     * @return list<string>
     */
    private function destructiveArgumentValues(string $arguments, string $dynamicSentinel): array
    {
        $literalMatches = [];
        $literalCount = preg_match_all('/([\'"])([^\'"]*)\1/', $arguments, $literalMatches, PREG_SET_ORDER);
        $literalValues = [];
        $hasInterpolatedLiteral = false;

        if ($literalCount !== false) {
            foreach ($literalMatches as $match) {
                $literalValues[] = (string) $match[2];
                $hasInterpolatedLiteral = $hasInterpolatedLiteral
                    || ($match[1] === '"' && str_contains((string) $match[2], '$'));
            }
        }

        $withoutLiterals = preg_replace('/([\'"])([^\'"]*)\1/', '', $arguments);
        $nonLiteralExpression = preg_replace('/[\s\[\],]/', '', $withoutLiterals ?? $arguments);
        if ($literalValues === [] || $nonLiteralExpression !== '' || $hasInterpolatedLiteral) {
            $literalValues[] = $dynamicSentinel;
        }

        return $literalValues;
    }

    /**
     * @param  list<array{migration: string, operation: string, table: string}>  $operations
     * @param  array<string, array<string, mixed>>  $evidenceByMigration
     * @return list<string>
     */
    private function missingEvidence(array $operations, array $evidenceByMigration): array
    {
        $missing = [];

        foreach ($operations as $operation) {
            $migration = $operation['migration'];
            if (str_starts_with($operation['table'], '__dynamic_drop_')) {
                $missing[] = "{$migration} uses unresolved destructive target {$operation['table']}";

                continue;
            }

            $evidence = $evidenceByMigration[$migration] ?? null;

            if (! is_array($evidence)) {
                $missing[] = "{$migration} drops {$operation['table']} without retirement evidence";

                continue;
            }

            $matchesOperation = ($evidence['operation'] ?? null) === $operation['operation'];
            $matchesTable = strtolower((string) ($evidence['table'] ?? '')) === $operation['table'];
            $hasRunbook = is_string($evidence['runbook'] ?? null)
                && $evidence['runbook'] !== ''
                && is_file(base_path((string) $evidence['runbook']));
            $doesNotClaimProductionArchive = ($evidence['production_archive_status'] ?? null) === 'not_asserted_by_repository';

            if (! $matchesOperation || ! $matchesTable || ! $hasRunbook || ! $doesNotClaimProductionArchive) {
                $missing[] = "{$migration} has incomplete retirement evidence for {$operation['table']}";
            }
        }

        return $missing;
    }

    private function methodBody(string $source, string $methodName): ?string
    {
        $tokens = token_get_all($source);
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (! is_array($token) || $token[0] !== T_FUNCTION) {
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

    private function relativeBackendPath(string $filePath): string
    {
        $base = rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $this->assertStringStartsWith($base, $filePath);

        return str_replace(DIRECTORY_SEPARATOR, '/', substr($filePath, strlen($base)));
    }

    /**
     * @param  string|array{int, string, int}  $token
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
