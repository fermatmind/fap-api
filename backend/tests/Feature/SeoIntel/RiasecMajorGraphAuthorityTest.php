<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Models\ContentPage;
use App\Services\SeoIntel\RiasecMajorGraphAuthorityValidator;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class RiasecMajorGraphAuthorityTest extends TestCase
{
    use RefreshDatabase;

    public function createApplication(): Application
    {
        $app = require dirname(__DIR__, 3).'/bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    #[Test]
    public function source_package_defines_backend_authority_clusters_with_required_gates(): void
    {
        $source = $this->sourcePackage();
        $artifact = $this->artifact();

        $this->assertSame('riasec-major-graph-authority.v1', $source['schema_version'] ?? null);
        $this->assertSame('FA30-API-09', $source['task'] ?? null);
        $this->assertSame('backend_authority_contract_only', $source['mode'] ?? null);
        $this->assertSame('FA30-API-09', $artifact['task'] ?? null);
        $this->assertSame('FA30-API-10', $artifact['next_task'] ?? null);

        $clusters = $source['clusters'] ?? [];
        $this->assertCount(6, $clusters);

        foreach ($clusters as $cluster) {
            foreach ($artifact['authority_payload_required_fields'] as $field) {
                $this->assertArrayHasKey($field, $cluster);
                $this->assertNotEmpty($cluster[$field], $field.' must not be empty');
            }

            $this->assertSame('en', $cluster['locale'] ?? null);
            $this->assertSame('noindex', $cluster['indexability_status'] ?? null);
            $this->assertSame('exploration_only', $cluster['claim_tier'] ?? null);
            $this->assertContains($cluster['review_status'] ?? null, ['draft', 'operator_review_required']);

            foreach (array_merge($cluster['riasec_primary_codes'], $cluster['riasec_secondary_codes']) as $code) {
                $this->assertContains($code, ['R', 'I', 'A', 'S', 'E', 'C']);
            }
        }

        foreach ($artifact['negative_guarantees'] ?? [] as $flag => $value) {
            $this->assertFalse((bool) $value, $flag.' must remain false');
        }
    }

    #[Test]
    public function validator_rejects_deterministic_major_admission_salary_success_and_replacement_claims(): void
    {
        $validator = new RiasecMajorGraphAuthorityValidator;

        foreach ([
            'This is the best major for you.',
            'The graph predicts Gaokao admission probability.',
            'This cluster predicts employment salary.',
            'This score predicts career success rate.',
            'This can replace a counselor.',
            '这是推荐最佳专业并预测高考录取的页面。',
        ] as $claim) {
            $issues = $validator->validateClaimText($claim, 'test_claim');

            $this->assertNotEmpty($issues, $claim);
            $this->assertStringStartsWith('test_claim.forbidden_claim.', $issues[0]);
        }
    }

    #[Test]
    public function validator_rejects_indexable_unreviewed_clusters(): void
    {
        $payload = $this->sourcePackage();
        $payload['clusters'][0]['indexability_status'] = 'indexable';
        $payload['clusters'][0]['review_status'] = 'draft';

        $result = (new RiasecMajorGraphAuthorityValidator)->validate($payload);

        $this->assertFalse((bool) $result['ok']);
        $this->assertContains('cluster.investigative-science-research.indexable_requires_review', $result['issues']);
        $this->assertContains('cluster.investigative-science-research.indexable_requires_reviewed_exploration_claim_tier', $result['issues']);
    }

    #[Test]
    public function audit_command_emits_stable_readonly_json_without_writing_cms_rows(): void
    {
        $this->assertSame(0, ContentPage::query()->withoutGlobalScopes()->count());

        $exitCode = Artisan::call('riasec:major-graph-authority-audit', [
            '--json' => true,
            '--strict' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $output = Artisan::output();
        $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('FA30-API-09', $decoded['task'] ?? null);
        $this->assertSame('pass', $decoded['status'] ?? null);
        $this->assertTrue((bool) ($decoded['ok'] ?? false));
        $this->assertSame(6, $decoded['cluster_count'] ?? null);
        $this->assertSame(0, $decoded['indexable_cluster_count'] ?? null);
        $this->assertSame(0, $decoded['issue_count'] ?? null);
        $this->assertFalse((bool) data_get($decoded, 'boundary.cms_write_performed', true));
        $this->assertFalse((bool) data_get($decoded, 'boundary.public_api_route_created', true));

        $this->assertSame(0, ContentPage::query()->withoutGlobalScopes()->count());
    }

    /**
     * @return array<string, mixed>
     */
    private function sourcePackage(): array
    {
        $path = base_path('docs/seo/import-packages/riasec-major-graph-authority/riasec_major_graph_authority.v1.json');

        $this->assertFileExists($path);

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/riasec-major-graph-authority.v1.json');

        $this->assertFileExists($path);

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }
}
