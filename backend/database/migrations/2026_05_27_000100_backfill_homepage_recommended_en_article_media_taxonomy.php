<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var array<string, array{
     *     cover_image_alt: string,
     *     category: array{slug: string, name: string},
     *     tags: list<array{slug: string, name: string}>
     * }>
     */
    private array $fixtures = [
        'best-valentines-date-by-personality-and-relationship-science' => [
            'cover_image_alt' => 'Abstract path map connecting two relationship nodes to represent lower-friction date design.',
            'category' => ['slug' => 'relationships-and-love', 'name' => 'Relationships and Love'],
            'tags' => [
                ['slug' => 'intimate-relationships', 'name' => 'Intimate Relationships'],
                ['slug' => 'personality-psychology', 'name' => 'Personality Psychology'],
                ['slug' => 'relationship-science', 'name' => 'Relationship Science'],
                ['slug' => 'valentines-day', 'name' => 'Valentine\'s Day'],
                ['slug' => 'date-design', 'name' => 'Date Design'],
            ],
        ],
        'are-infj-men-rare-or-socially-silenced' => [
            'cover_image_alt' => 'Translucent male silhouette and muted sound-wave lines symbolizing self-silencing among highly sensitive men.',
            'category' => ['slug' => 'personality-psychology', 'name' => 'Personality Psychology'],
            'tags' => [
                ['slug' => 'infj', 'name' => 'INFJ'],
                ['slug' => 'emotional-expression', 'name' => 'Emotional Expression'],
                ['slug' => 'masculinity-norms', 'name' => 'Masculinity Norms'],
                ['slug' => 'self-silencing', 'name' => 'Self-Silencing'],
                ['slug' => 'highly-sensitive-people', 'name' => 'Highly Sensitive People'],
            ],
        ],
        'which-love-script-fits-you-best' => [
            'cover_image_alt' => 'Abstract relationship network showing geometric paths for seven love scripts.',
            'category' => ['slug' => 'relationships-and-love', 'name' => 'Relationships and Love'],
            'tags' => [
                ['slug' => 'intimate-relationships', 'name' => 'Intimate Relationships'],
                ['slug' => 'relationship-science', 'name' => 'Relationship Science'],
                ['slug' => 'mbti', 'name' => 'MBTI'],
                ['slug' => 'attachment-and-intimacy', 'name' => 'Attachment and Intimacy'],
                ['slug' => 'love-styles', 'name' => 'Love Styles'],
            ],
        ],
    ];

    public function up(): void
    {
        if (
            ! Schema::hasTable('articles')
            || ! Schema::hasTable('article_categories')
            || ! Schema::hasTable('article_tags')
            || ! Schema::hasTable('article_tag_map')
        ) {
            return;
        }

        DB::transaction(function (): void {
            foreach ($this->fixtures as $slug => $fixture) {
                $this->backfillArticle($slug, $fixture);
            }
        });
    }

    public function down(): void
    {
        // Forward-only authority repair. Removing media/taxonomy would
        // reintroduce EN homepage recommended article parity gaps.
    }

    /**
     * @param  array{
     *     cover_image_alt: string,
     *     category: array{slug: string, name: string},
     *     tags: list<array{slug: string, name: string}>
     * }  $fixture
     */
    private function backfillArticle(string $slug, array $fixture): void
    {
        $article = DB::table('articles')
            ->where('org_id', 0)
            ->where('locale', 'en')
            ->where('slug', $slug)
            ->where('status', 'published')
            ->where('is_public', true)
            ->first();

        if (! $article) {
            return;
        }

        $now = now();
        $coverImageUrl = 'https://api.fermatmind.com/static/articles/covers/'.$slug.'.svg';
        $categoryId = $this->ensureCategory($fixture['category']['slug'], $fixture['category']['name'], $now);

        DB::table('articles')
            ->where('id', (int) $article->id)
            ->update([
                'category_id' => $categoryId,
                'cover_image_url' => $coverImageUrl,
                'cover_image_alt' => $fixture['cover_image_alt'],
                'cover_image_width' => 1200,
                'cover_image_height' => 675,
                'cover_image_variants' => json_encode([
                    'hero' => $coverImageUrl,
                    'card' => $coverImageUrl,
                    'thumbnail' => $coverImageUrl,
                    'og' => $coverImageUrl,
                    'preload' => $coverImageUrl,
                ], JSON_THROW_ON_ERROR),
                'updated_at' => $now,
            ]);

        if (Schema::hasTable('article_seo_meta')) {
            DB::table('article_seo_meta')
                ->where('org_id', 0)
                ->where('article_id', (int) $article->id)
                ->where('locale', 'en')
                ->update([
                    'og_image_url' => $coverImageUrl,
                    'updated_at' => $now,
                ]);
        }

        foreach ($fixture['tags'] as $tag) {
            $tagId = $this->ensureTag($tag['slug'], $tag['name'], $now);

            DB::table('article_tag_map')->insertOrIgnore([
                'org_id' => 0,
                'article_id' => (int) $article->id,
                'tag_id' => $tagId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function ensureCategory(string $slug, string $name, Carbon\CarbonInterface $now): int
    {
        $existing = DB::table('article_categories')
            ->where('org_id', 0)
            ->where('slug', $slug)
            ->first();

        if ($existing) {
            DB::table('article_categories')
                ->where('id', (int) $existing->id)
                ->update([
                    'name' => $name,
                    'is_active' => true,
                    'updated_at' => $now,
                ]);

            return (int) $existing->id;
        }

        return (int) DB::table('article_categories')->insertGetId([
            'org_id' => 0,
            'slug' => $slug,
            'name' => $name,
            'description' => null,
            'sort_order' => 0,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function ensureTag(string $slug, string $name, Carbon\CarbonInterface $now): int
    {
        $existing = DB::table('article_tags')
            ->where('org_id', 0)
            ->where('slug', $slug)
            ->first();

        if ($existing) {
            DB::table('article_tags')
                ->where('id', (int) $existing->id)
                ->update([
                    'name' => $name,
                    'is_active' => true,
                    'updated_at' => $now,
                ]);

            return (int) $existing->id;
        }

        return (int) DB::table('article_tags')->insertGetId([
            'org_id' => 0,
            'slug' => $slug,
            'name' => $name,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
};
