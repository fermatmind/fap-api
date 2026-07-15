<?php

declare(strict_types=1);

namespace Tests\Feature\SEO;

use App\Models\MediaAsset;
use App\Models\MediaVariant;
use App\Services\BigFive\AuthorityV2\MediaAuthority\BigFiveAuthorityV2MediaMappingPreflight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

final class BigFiveAuthorityV241Test extends TestCase
{
    use RefreshDatabase;

    private string $packagePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->packagePath = base_path('../generated/big-five-authority-v2/big5-authority-v2-media-authority-41');
    }

    public function test_repository_package_keeps_exact_pr34_inventory_fail_closed_and_hash_locked(): void
    {
        $result = app(BigFiveAuthorityV2MediaMappingPreflight::class)->preflight(
            '../generated/big-five-authority-v2/big5-authority-v2-media-authority-41/approved-media-intake.json',
            '../generated/big-five-authority-v2/big5-authority-v2-media-og-34/candidate-media-map.json',
            '../generated/big-five-authority-v2/big5-authority-v2-media-og-34/upload-mapping-manifest.json',
        );

        $this->assertTrue($result['ok']);
        $this->assertSame('PASS_FAIL_CLOSED_NO_APPROVED_ASSETS', $result['status']);
        $this->assertSame('preflight_only_zero_write', $result['mode']);
        $this->assertSame(trim(File::get($this->packagePath.'/mapping-package.sha256')), $result['mapping_package_sha256']);
        $this->assertSame([
            'candidate_pages' => 231,
            'family_locale_requirement_groups' => 18,
            'grouped_slot_requirements' => 54,
            'approved_grouped_slot_requirements' => 0,
            'pending_grouped_slot_requirements' => 54,
            'total_page_slots' => 693,
            'mapped_page_slots' => 0,
            'missing_pending_page_slots' => 693,
        ], $result['counts']);
        $this->assertCount(231, $result['mapping_package']['mappings']);
        foreach ($result['mapping_package']['mappings'] as $mapping) {
            $this->assertSame('missing_pending', $mapping['mapping_status']);
            $this->assertSame(['hero', 'inline', 'og'], array_column($mapping['slots'], 'slot'));
            foreach ($mapping['slots'] as $slot) {
                $this->assertSame('missing_pending', $slot['status']);
                $this->assertSame(
                    'big5:'.$mapping['page_family'].':'.$mapping['locale'].':'.$slot['slot'],
                    $slot['content_identity'],
                );
                foreach (['media_asset_id', 'media_asset_key', 'variant_key', 'public_url', 'alt', 'rights', 'license', 'provenance', 'operator_approval_ref'] as $field) {
                    $this->assertNull($slot[$field]);
                }
            }
        }
        foreach ($result['actions'] as $action => $count) {
            $this->assertSame(0, $count, 'Expected zero action for '.$action);
        }
    }

    public function test_artisan_preflight_is_discoverable_and_has_no_write_or_upload_mode(): void
    {
        $assetCount = MediaAsset::query()->withoutGlobalScopes()->count();
        $variantCount = MediaVariant::query()->count();

        $this->artisan('personality:big-five-authority-v2-media-intake', ['--preflight' => true])
            ->expectsOutputToContain('ok=1')
            ->expectsOutputToContain('status=PASS_FAIL_CLOSED_NO_APPROVED_ASSETS')
            ->expectsOutputToContain('candidate_pages=231')
            ->expectsOutputToContain('approved_grouped_slot_requirements=0')
            ->expectsOutputToContain('mapped_page_slots=0')
            ->expectsOutputToContain('missing_pending_page_slots=693')
            ->expectsOutputToContain('database_writes=0')
            ->expectsOutputToContain('media_uploads=0')
            ->expectsOutputToContain('cms_mapping_writes=0')
            ->assertSuccessful();

        $this->assertSame($assetCount, MediaAsset::query()->withoutGlobalScopes()->count());
        $this->assertSame($variantCount, MediaVariant::query()->count());

        $this->artisan('personality:big-five-authority-v2-media-intake')
            ->expectsOutputToContain('status=FAIL_CLOSED')
            ->expectsOutputToContain('--preflight is required')
            ->assertFailed();
    }

    public function test_valid_operator_approved_media_library_identity_maps_only_its_locked_group_slot(): void
    {
        $identity = 'big5:model_hub:en:hero';
        $publicUrl = 'https://assets.fermatmind.com/storage/media/big5/model-hub-en-hero.jpg';
        $entry = $this->approvedEntry($identity, $publicUrl);
        $asset = $this->createApprovedAsset($entry);
        $entry['media_asset_id'] = $asset->getKey();
        $intakePath = $this->writeIntake([$entry]);

        try {
            $result = app(BigFiveAuthorityV2MediaMappingPreflight::class)->preflight(
                $intakePath,
                '../generated/big-five-authority-v2/big5-authority-v2-media-og-34/candidate-media-map.json',
                '../generated/big-five-authority-v2/big5-authority-v2-media-og-34/upload-mapping-manifest.json',
            );
        } finally {
            File::delete($intakePath);
        }

        $this->assertSame('PASS_APPROVED_MEDIA_PREFLIGHT', $result['status']);
        $this->assertSame(1, $result['counts']['approved_grouped_slot_requirements']);
        $this->assertSame(1, $result['counts']['mapped_page_slots']);
        $this->assertSame(692, $result['counts']['missing_pending_page_slots']);
        $this->assertSame(1, $result['actions']['database_reads']);
        foreach (array_diff_key($result['actions'], ['database_reads' => true]) as $count) {
            $this->assertSame(0, $count);
        }

        $modelHub = collect($result['mapping_package']['mappings'])
            ->first(fn (array $mapping): bool => $mapping['page_family'] === 'model_hub' && $mapping['locale'] === 'en');
        $this->assertIsArray($modelHub);
        $hero = collect($modelHub['slots'])->firstWhere('slot', 'hero');
        $this->assertSame('approved_mapped', $hero['status']);
        $this->assertSame($asset->getKey(), $hero['media_asset_id']);
        $this->assertSame($publicUrl, $hero['public_url']);
        $this->assertSame($identity, $hero['content_identity']);
        $this->assertSame('missing_pending', collect($modelHub['slots'])->firstWhere('slot', 'inline')['status']);
        $this->assertSame(1, MediaAsset::query()->withoutGlobalScopes()->count());
        $this->assertSame(1, MediaVariant::query()->count());
    }

    public function test_input_cannot_fabricate_operator_approval_or_variant_url_authority(): void
    {
        $identity = 'big5:model_hub:en:hero';
        $entry = $this->approvedEntry(
            $identity,
            'https://assets.fermatmind.com/storage/media/big5/model-hub-en-hero.jpg',
        );
        $asset = $this->createApprovedAsset($entry);
        $entry['media_asset_id'] = $asset->getKey();
        $entry['operator_approval_ref'] = 'FABRICATED-APPROVAL';
        $intakePath = $this->writeIntake([$entry]);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('operator_approval_ref does not match Media Library authority');
            app(BigFiveAuthorityV2MediaMappingPreflight::class)->preflight(
                $intakePath,
                '../generated/big-five-authority-v2/big5-authority-v2-media-og-34/candidate-media-map.json',
                '../generated/big-five-authority-v2/big5-authority-v2-media-og-34/upload-mapping-manifest.json',
            );
        } finally {
            File::delete($intakePath);
        }
    }

    /** @return array<string, mixed> */
    private function approvedEntry(string $identity, string $publicUrl): array
    {
        return [
            'approval_status' => 'operator_approved',
            'page_family' => 'model_hub',
            'locale' => 'en',
            'slot' => 'hero',
            'content_identity' => $identity,
            'media_asset_id' => 1,
            'media_asset_key' => 'big5.model_hub.en.hero',
            'variant_key' => 'hero',
            'public_url' => $publicUrl,
            'alt' => 'Big Five model overview illustration',
            'rights' => 'FermatMind-owned original artwork',
            'license' => 'internal-original-v1',
            'provenance' => 'Media Library upload batch BIG5-MEDIA-TEST',
            'operator_approval_ref' => 'BIG5-MEDIA-APPROVAL-TEST-001',
        ];
    }

    /** @param array<string, mixed> $entry */
    private function createApprovedAsset(array $entry): MediaAsset
    {
        $asset = MediaAsset::query()->create([
            'org_id' => 0,
            'asset_key' => $entry['media_asset_key'],
            'disk' => 'public',
            'path' => 'media/big5/source.jpg',
            'url' => 'https://assets.fermatmind.com/storage/media/big5/source.jpg',
            'alt' => $entry['alt'],
            'status' => MediaAsset::STATUS_PUBLISHED,
            'is_public' => true,
            'sync_status' => MediaAsset::SYNC_SYNCED,
            'cdn_status' => MediaAsset::CDN_VERIFIED,
            'payload_json' => [
                'locale' => $entry['locale'],
                'rights' => $entry['rights'],
                'license' => $entry['license'],
                'provenance' => $entry['provenance'],
                'operator_approval_ref' => $entry['operator_approval_ref'],
                'content_identity' => $entry['content_identity'],
            ],
        ]);
        $asset->variants()->create([
            'variant_key' => $entry['variant_key'],
            'path' => 'media/big5/model-hub-en-hero.jpg',
            'url' => $entry['public_url'],
            'sync_status' => MediaAsset::SYNC_SYNCED,
            'cdn_status' => MediaAsset::CDN_VERIFIED,
        ]);

        return $asset->fresh('variants');
    }

    /** @param list<array<string, mixed>> $entries */
    private function writeIntake(array $entries): string
    {
        $path = storage_path('framework/testing/big5-media-intake-'.uniqid().'.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode([
            'schema_version' => BigFiveAuthorityV2MediaMappingPreflight::INTAKE_SCHEMA,
            'operator_approval_claimed' => $entries !== [],
            'approved_assets' => $entries,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);

        return $path;
    }
}
