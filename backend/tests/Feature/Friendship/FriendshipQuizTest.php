<?php

declare(strict_types=1);

namespace Tests\Feature\Friendship;

use App\Services\Friendship\FriendshipQuiz;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class FriendshipQuizTest extends TestCase
{
    private string $owner;

    private string $friend;

    private array $payload;

    private string $base = '/api/v0.3/friendship/challenges';

    protected function setUp(): void
    {
        parent::setUp();
        (require database_path('migrations/2026_10_26_150000_create_friendship_challenges.php'))->up();
        $this->owner = bin2hex(random_bytes(32));
        $this->friend = bin2hex(random_bytes(32));
        $pack = app(FriendshipQuiz::class)->catalog();
        $questions = [];
        foreach (array_keys($pack['categories']) as $cat) {
            $questions = array_merge($questions, array_slice(array_values(array_filter($pack['questions'], fn ($q) => $q['category'] === $cat)), 0, 5));
        }
        $this->payload = ['id' => (string) Str::uuid(), 'nickname' => 'Synthetic creator', 'locale' => 'en', 'version' => $pack['version'],
            'question_ids' => array_column($questions, 'id'), 'answers' => array_fill(0, 30, 0), 'contribute' => false];
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
    }

    private function create(): string
    {
        $this->withToken($this->owner)->postJson($this->base, $this->payload)->assertOk();

        return $this->base.'/'.$this->payload['id'];
    }

    public function test_catalog_has_balanced_original_bilingual_choices(): void
    {
        $pack = $this->getJson('/api/v0.3/friendship/catalog')->assertOk()->json();
        $this->assertCount(240, $pack['questions']);
        $this->assertCount(240, array_unique(array_column($pack['questions'], 'id')));
        foreach ($pack['questions'] as $q) {
            foreach (['en', 'zh'] as $locale) {
                $this->assertCount(2, $q[$locale]['options']);
                $this->assertNotSame($q[$locale]['options'][0], $q[$locale]['options'][1]);
            }
        }
        foreach (array_count_values(array_column($pack['questions'], 'category')) as $count) {
            $this->assertSame(40, $count);
        }
    }

    public function test_create_retry_permissions_and_frozen_questions(): void
    {
        $url = $this->create();
        $this->withToken($this->owner)->postJson($this->base, $this->payload)->assertOk();
        $this->assertSame(1, DB::table('friendship_challenges')->count());
        $public = $this->getJson($url)->assertOk()->json();
        $this->assertArrayNotHasKey('answers', $public);
        $this->assertArrayNotHasKey('owner_hash', $public);
        $this->assertSame($this->payload['question_ids'], array_column($public['questions'], 'id'));
        $this->withToken($this->friend)->getJson($url.'/manage')->assertNotFound();
        $this->withToken($this->friend)->deleteJson($url.'/manage')->assertNotFound();
        $this->withToken($this->friend)->patchJson($url.'/manage', ['closed' => true])->assertNotFound();
        $this->withToken('')->patchJson($url.'/manage', ['closed' => true])->assertUnauthorized();
        $this->withToken($this->owner)->getJson($url.'/manage')->assertOk()->assertJsonPath('answers.0', 0);
    }

    public function test_server_scores_zero_full_and_rounded_boundary_with_idempotent_entries(): void
    {
        $url = $this->create();
        foreach ([0, 30, 22] as $correct) {
            $key = bin2hex(random_bytes(32));
            $data = ['nickname' => 'Synthetic friend', 'guesses' => array_merge(array_fill(0, $correct, 0), array_fill(0, 30 - $correct, 1))];
            $res = $this->withToken($key)->postJson($url.'/entries', $data)->assertOk()->assertJsonPath('correct', $correct)
                ->assertJsonPath('percent', (int) round($correct / 30 * 100))->assertJsonPath('preview', false);
            $this->withToken($key)->postJson($url.'/entries', $data)->assertOk()->assertJsonPath('id', $res->json('id'));
            $this->withToken($key)->getJson($url.'/result')->assertOk()->assertJsonPath('correct', $correct);
            $data['nickname'] = 'Changed';
            $this->withToken($key)->postJson($url.'/entries', $data)->assertStatus(409);
        }
        $this->assertSame(3, DB::table('friendship_entries')->count());
        $this->withToken($this->owner)->postJson($url.'/entries', ['nickname' => 'Preview', 'guesses' => array_fill(0, 30, 0)])
            ->assertOk()->assertJsonPath('preview', true);
        $this->assertSame(3, DB::table('friendship_entries')->count());
    }

    public function test_missing_answers_invalid_choices_duplicates_and_versions_are_rejected(): void
    {
        $url = $this->create();
        $this->withToken($this->friend)->postJson($url.'/entries', ['nickname' => 'Friend', 'guesses' => [0]])->assertUnprocessable();
        $data = $this->payload;
        $data['id'] = (string) Str::uuid();
        $data['question_ids'][1] = $data['question_ids'][0];
        $this->withToken($this->owner)->postJson($this->base, $data)->assertUnprocessable();
        $data = $this->payload;
        $data['version'] = 'old';
        $this->postJson($this->base, $data)->assertStatus(409);
        $data = $this->payload;
        $data['answers'][0] = '0';
        $this->postJson($this->base, $data)->assertUnprocessable();
        $this->postJson($url.'/entries', ['nickname' => '<script>', 'guesses' => array_fill(0, 30, 0)])->assertUnprocessable();
    }

    public function test_hidden_entries_closure_expiry_delete_and_pruning(): void
    {
        $url = $this->create();
        $entry = $this->withToken($this->friend)->postJson($url.'/entries', ['nickname' => 'Friend', 'guesses' => array_fill(0, 30, 0)])->assertOk()->json('id');
        $this->withToken($this->owner)->patchJson($url.'/manage', ['entry_id' => $entry, 'hidden' => true])->assertOk();
        $this->getJson($url)->assertJsonCount(0, 'entries');
        $this->patchJson($url.'/manage', ['closed' => true])->assertOk();
        $this->getJson($url)->assertStatus(410);
        $this->getJson($url.'/manage')->assertOk();
        $this->patchJson($url.'/manage', ['closed' => false])->assertOk();
        $this->deleteJson($url.'/manage')->assertOk();
        $this->assertSame(0, DB::table('friendship_entries')->count());
        $this->getJson($url)->assertNotFound();
        $url = $this->create();
        $this->travel(91)->days();
        $this->getJson($url)->assertStatus(410);
        $this->artisan('friendship:prune')->assertSuccessful();
        $this->assertSame(0, DB::table('friendship_challenges')->count());
    }

    public function test_maximum_length_and_real_opt_in_counts(): void
    {
        $pack = app(FriendshipQuiz::class)->catalog();
        $ids = [];
        foreach (array_keys($pack['categories']) as $cat) {
            $ids = array_merge($ids, array_slice(array_column(array_values(array_filter($pack['questions'], fn ($q) => $q['category'] === $cat)), 'id'), 0, 25));
        }
        $this->payload['question_ids'] = $ids;
        $this->payload['answers'] = array_fill(0, 150, 0);
        $url = $this->create();
        $this->withToken($this->friend)->postJson($url.'/entries', ['nickname' => 'Synthetic', 'guesses' => array_fill(0, 150, 0)])
            ->assertOk()->assertJsonPath('correct', 150)->assertJsonPath('percent', 100);
        // Fixed synthetic rows exercise the threshold, without pretending to be real users.
        $row = (array) DB::table('friendship_challenges')->first();
        $row['contribute'] = true;
        for ($i = 0; $i < 20; $i++) {
            $row['id'] = (string) Str::uuid();
            DB::table('friendship_challenges')->insert($row);
        }
        $this->getJson('/api/v0.3/friendship/stats')->assertOk()->assertJsonPath('questions.food-01', [20, 0]);
        DB::table('friendship_challenges')->where('contribute', true)->limit(1)->update(['closed' => true]);
        $this->getJson('/api/v0.3/friendship/stats')->assertJsonPath('questions', []);
    }

    public function test_stats_suppresses_insufficient_and_nonconsenting_samples(): void
    {
        $this->create();
        $this->getJson('/api/v0.3/friendship/stats')->assertOk()->assertJsonPath('questions', []);
    }
}
