<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PhpParser\Error;
use PHPUnit\Framework\TestCase;

final class PhpSourceCallsTest extends TestCase
{
    public function test_scanner_ignores_comments_generated_scripts_and_domain_methods(): void
    {
        $source = <<<'SOURCE'
<?php
// env('COMMENT'); request(); JsonResponse
$script = <<<'PHP'
<?php getenv('INDEPENDENT_SCRIPT'); response();
PHP;
$validator->request($input);
Validator::request($input);
function request() {}
$documentation = "env('GENERATED')";
SOURCE;
        $scan = require dirname(__DIR__, 2).'/scripts/ci/php_source_calls.php';
        $this->assertSame([], $scan($source, ['env', 'getenv', 'request', 'response'], ['jsonresponse']));
    }

    public function test_scanner_rejects_actual_calls_including_whitespace_and_import_aliases(): void
    {
        $source = <<<'PHP'
<?php
namespace Fixture;
use function getenv as runtimeValue;
use Illuminate\Http\JsonResponse as Reply;
env /* comment */ ('KEY');
\getenv ('KEY');
runtimeValue('KEY');
request ();
new Reply([]);
PHP;
        $scan = require dirname(__DIR__, 2).'/scripts/ci/php_source_calls.php';
        $names = array_column($scan($source, ['env', 'getenv', 'request'], ['jsonresponse']), 'name');
        $this->assertSame(1, count(array_keys($names, 'env', true)));
        $this->assertSame(2, count(array_keys($names, 'getenv', true)));
        $this->assertContains('request', $names);
        $this->assertContains('Illuminate\Http\JsonResponse', $names);
    }

    public function test_invalid_php_cannot_produce_a_clean_verdict(): void
    {
        $scan = require dirname(__DIR__, 2).'/scripts/ci/php_source_calls.php';
        $this->expectException(Error::class);
        $scan('<?php env(', ['env']);
    }
}
