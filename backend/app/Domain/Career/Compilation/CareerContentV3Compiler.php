<?php

declare(strict_types=1);

namespace App\Domain\Career\Compilation;

use App\Domain\Career\Display\CareerContentV3Contract;
use App\Domain\Career\Display\CareerCurrentAuthorityPackage;

final class CareerContentV3Compiler
{
    public function __construct(
        private readonly CareerCurrentAuthorityPackage $package,
        private readonly CareerContentV3Projector $projector,
    ) {}

    /** @return array<string,mixed> */
    public function compile(string $backendRoot): array
    {
        $authority = $this->package->load($backendRoot);
        $pages = 0;
        $enhanced = 0;
        $legacy = 0;
        $hashes = [];
        foreach ($authority['rows'] as $slug => $row) {
            $localizedPages = is_array(data_get($row, 'page_payload_json.page'))
                ? data_get($row, 'page_payload_json.page')
                : ($row['page_payload_json'] ?? []);
            foreach (['en' => 'en', 'zh' => 'zh-CN'] as $pageLocale => $locale) {
                $page = is_array($localizedPages) ? ($localizedPages[$pageLocale] ?? null) : null;
                if (! is_array($page)) {
                    throw new CareerTenBlockCompileFailure('CONTENT_V3_LOCALE_PAGE_INVALID');
                }
                $presentation = data_get($row, 'metadata_json.presentation_v2.'.$pageLocale);
                $content = $this->projector->project(
                    $slug,
                    $locale,
                    $page,
                    is_array($presentation) ? $presentation : null,
                    is_array($row['sources_json'] ?? null) ? $row['sources_json'] : [],
                );
                CareerContentV3Contract::assert($content);
                $hashes[$slug][$locale] = CareerCurrentAuthorityPackage::hashValue($content);
                $pages++;
                $content['content_state'] === 'enhanced' ? $enhanced++ : $legacy++;
            }
        }

        return [
            'contract_version' => 'career.detail.content.v3.compile_receipt.v1',
            'career_count' => count($authority['rows']),
            'locale_page_count' => $pages,
            'enhanced_locale_page_count' => $enhanced,
            'legacy_locale_page_count' => $legacy,
            'projection_sha256' => CareerCurrentAuthorityPackage::hashValue($hashes),
            'database_writes' => 0,
            'cache_writes' => 0,
            'cms_writes' => 0,
            'discoverability_writes' => 0,
            'search_submissions' => 0,
        ];
    }
}
