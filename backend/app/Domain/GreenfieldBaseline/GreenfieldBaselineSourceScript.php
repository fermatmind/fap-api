<?php

declare(strict_types=1);

namespace App\Domain\GreenfieldBaseline;

final class GreenfieldBaselineSourceScript
{
    public function render(): string
    {
        $catalog = var_export(GreenfieldBaselineCatalog::datasets(), true);
        $forbiddenFields = var_export(GreenfieldBaselineCatalog::forbiddenFieldNames(), true);
        $streamSchema = var_export(GreenfieldBaselineCatalog::STREAM_SCHEMA, true);
        $projectionFilename = var_export(GreenfieldBaselineCatalog::PROJECTION_FILENAME, true);

        $template = <<<'PHP'
<?php
declare(strict_types=1);

$catalog = __CATALOG__;
$forbiddenFields = __FORBIDDEN_FIELDS__;
$streamSchema = __STREAM_SCHEMA__;
$projectionFilename = __PROJECTION_FILENAME__;

$fail = static function (string $code): never {
    fwrite(STDERR, $code."\n");
    exit(1);
};
$emit = static function (array $payload): void {
    $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    fwrite(STDOUT, $encoded."\n");
};

$deployPath = trim((string) getenv('DEPLOY_PATH'));
$expectedActiveRevision = trim((string) getenv('EXPECTED_ACTIVE_REVISION'));
if ($deployPath === '' || ! str_starts_with($deployPath, '/') || str_contains($deployPath, '..')) {
    $fail('INVALID_DEPLOY_PATH');
}
if (preg_match('/^[0-9a-f]{40}$/', $expectedActiveRevision) !== 1) {
    $fail('INVALID_EXPECTED_ACTIVE_REVISION');
}

$current = realpath($deployPath.'/current');
$releases = realpath($deployPath.'/releases');
if (! is_string($current) || ! is_string($releases) || ! str_starts_with($current, $releases.'/')) {
    $fail('ACTIVE_RELEASE_BOUNDARY_FAILED');
}
$revisionPath = $current.'/REVISION';
$activeRevision = is_file($revisionPath) ? trim((string) file_get_contents($revisionPath)) : '';
if (! hash_equals($expectedActiveRevision, $activeRevision)) {
    $fail('ACTIVE_REVISION_MISMATCH');
}

$backend = $current.'/backend';
$autoload = $backend.'/vendor/autoload.php';
if (! is_file($autoload)) {
    $fail('AUTOLOAD_MISSING');
}
require $autoload;

try {
    Dotenv\Dotenv::createImmutable($backend)->safeLoad();
    $connection = trim((string) ($_ENV['DB_CONNECTION'] ?? getenv('DB_CONNECTION') ?: 'mysql'));
    if ($connection !== 'mysql') {
        $fail('SOURCE_DATABASE_MUST_BE_MYSQL');
    }
    $host = (string) ($_ENV['DB_HOST'] ?? getenv('DB_HOST'));
    $port = (string) ($_ENV['DB_PORT'] ?? getenv('DB_PORT') ?: '3306');
    $database = (string) ($_ENV['DB_DATABASE'] ?? getenv('DB_DATABASE'));
    $username = (string) ($_ENV['DB_USERNAME'] ?? getenv('DB_USERNAME'));
    $password = (string) ($_ENV['DB_PASSWORD'] ?? getenv('DB_PASSWORD'));
    if ($host === '' || $database === '' || $username === '' || preg_match('/^[0-9]{1,5}$/', $port) !== 1) {
        $fail('SOURCE_DATABASE_CONFIG_INCOMPLETE');
    }

    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $database),
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false,
        ],
    );
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $pdo->exec('SET TRANSACTION READ ONLY');
    $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');

    $emit([
        'type' => 'header',
        'schema_version' => $streamSchema,
        'active_revision' => $activeRevision,
        'source_database_name_sha256' => hash('sha256', $database),
        'writes_committed' => false,
    ]);

    $counts = [];
    foreach ($catalog as $definition) {
        $table = (string) $definition['table'];
        $name = (string) $definition['name'];
        if (preg_match('/^[a-z0-9_]+$/', $table) !== 1 || preg_match('/^[a-z0-9_]+$/', $name) !== 1) {
            $fail('INVALID_DATASET_CATALOG');
        }

        $exists = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?');
        $exists->execute([$database, $table]);
        if ((int) $exists->fetchColumn() !== 1) {
            if (($definition['required'] ?? true) === true) {
                $fail('REQUIRED_SOURCE_TABLE_MISSING');
            }
            $counts[$name] = 0;
            continue;
        }

        $columnsStatement = $pdo->prepare('SELECT column_name FROM information_schema.columns WHERE table_schema = ? AND table_name = ? ORDER BY ordinal_position');
        $columnsStatement->execute([$database, $table]);
        $columns = $columnsStatement->fetchAll(PDO::FETCH_COLUMN);
        if (! is_array($columns) || $columns === []) {
            $fail('SOURCE_TABLE_COLUMNS_MISSING');
        }

        $orderColumns = [];
        foreach (['id', 'sku', 'slug', 'locale', 'created_at'] as $candidate) {
            if (in_array($candidate, $columns, true)) {
                $orderColumns[] = '`'.$candidate.'`';
            }
        }
        if ($orderColumns === []) {
            $orderColumns[] = '`'.(string) $columns[0].'`';
        }

        $forcedNullColumns = array_fill_keys(array_merge(
            $forbiddenFields,
            (array) ($definition['nullColumns'] ?? []),
        ), true);
        $selectColumns = [];
        foreach ($columns as $column) {
            $isActorIdentifier = str_ends_with((string) $column, '_admin_user_id')
                || in_array($column, ['created_by', 'reviewed_by', 'created_by_admin_id'], true);
            $quotedColumn = '`'.str_replace('`', '``', (string) $column).'`';
            $selectColumns[] = isset($forcedNullColumns[$column]) || $isActorIdentifier
                ? 'NULL AS '.$quotedColumn
                : $quotedColumn;
        }

        $sql = sprintf('SELECT %s FROM `%s` WHERE %s ORDER BY %s', implode(', ', $selectColumns), $table, (string) $definition['where'], implode(', ', $orderColumns));
        $statement = $pdo->query($sql);
        $count = 0;
        while (($row = $statement->fetch()) !== false) {
            foreach ((array) ($definition['nullColumns'] ?? []) as $column) {
                if (array_key_exists((string) $column, $row)) {
                    $row[(string) $column] = null;
                }
            }
            if (($definition['alignWorkingRevision'] ?? false) === true
                && array_key_exists('working_revision_id', $row)
                && array_key_exists('published_revision_id', $row)) {
                $row['working_revision_id'] = $row['published_revision_id'];
            }
            $emit(['type' => 'row', 'dataset' => $name, 'row' => $row]);
            $count++;
        }
        $counts[$name] = $count;
    }

    $projectionRoot = $backend.'/storage/app/private/career_runtime_publish_projection';
    $candidates = [];
    foreach (glob($projectionRoot.'/*/'.$projectionFilename) ?: [] as $path) {
        if (is_file($path)) {
            $candidates[] = ['path' => $path, 'mtime' => filemtime($path) ?: 0];
        }
    }
    usort($candidates, static fn (array $left, array $right): int => ($right['mtime'] <=> $left['mtime']) ?: strcmp($right['path'], $left['path']));
    if ($candidates === []) {
        $fail('CAREER_RUNTIME_PROJECTION_MISSING');
    }
    $projectionBytes = file_get_contents((string) $candidates[0]['path']);
    if (! is_string($projectionBytes) || json_decode($projectionBytes, true) === null) {
        $fail('CAREER_RUNTIME_PROJECTION_INVALID');
    }
    $emit([
        'type' => 'artifact',
        'name' => 'career_runtime_publish_projection',
        'sha256' => hash('sha256', $projectionBytes),
        'content_base64' => base64_encode($projectionBytes),
    ]);

    $pdo->rollBack();
    ksort($counts, SORT_STRING);
    $emit([
        'type' => 'footer',
        'counts' => $counts,
        'writes_committed' => false,
    ]);
} catch (Throwable $throwable) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $fail('GREENFIELD_SOURCE_EXPORT_FAILED');
}
PHP;

        return str_replace(
            ['__CATALOG__', '__FORBIDDEN_FIELDS__', '__STREAM_SCHEMA__', '__PROJECTION_FILENAME__'],
            [$catalog, $forbiddenFields, $streamSchema, $projectionFilename],
            $template,
        )."\n";
    }
}
