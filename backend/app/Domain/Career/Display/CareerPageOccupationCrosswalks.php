<?php

declare(strict_types=1);

namespace App\Domain\Career\Display;

final class CareerPageOccupationCrosswalks
{
    public const FACT_ID = 'us-occupational-classification';

    /**
     * Resolve only a page's explicitly selected official occupation sources.
     *
     * @return list<array{source_system:string,source_code:string,source_title:string,mapping_type:string,confidence_score:int}>|null
     */
    public static function resolve(array $content): ?array
    {
        $fact = array_column($content['fact_register']['facts'] ?? [], null, 'fact_id')[self::FACT_ID] ?? null;
        if ($fact === null) {
            return null;
        }

        $sources = [];
        foreach ($content['blocks'] as $block) {
            foreach ($block['items'] as $item) {
                if ($item['type'] !== 'sources' || ($item['visibility'] ?? 'public') !== 'public') {
                    continue;
                }
                foreach ($item['data']['entries'] as $source) {
                    $sources[$source['id']] = $source;
                }
            }
        }

        $refs = $fact['source_refs'];
        $combined = count($refs) > 1;
        if ($combined && ! in_array('{{fact:'.self::FACT_ID.'}}', $content['hero']['badges'] ?? [], true)
            && ! in_array($fact['display_value'], $content['hero']['badges'] ?? [], true)) {
            throw new CareerCurrentAuthorityPackageFailure('CAREER_PAGE_OCCUPATION_CLASSIFICATION_INVALID');
        }

        $codes = [];
        $rows = [];
        foreach ($refs as $ref) {
            $source = $sources[$ref] ?? [];
            if (preg_match('#\Ahttps://www\.onetonline\.org/link/(?:summary|details|custom)/(\d{2}-\d{4}\.\d{2})\z#D', $source['url'] ?? '', $match) !== 1
                || isset($codes[$match[1]])) {
                throw new CareerCurrentAuthorityPackageFailure('CAREER_PAGE_OCCUPATION_CLASSIFICATION_INVALID');
            }
            $code = $match[1];
            $codes[$code] = true;
            foreach (['us_soc' => substr($code, 0, 7), 'onet_soc_2019' => $code] as $system => $value) {
                $rows[] = [
                    'source_system' => $system,
                    'source_code' => $value,
                    'source_title' => $source['name'],
                    'mapping_type' => $combined ? 'combined_member' : 'direct_match',
                    'confidence_score' => 1,
                ];
            }
        }

        return $rows;
    }
}
