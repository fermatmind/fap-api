<?php

declare(strict_types=1);

namespace Tests\Sre;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class MigrationPurityGateTest extends TestCase
{
    /**
     * @var list<string>
     */
    private const LEGACY_FILE_ALLOWLIST = [
        '2026_02_13_020000_add_identity_unique_to_idempotency_keys.php',
        '2026_02_14_235000_add_is_active_to_organization_members_table.php',
        '2026_04_23_000100_add_article_translation_contract_v1.php',
        '2026_04_23_010000_create_article_translation_revisions_table.php',
        '2026_04_23_020000_reconcile_article_working_revisions_for_editor_cutover.php',
        '2026_04_23_030000_repair_article_published_revision_pointers.php',
        '2026_04_23_040000_consolidate_article_translation_canonical_owners.php',
        '2026_04_23_050000_add_multilingual_contract_to_cms_content_tables.php',
        '2026_04_24_100000_create_cms_translation_revisions.php',
        '2026_04_24_160000_normalize_support_article_public_paths.php',
        '2026_04_24_170000_normalize_help_content_page_public_paths.php',
        '2026_05_06_010000_add_org_scope_to_personality_profile_children.php',
        '2026_05_27_000100_backfill_homepage_recommended_en_article_media_taxonomy.php',
        '2026_06_11_000100_add_article_sitemap_llms_eligibility_fields.php',
        '2026_06_23_000100_expand_content_release_id_columns.php',
        '2026_06_23_000200_expand_content_release_action_column.php',
        '2026_08_10_120000_converge_assessment_catalog_product_truth.php',
        '2026_08_13_120000_unify_wechat_report_unlock_to_199.php',
        '2026_08_13_130000_add_wechat_membership_skus.php',
    ];

    private const BASELINE_CUTOFF_MIGRATION = '2026_04_21_000000';

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
        '/\bALTER\s+TABLE\b.*\bMODIFY\s+COLUMN\b/is',
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
            if (basename($filePath) < self::BASELINE_CUTOFF_MIGRATION) {
                continue;
            }

            if (in_array(basename($filePath), self::LEGACY_FILE_ALLOWLIST, true)) {
                continue;
            }

            $source = file_get_contents($filePath);
            $this->assertIsString($source, 'unable to read migration file: '.$filePath);

