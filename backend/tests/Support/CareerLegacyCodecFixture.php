<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Career\Display\CareerCurrentAuthorityPackage;
use PHPUnit\Framework\Assert;

/** Synthetic historical transport only; never a Current body or publish candidate. */
final class CareerLegacyCodecFixture
{
    public static function createRepository(string $sourceRepository): string
    {
        $root = sys_get_temp_dir().'/career-legacy-codec-'.bin2hex(random_bytes(8));
        $current = $root.'/backend/content_assets/career/current';
        Assert::assertTrue(mkdir($current, 0700, true));
        Assert::assertTrue(mkdir($root.'/backend/docs/career/contracts', 0700, true));
        $contract = '/backend/docs/career/contracts/career-sharded-current-field-ownership.v1.json';
        Assert::assertTrue(copy($sourceRepository.$contract, $root.$contract));
        exec('git init --quiet '.escapeshellarg($root), $output, $exitCode);
        Assert::assertSame(0, $exitCode);

        // The retired codec requires 1046 identities and all 64 buckets. Repeat
        // one minimal bilingual payload, rather than copying historical bodies.
        $slugs = ['accountants-and-auditors'];
        foreach (range(1, 1045) as $index) {
            $slugs[] = sprintf('fixture-career-%04d', $index);
        }
        $handle = fopen($current.'/assets.jsonl', 'wb');
        Assert::assertIsResource($handle);
        foreach ($slugs as $index => $slug) {
            fwrite($handle, \CareerLegacyCurrentSharder::canonicalJson(self::row($slug, $index === 1045))."\n");
        }
        fclose($handle);
        file_put_contents($current.'/manifest.json', json_encode([
            'contract_version' => 'career.current_authority_manifest.v1',
            'files' => [['sha256' => hash_file('sha256', $current.'/assets.jsonl'), 'row_count' => 1046]],
            'set_hashes' => ['slug_set_sha256' => hash('sha256', \CareerLegacyCurrentSharder::canonicalJson($slugs))],
        ], JSON_THROW_ON_ERROR));

        return (string) realpath($root);
    }

    public static function row(string $slug, bool $direct = false): array
    {
        $components = array_keys(\CareerLegacyCurrentSharder::componentModuleMap());
        $pages = $faqPages = [];
        foreach (['en', 'zh'] as $locale) {
            $question = $locale === 'en' ? 'Fixture question?' : '测试问题？';
            $answer = $locale === 'en' ? 'Fixture answer.' : '测试回答。';
            $pages[$locale] = array_fill_keys($components, ['fixture' => $locale]);
            $pages[$locale]['path'] = '/'.$locale.'/career/jobs/'.$slug;
            $pages[$locale]['faq_block'] = ['items' => [['question' => $question, 'answer' => $answer]]];
            $faqPages[$locale] = [
                '@type' => 'FAQPage',
                'mainEntity' => [['@type' => 'Question', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $answer], 'name' => $question]],
            ];
        }
        $binding = ['claim_key' => 'fixture.definition', 'input_jsonpaths' => ['$.definition.summary']];

        return [
            'canonical_slug' => $slug,
            'surface_version' => CareerCurrentAuthorityPackage::SURFACE_VERSION,
            'asset_type' => CareerCurrentAuthorityPackage::ASSET_TYPE,
            'asset_role' => CareerCurrentAuthorityPackage::ASSET_ROLE,
            'status' => CareerCurrentAuthorityPackage::READY_STATUS,
            'component_order_json' => array_slice($components, 0, 28),
            'page_payload_json' => $direct ? $pages : ['page' => $pages],
            'seo_payload_json' => ['en' => ['title' => 'Fixture'], 'zh' => ['title' => '测试']],
            'sources_json' => ['references' => [['source_key' => 'fixture.source', 'url' => 'https://example.test/source']]],
            'structured_data_json' => ['faq_page' => $faqPages],
            'implementation_contract_json' => ['fixture' => true],
            'metadata_json' => [
                'presentation_v1' => ['zh' => ['hero' => ['title' => '测试']]],
                'presentation_v2' => ['en' => ['fixture' => true], 'zh' => ['fixture' => true]],
                'structured_components_v1' => ['locales' => ['en' => ['bindings' => [$binding]], 'zh-CN' => ['bindings' => [$binding]]]],
            ],
        ];
    }
}
