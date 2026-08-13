<?php

declare(strict_types=1);

namespace App\PersonalityCms\DesktopClone;

use App\Models\PersonalityProfileVariantCloneContent;
use App\PersonalityCms\DesktopClone\Baseline\PersonalityDesktopCloneBaselineNormalizer;
use App\PersonalityCms\DesktopClone\Baseline\PersonalityDesktopCloneBaselineReader;
use App\Support\Idempotency\IdempotencyKey;
use App\Support\Mbti\MbtiZhResultContentPolicy;
use RuntimeException;

final class MbtiZhResultContentPackage
{
    public const PACKAGE_SCHEMA = 'mbti_zh_result_content_package.v1';

    public const SNAPSHOT_KEY = 'mbti_zh_result_clone_v1';

    private const FORBIDDEN_READER_COPY = [
        '费马心理',
        '不是喊口号',
        '解锁完整报告',
        '当前价格',
        '占位说明',
    ];

    public function __construct(
        private readonly PersonalityDesktopCloneBaselineReader $reader,
        private readonly PersonalityDesktopCloneBaselineNormalizer $normalizer,
        private readonly PersonalityVariantCloneContentValidator $validator,
    ) {}

    /** @return array<string, mixed> */
    public function compile(): array
    {
        $sourceDir = $this->reader->resolveSourceDir();
        $documents = $this->reader->read($sourceDir, ['zh-CN']);
        $rows = $this->normalizer->normalizeDocuments($documents);

        if (count($rows) !== 32) {
            throw new RuntimeException('The zh-CN MBTI result package must contain exactly 32 variants.');
        }

        $compiledRows = [];
        foreach ($rows as $row) {
            $fullCode = strtoupper(trim((string) ($row['full_code'] ?? '')));
            $content = MbtiZhResultContentPolicy::normalizeDesktopContent((array) ($row['content_json'] ?? []), 'zh-CN');
            $content = $this->contextualizeSharedModuleCopy($content);
            $assetSlots = MbtiZhResultContentPolicy::normalizeAssetSlots(
                PersonalityDesktopCloneAssetSlotSupport::normalizeAssetSlots((array) ($row['asset_slots_json'] ?? [])),
                'zh-CN',
            );
            $assetSlots = $this->validator->assertValid($content, $assetSlots, PersonalityProfileVariantCloneContent::STATUS_PUBLISHED);
            // Validation canonicalizes blank strings to null. Re-apply the public zh-CN
            // contract so disabled decorative slots expose the exact empty alt text.
            $assetSlots = MbtiZhResultContentPolicy::normalizeAssetSlots($assetSlots, 'zh-CN');
            $this->assertQuality($fullCode, $content, $assetSlots);

            $sourcePayload = [
                'schema' => self::PACKAGE_SCHEMA,
                'locale' => 'zh-CN',
                'template_key' => PersonalityProfileVariantCloneContent::TEMPLATE_KEY_MBTI_DESKTOP_CLONE_V1,
                'full_code' => $fullCode,
                'content_json' => $content,
                'asset_slots_json' => $assetSlots,
            ];
            $sourceHash = IdempotencyKey::hashPayload($sourcePayload);
            $compiledRows[] = [
                ...$row,
                'locale' => 'zh-CN',
                'template_key' => PersonalityProfileVariantCloneContent::TEMPLATE_KEY_MBTI_DESKTOP_CLONE_V1,
                'content_json' => $content,
                'asset_slots_json' => $assetSlots,
                'source_hash' => $sourceHash,
            ];
        }

        usort($compiledRows, static fn (array $left, array $right): int => $left['full_code'] <=> $right['full_code']);
        $sourceManifest = array_map(static fn (array $row): array => [
            'full_code' => $row['full_code'],
            'source_hash' => $row['source_hash'],
        ], $compiledRows);
        $packageHash = IdempotencyKey::hashPayload([
            'schema' => self::PACKAGE_SCHEMA,
            'locale' => 'zh-CN',
            'template_key' => PersonalityProfileVariantCloneContent::TEMPLATE_KEY_MBTI_DESKTOP_CLONE_V1,
            'sources' => $sourceManifest,
        ]);
        $packageId = 'mbti-zh-result-'.substr($packageHash, 0, 16);

        foreach ($compiledRows as $index => $row) {
            $compiledRows[$index]['meta_json'] = array_merge(
                is_array($row['meta_json'] ?? null) ? $row['meta_json'] : [],
                [
                    'package_schema' => self::PACKAGE_SCHEMA,
                    'package_id' => $packageId,
                    'package_hash' => $packageHash,
                    'source_hash' => $row['source_hash'],
                ],
            );
        }

        return [
            'schema' => self::PACKAGE_SCHEMA,
            'package_id' => $packageId,
            'package_hash' => $packageHash,
            'locale' => 'zh-CN',
            'template_key' => PersonalityProfileVariantCloneContent::TEMPLATE_KEY_MBTI_DESKTOP_CLONE_V1,
            'record_count' => count($compiledRows),
            'source_manifest' => $sourceManifest,
            'rows' => $compiledRows,
        ];
    }