            $cleanSource = $this->stripCommentsPreservingLineNumbers($source);
            $lines = preg_split('/\R/', $cleanSource);
            if (! is_array($lines)) {
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
            "migration purity gate violations:\n".implode("\n", $violations)
        );
    }

    /**
     * @param  array<int, string>  $lines
     * @param  array<string, bool>  $seen
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
     * @param  array<int, string>  $lines
     * @param  list<string>  $modelAliases
     * @param  array<string, bool>  $seen
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
                $isNewModel = preg_match('/\bnew\s+'.$quoted.'\s*\(/', $line) === 1;
                $isStaticModelCall = preg_match('/\b'.$quoted.'::/', $line) === 1;

                if (! $isNewModel && ! $isStaticModelCall) {
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
     * @param  array<int, string>  $lines
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

            $explicitAlias = $matches[2] ?? '';
            $aliases[] = $explicitAlias !== '' ? $explicitAlias : $matches[1];
        }

        return array_values(array_unique($aliases));
    }

    private function isAllowlisted(string $keyword, string $snippet): bool
    {
        if ($keyword === 'DB::table(' && $this->isReadOnlyDuplicatePrecondition($snippet)) {
            return true;
        }
        if (! in_array($keyword, ['DB::select(', 'DB::statement('], true)) {
            return false;
        }

        foreach (self::DB_GUARD_ALLOWLIST as $allowPattern) {
            if (preg_match($allowPattern, $snippet) === 1) {
                return true;
            }
        }

        return false;
    }

    private function isReadOnlyDuplicatePrecondition(string $snippet): bool
    {
        try {
            $nodes = (new \PhpParser\ParserFactory)->createForNewestSupportedVersion()->parse('<?php '.$snippet);
        } catch (\PhpParser\Error) {
            return false;
        }
        if (count($nodes ?? []) !== 1 || ! $nodes[0] instanceof \PhpParser\Node\Stmt\Expression
            || ! $nodes[0]->expr instanceof \PhpParser\Node\Expr\Assign
            || ! $nodes[0]->expr->var instanceof \PhpParser\Node\Expr\Variable
            || ! is_string($nodes[0]->expr->var->name)) {
            return false;
        }
        $call = $nodes[0]->expr->expr;
        $arguments = [];
        foreach (['first', 'havingRaw', 'groupByRaw', 'selectRaw'] as $method) {
            if (! $call instanceof \PhpParser\Node\Expr\MethodCall
                || ! $call->name instanceof \PhpParser\Node\Identifier || $call->name->toString() !== $method
                || count($call->args) !== ($method === 'first' ? 0 : 1)) {
                return false;
            }
            if ($method !== 'first') {
                if (! $call->args[0]->value instanceof \PhpParser\Node\Scalar\String_) {
                    return false;
                }
                $arguments[$method] = preg_replace('/\s+/', ' ', trim($call->args[0]->value->value));
            }
            $call = $call->var;
        }
        if (! $call instanceof \PhpParser\Node\Expr\StaticCall
            || ! $call->class instanceof \PhpParser\Node\Name || $call->class->toString() !== 'DB'
            || ! $call->name instanceof \PhpParser\Node\Identifier || $call->name->toString() !== 'table'
            || count($call->args) !== 1) {
            return false;
        }
        $table = $call->args[0]->value;
        if (! $table instanceof \PhpParser\Node\Scalar\String_
            && ! ($table instanceof \PhpParser\Node\Expr\ClassConstFetch
                && $table->class instanceof \PhpParser\Node\Name && $table->class->toString() === 'self'
                && $table->name instanceof \PhpParser\Node\Identifier)) {
            return false;
        }
        // Accept only a bounded duplicate-existence aggregate, never arbitrary raw SQL or callbacks.
        $column = '[A-Za-z_][A-Za-z0-9_]*';
        $expression = '(?:'.$column.'|LOWER\(TRIM\('.$column.'\)\))';
        if (preg_match('/^('.$expression.') AS '.$column.', COUNT\(\*\) AS '.$column.'$/iD', $arguments['selectRaw'], $match) !== 1) {
            return false;
        }

        return strcasecmp($match[1], $arguments['groupByRaw']) === 0
            && preg_match('/^COUNT\(\*\) > 1$/iD', $arguments['havingRaw']) === 1;
    }

    #[Test]
    public function duplicate_preconditions_do_not_authorize_dml_backfills_or_side_effects(): void
    {
        $read = "\$duplicate = DB::table(self::TABLE)->selectRaw('LOWER(TRIM(canonical_slug)) AS slug, COUNT(*) AS aggregate')->groupByRaw('LOWER(TRIM(canonical_slug))')->havingRaw('COUNT(*) > 1')->first();";
        $this->assertTrue($this->isReadOnlyDuplicatePrecondition($read));
        foreach ([
            str_replace('->first()', "->update(['canonical_slug' => 'replaced'])", $read),
            str_replace('->first()', '->delete()', $read),
            str_replace('->first()', '->insert([])', $read),
            str_replace('->first()', '->upsert([], [])', $read),
            str_replace('->first()', '->truncate()', $read),
            str_replace('LOWER(TRIM(canonical_slug))', 'SLEEP(10)', $read),
            str_replace('COUNT(*) > 1', 'COUNT(*) > 1; DELETE FROM users', $read),
            str_replace('self::TABLE', 'sideEffect()', $read),
            str_replace('$duplicate =', '$state[sideEffect()] =', $read),
            str_replace('->first()', '->first()->save()', $read),
            $read.' DB::table(self::TABLE)->delete();',
            str_replace('->first()', '->first(function () { sideEffect(); })', $read),
        ] as $unsafe) {
            $this->assertFalse($this->isAllowlisted('DB::table(', $unsafe), $unsafe);
        }
    }

    /**
     * @param  array<int, string>  $lines
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

        return substr($snippet, 0, 217).'...';
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
}
