<?php

declare(strict_types=1);

namespace Tests\Feature\V0_5;

use App\Models\PersonalityResultIntroduction;
use App\Services\Cms\MbtiResultIntroductionPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PersonalityResultIntroductionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $publisher = app(MbtiResultIntroductionPublisher::class);
        $publisher->publish($publisher->package()['package_hash'], true);
    }

    public function test_publisher_is_idempotent_and_rejects_package_drift(): void
    {
        $publisher = app(MbtiResultIntroductionPublisher::class);
        $hash = $publisher->package()['package_hash'];
        $this->assertSame(0, $publisher->publish($hash, true)['created']);
        $this->artisan('personality:publish-result-introductions', ['--expected-hash' => $hash])->assertSuccessful();
        $this->artisan('personality:publish-result-introductions', ['--expected-hash' => str_repeat('0', 64), '--write' => true])->assertFailed();
        $this->assertSame(64, PersonalityResultIntroduction::query()->count());
    }

    public function test_conflict_rejects_the_whole_batch_without_filling_missing_rows(): void
    {
        $publisher = app(MbtiResultIntroductionPublisher::class);
        PersonalityResultIntroduction::query()->where('locale', 'en')->delete();
        PersonalityResultIntroduction::query()->where('full_code', 'INTP-A')->update(['status' => 'draft']);
        $this->artisan('personality:publish-result-introductions', ['--expected-hash' => $publisher->package()['package_hash'], '--write' => true])->assertFailed();
        $this->assertSame(0, PersonalityResultIntroduction::query()->where('locale', 'en')->count());
        $this->assertSame('draft', PersonalityResultIntroduction::query()->where('full_code', 'INTP-A')->first()->status);
    }

    public function test_all_64_published_assets_round_trip_with_exact_type_and_language(): void
    {
        $paragraphsByLocale = [];
        $this->assertSame(64, PersonalityResultIntroduction::query()->count());
        foreach (['zh-CN', 'en'] as $locale) {
            $document = json_decode(file_get_contents(base_path('content_assets/personality_public/mbti_result_introductions.'.$locale.'.v1.json')), true, flags: JSON_THROW_ON_ERROR);
            $expectedCodes = [];
            foreach (['E', 'I'] as $ei) {
                foreach (['S', 'N'] as $sn) {
                    foreach (['T', 'F'] as $tf) {
                        foreach (['J', 'P'] as $jp) {
                            foreach (['A', 'T'] as $at) {
                                $expectedCodes[] = $ei.$sn.$tf.$jp.'-'.$at;
                            }
                        }
                    }
                }
            }
            $this->assertEqualsCanonicalizing($expectedCodes, array_column($document['introductions'], 'full_code'));
            foreach ($document['introductions'] as $asset) {
                $code = $asset['full_code'];
                $response = $this->getJson('/api/v0.5/personality/'.strtolower($code).'/result-intro?locale='.$locale);
                $response->assertOk()->assertJsonPath('ok', true)
                    ->assertJsonPath('full_code', $code)->assertJsonPath('locale', $locale)
                    ->assertJsonPath('schema', 'mbti_result_introduction.v1')
                    ->assertJsonPath('paragraphs', $asset['paragraphs'])->assertJsonPath('revision', 1);
                $this->assertCount(2, $response->json('paragraphs'));
                $this->assertSame(hash('sha256', json_encode($asset['paragraphs'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), $response->json('content_hash'));
                foreach ($asset['paragraphs'] as $paragraph) {
                    $this->assertGreaterThan(100, mb_strlen($paragraph), $code.' '.$locale);
                    $this->assertStringNotContainsString('「1」', $paragraph);
                    $this->assertStringNotContainsString('这份结果把', $paragraph);
                    $this->assertStringNotContainsString('stable behavioral pattern', $paragraph);
                    $paragraphsByLocale[$locale][] = $paragraph;
                }
            }
            $this->assertCount(64, array_unique($paragraphsByLocale[$locale]));
        }
    }

    public function test_draft_future_and_missing_content_never_fall_back_to_another_type_or_locale(): void
    {
        $path = '/api/v0.5/personality/intp-a/result-intro?locale=en';
        $record = PersonalityResultIntroduction::publishedFor('INTP-A', 'en');
        $record->update(['status' => 'draft']);
        $this->getJson($path)->assertNotFound();
        $record->update(['status' => 'published', 'published_at' => now()->addDay()]);
        $this->getJson($path)->assertNotFound();
        $record->delete();
        $this->getJson($path)->assertNotFound();
        $this->getJson('/api/v0.5/personality/intp-t/result-intro?locale=en')->assertOk();
        $this->getJson('/api/v0.5/personality/intp-a/result-intro?locale=zh-CN')->assertOk();
    }

    public function test_invalid_identity_locale_and_tenant_are_not_served(): void
    {
        $this->getJson('/api/v0.5/personality/intp/result-intro?locale=en')->assertNotFound();
        $this->getJson('/api/v0.5/personality/xxxx-a/result-intro?locale=en')->assertNotFound();
        $this->getJson('/api/v0.5/personality/intp-a/result-intro?locale=fr')->assertStatus(422);
        $this->getJson('/api/v0.5/personality/intp-a/result-intro?locale=en&org_id=9')->assertNotFound();
    }
}
