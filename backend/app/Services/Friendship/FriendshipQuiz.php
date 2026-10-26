<?php

declare(strict_types=1);

namespace App\Services\Friendship;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class FriendshipQuiz
{
    public function catalog(): array
    {
        return json_decode(file_get_contents(base_path('content_packs/friendship/v1/questions.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    public function create(array $data, string $key): array
    {
        $pack = $this->catalog();
        abort_unless($data['version'] === $pack['version'], 409, 'QUESTION_VERSION_CHANGED');
        $bank = array_column($pack['questions'], null, 'id');
        $questions = [];
        foreach ($data['question_ids'] as $id) {
            abort_unless(isset($bank[$id]), 422, 'UNKNOWN_QUESTION');
            $questions[] = $bank[$id];
        }
        $counts = array_count_values(array_column($questions, 'category'));
        $size = count($questions);
        abort_unless(in_array($size, [30, 60, 90, 120, 150], true) && count($data['answers']) === $size, 422, 'INCOMPLETE_ANSWERS');
        foreach (array_keys($pack['categories']) as $category) {
            abort_unless(($counts[$category] ?? 0) === $size / 6, 422, 'UNBALANCED_CATEGORIES');
        }
        $payloadHash = hash('sha256', json_encode($data));

        return DB::transaction(function () use ($data, $key, $questions, $pack, $payloadHash) {
            // insertOrIgnore makes creation retries safe even when requests race.
            DB::table('friendship_challenges')->insertOrIgnore([
                'id' => $data['id'], 'owner_hash' => hash('sha256', $key),
                'nickname' => $data['nickname'], 'locale' => $data['locale'], 'version' => $pack['version'],
                'questions' => json_encode($questions), 'answers' => json_encode($data['answers']),
                'payload_hash' => $payloadHash, 'contribute' => $data['contribute'] ?? false,
                'created_at' => now(), 'expires_at' => now()->addDays(90),
            ]);
            $challenge = $this->challenge($data['id'], true);
            $this->owner($challenge, $key);
            abort_unless(hash_equals($challenge->payload_hash, $payloadHash), 409, 'CREATE_CONFLICT');

            return $this->projection($challenge, true);
        });
    }

    public function challenge(string $id, bool $lock = false): object
    {
        $query = DB::table('friendship_challenges')->where('id', $id);
        $row = ($lock ? $query->lockForUpdate() : $query)->first();
        abort_unless($row, 404, 'QUIZ_NOT_FOUND');
        abort_if(now()->greaterThanOrEqualTo($row->expires_at), 410, 'QUIZ_EXPIRED');

        return $row;
    }

    public function owner(object $challenge, string $key): void
    {
        abort_unless($key !== '' && hash_equals($challenge->owner_hash, hash('sha256', $key)), 404, 'QUIZ_NOT_FOUND');
    }

    public function projection(object $challenge, bool $owner = false): array
    {
        abort_if($challenge->closed && ! $owner, 410, 'QUIZ_CLOSED');
        $result = [
            'id' => $challenge->id, 'nickname' => $challenge->nickname, 'locale' => $challenge->locale,
            'version' => $challenge->version, 'questions' => json_decode($challenge->questions, true),
            'closed' => (bool) $challenge->closed, 'expires_at' => $challenge->expires_at,
        ];
        if ($owner) {
            $result['answers'] = json_decode($challenge->answers, true);
            $result['entries'] = $this->entries($challenge->id, true);
        } else {
            $result['entries'] = $this->entries($challenge->id);
        }

        return $result;
    }

    public function entries(string $id, bool $owner = false): array
    {
        $query = DB::table('friendship_entries')->where('challenge_id', $id);
        if (! $owner) {
            $query->where('hidden', false);
        }

        return $query->orderByDesc('correct')->orderBy('created_at')->orderBy('id')
            ->limit(1000)->get(['id', 'nickname', 'correct', 'categories', 'hidden'])->map(function ($entry) {
                $entry->categories = json_decode($entry->categories, true);
                $entry->hidden = (bool) $entry->hidden;

                return (array) $entry;
            })->all();
    }

    public function submit(object $challenge, array $data, string $key): array
    {
        abort_if($challenge->closed, 410, 'QUIZ_CLOSED');
        $answers = json_decode($challenge->answers, true);
        $questions = json_decode($challenge->questions, true);
        abort_unless(count($data['guesses']) === count($answers), 422, 'INCOMPLETE_ANSWERS');
        $categories = [];
        $correct = 0;
        foreach ($questions as $i => $question) {
            $cat = $question['category'];
            $categories[$cat] ??= ['correct' => 0, 'total' => 0];
            $categories[$cat]['total']++;
            if ($data['guesses'][$i] === $answers[$i]) {
                $correct++;
                $categories[$cat]['correct']++;
            }
        }
        $result = ['nickname' => $data['nickname'], 'correct' => $correct, 'total' => count($answers),
            'percent' => (int) round(100 * $correct / count($answers)), 'categories' => $categories,
            'answers' => $answers, 'guesses' => $data['guesses']];
        if (hash_equals($challenge->owner_hash, hash('sha256', $key))) {
            return $result + ['preview' => true, 'entries' => $this->entries($challenge->id)];
        }
        $hash = hash('sha256', $key);

        return DB::transaction(function () use ($challenge, $data, $hash, $result) {
            // Locking the challenge serializes capacity, closure and entry idempotency.
            $locked = $this->challenge($challenge->id, true);
            abort_if($locked->closed, 410, 'QUIZ_CLOSED');
            $entry = DB::table('friendship_entries')->where('challenge_id', $challenge->id)->where('player_hash', $hash)->first();
            if ($entry) {
                abort_unless(json_decode($entry->guesses, true) === $data['guesses'] && $entry->nickname === $data['nickname'], 409, 'ALREADY_PLAYED');

                return $result + ['id' => $entry->id, 'preview' => false, 'entries' => $this->entries($challenge->id)];
            }
            abort_if(DB::table('friendship_entries')->where('challenge_id', $challenge->id)->count() >= 1000, 409, 'QUIZ_FULL');
            $id = (string) Str::uuid();
            DB::table('friendship_entries')->insert([
                'id' => $id, 'challenge_id' => $challenge->id, 'player_hash' => $hash,
                'nickname' => $data['nickname'], 'guesses' => json_encode($data['guesses']), 'correct' => $result['correct'],
                'categories' => json_encode($result['categories']), 'created_at' => now(),
            ]);

            return $result + ['id' => $id, 'preview' => false, 'entries' => $this->entries($challenge->id)];
        });
    }

    public function result(object $challenge, string $key): array
    {
        abort_if($challenge->closed, 410, 'QUIZ_CLOSED');
        $entry = DB::table('friendship_entries')->where('challenge_id', $challenge->id)->where('player_hash', hash('sha256', $key))->first();
        abort_unless($entry, 404, 'RESULT_NOT_FOUND');

        return $this->submit($challenge, ['nickname' => $entry->nickname, 'guesses' => json_decode($entry->guesses, true)], $key);
    }

    public function stats(): array
    {
        // Opt-in creator choices only; never friend guesses. Bounded sample, no population claims.
        $rows = DB::table('friendship_challenges')->where('contribute', true)->where('closed', false)
            ->where('expires_at', '>', now())->orderByDesc('created_at')->limit(200)->get(['questions', 'answers']);
        $counts = [];
        foreach ($rows as $row) {
            foreach (json_decode($row->questions, true) as $i => $q) {
                $counts[$q['id']] ??= [0, 0];
                $counts[$q['id']][json_decode($row->answers, true)[$i]]++;
            }
        }

        return ['minimum' => 20, 'scope' => 'latest_200_opt_in_active_quizzes',
            'questions' => array_filter($counts, fn ($count) => array_sum($count) >= 20)];
    }
}
