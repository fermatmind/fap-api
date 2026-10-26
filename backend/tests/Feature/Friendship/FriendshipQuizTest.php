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
        (require database_path('migrations/2026_10_26_180000_create_friendship_progress.php'))->up();
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

    public function test_sequential_feedback_reveals_only_locked_choices_and_restores_combos(): void
    {
        $url = $this->create();
        $this->withToken($this->friend)->getJson($url.'/progress')->assertNotFound();
        $this->withToken('')->postJson($url.'/answers', ['nickname' => 'Friend', 'index' => 0, 'guess' => 0])->assertUnauthorized();
        $this->withToken($this->friend)->postJson($url.'/answers', ['nickname' => 'Friend', 'index' => 1, 'guess' => 0])->assertStatus(409);
        foreach ([0, 0, 1, 0] as $index => $guess) {
            $response = $this->postJson($url.'/answers', ['nickname' => 'Friend', 'index' => $index, 'guess' => $guess])->assertOk();
            $response->assertJsonCount($index + 1, 'answers')->assertJsonCount($index + 1, 'guesses');
        }
        $this->getJson($url.'/progress')->assertOk()->assertJsonPath('combo', 1)->assertJsonPath('best_combo', 2)->assertJsonCount(4, 'answers');
        $this->assertSame(0, DB::table('friendship_entries')->count());
        $this->assertSame(1, DB::table('friendship_progress')->count());
        $public = $this->getJson($url)->assertOk()->json();
        $this->assertArrayNotHasKey('answers', $public);
        $this->assertArrayNotHasKey('question_stats', $public);
        $this->withToken(bin2hex(random_bytes(32)))->getJson($url.'/progress')->assertNotFound();
    }

    public function test_feedback_retry_is_idempotent_and_revealed_guesses_cannot_be_changed_at_submit(): void
    {
        $url = $this->create();
        $data = ['nickname' => 'Friend', 'index' => 0, 'guess' => 1];
        $first = $this->withToken($this->friend)->postJson($url.'/answers', $data)->assertOk()->json();
        $this->assertSame($first, $this->postJson($url.'/answers', $data)->assertOk()->json());
        $this->postJson($url.'/answers', array_replace($data, ['guess' => 0]))->assertStatus(409);
        $this->postJson($url.'/answers', array_replace($data, ['nickname' => 'Changed']))->assertStatus(409);
        $this->postJson($url.'/answers', array_replace($data, ['guess' => '0']))->assertUnprocessable();
        $this->postJson($url.'/entries', ['nickname' => 'Friend', 'guesses' => array_fill(0, 30, 0)])->assertStatus(409);
        // Old bulk clients can finish unanswered questions, but must preserve the committed prefix.
        $guesses = array_fill(0, 30, 0);
        $guesses[0] = 1;
        $this->postJson($url.'/entries', ['nickname' => 'Friend', 'guesses' => $guesses])->assertOk()->assertJsonPath('correct', 29);
        $this->getJson($url.'/result')->assertOk()->assertJsonPath('correct', 29);
        $this->assertSame(1, DB::table('friendship_entries')->count());
    }

    public function test_progress_obeys_closure_and_cascades_on_expiry_or_owner_deletion(): void
    {
        $url = $this->create();
        $this->withToken($this->friend)->postJson($url.'/answers', ['nickname' => 'Friend', 'index' => 0, 'guess' => 0])->assertOk();
        $this->withToken($this->owner)->patchJson($url.'/manage', ['closed' => true])->assertOk();
        $this->withToken($this->friend)->getJson($url.'/progress')->assertStatus(410);
        $this->postJson($url.'/answers', ['nickname' => 'Friend', 'index' => 1, 'guess' => 0])->assertStatus(410);
        $this->withToken($this->owner)->patchJson($url.'/manage', ['closed' => false])->assertOk();
        $this->deleteJson($url.'/manage')->assertOk();
        $this->assertSame(0, DB::table('friendship_progress')->count());
        $url = $this->create();
        $this->withToken($this->friend)->postJson($url.'/answers', ['nickname' => 'Friend', 'index' => 0, 'guess' => 0])->assertOk();
        DB::table('friendship_challenges')->where('id', $this->payload['id'])->update(['expires_at' => now()->subMinute()]);
        $this->getJson($url.'/progress')->assertStatus(410);
        $this->artisan('friendship:prune')->assertExitCode(0);
        $this->assertSame(0, DB::table('friendship_progress')->count());
    }

    public function test_unfinished_progress_is_bounded_without_entering_the_friendboard(): void
    {
        $url = $this->create();
        $rows = [];
        for ($i = 0; $i < 1000; $i++) {
            $rows[] = ['challenge_id' => $this->payload['id'], 'player_hash' => hash('sha256', 'synthetic-'.$i), 'nickname' => 'Synthetic', 'guesses' => '[0]', 'created_at' => now()];
        }
        foreach (array_chunk($rows, 100) as $batch) {
            DB::table('friendship_progress')->insert($batch);
        }
        $this->withToken($this->friend)->postJson($url.'/answers', ['nickname' => 'Friend', 'index' => 0, 'guess' => 0])->assertStatus(409);
        $this->getJson($url)->assertOk()->assertJsonCount(0, 'entries');
        $this->assertSame(1000, DB::table('friendship_progress')->count());
    }

    public function test_real_answer_throttle_does_not_consume_the_completion_bucket(): void
    {
        $this->withMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        config(['fap.rate_limits.bypass_in_test_env' => false]);
        $url = $this->create();
        for ($i = 0; $i < 30; $i++) {
            $this->withToken($this->friend)->postJson($url.'/answers', ['nickname' => 'Friend', 'index' => $i, 'guess' => 0])->assertOk();
        }
        $this->postJson($url.'/entries', ['nickname' => 'Friend', 'guesses' => array_fill(0, 30, 0)])->assertOk();
        for ($i = 0; $i < 60; $i++) {
            $this->postJson($url.'/answers', ['nickname' => 'Friend', 'index' => 0, 'guess' => 0])->assertOk();
        }
        $this->postJson($url.'/answers', ['nickname' => 'Friend', 'index' => 0, 'guess' => 0])->assertStatus(429);
        $this->getJson($url.'/result')->assertOk()->assertJsonPath('correct', 30);
    }

    public function test_question_accuracy_is_authorized_and_excludes_hidden_and_preview_entries(): void
    {
        $url = $this->create();
        $result = $this->withToken($this->friend)->postJson($url.'/entries', ['nickname' => 'Friend', 'guesses' => array_fill(0, 30, 0)])->assertOk();
        $result->assertJsonPath('question_stats.0.correct', 1)->assertJsonPath('question_stats.0.total', 1);
        $this->withToken($this->owner)->postJson($url.'/entries', ['nickname' => 'Preview', 'guesses' => array_fill(0, 30, 0)])->assertJsonPath('question_stats.0.total', 1);
        $this->patchJson($url.'/manage', ['entry_id' => $result->json('id'), 'hidden' => true])->assertOk()->assertJsonPath('question_stats.0.total', 0);
        $this->withToken(bin2hex(random_bytes(32)))->getJson($url.'/result')->assertNotFound();
        $this->getJson($url)->assertOk()->assertJsonMissingPath('question_stats');
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

    public function test_mysql_ddl_avoids_implicit_timestamp_defaults(): void
    {
        $connection = new \Illuminate\Database\MySqlConnection(
            fn () => throw new \LogicException('DDL generation must not open a database'),
            'friendship_contract', '', ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci']
        );
        $connection->setSchemaGrammar(new \Illuminate\Database\Schema\Grammars\MySqlGrammar($connection));
        $original = \Illuminate\Support\Facades\Schema::getFacadeRoot();
        \Illuminate\Support\Facades\Schema::swap($connection->getSchemaBuilder());
        try {
            $queries = $connection->pretend(function () {
                (require database_path('migrations/2026_10_26_150000_create_friendship_challenges.php'))->up();
                (require database_path('migrations/2026_10_26_180000_create_friendship_progress.php'))->up();
            });
        } finally {
            \Illuminate\Support\Facades\Schema::swap($original);
        }
        $sql = strtolower(implode("\n", array_column($queries, 'query')));
        $this->assertStringContainsString('`expires_at` datetime not null', $sql);
        $this->assertStringContainsString('`created_at` datetime not null', $sql);
        $this->assertStringNotContainsString(' timestamp', $sql);
        $this->assertStringContainsString('create table `friendship_progress`', $sql);
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
        for ($i = 0; $i < 150; $i++) {
            $this->withToken($this->friend)->postJson($url.'/answers', ['nickname' => 'Synthetic', 'index' => $i, 'guess' => 0])
                ->assertOk()->assertJsonCount($i + 1, 'answers')->assertJsonPath('combo', $i + 1);
        }
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
