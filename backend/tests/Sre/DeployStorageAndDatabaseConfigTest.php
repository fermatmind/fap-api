<?php

declare(strict_types=1);

namespace Tests\Sre;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class DeployStorageAndDatabaseConfigTest extends TestCase
{
    #[Test]
    public function mysql_connection_keeps_ssl_ca_option_configurable(): void
    {
        $source = $this->readRepoFile('backend/config/database.php');
        $mysqlBlock = $this->extractArrayBlock($source, "'mysql' => [");

        $this->assertStringContainsString("'options' => extension_loaded('pdo_mysql')", $mysqlBlock);
        $this->assertStringContainsString('MYSQL_ATTR_SSL_CA', $mysqlBlock);
        $this->assertStringContainsString("env('MYSQL_ATTR_SSL_CA')", $mysqlBlock);
    }

    #[Test]
    public function deploy_keeps_artifact_parent_dirs_group_writable_without_rewriting_artifacts_tree(): void
    {
        $source = $this->readRepoFile('deploy.php');

        $this->assertStringContainsString('ensureOwnedWritableDir("{$base}/app", $owner, \'www-data\');', $source);
        $this->assertStringContainsString('ensureOwnedWritableDir("{$base}/app/private", $owner, \'www-data\');', $source);
        $this->assertStringContainsString('ensureOwnedWritableDir("{$base}/app/private/artifacts", $owner, \'www-data\');', $source);
        $this->assertStringNotContainsString('ensureOwnedWritableTree(deploySharedPath($base, \'shared/backend/storage/app/private/artifacts\')', $source);
        $this->assertDoesNotMatchRegularExpression('/chmod\s+(?:0?777|a\+w|ugo\+rwX)/', $source);
    }

    private function readRepoFile(string $relativePath): string
    {
        $path = dirname(__DIR__, 3).'/'.$relativePath;
        $source = file_get_contents($path);

        $this->assertIsString($source, 'unable to read '.$relativePath);

        return $source;
    }

    private function extractArrayBlock(string $source, string $needle): string
    {
        $offset = strpos($source, $needle);
        $this->assertNotFalse($offset, 'missing block start: '.$needle);

        $start = strpos($source, '[', (int) $offset);
        $this->assertNotFalse($start, 'missing array start: '.$needle);

        $depth = 0;
        $length = strlen($source);

        for ($i = (int) $start; $i < $length; $i++) {
            $char = $source[$i];

            if ($char === '[') {
                $depth++;
            } elseif ($char === ']') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, (int) $offset, $i - (int) $offset + 1);
                }
            }
        }

        $this->fail('missing array end: '.$needle);
    }
}
