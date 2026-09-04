<?php

declare(strict_types=1);

namespace Tests\Feature\CareerCms;

use App\CareerCms\Baseline\CareerGuideBaselineImporter;
use App\CareerCms\Baseline\CareerGuideBaselineNormalizer;
use App\CareerCms\Baseline\CareerGuideBaselineReader;
use App\Models\Article;
use App\Models\CareerGuide;
use App\Models\CareerGuideRevision;
use App\Models\CareerJob;
use App\Models\PersonalityProfile;
use App\Services\Career\Recovery\CareerGuideLocaleRecovery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CareerGuideLocaleRecoveryTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array<string,mixed>> */
    private array $guides;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.frontend_url', 'https://www.example.test');

        $reader = app(CareerGuideBaselineReader::class);
        $documents = $reader->read($reader->resolveSourceDir(), ['en', 'zh-CN']);
        $zhDocument = collect($documents)->firstOrFail(
            static fn (array $document): bool => data_get($document, 'payload.meta.locale') === 'zh-CN',
        );
        $codes = array_map(
            static fn (array $guide): string => (string) $guide['guide_code'],
            array_slice((array) data_get($zhDocument, 'payload.guides', []), 0, 20),
        );
        $this->guides = app(CareerGuideBaselineNormalizer::class)->normalizeDocuments($documents, $codes);
        $this->seedRelationTargets($this->guides);
    }

    public function test_it_atomically_restores_twenty_chinese_guides_and_creates_distinct_english_rows(): void
    {
        $unrelatedGuide = CareerGuide::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'guide_code' => 'unrelated-guide',
            'slug' => 'unrelated-guide',
            'locale' => 'zh-CN',
            'title' => '不相关指南',
            'excerpt' => 'must remain untouched',
            'body_md' => '# 不相关指南',
            'body_html' => '<h1>不相关指南</h1>',
            'status' => CareerGuide::STATUS_PUBLISHED,
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => now(),
            'schema_version' => 'v1',
        ]);
        $unrelatedState = $unrelatedGuide->fresh()->getAttributes();
        $this->seedCorruptedChineseCohort();
        $this->artisan('career:recover-guide-locale-corruption', ['--execute' => true])
            ->expectsOutputToContain('status=recovered')
            ->expectsOutputToContain('created_count=20')
            ->expectsOutputToContain('updated_count=20')
            ->expectsOutputToContain('readback_count=40')
            ->assertExitCode(0);

        $this->assertSame(41, CareerGuide::query()->withoutGlobalScopes()->count());
        $this->assertSame($unrelatedState, $unrelatedGuide->fresh()->getAttributes());
        foreach ($this->guideCodes() as $code) {
            $zh = $this->guide($code, 'zh-CN');
            $en = $this->guide($code, 'en');
            $zhPayload = $this->payload($code, 'zh-CN');
            $enPayload = $this->payload($code, 'en');

            $this->assertNotSame($zh->id, $en->id);
            $this->assertSame($zhPayload['title'], $zh->title);
            $this->assertSame($zhPayload['body_md'], $zh->body_md);
            $this->assertSame($enPayload['title'], $en->title);
            $this->assertSame($enPayload['body_md'], $en->body_md);
            $this->assertSame('zh-CN', $zh->locale);
            $this->assertSame('en', $en->locale);
            $this->assertSame(
                CareerGuideLocaleRecovery::OPERATION_VERSION,
                CareerGuideRevision::query()->where('career_guide_id', $zh->id)->latest('revision_no')->value('note'),
            );
            $this->assertSame(
                CareerGuideLocaleRecovery::OPERATION_VERSION,
                CareerGuideRevision::query()->where('career_guide_id', $en->id)->latest('revision_no')->value('note'),
            );
        }

        $revisionCount = CareerGuideRevision::query()->count();
        $this->artisan('career:recover-guide-locale-corruption', ['--execute' => true])
            ->expectsOutputToContain('created_count=0')
            ->expectsOutputToContain('updated_count=0')
            ->expectsOutputToContain('readback_count=40')
            ->assertExitCode(0);
        $this->assertSame($revisionCount, CareerGuideRevision::query()->count());
    }

    public function test_unknown_chinese_drift_fails_before_any_recovery_write(): void
    {
        $this->seedCorruptedChineseCohort();
        $guide = $this->guide($this->guideCodes()[0], 'zh-CN');
        $guide->forceFill(['excerpt' => 'unknown drift'])->save();

        $before = $this->databaseFingerprint();
        $this->artisan('career:recover-guide-locale-corruption', ['--execute' => true])
            ->expectsOutputToContain('career_guide_recovery_unknown_chinese_state')
            ->assertExitCode(1);

        $this->assertSame($before, $this->databaseFingerprint());
        $this->assertSame(0, CareerGuide::query()->withoutGlobalScopes()->where('locale', 'en')->count());
    }

    public function test_missing_relationship_fails_before_any_recovery_write(): void
    {
        $this->seedCorruptedChineseCohort();
        $requiredJob = collect($this->guides)
            ->first(static fn (array $guide): bool => $guide['locale'] === 'en' && $guide['related_jobs'] !== []);
        $jobCode = (string) data_get($requiredJob, 'related_jobs.0.job_code');
        CareerJob::query()->withoutGlobalScopes()->where(['org_id' => 0, 'locale' => 'en', 'job_code' => $jobCode])->delete();

        $before = $this->databaseFingerprint();
        $this->artisan('career:recover-guide-locale-corruption', ['--execute' => true])
            ->expectsOutputToContain('Unable to resolve related_jobs')
            ->assertExitCode(1);

        $this->assertSame($before, $this->databaseFingerprint());
        $this->assertSame(0, CareerGuide::query()->withoutGlobalScopes()->where('locale', 'en')->count());
    }

    private function seedCorruptedChineseCohort(): void
    {
        $zhGuides = array_values(array_filter($this->guides, static fn (array $guide): bool => $guide['locale'] === 'zh-CN'));
        app(CareerGuideBaselineImporter::class)->import($zhGuides, [
            'dry_run' => false,
            'upsert' => false,
            'status' => null,
        ]);

        foreach ($this->guideCodes() as $code) {
            $guide = $this->guide($code, 'zh-CN');
            $en = $this->payload($code, 'en');
            $content = [];
            foreach (['title', 'excerpt', 'category_slug', 'body_md', 'body_html', 'related_industry_slugs_json', 'schema_version', 'sort_order'] as $field) {
                $content[$field] = $en[$field];
            }
            $guide->forceFill($content)->save();
            CareerGuideRevision::query()->create([
                'career_guide_id' => $guide->id,
                'revision_no' => ((int) CareerGuideRevision::query()->where('career_guide_id', $guide->id)->max('revision_no')) + 1,
                'snapshot_json' => [
                    'schema_version' => 'fermatmind.career_cms_promotion_revision.v2',
                    'promotion' => [
                        'package_sha256' => CareerGuideLocaleRecovery::CORRUPTING_PACKAGE_SHA256,
                        'asset_key' => '0:en:'.$guide->slug,
                    ],
                    'content' => $content,
                ],
                'note' => 'corrupting promotion fixture',
                'created_at' => now(),
            ]);
        }
    }

    /** @param list<array<string,mixed>> $guides */
    private function seedRelationTargets(array $guides): void
    {
        foreach ($guides as $guide) {
            $locale = (string) $guide['locale'];
            foreach ($guide['related_jobs'] as $relation) {
                CareerJob::query()->withoutGlobalScopes()->firstOrCreate(
                    ['org_id' => 0, 'locale' => $locale, 'job_code' => $relation['job_code']],
                    [
                        'slug' => $relation['job_code'],
                        'title' => $relation['job_code'],
                        'status' => CareerJob::STATUS_PUBLISHED,
                        'is_public' => true,
                        'is_indexable' => true,
                        'published_at' => now(),
                        'schema_version' => 'v1',
                        'sort_order' => 0,
                    ],
                );
            }
            foreach ($guide['related_articles'] as $relation) {
                Article::query()->withoutGlobalScopes()->firstOrCreate(
                    ['org_id' => 0, 'locale' => $locale, 'slug' => $relation['slug']],
                    [
                        'title' => $relation['slug'],
                        'excerpt' => $relation['slug'].' excerpt',
                        'content_md' => '# '.$relation['slug'],
                        'content_html' => '<h1>'.$relation['slug'].'</h1>',
                        'status' => 'published',
                        'is_public' => true,
                        'is_indexable' => true,
                        'published_at' => now(),
                    ],
                );
            }
            foreach ($guide['related_personality_profiles'] as $relation) {
                PersonalityProfile::query()->withoutGlobalScopes()->firstOrCreate(
                    ['org_id' => 0, 'locale' => $locale, 'scale_code' => PersonalityProfile::SCALE_CODE_MBTI, 'type_code' => $relation['type_code']],
                    [
                        'slug' => strtolower($relation['type_code']),
                        'title' => $relation['type_code'],
                        'status' => 'published',
                        'is_public' => true,
                        'is_indexable' => true,
                        'published_at' => now(),
                        'schema_version' => 'v1',
                    ],
                );
            }
        }
    }

    /** @return list<string> */
    private function guideCodes(): array
    {
        return array_values(array_unique(array_map(
            static fn (array $guide): string => (string) $guide['guide_code'],
            $this->guides,
        )));
    }

    /** @return array<string,mixed> */
    private function payload(string $code, string $locale): array
    {
        return collect($this->guides)->firstOrFail(
            static fn (array $guide): bool => $guide['guide_code'] === $code && $guide['locale'] === $locale,
        );
    }

    private function guide(string $code, string $locale): CareerGuide
    {
        return CareerGuide::query()->withoutGlobalScopes()->where([
            'org_id' => 0,
            'guide_code' => $code,
            'locale' => $locale,
        ])->firstOrFail();
    }

    private function databaseFingerprint(): string
    {
        $tables = [
            'career_guides',
            'career_guide_revisions',
            'career_guide_seo_meta',
            'career_guide_job_map',
            'career_guide_article_map',
            'career_guide_personality_map',
        ];
        $state = [];
        foreach ($tables as $table) {
            $state[$table] = DB::table($table)->get()->map(static fn ($row): array => (array) $row)->all();
        }

        return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
    }
}
