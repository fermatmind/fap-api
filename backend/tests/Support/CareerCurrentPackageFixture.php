<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Career\Display\CareerContentV3Contract;
use App\Domain\Career\Display\CareerCurrentAuthorityPackage;
use App\Domain\Career\Display\CareerCurrentAuthorityReleaseIntent;
use Illuminate\Filesystem\Filesystem;

/** Compact mutation fixture; the real 2092-page corpus is scanned separately. */
final class CareerCurrentPackageFixture
{
    public static function create(string $source): string
    {
        $root = sys_get_temp_dir().'/career-current-fixture-'.bin2hex(random_bytes(8));
        $relative = CareerCurrentAuthorityPackage::RELATIVE_PATH;
        $files = new Filesystem;
        $manifest = json_decode(file_get_contents($source.'/'.$relative.'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        $registries = [];
        try {
            foreach ($manifest['files'] as &$entry) {
                $slug = $entry['canonical_slug'];
                $locale = $entry['locale'];
                if ($slug === 'accountants-and-auditors') {
                    $page = json_decode(file_get_contents($source.'/'.$relative.'/'.$entry['path']), true, 512, JSON_THROW_ON_ERROR);
                } else {
                    $metric = static fn (string $key): array => ['availability' => 'missing', 'fact_ref' => null, 'key' => $key, 'label' => $key];
                    $page = [
                        'contract_version' => CareerContentV3Contract::CONTRACT_VERSION,
                        'locale' => $locale,
                        'subject' => ['canonical_slug' => $slug, 'name' => $slug, 'summary' => 'Fixture public summary'],
                        'content_state' => 'enhanced',
                        'blocks' => [[
                            'id' => 'profile', 'copy_key' => 'career.section.profile', 'content_state' => 'enhanced', 'availability' => 'available',
                            'items' => [['id' => 'fixture-prose', 'copy_key' => 'career.item.fixture-prose', 'type' => 'prose', 'availability' => 'available', 'data' => ['paragraphs' => ['Fixture public body']]]],
                        ]],
                        'hero' => ['ai' => $metric('ai'), 'badges' => [], 'metrics' => array_map($metric, ['us_pay', 'us_growth', 'us_employment', 'us_openings', 'china_pay'])],
                        'seo' => ['description' => ['availability' => 'available', 'text' => 'Fixture description'], 'title' => ['availability' => 'available', 'text' => $slug]],
                    ];
                    $page['source_content_sha256'] = CareerCurrentAuthorityPackage::hashValue($page);
                }
                CareerContentV3Contract::assert($page);
                $bytes = CareerCurrentAuthorityPackage::encodePrettyCanonical($page);
                $path = $root.'/'.$relative.'/'.$entry['path'];
                $files->ensureDirectoryExists(dirname($path));
                file_put_contents($path, $bytes);
                $entry['bytes'] = strlen($bytes);
                $entry['sha256'] = hash('sha256', $bytes);
                $entry['source_content_sha256'] = $page['source_content_sha256'];
                // Exercise the supported precomputed-qualification-free input to migration.
                unset($entry['body_qualification']);
                $sources = [];
                foreach ($page['blocks'] as $block) {
                    foreach ($block['items'] as $item) {
                        if ($item['type'] === 'sources') {
                            $sources = array_merge($sources, $item['data']['entries']);
                        }
                    }
                }
                $registries[] = [$slug.'|'.$locale, CareerCurrentAuthorityPackage::hashValue($sources)];
            }
            unset($entry);
            $manifest['source_registry_sha256'] = CareerCurrentAuthorityPackage::hashValue($registries);
            $manifest['set_hashes']['source_semantic_aggregate_sha256'] = CareerCurrentAuthorityPackage::hashValue(array_column($manifest['files'], 'source_content_sha256'));
            $manifest['aggregate_sha256'] = CareerCurrentAuthorityPackage::hashValue(array_diff_key($manifest, ['aggregate_sha256' => true]));
            $manifestBytes = CareerCurrentAuthorityPackage::encodePrettyCanonical($manifest);
            file_put_contents($root.'/'.$relative.'/manifest.json', $manifestBytes);
            $intent = json_decode(file_get_contents($source.'/'.CareerCurrentAuthorityReleaseIntent::RELATIVE_PATH), true, 512, JSON_THROW_ON_ERROR);
            $intent['source_registry_sha256'] = $manifest['source_registry_sha256'];
            $intent['aggregate_sha256'] = $manifest['aggregate_sha256'];
            $intent['manifest_sha256'] = hash('sha256', $manifestBytes);
            $intent['versionless_projection_sha256'] = $manifest['set_hashes']['source_semantic_aggregate_sha256'];
            file_put_contents($root.'/'.CareerCurrentAuthorityReleaseIntent::RELATIVE_PATH, CareerCurrentAuthorityPackage::encodePrettyCanonical($intent));

            return $root;
        } catch (\Throwable $error) {
            $files->deleteDirectory($root);
            throw $error;
        }
    }
}
