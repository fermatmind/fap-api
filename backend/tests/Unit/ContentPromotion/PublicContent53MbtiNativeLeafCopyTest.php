<?php

declare(strict_types=1);

namespace Tests\Unit\ContentPromotion;

use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileVariant;
use App\Models\PersonalityProfileVariantRevision;
use App\Models\PersonalityProfileVariantSection;
use App\Services\Cms\MbtiEnglishVariantSectionEnrichmentService;
use App\Services\ContentPromotion\PromotionContext;
use App\Services\ContentPromotion\PromotionContextFactory;
use App\Services\ContentPromotion\PublicContent53MbtiResultCopy;
use App\Services\ContentPromotion\PublicContent53Package;
use App\Services\ContentPromotion\PublicContent53State;
use App\Services\ContentPromotion\PublicContent53Writer;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class PublicContent53MbtiNativeLeafCopyTest extends TestCase
{
    use RefreshDatabase;

    private function rows(): array
    {
        return app(PublicContent53Package::class)->read(base_path(), $this->digest())['candidates'];
    }

    private function digest(): string
    {
        $chain = PublicContent53MbtiResultCopy::NATIVE_SCHEMA."\n";
        foreach (['mbti-native-leaf-copy.json', 'mbti-native-leaf-copy-review.json'] as $file) {
            $chain .= $file."\n".hash_file('sha256', base_path(PublicContent53Package::PACKAGE.'/'.$file))."\n";
        }

        return hash('sha256', $chain);
    }

    private function context(): PromotionContext
    {
        return new PromotionContext(base_path(PublicContent53Package::PACKAGE), $this->digest(), 'W3', 'PUBLIC-CONTENT-53', str_repeat('a', 40), str_repeat('b', 64), str_repeat('c', 64), '12345', 1, str_repeat('d', 64), 32, str_repeat('e', 64));
    }

    private function typeLabel(array $row): string
    {
        preg_match('/^A growth approach to test for '.preg_quote($row['source_identity']['runtime_type_code'], '/').' (.+) is turning /', $row['snapshot']['growth_strength'], $m);

        return $m[1];
    }

    private function seedTargets(): void
    {
        $factory = new MbtiEnglishVariantSectionEnrichmentService;
        $profiles = [];
        foreach ($this->rows() as $row) {
            $code = $row['source_identity']['runtime_type_code'];
            $type = substr($code, 0, 4);
            $profile = $profiles[$type] ??= PersonalityProfile::create(['org_id' => 0, 'scale_code' => 'MBTI', 'type_code' => $type, 'canonical_type_code' => $type, 'slug' => strtolower($type), 'locale' => 'en', 'title' => $this->typeLabel($row), 'status' => 'published', 'is_public' => true, 'is_indexable' => true, 'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2]);
            $variant = PersonalityProfileVariant::create(['org_id' => 0, 'personality_profile_id' => $profile->id, 'canonical_type_code' => $type, 'variant_code' => substr($code, -1), 'runtime_type_code' => $code, 'is_published' => true]);
            $sections = [];
            foreach ($factory->sectionKeys() as $key) {
                $sections[$key] = $factory->build($code, $this->typeLabel($row), $key);
            }
            $items = array_fill(0, 12, ['question' => 'Keep question', 'intent' => 'keep', 'answer' => 'Keep native answer']);
            $items[5] = ['question' => 'What stresses '.$code.'?', 'intent' => 'stress', 'answer' => 'Keep protected INTJ-T stress answer'];
            $sections['faq'] = ['section_key' => 'faq', 'render_variant' => 'faq', 'body_md' => 'Keep FAQ introduction', 'payload_json' => ['items' => $items], 'sort_order' => 900, 'is_enabled' => true];
            foreach ($row['source_identity']['leaf_map'] as $name => $leaf) {
                $path = $name === 'stress_answer' ? ['payload_json', 'items', 5, 'answer'] : $leaf['path'];
                data_set($sections[$leaf['section_key']], implode('.', $path), $row['before'][$name]);
            }
            foreach ($sections as $section) {
                $section['payload_json'] = ['future' => ['keep' => [7, 1, 4]], ...($section['payload_json'] ?? [])];
                PersonalityProfileVariantSection::create(['org_id' => 0, 'personality_profile_variant_id' => $variant->id, ...$section, 'body_html' => '<p>Keep independently authored HTML</p>']);
            }
            PersonalityProfileVariantRevision::create(['personality_profile_variant_id' => $variant->id, 'revision_no' => 1, 'snapshot_json' => ['draft' => 'Keep '.$code.' operator draft'], 'note' => 'Operator draft', 'created_at' => now()]);
        }
    }

    public function test_all_576_generator_sections_change_only_the_156_reviewed_leaves_and_faq_stays_native(): void
    {
        $fixture = json_decode((string) file_get_contents(base_path('tests/Fixtures/mbti_english_pre_native_copy_sections.json')), true, 64, JSON_THROW_ON_ERROR);
        $factory = new MbtiEnglishVariantSectionEnrichmentService;
        $this->assertNotContains('faq', $factory->sectionKeys());
        $changed = $leaves = $count = 0;
        foreach ($this->rows() as $row) {
            $code = $row['source_identity']['runtime_type_code'];
            foreach ($factory->sectionKeys() as $key) {
                $built = $factory->build($code, $this->typeLabel($row), $key);
                $old = $built;
                foreach ($row['source_identity']['leaf_map'] as $name => $leaf) {
                    if ($leaf['section_key'] !== $key || $name === 'stress_answer') {
                        continue;
                    }
                    $this->assertSame($row['snapshot'][$name], data_get($built, implode('.', $leaf['path'])));
                    data_set($old, implode('.', $leaf['path']), $row['before'][$name]);
                    $leaves++;
                }
                $beforeHash = $fixture['sections'][$code][$key];
                $this->assertSame($beforeHash, hash('sha256', PromotionContextFactory::canonicalJson($old)), $code.'.'.$key.' unowned delta');
                $changed += $beforeHash !== hash('sha256', PromotionContextFactory::canonicalJson($built)) ? 1 : 0;
                $count++;
            }
        }
        $this->assertSame([576, 125, 156], [$count, $changed, $leaves]);
    }

    public static function environments(): array
    {
        return [['staging'], ['production']];
    }

    #[DataProvider('environments')]
    public function test_32_native_variants_publish_replay_and_canonical_snapshot_restore_preserve_controls(string $environment): void
    {
        config(['content_promotion.public_content_53_environment' => $environment]);
        $this->seedTargets();
        $states = app(PublicContent53State::class);
        $writer = app(PublicContent53Writer::class);
        $rows = $this->rows();
        $before = $states->read($this->digest());
        $drafts = PersonalityProfileVariantRevision::orderBy('id')->get()->map->getRawOriginal()->all();
        $this->assertCount(32, $rows);
        $this->assertSame(187, array_sum(array_map(static fn ($row) => count($row['snapshot']), $rows)));
        $this->assertSame(156, array_sum(array_map(static fn ($saved) => count($saved['native_sections']), $before)));
        $states->assertBaseline($rows);
        $published = DB::transaction(fn () => $writer->publish($this->context(), $before));
        $this->assertSame(32, $published['written_count']);
        foreach ($rows as $row) {
            $this->assertSame($row['snapshot'], $states->projection($row));
        }
        $this->assertSame(64, PersonalityProfileVariantRevision::count());
        $this->assertSame(0, DB::transaction(fn () => $writer->publish($this->context(), $published['state']))['written_count']);
        $canonicalBefore = json_decode(PromotionContextFactory::canonicalJson($before), true, 512, JSON_THROW_ON_ERROR);
        $canonicalAfter = json_decode(PromotionContextFactory::canonicalJson($published['state']), true, 512, JSON_THROW_ON_ERROR);
        DB::transaction(fn () => $writer->restore($this->context(), $canonicalBefore, $canonicalAfter));
        foreach ($rows as $row) {
            $this->assertSame($row['before'], $states->projection($row));
        }
        $this->assertSame(96, PersonalityProfileVariantRevision::count());
        foreach ($drafts as $draft) {
            $this->assertSame($draft, PersonalityProfileVariantRevision::findOrFail($draft['id'])->getRawOriginal());
        }
        $restored = $states->read($this->digest());
        foreach ($before as $id => $saved) {
            foreach (['profile', 'variants', 'sections'] as $key) {
                $this->assertSame($saved['controls'][$key], $restored[$id]['controls'][$key]);
            }
        }
        $this->assertDatabaseCount('attempts', 0);
    }

    public function test_faq_follows_unique_question_identity_after_reordering_and_reuses_already_fixed_leaves(): void
    {
        $this->seedTargets();
        $row = $this->rows()[0];
        $variant = PersonalityProfileVariant::where('runtime_type_code', 'ENFJ-A')->firstOrFail();
        $faq = PersonalityProfileVariantSection::where('personality_profile_variant_id', $variant->id)->where('section_key', 'faq')->firstOrFail();
        $payload = $faq->payload_json;
        [$payload['items'][0], $payload['items'][5]] = [$payload['items'][5], $payload['items'][0]];
        $faq->update(['payload_json' => array_reverse($payload, true)]);
        $motivator = PersonalityProfileVariantSection::where('personality_profile_variant_id', $variant->id)->where('section_key', 'growth.motivators')->firstOrFail();
        $motivator->update(['body_md' => $row['snapshot']['motivator_body']]);
        $states = app(PublicContent53State::class);
        $states->assertBaseline($this->rows());
        $before = $states->read($this->digest());
        $published = DB::transaction(fn () => app(PublicContent53Writer::class)->publish($this->context(), $before));
        $this->assertSame($row['snapshot']['stress_answer'], $faq->fresh()->payload_json['items'][0]['answer']);
        $this->assertSame('Keep native answer', $faq->fresh()->payload_json['items'][5]['answer']);
        $this->assertSame($row['snapshot']['motivator_body'], $motivator->fresh()->body_md);
        $this->assertSame($row['snapshot'], $states->projection($row));
        $this->assertSame(32, $published['written_count']);
    }

    public function test_injected_late_revision_failure_rolls_back_the_entire_batch(): void
    {
        $this->seedTargets();
        $states = app(PublicContent53State::class);
        $before = $states->read($this->digest());
        $dispatcher = PersonalityProfileVariantRevision::getEventDispatcher();
        PersonalityProfileVariantRevision::setEventDispatcher(clone $dispatcher);
        $writes = 0;
        PersonalityProfileVariantRevision::creating(static function () use (&$writes): void {
            if (++$writes === 17) {
                throw new RuntimeException('Injected native batch revision failure');
            }
        });
        try {
            DB::transaction(fn () => app(PublicContent53Writer::class)->publish($this->context(), $before));
            $this->fail('Must roll back all native rows.');
        } catch (RuntimeException $error) {
            $this->assertSame('Injected native batch revision failure', $error->getMessage());
        } finally {
            PersonalityProfileVariantRevision::setEventDispatcher($dispatcher);
        }
        $this->assertSame($before, $states->read($this->digest()));
        $this->assertSame(32, PersonalityProfileVariantRevision::count());
    }

    public function test_stale_unowned_or_target_changes_and_later_writers_cannot_be_overwritten(): void
    {
        $this->seedTargets();
        $states = app(PublicContent53State::class);
        $writer = app(PublicContent53Writer::class);
        $before = $states->read($this->digest());
        $section = PersonalityProfileVariantSection::where('section_key', 'overview')->firstOrFail();
        $original = $section->body_html;
        $section->update(['body_html' => '<p>New operator HTML</p>']);
        try {
            DB::transaction(fn () => $writer->publish($this->context(), $before));
            $this->fail('Must reject stale control state.');
        } catch (DomainException $error) {
            $this->assertSame('public_content_53_native_prestate_drift', $error->getMessage());
        }
        $section->update(['body_html' => $original]);
        $before = $states->read($this->digest());
        $published = DB::transaction(fn () => $writer->publish($this->context(), $before));
        $target = PersonalityProfileVariantSection::where('section_key', 'growth.strengths')->firstOrFail();
        $payload = $target->payload_json;
        $payload['items'][0]['body'] = 'Newer operator body';
        $target->update(['payload_json' => $payload]);
        $this->expectExceptionMessage('public_content_53_rollback_concurrent_state');
        DB::transaction(fn () => $writer->restore($this->context(), $before, $published['state']));
    }

    public function test_package_refuses_extra_missing_foreign_and_position_only_faq_targets(): void
    {
        $original = json_decode((string) file_get_contents(base_path(PublicContent53Package::PACKAGE.'/mbti-native-leaf-copy.json')), true, 64, JSON_THROW_ON_ERROR);
        foreach (['missing', 'extra', 'org', 'locale', 'faq_position', 'faq_question', 'protected'] as $case) {
            $data = $original;
            match ($case) {
                'missing' => array_pop($data['candidates']),
                'extra' => $data['candidates'][] = $data['candidates'][0],
                'org' => $data['candidates'][0]['identity']['org_id'] = 7,
                'locale' => $data['candidates'][0]['identity']['locale'] = 'zh-CN',
                'faq_position' => $data['candidates'][0]['source_identity']['leaf_map']['stress_answer']['path'] = ['payload_json', 'items', 5, 'answer'],
                'faq_question' => $data['candidates'][0]['source_identity']['faq_identity']['question'] = 'Different question?',
                'protected' => $data['candidates'][21]['snapshot']['motivator_body'] = 'Overwrite protected INTJ-T motivator',
            };
            $bytes = json_encode($data, JSON_THROW_ON_ERROR);
            $review = ['schema' => 'fermatmind.mbti_english_native_leaf_copy.review.v1', 'candidate_sha256' => hash('sha256', $bytes), 'verdict' => 'PASS', 'pages' => array_fill_keys(array_column($original['candidates'], 'page_id'), 'PASS')];
            try {
                PublicContent53MbtiResultCopy::nativeLeafPackage($bytes, json_encode($review, JSON_THROW_ON_ERROR));
                $this->fail('Must reject '.$case);
            } catch (DomainException $error) {
                $this->assertStringStartsWith('mbti_result_copy_', $error->getMessage());
            }
        }
    }

    public function test_existing_four_phase_service_reads_all_32_variants_and_restores_its_canonical_snapshot(): void
    {
        $this->seedTargets();
        config(['ops.content_release_observability.hmac_revalidation_url' => 'https://staging.fermatmind.com/api/content-release/revalidate',
            'ops.content_release_observability.hmac_revalidation_secret' => 'test-only-secret']);
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        $directory = sys_get_temp_dir().'/mbti-native-phases-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $states = app(PublicContent53State::class);
        $before = $states->read($this->digest());
        $previous = $reference = '';
        try {
            foreach (['preflight', 'draft-import', 'publish', 'live-qa'] as $phase) {
                config(['content_promotion.execution.previous_receipt' => $previous]);
                $path = $directory.'/'.$phase.'.json';
                $result = app(\App\Services\ContentPromotion\ExactPackagePromotionService::class)->execute($this->context(), $phase, $path);
                $this->assertSame('SUCCEEDED', $result['receipt']['result']);
                $this->assertSame(32, $result['receipt']['readback_count']);
                if ($phase === 'publish') {
                    $reference = $result['receipt']['rollback_reference'];
                }
                $previous = $path;
            }
            $snapshot = \App\Models\ContentReleaseSnapshot::findOrFail((int) substr($reference, strlen('content-release-snapshot:')));
            $snapshot->meta_json = json_decode(PromotionContextFactory::canonicalJson($snapshot->meta_json), true, 512, JSON_THROW_ON_ERROR);
            $snapshot->save();
            app(\App\Services\ContentPromotion\Adapters\PublicContent53PromotionAdapter::class)->rollback($this->context(), $reference);
            $restored = $states->read($this->digest());
            foreach ($before as $id => $saved) {
                $this->assertSame($saved['controls']['sections'], $restored[$id]['controls']['sections']);
            }
            \Illuminate\Support\Facades\Http::assertNothingSent();
        } finally {
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }
}
