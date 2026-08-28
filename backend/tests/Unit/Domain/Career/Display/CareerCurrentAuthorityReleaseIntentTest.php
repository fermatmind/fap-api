<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Display;

use App\Domain\Career\Display\CareerCurrentAuthorityPackage;
use App\Domain\Career\Display\CareerCurrentAuthorityPackageFailure;
use App\Domain\Career\Display\CareerCurrentAuthorityPackageLoader;
use App\Domain\Career\Display\CareerCurrentAuthorityReleaseIntent;
use App\Domain\Career\Display\CareerShardedCurrentAuthorityPackage;
use PHPUnit\Framework\TestCase;

final class CareerCurrentAuthorityReleaseIntentTest extends TestCase
{
    public function test_it_binds_the_locked_package_without_binding_the_execution_release_sha(): void
    {
        $result = $this->verifier()->verify($this->backendRoot());

        self::assertSame('career.current_authority_release_intent.v1', $result['intent']['contract_version']);
        self::assertSame('2c956887ad9460849fd29cbfacb145e1397993cd', $result['intent']['source_merge_sha']);
        self::assertSame('1dc96c10d79cde77b0172278166beeb9437b88a641d3899fa0b224dc8a7c072e', $result['intent']['manifest_sha256']);
        self::assertSame('674ccf9264e256a602c1535b2923856ad282edea9c73c50cdcceb3d00ac65f13', $result['intent']['aggregate_sha256']);
        self::assertSame('55f9425593f5f1361bcbcc828cc3dcd0c3410fd3b53c57131b4686130cd04a79', $result['intent']['versionless_projection_sha256']);
        self::assertSame(['zh-CN', 'en'], $result['intent']['locales']);
        self::assertSame(['software-developers'], $result['intent']['manual_hold_slugs']);
        self::assertFalse($result['intent']['discoverability']);
        self::assertFalse($result['intent']['search_submission']);
        self::assertSame(1046, $result['package']['slug_count']);
        self::assertSame(2092, $result['package']['locale_page_count']);
        self::assertSame(10, $result['package']['module_count']);
        self::assertSame(640, $result['package']['shard_count']);
        self::assertSame(
            hash('sha256', 'career-current-authority|2c956887ad9460849fd29cbfacb145e1397993cd|674ccf9264e256a602c1535b2923856ad282edea9c73c50cdcceb3d00ac65f13|55f9425593f5f1361bcbcc828cc3dcd0c3410fd3b53c57131b4686130cd04a79'),
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
            self::assertSame('CURRENT_RELEASE_INTENT_INVALID', $failure->safeCode);
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
        $package = new CareerCurrentAuthorityPackage;

        return new CareerCurrentAuthorityReleaseIntent(new CareerCurrentAuthorityPackageLoader(
            $package,
            new CareerShardedCurrentAuthorityPackage($package),
        ));
    }

    private function backendRoot(): string
    {
        return dirname(__DIR__, 5);
    }
}
