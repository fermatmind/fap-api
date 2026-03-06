<?php

declare(strict_types=1);

namespace Tests\Feature\API\V0_5;

use App\Models\Article;
use App\Models\Topic;
use App\Models\TopicArticle;
use App\Models\TopicCareer;
use App\Models\TopicPersonality;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TopicControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_returns_global_topics_by_default(): void
    {
        Topic::query()->withoutGlobalScopes()->create([
            'org_id' => 15,
            'name' => 'Tenant Topic',
            'slug' => 'tenant-topic',
            'description' => 'Tenant only topic',
        ]);

        $response = $this->getJson('/api/v0.5/topics');

        $response
            ->assertOk()
            ->assertJsonPath('ok', true);

        $slugs = collect($response->json('items'))->pluck('slug')->all();

        $this->assertContains('mbti', $slugs);
        $this->assertNotContains('tenant-topic', $slugs);
    }

    public function test_show_returns_topic_with_articles_careers_and_personalities(): void
    {
        $topic = Topic::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'name' => 'Career Strategy',
            'slug' => 'career-strategy',
            'description' => 'Topic for articles and guidance',
            'seo_title' => 'Career Strategy Topic | FermatMind',
            'seo_description' => 'Grouped career strategy content.',
        ]);

        $article = Article::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'category_id' => null,
            'author_admin_user_id' => null,
            'slug' => 'systems-over-speed',
            'locale' => 'en',
            'title' => 'Systems Over Speed',
            'excerpt' => 'A systems-first approach to work.',
            'content_md' => 'Body copy',
            'content_html' => null,
            'cover_image_url' => null,
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => now(),
            'scheduled_at' => null,
        ]);

        TopicArticle::query()->create([
            'topic_id' => (int) $topic->id,
            'article_id' => (int) $article->id,
        ]);

        TopicCareer::query()->create([
            'topic_id' => (int) $topic->id,
            'career_id' => 1,
        ]);

        TopicPersonality::query()->create([
            'topic_id' => (int) $topic->id,
            'personality_type' => 'INTP',
        ]);

        $response = $this->getJson('/api/v0.5/topics/career-strategy');

        $response
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('topic.slug', 'career-strategy')
            ->assertJsonPath('articles.0.slug', 'systems-over-speed')
            ->assertJsonPath('careers.0.slug', 'software-engineer')
            ->assertJsonPath('personalities.0.slug', 'intp');
    }
}
