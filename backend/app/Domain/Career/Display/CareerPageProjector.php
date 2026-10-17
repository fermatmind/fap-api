<?php

declare(strict_types=1);

namespace App\Domain\Career\Display;

/** The public page has exactly one editable source: the installed locale file. */
final class CareerPageProjector
{
    public const VERSION = 'career.detail.page.v1';

    public function __construct(
        private readonly CareerContentV3CanonicalReader $reader,
        private readonly CareerContentV3AuthorityPackage $package,
        private readonly CareerContentV3FactResolver $facts,
    ) {}

    public function contains(string $slug): bool
    {
        return isset($this->reader->authority()['entries'][$slug]);
    }

    /** @return array<string, mixed> */
    public function fileEntry(string $slug, string $locale): array
    {
        return $this->reader->fileEntry($slug, $locale);
    }

    public function read(string $slug, string $locale): array
    {
        $locale = in_array(strtolower($locale), ['zh', 'zh-cn'], true) ? 'zh-CN' : $locale;
        $source = $this->package->pageFromIndex($this->reader->authority(), $slug, $locale);

        return $this->project($source);
    }

    public function project(array $source): array
    {
        $content = $this->facts->resolve($source);
        if (! isset($content['hero'], $content['seo'])) {
            throw new CareerCurrentAuthorityPackageFailure('CAREER_PAGE_FIELDS_MISSING');
        }
        $display = (new CareerPageDisplayResolver)->resolve($content);
        unset($content['display'], $content['authoring_structure']);
        foreach ($content['blocks'] as &$block) {
            $block['items'] = array_values(array_filter($block['items'], static fn (array $item): bool => ($item['visibility'] ?? 'public') !== 'internal'));
            foreach ($block['items'] as &$item) {
                unset($item['visibility']);
            }
            unset($item);
            if ($block['items'] === []) {
                $block['availability'] = 'missing';
            }
        }
        unset($block);
        $factMap = [];
        foreach ($content['fact_register']['facts'] ?? [] as $fact) {
            $factMap[$fact['fact_id']] = $fact;
        }
        $hero = $content['hero'];
        foreach ($hero['metrics'] as $index => $metric) {
            $hero['metrics'][$index]['fact'] = $metric['availability'] === 'available'
                ? $factMap[$metric['fact_ref']] : null;
        }
        $hero['ai']['fact'] = $hero['ai']['availability'] === 'available'
            ? $factMap[$hero['ai']['fact_ref']] : null;

        return $this->normalizeLinks([
            'contract_version' => self::VERSION,
            'locale' => $content['locale'],
            'subject' => $content['subject'],
            'source_content_sha256' => $content['source_content_sha256'],
            'hero' => $hero,
            'seo' => $content['seo'],
            'content' => $content,
            ...($display === null ? [] : ['display' => $display]),
        ]);
    }

    /** Normalize navigation only; locale files and their provenance remain unchanged. */
    public function normalizeLinks(array $page): array
    {
        $segment = ($page['locale'] ?? null) === 'zh-CN' ? 'zh' : 'en';
        $changes = [];
        $localize = static function (string $href) use ($segment): string {
            return preg_replace('#^/(?:en|zh)/(tests|career/jobs)/#', '/'.$segment.'/$1/', $href);
        };
        foreach ($page['display']['components']['personality_fit_block']['assessments'] ?? [] as $i => $assessment) {
            $href = $assessment['cta_href'];
            $explicit = str_starts_with($href, '/en/')
                ? preg_match('/英文|英语|English|\(EN\)/iu', $assessment['cta_label'])
                : preg_match('/中文|Chinese|\(ZH\)/iu', $assessment['cta_label']);
            $normalized = $explicit ? $href : $localize($href);
            $page['display']['components']['personality_fit_block']['assessments'][$i]['cta_href'] = $normalized;
            $changes['career.item.personality-fit-block'][$href] = $normalized;
        }
        foreach ($page['display']['components']['personality_fit_block']['directions'] ?? [] as $i => $direction) {
            if (! isset($direction['target'])) {
                continue;
            }
            $href = $direction['target']['href'];
            $normalized = $localize($href);
            $page['display']['components']['personality_fit_block']['directions'][$i]['target']['href'] = $normalized;
            $changes['career.item.personality-fit-block'][$href] = $normalized;
        }
        foreach ($page['display']['components']['market_signal_card']['transitions'] ?? [] as $i => $transition) {
            $href = $transition['target_href'];
            $normalized = $localize($href);
            $page['display']['components']['market_signal_card']['transitions'][$i]['target_href'] = $normalized;
            $changes['career.item.market-signal-card'][$href] = $normalized;
        }
        foreach ($page['content']['blocks'] ?? [] as $b => $block) {
            foreach ($block['items'] as $i => $item) {
                if ($item['type'] !== 'links' || ! isset($changes[$item['copy_key']])) {
                    continue;
                }
                foreach ($item['data']['entries'] as $e => $entry) {
                    $page['content']['blocks'][$b]['items'][$i]['data']['entries'][$e]['url'] = $changes[$item['copy_key']][$entry['url']] ?? $entry['url'];
                }
            }
        }

        return $page;
    }

    public static function assertFields(array $content): void
    {
        if (! array_key_exists('hero', $content) && ! array_key_exists('seo', $content)) {
            return; // Old content fixtures remain readable during the additive rollout.
        }
        $hero = $content['hero'] ?? null;
        $seo = $content['seo'] ?? null;
        if (! is_array($hero) || ! is_array($seo)
            || array_keys($hero) !== ['ai', 'badges', 'metrics']
            || array_keys($seo) !== ['description', 'title']
            || ! is_array($hero['badges']) || ! array_is_list($hero['badges'])
            || ! is_array($hero['metrics']) || ! array_is_list($hero['metrics'])
            || count($hero['metrics']) !== 5) {
            throw new CareerCurrentAuthorityPackageFailure('CAREER_PAGE_FIELDS_INVALID');
        }
        foreach ($hero['badges'] as $badge) {
            if (! is_string($badge) || trim($badge) === '') {
                throw new CareerCurrentAuthorityPackageFailure('CAREER_PAGE_FIELDS_INVALID');
            }
        }
        $factIds = array_column($content['fact_register']['facts'] ?? [], 'fact_id');
        $slots = ['us_pay', 'us_growth', 'us_employment', 'us_openings', 'china_pay'];
        foreach ([...$hero['metrics'], $hero['ai']] as $index => $metric) {
            if (! is_array($metric) || array_keys($metric) !== ['availability', 'fact_ref', 'key', 'label']
                || $metric['key'] !== ($slots[$index] ?? 'ai')
                || ! is_string($metric['label']) || trim($metric['label']) === ''
                || ! in_array($metric['availability'], ['available', 'missing'], true)
                || ($metric['availability'] === 'available'
                    ? ! in_array($metric['fact_ref'], $factIds, true)
                    : $metric['fact_ref'] !== null)) {
                throw new CareerCurrentAuthorityPackageFailure('CAREER_PAGE_METRIC_INVALID');
            }
        }
        foreach ($seo as $field) {
            if (! is_array($field) || array_keys($field) !== ['availability', 'text']
                || ! in_array($field['availability'], ['available', 'missing'], true)
                || ($field['availability'] === 'available'
                    ? ! is_string($field['text']) || trim($field['text']) === ''
                    : $field['text'] !== null)) {
                throw new CareerCurrentAuthorityPackageFailure('CAREER_PAGE_SEO_INVALID');
            }
        }
    }
}
