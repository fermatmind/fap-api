<?php

declare(strict_types=1);

namespace Tests\Fixtures\Career;

use App\Domain\Career\Publish\CareerFullReleaseLedgerProjectionService;
use App\Domain\Career\Publish\CareerGenerationAuthorityLoader;
use App\Domain\Career\Publish\CareerGenerationCanonicalJson;
use App\Domain\Career\Publish\CareerRuntimePublishProjectionExporter;
use App\Domain\Career\Publish\CareerRuntimePublishProjectionLookup;
use App\Domain\Career\Publish\CareerRuntimePublishProjectionService;
use App\Domain\Career\Publish\CareerRuntimePublishProjectionVisibility;
use Illuminate\Support\Facades\File;

final class CareerGenerationAuthorityFixture
{
    /** @param list<array<string, mixed>> $items */
    public static function write(array $items): void
    {
        $generationId = 'test-generation-'.strtolower(str()->random(12));
        $root = storage_path('app/private/career_generation_authority');
        $generationDirectory = $root.'/generations/'.$generationId;
        File::deleteDirectory($root);
        File::ensureDirectoryExists($generationDirectory);

        $items = self::completeLocalePairs($items);
        $localeRows = [];
        $publicLocaleRows = [];
        $slugs = [];
        $publicSlugs = [];
        foreach ($items as $item) {
            $slug = (string) $item['slug'];
            $locale = (string) $item['locale'];
            $slugs[$slug] = true;
            $localeRows[] = $slug.'|'.$locale;
            if (($item['runtime_publish_state'] ?? null) === CareerRuntimePublishProjectionService::STATE_PUBLISHED) {
                $publicSlugs[$slug] = true;
                $publicLocaleRows[] = $slug.'|'.$locale;
            }
        }
        $slugSet = array_keys($slugs);
        sort($slugSet, SORT_STRING);
        $authority = [
            'frozen_manifest_sha256' => str_repeat('1', 64),
            'target_slug_set_sha256' => CareerGenerationCanonicalJson::setSha256($slugSet),
            'target_locale_row_set_sha256' => CareerGenerationCanonicalJson::setSha256($localeRows),
            'receipt_set_sha256' => str_repeat('2', 64),
        ];

        $projection = [
            'projection_kind' => CareerRuntimePublishProjectionService::PROJECTION_KIND,
            'projection_version' => CareerRuntimePublishProjectionService::PROJECTION_VERSION,
            'source_authority' => 'CareerFullReleaseLedger',
            'generation_id' => $generationId,
            'artifact_identity' => 'career-runtime-publish-projection@'.$generationId,
            'generation_authority' => $authority,
            'items' => $items,
        ];
        $ledger = [
            'ledger_kind' => 'career_full_release_ledger',
            'ledger_version' => 'career.full_release_ledger.test.v1',
            'generation_id' => $generationId,
            'artifact_identity' => 'career-full-release-ledger@'.$generationId,
            'generation_authority' => $authority,
            'members' => array_map(
                static fn (string $slug): array => ['canonical_slug' => $slug],
                $slugSet,
            ),
        ];

        $projectionPath = $generationDirectory.'/'.CareerRuntimePublishProjectionExporter::PROJECTION_FILENAME;
        $ledgerPath = $generationDirectory.'/'.CareerFullReleaseLedgerProjectionService::LEDGER_FILENAME;
        self::writeJson($projectionPath, $projection);
        self::writeJson($ledgerPath, $ledger);

        $payload = [
            'generation_id' => $generationId,
            'artifacts' => [
                'projection' => [
                    'identity' => 'career-runtime-publish-projection@'.$generationId,
                    'path' => 'generations/'.$generationId.'/'.CareerRuntimePublishProjectionExporter::PROJECTION_FILENAME,
                    'sha256' => hash_file('sha256', $projectionPath),
                ],
                'ledger' => [
                    'identity' => 'career-full-release-ledger@'.$generationId,
                    'path' => 'generations/'.$generationId.'/'.CareerFullReleaseLedgerProjectionService::LEDGER_FILENAME,
                    'sha256' => hash_file('sha256', $ledgerPath),
                ],
            ],
            'authority' => $authority,
            'counts' => [
                'public_slug_count' => count($publicSlugs),
                'public_locale_row_count' => count($publicLocaleRows),
            ],
            'lineage' => [
                'previous_generation_id' => null,
                'previous_pointer_sha256' => null,
            ],
            'timestamps' => [
                'created_at' => '2026-08-12T00:00:00Z',
                'activated_at' => '2026-08-12T00:00:01Z',
            ],
            'activation_receipt' => [
                'identity' => 'activation:'.$generationId,
                'sha256' => str_repeat('3', 64),
            ],
            'rollback' => [
                'eligible' => false,
                'previous_generation_id' => null,
            ],
            'discoverability' => [
                'sitemap_mutated' => false,
                'llms_mutated' => false,
                'search_mutated' => false,
            ],
            'revocation_receipt' => null,
        ];
        $document = [
            'schema_version' => CareerGenerationAuthorityLoader::POINTER_SCHEMA_VERSION,
            'payload_sha256' => CareerGenerationCanonicalJson::sha256($payload),
            'payload' => $payload,
        ];
        self::writeJson($generationDirectory.'/'.CareerGenerationAuthorityLoader::GENERATION_POINTER_FILENAME, $document);
        self::writeJson($root.'/'.CareerGenerationAuthorityLoader::ACTIVE_POINTER_FILENAME, $document);

        app()->forgetInstance(CareerRuntimePublishProjectionLookup::class);
        app()->forgetInstance(CareerRuntimePublishProjectionVisibility::class);
    }

    /** @param list<array<string, mixed>> $items @return list<array<string, mixed>> */
    private static function completeLocalePairs(array $items): array
    {
        $byIdentity = [];
        foreach ($items as $item) {
            $slug = strtolower(trim((string) ($item['slug'] ?? '')));
            $locale = str_starts_with(strtolower(trim((string) ($item['locale'] ?? 'en'))), 'zh') ? 'zh' : 'en';
            $item['slug'] = $slug;
            $item['locale'] = $locale;
            $byIdentity[$slug.'|'.$locale] = $item;
        }
        foreach ($byIdentity as $identity => $item) {
            [$slug, $locale] = explode('|', $identity, 2);
            $otherLocale = $locale === 'en' ? 'zh' : 'en';
            if (isset($byIdentity[$slug.'|'.$otherLocale])) {
                continue;
            }
            $blocked = $item;
            $blocked['locale'] = $otherLocale;
            $blocked['runtime_publish_state'] = CareerRuntimePublishProjectionService::STATE_QUARANTINED;
            foreach (['detail_route_enabled', 'dataset_visible', 'search_visible', 'sitemap_live', 'llms_live', 'llms_full_live', 'canonical_self', 'robots_indexable', 'release_gate_pass'] as $key) {
                $blocked[$key] = false;
            }
            $blocked['canonical_url'] = null;
            $byIdentity[$slug.'|'.$otherLocale] = $blocked;
        }
        ksort($byIdentity, SORT_STRING);

        return array_values($byIdentity);
    }

    /** @param array<string, mixed> $payload */
    private static function writeJson(string $path, array $payload): void
    {
        File::put($path, json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT,
        )."\n");
    }
}
