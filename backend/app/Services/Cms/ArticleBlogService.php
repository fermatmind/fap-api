<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\LandingSurface;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Locale-owned display copy; shared taxonomy IDs/slugs remain unchanged. */
final class ArticleBlogService
{
    public const LINE_KEYS = [
        'personality-and-self-understanding',
        'career-and-learning',
        'communication-and-personal-growth',
        'research-and-methods',
    ];

    public function __construct(private readonly ArticlePublicListQuery $query) {}

    public function read(int $orgId, string $locale, callable $project): array
    {
        $empty = ['schema_version' => 1, 'configuration_state' => 'unconfigured',
            'title' => null, 'description' => null, 'categories' => [], 'featured_items' => []];
        $surface = LandingSurface::withoutGlobalScopes()->where('org_id', $orgId)
            ->where('surface_key', 'articles_index')->where('locale', $locale)->publishedPublic()
            ->where(fn ($q) => $q->whereNull('published_at')->orWhere('published_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('scheduled_at')->orWhere('scheduled_at', '<=', now()))->first();
        $config = $surface?->payload_json['blog_v1'] ?? null;
        if ($config === null) {
            return $empty;
        }

        $validator = Validator::make(is_array($config) ? $config : [], [
            'schema_version' => ['required', 'integer', Rule::in([1])],
            'categories' => ['present', 'array', 'max:32'],
            'categories.*.slug' => ['required', 'string', 'max:127', 'distinct', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'categories.*.line_key' => ['required', Rule::in(self::LINE_KEYS)],
            'categories.*.name' => ['required', 'string', 'max:255'],
            'categories.*.description' => ['required', 'string', 'max:4000'],
            'featured_article_ids' => ['present', 'array', 'max:12'],
            'featured_article_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ]);
        if ($validator->fails() || ! array_is_list($config['categories']) || ! array_is_list($config['featured_article_ids'])
            || trim((string) $surface->title) === '' || trim((string) $surface->description) === '') {
            return array_replace($empty, ['configuration_state' => 'invalid']);
        }

        $counts = $this->query->categoryCounts($orgId, $locale);
        $categories = [];
        foreach ($config['categories'] as $category) {
            $count = (int) ($counts[$category['slug']] ?? 0);
            if ($count > 0) {
                $categories[] = ['slug' => $category['slug'], 'line_key' => $category['line_key'],
                    'name' => $category['name'], 'description' => $category['description'], 'article_count' => $count];
            }
        }
        $ids = array_map('intval', $config['featured_article_ids']);
        $featured = $ids === [] ? [] : $this->query->selected($orgId, $locale, $ids)->map($project)->all();

        return ['schema_version' => 1, 'configuration_state' => 'published',
            'title' => $surface->title, 'description' => $surface->description,
            'categories' => $categories, 'featured_items' => $featured];
    }
}
