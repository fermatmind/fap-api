<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Cms;

use App\Models\AuditLog;
use App\Services\Cms\BlogV1RevisionWorkspace;
use App\Support\CanonicalTranslationPayloadHash as Hash;
use PDO;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/** Actual binary JSON serialization; never reads or writes application tables. */
final class BlogV1SurfaceProvenanceMysqlTest extends TestCase
{
    public function test_actual_mysql_roundtrip_accepts_object_reordering_and_refuses_provenance_shape_value_type_and_list_drift(): void
    {
        $socket = getenv('BLOG_SURFACE_TEST_MYSQL_SOCKET');
        if (! is_string($socket) || $socket === '') {
            $this->markTestSkipped('Requires an isolated, no-network MySQL test socket.');
        }
        $this->assertStringContainsString('mysql-blog-json-fixture-', $socket);
        $pdo = new PDO('mysql:unix_socket='.$socket.';charset=utf8mb4', 'root', '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        $this->assertStringNotContainsString('MariaDB', $version);
        $records = array_map(static fn (string $locale, int $id): array => [
            'id' => $id, 'org_id' => 0, 'surface_key' => 'articles_index', 'locale' => $locale,
            'owner_admin_user_id' => 1, 'source_sha256' => BlogV1RevisionWorkspace::SOURCE_SHA256,
            'surface_package_sha256' => str_repeat('a', 64), 'candidate_sha256' => str_repeat('b', 64),
            'surface_state_sha256' => str_repeat('c', 64),
        ], ['en', 'zh-CN'], [-1, -2]); // Explicit synthetic IDs, not CMS rows.
        $meta = ['surface_locales' => ['en', 'zh-CN'], 'surface_records' => $records];
        $statement = $pdo->prepare("SELECT JSON_EXTRACT(CAST(? AS JSON), '$')");
        $roundtrip = static function (array $payload) use ($statement): AuditLog {
            $statement->execute([json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)]);
            $audit = new AuditLog;
            $audit->setRawAttributes(['meta_json' => (string) $statement->fetchColumn()], true);

            return $audit;
        };
        $workspace = (new ReflectionClass(BlogV1RevisionWorkspace::class))->newInstanceWithoutConstructor();
        $guard = new ReflectionMethod(BlogV1RevisionWorkspace::class, 'surfaceRecordsMatch');
        $actual = $roundtrip($meta);
        $this->assertNotSame($records, $actual->meta_json['surface_records'], 'This must reproduce actual MySQL object key reordering.');
        $this->assertSame(Hash::hash($records), Hash::hash($actual->meta_json['surface_records']));
        $this->assertTrue($guard->invoke($workspace, $actual, $records));

        $cases = [];
        foreach (['id' => -99, 'owner_admin_user_id' => 99, 'source_sha256' => str_repeat('d', 64),
            'surface_package_sha256' => str_repeat('d', 64), 'candidate_sha256' => str_repeat('d', 64),
            'surface_state_sha256' => str_repeat('d', 64), 'locale' => 'fr', 'surface_key' => 'another_surface',
            'org_id' => 8] as $key => $value) {
            $changed = $meta;
            $changed['surface_records'][0][$key] = $value;
            $cases[$key.'_value'] = $changed;
        }
        foreach (['id' => '-1', 'owner_admin_user_id' => 1.0, 'org_id' => false] as $key => $value) {
            $changed = $meta;
            $changed['surface_records'][0][$key] = $value;
            $cases[$key.'_type'] = $changed;
        }
        $missing = $meta;
        unset($missing['surface_records'][0]['candidate_sha256']);
        $cases['missing_field'] = $missing;
        $extra = $meta;
        $extra['surface_records'][0]['unexpected'] = true;
        $cases['extra_field'] = $extra;
        $reversed = $meta;
        $reversed['surface_records'] = array_reverse($records);
        $cases['list_order'] = $reversed;
        $numericObject = $meta;
        $numericObject['surface_records'] = (object) $records;
        $cases['list_to_numeric_object'] = $numericObject;
        $recordList = $meta;
        $recordList['surface_records'][0] = array_values($records[0]);
        $cases['record_object_to_list'] = $recordList;
        $count = $meta;
        array_pop($count['surface_records']);
        $cases['list_count'] = $count;
        foreach ($cases as $case => $payload) {
            $this->assertFalse($guard->invoke($workspace, $roundtrip($payload), $records), $case);
        }
        $this->assertFalse(Hash::sameValue(['id' => 1], ['id' => 1.0]));
        $this->assertFalse(Hash::sameValue(['ids' => [1, 2]], ['ids' => [2, 1]]));
        $this->assertTrue(Hash::sameValue(['nested' => ['id' => 1, 'owner' => 2]], ['nested' => ['owner' => 2, 'id' => 1]]));

        $evidence = getenv('BLOG_SURFACE_TEST_MYSQL_EVIDENCE');
        if (is_string($evidence) && $evidence !== '') {
            file_put_contents($evidence, json_encode([
                'mysql_version' => $version, 'transport' => 'isolated_private_socket_no_network',
                'actual_mysql_json_roundtrip' => true, 'native_audit_cast' => true, 'native_service_guard' => true,
                'input_record_keys' => array_keys($records[0]), 'mysql_record_keys' => array_keys($actual->meta_json['surface_records'][0]),
                'old_strict_comparison' => $records === $actual->meta_json['surface_records'],
                'existing_canonical_hash_equal' => Hash::hash($records) === Hash::hash($actual->meta_json['surface_records']),
                'fixed_guard_accepted' => true, 'negative_cases_refused' => array_keys($cases),
                'synthetic_only' => true, 'application_table_reads' => 0, 'application_table_writes' => 0,
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n");
        }
    }
}
