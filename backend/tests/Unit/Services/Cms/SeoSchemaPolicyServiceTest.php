<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Cms;

use App\Models\AdminUser;
use App\Models\Article;
use App\Services\Cms\ContentGovernanceService;
use App\Services\Cms\SeoSchemaPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SeoSchemaPolicyServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_finalize_enforces_page_type_author_publisher_and_core_fields(): void
    {
        config([
            'app.name' => 'FermatMind',
            'app.frontend_url' => 'https://staging.fermatmind.com',
        ]);

        $author = AdminUser::query()->create([
            'name' => 'Schema Author',
            'email' => 'schema-author@example.test',
            'password' => bcrypt('secret'),
            'is_active' => 1,
        ]);

        $article = Article::query()->create([
            'org_id' => 0,
            'author_admin_user_id' => (int) $author->id,
            'slug' => 'schema-policy-article',
            'locale' => 'en',
            'title' => 'Schema Policy Article',
            'excerpt' => 'Schema policy excerpt.',
            'content_md' => '# Body',
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => now()->subMinute(),
        ]);

        $schema = SeoSchemaPolicyService::finalize($article, [
            'headline' => 'Wrong headline',
            'description' => 'Wrong description',
        ], [
            'page_type' => ContentGovernanceService::PAGE_TYPE_GUIDE,
            'title' => 'Schema Policy Article',
            'description' => 'Schema policy excerpt.',
            'canonical' => 'https://staging.fermatmind.com/en/articles/schema-policy-article',
            'locale' => 'en',
            'published_at' => $article->published_at,
            'updated_at' => $article->updated_at,
            'overrides' => [
                '@type' => 'WebPage',
                'headline' => 'Override headline',
                'publisher' => ['@type' => 'Person', 'name' => 'Bad Publisher'],
                'keywords' => ['schema', 'policy'],
            ],
        ]);

        $this->assertSame('Article', data_get($schema, '@type'));
        $this->assertSame('Schema Policy Article', data_get($schema, 'headline'));
        $this->assertSame('Schema policy excerpt.', data_get($schema, 'description'));
        $this->assertSame('Schema Author', data_get($schema, 'author.name'));
        $this->assertSame('Organization', data_get($schema, 'publisher.@type'));
        $this->assertSame('FermatMind', data_get($schema, 'publisher.name'));
        $this->assertSame(['schema', 'policy'], data_get($schema, 'keywords'));
    }

    public function test_sanitize_stored_overrides_removes_protected_keys(): void
    {
        $sanitized = SeoSchemaPolicyService::sanitizeStoredOverrides([
            '@type' => 'WebPage',
            'publisher' => ['name' => 'Bad Publisher'],
            'mainEntityOfPage' => 'https://example.test/bad',
            'keywords' => ['safe'],
            'about' => ['@type' => 'Thing', 'name' => 'Safe'],
        ]);

        $this->assertSame([
            'keywords' => ['safe'],
            'about' => ['@type' => 'Thing', 'name' => 'Safe'],
        ], $sanitized);
    }

    public function test_expected_schema_type_and_protected_override_detection_follow_policy(): void
    {
        $this->assertSame('CollectionPage', SeoSchemaPolicyService::expectedSchemaTypeForPageType(ContentGovernanceService::PAGE_TYPE_HUB));
        $this->assertSame('ItemPage', SeoSchemaPolicyService::expectedSchemaTypeForPageType(ContentGovernanceService::PAGE_TYPE_ENTITY));
        $this->assertSame('Article', SeoSchemaPolicyService::expectedSchemaTypeForPageType(ContentGovernanceService::PAGE_TYPE_GUIDE));

        $this->assertSame(
            ['@type', 'publisher'],
            SeoSchemaPolicyService::protectedOverrideViolations([
                '@type' => 'WebPage',
                'publisher' => ['name' => 'Bad'],
                'keywords' => ['safe'],
            ])
        );
    }
}
