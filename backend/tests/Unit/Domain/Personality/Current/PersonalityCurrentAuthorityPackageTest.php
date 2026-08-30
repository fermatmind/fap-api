<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Personality\Current;

use App\Domain\Personality\Current\PersonalityCurrentAuthorityFailure;
use App\Domain\Personality\Current\PersonalityCurrentAuthorityPackage;
use App\Domain\Personality\Current\PersonalityPageContentContract;
use Tests\TestCase;

final class PersonalityCurrentAuthorityPackageTest extends TestCase
{
    public function test_installed_package_has_exact_bilingual_public_inventory(): void
    {
        $package = new PersonalityCurrentAuthorityPackage;
        $index = $package->manifestIndex(base_path());

        $this->assertCount(364, $index['entries']);
        $this->assertSame(182, $index['manifest']['coverage']['pages_per_locale']);
        $this->assertSame(['big_five' => 104, 'enneagram' => 116, 'mbti' => 144], $index['manifest']['coverage']['by_framework']);
        $this->assertSame(364, $index['manifest']['coverage']['baseline_locale_pages']);
        $this->assertSame(0, $index['manifest']['coverage']['enhanced_locale_pages']);

        $at = $package->pageFromIndex($index, 'mbti', 'comparison_at', 'intj-a-vs-intj-t', 'zh');
        $this->assertSame('/zh/personality/intj-a-vs-intj-t', $at['identity']['canonical_path']);
        $this->assertSame('mbti.at_comparison.v1', $at['payload_contract']);

        $subtype = $package->pageFromIndex($index, 'enneagram', 'instinctual_subtype', 'type-1/self-preservation', 'en');
        $this->assertSame('personality_public_asset.v2', $subtype['payload_contract']);
    }

    public function test_page_contract_rejects_payload_hash_drift(): void
    {
        $path = base_path('content_assets/personality_public/current/pages/mbti/comparison-at/intj-a-vs-intj-t/en.json');
        $page = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $page['payload']['comparison']['title'] = 'tampered';

        try {
            PersonalityPageContentContract::assert($page);
            $this->fail('Expected source hash mismatch.');
        } catch (PersonalityCurrentAuthorityFailure $failure) {
            $this->assertSame('PERSONALITY_CURRENT_SOURCE_HASH_MISMATCH', $failure->safeCode);
        }
    }

    public function test_page_contract_rejects_wrong_locale_canonical_path(): void
    {
        $path = base_path('content_assets/personality_public/current/pages/mbti/profile/intj/en.json');
        $page = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $page['identity']['canonical_path'] = '/zh/personality/intj';

        $this->expectException(PersonalityCurrentAuthorityFailure::class);
        PersonalityPageContentContract::assert($page);
    }
}
