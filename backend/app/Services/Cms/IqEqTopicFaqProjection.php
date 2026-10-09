<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\TopicProfile;
use App\Models\TopicProfileRevision;
use App\Services\ContentPromotion\IqEqTopicPackage;
use App\Services\ContentPromotion\PromotionContextFactory;
use App\Services\PublicSurface\AnswerSurfaceContractService;

/** Full answer projection only for the fixed, published SH-01 package. */
final class IqEqTopicFaqProjection
{
    public static function blocks(TopicProfile $profile, array $sections, AnswerSurfaceContractService $answers): array
    {
        $limit = self::limit($profile, $sections);
        if ($limit === 7) {
            $sections = array_values(array_filter($sections, static fn (array $section): bool => ($section['section_key'] ?? null) === 'faq'));
        }

        return $answers->extractFaqBlocksFromSectionPayloads($sections, $limit);
    }

    public static function limit(TopicProfile $profile, array $sections): int
    {
        if ((int) $profile->org_id !== 0 || $profile->slug !== 'iq-eq' || $profile->topic_code !== 'iq-eq'
            || ! in_array($profile->locale, ['zh-CN', 'en'], true) || ! $profile->is_public || $profile->status !== 'published'
            || ! $profile->published_revision_id) {
            return 4;
        }
        $revision = TopicProfileRevision::query()->whereKey($profile->published_revision_id)->first();
        if (! $revision || (int) $revision->profile_id !== (int) $profile->id
            || $revision->workflow_state !== 'published' || $revision->authority_package_sha256 !== IqEqTopicPackage::SHA256
            || $revision->authority_asset_key !== 'SH-01:'.$profile->locale
            || $revision->source_package !== IqEqTopicPackage::PACKAGE || ! is_array($revision->snapshot_json)) {
            return 4;
        }
        $snapshot = $revision->snapshot_json;
        if ($revision->source_hash !== hash('sha256', PromotionContextFactory::canonicalJson($snapshot))) {
            return 4;
        }
        $expected = $snapshot['section_candidates'][1]['payload_json']['items'] ?? null;
        $actual = array_values(array_filter($sections, static fn (array $section): bool => ($section['section_key'] ?? null) === 'faq'));
        if (! is_array($expected) || ! array_is_list($expected) || count($expected) !== 7 || count($actual) !== 1
            || ! is_array($actual[0]['payload_json']['items'] ?? null)
            || PromotionContextFactory::canonicalJson($expected) !== PromotionContextFactory::canonicalJson($actual[0]['payload_json']['items'])) {
            return 4;
        }

        return 7;
    }
}
