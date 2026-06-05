<?php

declare(strict_types=1);

namespace Tests\Feature\Cms;

use App\Models\ContentPage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class HelpContentImportPackageTest extends TestCase
{
    #[Test]
    public function help_service_import_package_preserves_draft_only_boundaries(): void
    {
        $repoBase = dirname(__DIR__, 4);
        $packagePath = $repoBase.'/backend/docs/help/import-packages/help-service-content-drafts-01.import.v1.json';
        $sourcePath = $repoBase.'/backend/docs/help/import-packages/content-pages-draft-source/content_pages.help_service_drafts_01.json';
        $generatedPath = $repoBase.'/backend/docs/help/generated/help-cms-import-package-01.v1.json';

        $this->assertFileExists($packagePath);
        $this->assertFileExists($sourcePath);
        $this->assertFileExists($generatedPath);

        $package = json_decode((string) file_get_contents($packagePath), true, 512, JSON_THROW_ON_ERROR);
        $sourceRows = json_decode((string) file_get_contents($sourcePath), true, 512, JSON_THROW_ON_ERROR);
        $generated = json_decode((string) file_get_contents($generatedPath), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('help-cms-import-package-01.v1', $package['schema_version'] ?? null);
        $this->assertContains($package['task'] ?? null, [
            'HELP-CMS-IMPORT-PACKAGE-01',
            'HELP-CONTENT-DRAFT-POLICY-REVISION-APPLY-01',
        ]);
        $this->assertSame('support@fermatmind.com', data_get($package, 'target_summary.support_contact'));
        $this->assertSame('help_service_policy.v1', data_get($package, 'target_summary.policy_version'));
        $this->assertTrue((bool) data_get($package, 'target_summary.policy_owner_answers_applied'));
        $this->assertTrue((bool) data_get($package, 'target_summary.direct_email_support_contact_applied'));
        $this->assertTrue((bool) data_get($package, 'authority.dry_run_import_possible_with_existing_tooling'));
        $this->assertSame('content-pages:import-local-baseline', data_get($package, 'authority.runtime_importer'));

        $gates = $package['global_gates'] ?? [];
        $this->assertTrue((bool) ($gates['draft_only'] ?? false));
        $this->assertFalse((bool) ($gates['cms_write_performed'] ?? true));
        $this->assertFalse((bool) ($gates['publish_allowed'] ?? true));
        $this->assertFalse((bool) ($gates['runtime_changed'] ?? true));
        $this->assertFalse((bool) ($gates['frontend_changed'] ?? true));
        $this->assertFalse((bool) ($gates['search_submission_performed'] ?? true));
        $this->assertFalse((bool) ($gates['payment_provider_changed'] ?? true));
        $this->assertFalse((bool) ($gates['private_url_access_performed'] ?? true));
        $this->assertTrue((bool) ($gates['requires_operator_review'] ?? false));
        $this->assertFalse((bool) ($gates['schema_enabled'] ?? true));
        $this->assertTrue((bool) ($gates['policy_owner_answers_applied'] ?? false));
        $this->assertTrue((bool) ($gates['direct_email_support_contact_applied'] ?? false));
        $this->assertSame('noindex,nofollow', $gates['robots_default'] ?? null);

        $targets = $package['targets'] ?? [];
        $this->assertCount(12, $targets);
        $this->assertCount(12, $sourceRows);

        $expectedRoutes = [
            '/zh/help/unlock-failure',
            '/en/help/unlock-failure',
            '/zh/help/payment-refund',
            '/en/help/payment-refund',
            '/zh/help/result-recovery',
            '/en/help/result-recovery',
            '/zh/help/privacy-data',
            '/en/help/privacy-data',
            '/zh/help/use-boundaries',
            '/en/help/use-boundaries',
            '/zh/help/data-deletion',
            '/en/help/data-deletion',
        ];

        $actualRoutes = array_column($targets, 'public_canonical_route');
        sort($actualRoutes);
        $sortedExpectedRoutes = $expectedRoutes;
        sort($sortedExpectedRoutes);
        $this->assertSame($sortedExpectedRoutes, $actualRoutes);

        foreach ($targets as $target) {
            $this->assertNotEmpty($target['slug'] ?? '');
            $this->assertContains($target['locale'] ?? null, ['zh-CN', 'en']);
            $this->assertNotEmpty($target['title'] ?? '');
            $this->assertNotEmpty($target['summary'] ?? '');
            $this->assertNotEmpty($target['body'] ?? '');
            $this->assertNotEmpty($target['faq_items'] ?? []);
            $this->assertSame('support@fermatmind.com', $target['support_contact'] ?? null);
            $this->assertSame('help_service_policy.v1', $target['policy_version'] ?? null);
            $this->assertSame('Unknown', $target['reviewer'] ?? null);
            $this->assertSame('2026-06-04', $target['updated_at_source'] ?? null);
            $this->assertSame('noindex,nofollow', $target['robots'] ?? null);
            $this->assertFalse((bool) ($target['is_public'] ?? true));
            $this->assertFalse((bool) ($target['is_indexable'] ?? true));
            $this->assertFalse((bool) ($target['schema_enabled'] ?? true));
            $this->assertTrue((bool) ($target['requires_operator_review'] ?? false));
            $this->assertFalse((bool) ($target['publish_allowed'] ?? true));
            $this->assertFalse((bool) ($target['cms_write_performed'] ?? true));
            $this->assertFalse((bool) ($target['search_submission_performed'] ?? true));
            $this->assertFalse((bool) ($target['private_url_access_performed'] ?? true));
            $this->assertStringStartsWith('/help/', (string) ($target['path'] ?? ''));
            $this->assertStringStartsWith('/', (string) ($target['public_canonical_route'] ?? ''));
            $this->assertStringNotContainsString('/orders/', (string) ($target['public_canonical_route'] ?? ''));
            $this->assertStringNotContainsString('/result/', (string) ($target['public_canonical_route'] ?? ''));
            $this->assertStringNotContainsString('/payment/', (string) ($target['public_canonical_route'] ?? ''));
            $this->assertStringNotContainsString('/history/', (string) ($target['public_canonical_route'] ?? ''));
        }

        foreach ($sourceRows as $row) {
            $this->assertSame('help', $row['kind'] ?? null);
            $this->assertSame('help', $row['template'] ?? null);
            $this->assertFalse((bool) ($row['isPublic'] ?? true));
            $this->assertFalse((bool) ($row['isIndexable'] ?? true));
            $this->assertSame('owner_review', $row['reviewState'] ?? null);
            $this->assertSame('draft', ContentPage::STATUS_DRAFT);
            $this->assertContains($row['pageType'] ?? null, ['support_static', 'refund', 'privacy', 'boundary']);
            $this->assertStringStartsWith('/help/', (string) ($row['path'] ?? ''));
            $this->assertSame($row['path'] ?? null, $row['canonicalPath'] ?? null);
            $this->assertNotEmpty($row['contentMd'] ?? '');
            $this->assertSame('support@fermatmind.com', $row['support_contact'] ?? null);
            $this->assertSame('help_service_policy.v1', $row['policy_version'] ?? null);
            $this->assertSame('Unknown', $row['reviewer'] ?? null);
            $this->assertNotEmpty($row['faq_items'] ?? []);
            $this->assertFalse((bool) ($row['schema_enabled'] ?? true));
        }

        $serialized = json_encode($package, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
        $this->assertStringNotContainsString('/help/contact-support', $serialized);
        $this->assertStringNotContainsString('/orders/', $serialized);
        $this->assertStringNotContainsString('/pay/', $serialized);
        $this->assertStringNotContainsString('/payment/', $serialized);
        $this->assertStringNotContainsString('/history/', $serialized);
        $this->assertStringNotContainsString('token=', $serialized);
        $this->assertStringNotContainsString('payment_id=', $serialized);
        $this->assertStringNotContainsString('transaction_id=', $serialized);
        $this->assertStringContainsString('非“费马测试”原因', $serialized);
        $this->assertStringContainsString('三个工作日', $serialized);
        $this->assertStringContainsString('两年', $serialized);
        $this->assertStringContainsString('无额外保留数据例外', $serialized);

        $this->assertSame('PASS_DRAFT_ONLY_IMPORT_PACKAGE_READY', $generated['decision'] ?? null);
        $this->assertTrue((bool) ($generated['dry_run_import_possible_with_existing_tooling'] ?? false));
        $this->assertSame(12, $generated['target_count'] ?? null);
        $this->assertFalse((bool) data_get($generated, 'no_go_checks.requires_real_payment_or_refund'));
        $this->assertFalse((bool) data_get($generated, 'no_go_checks.requires_search_submission'));
    }
}