    /**
     * @param  array<string, mixed>  $content
     * @param  list<array<string, mixed>>  $assetSlots
     */
    private function assertQuality(string $fullCode, array $content, array $assetSlots): void
    {
        foreach (['career', 'growth', 'relationships'] as $chapter) {
            if (! is_array($content['chapters'][$chapter] ?? null)) {
                throw new RuntimeException(sprintf('%s is missing chapter %s.', $fullCode, $chapter));
            }
        }
        if (! is_array($content['traits'] ?? null)) {
            throw new RuntimeException(sprintf('%s is missing the traits section.', $fullCode));
        }
        if (count((array) ($content['faq'] ?? [])) !== 4) {
            throw new RuntimeException(sprintf('%s must contain exactly four result FAQs.', $fullCode));
        }

        $readerCopy = IdempotencyKey::canonicalJson($content);
        foreach (self::FORBIDDEN_READER_COPY as $forbidden) {
            if (str_contains($readerCopy, $forbidden)) {
                throw new RuntimeException(sprintf('%s contains forbidden reader copy: %s.', $fullCode, $forbidden));
            }
        }

        if (count($assetSlots) !== 7) {
            throw new RuntimeException(sprintf('%s must contain exactly seven media slots.', $fullCode));
        }
        foreach ($assetSlots as $slot) {
            if (($slot['status'] ?? null) !== PersonalityDesktopCloneAssetSlotSupport::STATUS_DISABLED
                || ($slot['asset_ref'] ?? null) !== null
                || ($slot['alt'] ?? null) !== '') {
                throw new RuntimeException(sprintf('%s contains a consumable media slot.', $fullCode));
            }
        }
    }

    /** @param array<string,mixed> $content @return array<string,mixed> */
    private function contextualizeSharedModuleCopy(array $content): array
    {
        $nickname = trim((string) data_get($content, 'hero.profile_identity.nickname', ''));
        if ($nickname === '') {
            return $content;
        }
        $prefix = $nickname.'视角：';
        $modulePaths = [
            'chapters.growth.what_energizes.items',
            'chapters.growth.what_drains.items',
            'chapters.relationships.superpowers.items',
            'chapters.relationships.pitfalls.items',
        ];
        foreach ($modulePaths as $path) {
            foreach ((array) data_get($content, $path, []) as $itemIndex => $item) {
                if (! is_array($item)) {
                    continue;
                }
                foreach ((array) ($item['signals'] ?? []) as $signalIndex => $signal) {
                    data_set($content, $path.'.'.$itemIndex.'.signals.'.$signalIndex, $prefix.ltrim((string) $signal));
                }
                foreach (['do', 'avoid'] as $action) {
                    $value = trim((string) data_get($item, 'actions.'.$action, ''));
                    if ($value !== '') {
                        data_set($content, $path.'.'.$itemIndex.'.actions.'.$action, $prefix.$value);
                    }
                }
            }
        }
        foreach ((array) data_get($content, 'chapters.career.work_styles.items', []) as $index => $item) {
            $title = trim((string) data_get($item, 'title', ''));
            if ($title !== '') {
                data_set($content, 'chapters.career.work_styles.items.'.$index.'.title', $prefix.$title);
            }
        }

        return $content;
    }
}
