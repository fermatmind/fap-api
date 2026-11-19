<?php

declare(strict_types=1);

namespace Tests\Feature\Assessment;

use App\Models\User;
use App\Services\Account\AccountAssetLinker;
use App\Services\Assessment\Jung\JungBank;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class JungAssessmentFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\JungAssessmentIndexabilitySeeder::class);
    }

    public function test_indexability_publication_is_idempotent_and_rolls_back_on_identity_drift(): void
    {
        $this->seed(\Database\Seeders\JungAssessmentIndexabilitySeeder::class);
        foreach (['scales_registry', 'scales_registry_v2'] as $table) {
            \Illuminate\Support\Facades\DB::table($table)->where('org_id', 0)
                ->whereIn('code', [JungBank::ARCHETYPES, JungBank::FUNCTIONS])
                ->update(['is_indexable' => false]);
        }
        \Illuminate\Support\Facades\DB::table('scales_registry_v2')
            ->where('org_id', 0)->where('code', JungBank::FUNCTIONS)
            ->update(['primary_slug' => 'unexpected-identity']);

        try {
            $this->seed(\Database\Seeders\JungAssessmentIndexabilitySeeder::class);
            self::fail('Identity drift must reject the complete publication.');
        } catch (\RuntimeException $error) {
            self::assertSame('Expected published Jung assessment identity is unavailable.', $error->getMessage());
        }

        foreach (['scales_registry', 'scales_registry_v2'] as $table) {
            self::assertSame(0, \Illuminate\Support\Facades\DB::table($table)
                ->where('org_id', 0)->whereIn('code', [JungBank::ARCHETYPES, JungBank::FUNCTIONS])
                ->where('is_indexable', true)->count());
        }
    }

    public static function scales(): iterable
    {
        yield [JungBank::ARCHETYPES, 'zh-CN', 48, 'hero'];
        yield [JungBank::FUNCTIONS, 'en', 64, 'Ni'];
    }

    #[DataProvider('scales')]
    public function test_public_questions_and_owned_free_report(string $scale, string $locale, int $count, string $dimension): void
    {
        config(['fap.features.report_snapshot_strict_v2' => true]);
        $slug = $scale === JungBank::ARCHETYPES ? 'jung-archetypes' : 'jung-cognitive-functions';
        $this->getJson("/api/v0.3/scales/lookup?slug={$slug}&locale={$locale}")->assertOk()
            ->assertJsonPath('forms.0.question_count', $count)
            ->assertJsonPath('is_indexable', true);
        $catalog = $this->getJson("/api/v0.3/scales/catalog?locale={$locale}")
            ->assertOk()->json('items');
        $item = collect($catalog)->firstWhere('slug', $slug);
        self::assertIsArray($item);
        self::assertSame($count, $item['questions_count']);
        self::assertSame($count === 48 ? 10 : 13, $item['time_minutes']);
        self::assertTrue($item['is_indexable']);
        $questions = $this->getJson("/api/v0.3/scales/{$scale}/questions?locale={$locale}")
            ->assertOk()->assertJsonCount($count, 'questions.items');
        $items = $questions->json('questions.items');
        self::assertSame(['question_id', 'text', 'options'], array_keys($items[0]));
        self::assertCount(5, $items[0]['options']);
        $anon = 'jung-test-owner';
        $token = $this->postJson('/api/v0.3/auth/guest', ['anon_id' => $anon])->assertOk()->json('fm_token');
        $owner = ['X-Anon-Id' => $anon, 'Authorization' => 'Bearer '.$token];
        $start = $this->postJson('/api/v0.3/attempts/start', [
            'scale_code' => $scale,
            'locale' => $locale,
            'form_code' => $questions->json('form_code'),
        ], $owner)->assertOk()->assertJsonPath('ok', true);
        $attemptId = $start->json('attempt_id');
        $answers = array_map(fn (array $item): array => ['question_id' => $item['question_id'], 'code' => '3'], $items);

        $this->postJson('/api/v0.3/attempts/submit', [
            'attempt_id' => $attemptId,
            'duration_ms' => 120000,
            'answers' => $answers,
        ], $owner)->assertOk()->assertJsonPath('ok', true);

        $this->getJson("/api/v0.3/attempts/{$attemptId}/report-access", $owner)
            ->assertOk()
            ->assertJsonPath('access_state', 'ready')
            ->assertJsonPath('report_state', 'ready');
        $this->getJson("/api/v0.3/attempts/{$attemptId}/report", $owner)
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('locked', false)
            ->assertJsonPath('offers', [])
            ->assertJsonPath("report.jung.scores.{$dimension}", 50);
        $this->getJson("/api/v0.3/attempts/{$attemptId}/report", ['X-Anon-Id' => 'jung-other-browser'])
            ->assertNotFound();
        $this->assertDatabaseHas('attempt_drafts', ['attempt_id' => $attemptId, 'answers_json' => null, 'answered_count' => 0]);
        $user = User::factory()->create();
        $linked = app(AccountAssetLinker::class)->linkAttempts((string) $user->getKey(), [[
            'attempt_id' => $attemptId,
            'resume_token' => $start->json('resume_token'),
        ]]);
        self::assertSame([$attemptId], $linked['linked_attempt_ids']);
        $this->assertDatabaseHas('attempts', ['id' => $attemptId, 'user_id' => $user->getKey()]);
    }
}
