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
        $this->assertDoesNotMatchRegularExpression('/Schema::drop(?:IfExists)?\s*\(/', $clean);
        $this->assertDoesNotMatchRegularExpression('/->dropColumn\s*\(/', $clean);
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
            $this->assertArrayNotHasKey($migration, $byMigration, "Duplicate destructive migration evidence for {$migration}");
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

        if (preg_match_all('/Schema::drop(?:IfExists)?\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', $clean, $matches) > 0) {
            foreach ($matches[1] as $table) {
                $operations[] = [
                    'migration' => $migration,
                    'operation' => 'drop_table',
                    'table' => strtolower((string) $table),
                ];
            }
        }

        if (preg_match_all('/->dropColumn\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', $clean, $matches) > 0) {
            foreach ($matches[1] as $column) {
                $operations[] = [
                    'migration' => $migration,
                    'operation' => 'drop_column',
                    'table' => strtolower((string) $column),
                ];
            }
        }

        return $operations;
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
