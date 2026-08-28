<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Display;

use App\Domain\Career\Display\CareerContentV3AuthorityPackage;
use App\Domain\Career\Display\CareerCurrentAuthorityPackage;
use App\Domain\Career\Display\CareerCurrentAuthorityPackageFailure;
use App\Domain\Career\Display\CareerCurrentAuthorityPackageLoader;
use App\Domain\Career\Display\CareerCurrentAuthorityReleaseIntent;
use PHPUnit\Framework\TestCase;

final class CareerCurrentAuthorityReleaseIntentTest extends TestCase
{
    public function test_it_binds_the_locked_package_without_binding_the_execution_release_sha(): void
    {
        $result = $this->verifier()->verify($this->backendRoot());
        $manifestPath = $this->backendRoot().'/'.CareerCurrentAuthorityPackage::RELATIVE_PATH.'/manifest.json';
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('career.current_authority_release_intent.v1', $result['intent']['contract_version']);
        self::assertSame('2c956887ad9460849fd29cbfacb145e1397993cd', $result['intent']['source_merge_sha']);
        self::assertSame(hash_file('sha256', $manifestPath), $result['intent']['manifest_sha256']);
        self::assertSame($manifest['aggregate_sha256'], $result['intent']['aggregate_sha256']);
        self::assertSame(
            $manifest['set_hashes']['legacy_versionless_projection_sha256'],
            $result['intent']['versionless_projection_sha256'],
        );
        self::assertSame($manifest['source_registry_sha256'], $result['intent']['source_registry_sha256']);
        self::assertSame(['en', 'zh-CN'], $result['intent']['locales']);
        self::assertSame(['software-developers'], $result['intent']['manual_hold_slugs']);
        self::assertFalse($result['intent']['discoverability']);
        self::assertFalse($result['intent']['search_submission']);
        self::assertSame(1046, $result['package']['slug_count']);
        self::assertSame(2092, $result['package']['locale_page_count']);
        self::assertSame(2092, $result['package']['file_count']);
        self::assertSame(
            hash('sha256', sprintf(
                'career-current-authority|%s|%s|%s',
                $result['intent']['source_merge_sha'],
                $result['intent']['aggregate_sha256'],
                $result['intent']['versionless_projection_sha256'],
            )),
            $result['operation_key'],
        );
    }

    public function test_it_fails_closed_when_the_intent_digest_drifts_and_does_not_rewrite_it(): void
    {
        $source = $this->backendRoot().'/'.CareerCurrentAuthorityReleaseIntent::RELATIVE_PATH;
        $intent = json_decode((string) file_get_contents($source), true, 512, JSON_THROW_ON_ERROR);
        $intent['aggregate_sha256'] = str_repeat('0', 64);
        $temporary = tempnam(sys_get_temp_dir(), 'career-release-intent-');
        self::assertIsString($temporary);
        file_put_contents($temporary, json_encode($intent, JSON_THROW_ON_ERROR));
        $before = hash_file('sha256', $temporary);

        try {
            $this->verifier()->verify($this->backendRoot(), $temporary);
            self::fail('Digest drift must fail closed.');
        } catch (CareerCurrentAuthorityPackageFailure $failure) {
            self::assertSame('CURRENT_RELEASE_INTENT_PACKAGE_MISMATCH', $failure->safeCode);
            self::assertSame($before, hash_file('sha256', $temporary));
        } finally {
            unlink($temporary);
        }
    }

    public function test_deploy_publishes_only_after_technical_production_and_keeps_release_sha_out_of_the_operation_key(): void
    {
        $root = dirname(__DIR__, 6);
        $workflow = (string) file_get_contents($root.'/.github/workflows/deploy.yml');
        $publisher = (string) file_get_contents($root.'/backend/scripts/operations/career_current_authority_publish.php');

        self::assertStringContainsString("needs.production.result == 'success'", $workflow);
        self::assertStringContainsString('.classification.operations.career_current_authority_release == true', $workflow);
        self::assertStringContainsString('verify_career_current_authority_release.sh', $workflow);
        self::assertStringContainsString('and .file_count == 2092', (string) file_get_contents(
            $root.'/backend/scripts/ci/verify_career_current_authority_release.sh',
        ));
        self::assertStringContainsString('source_merge_sha == $source', $workflow);
        self::assertStringContainsString('.authority.component_28_count == 1046', $workflow);
        self::assertStringContainsString('automatic_retry_allowed == false', $workflow);
        self::assertStringContainsString(
            "\$receipt['authority']['component_28_count'] = \$result['authority']['valid_component_order_count'] ?? null;",
            $publisher,
        );
        self::assertStringContainsString(
            "'career-current-authority|'.\$sourceMergeSha.'|'.\$assetsSha256.'|'.\$versionlessProjectionSha256",
            $publisher,
        );
        self::assertStringNotContainsString(
            "'career-current-authority|'.\$releaseSha.'|'.\$assetsSha256",
            $publisher,
        );
        self::assertStringContainsString("'release_sha' => \$releaseSha", $publisher);
    }

    private function verifier(): CareerCurrentAuthorityReleaseIntent
    {
        return new CareerCurrentAuthorityReleaseIntent(new CareerCurrentAuthorityPackageLoader(
            new CareerContentV3AuthorityPackage,
        ));
    }

    private function backendRoot(): string
    {
        return dirname(__DIR__, 5);
    }
}
