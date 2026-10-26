<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_3;

use App\Http\Controllers\Controller;
use App\Services\Friendship\FriendshipQuiz;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class FriendshipController extends Controller
{
    public function __construct(private readonly FriendshipQuiz $quiz) {}

    private function response(array $data): JsonResponse
    {
        return response()->json($data)->withHeaders(['Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex, nofollow', 'Referrer-Policy' => 'no-referrer']);
    }

    private function key(Request $request): string
    {
        $key = (string) $request->bearerToken();
        abort_unless(preg_match('/^[a-f0-9]{64}$/D', $key) === 1, 401, 'KEY_REQUIRED');

        return $key;
    }

    private function nameRules(): array
    {
        return ['required', 'string', 'min:1', 'max:32', 'regex:/^[^<>\p{C}]+$/u'];
    }

    private function choiceRules(): array
    {
        return ['required', function ($attribute, $value, $fail) {
            if (! is_int($value) || ! in_array($value, [0, 1], true)) {
                $fail('Each choice must be 0 or 1.');
            }
        }];
    }

    public function catalog(): JsonResponse
    {
        return $this->response($this->quiz->catalog());
    }

    public function stats(): JsonResponse
    {
        return $this->response($this->quiz->stats());
    }

    public function create(Request $request): JsonResponse
    {
        $key = $this->key($request);
        $data = $request->validate([
            'id' => ['required', 'uuid'], 'nickname' => $this->nameRules(), 'locale' => ['required', Rule::in(['en', 'zh'])],
            'version' => ['required', 'string', 'max:40'], 'question_ids' => ['required', 'array', 'min:30', 'max:150', 'list'],
            'question_ids.*' => ['required', 'string', 'distinct', 'max:40'], 'answers' => ['required', 'array', 'min:30', 'max:150', 'list'],
            'answers.*' => $this->choiceRules(), 'contribute' => ['sometimes', 'boolean'],
        ]);

        return $this->response($this->quiz->create($data, $key));
    }

    public function show(string $id): JsonResponse
    {
        return $this->response($this->quiz->projection($this->quiz->challenge($id)));
    }

    public function submit(Request $request, string $id): JsonResponse
    {
        $key = $this->key($request);
        $data = $request->validate(['nickname' => $this->nameRules(), 'guesses' => ['required', 'array', 'min:30', 'max:150', 'list'], 'guesses.*' => $this->choiceRules()]);

        return $this->response($this->quiz->submit($this->quiz->challenge($id), $data, $key));
    }

    public function result(Request $request, string $id): JsonResponse
    {
        return $this->response($this->quiz->result($this->quiz->challenge($id), $this->key($request)));
    }

    public function progress(Request $request, string $id): JsonResponse
    {
        return $this->response($this->quiz->progress($this->quiz->challenge($id), $this->key($request)));
    }

    public function answer(Request $request, string $id): JsonResponse
    {
        $key = $this->key($request);
        $data = $request->validate(['nickname' => $this->nameRules(), 'index' => ['required', 'integer', 'min:0', 'max:149'], 'guess' => $this->choiceRules()]);

        return $this->response($this->quiz->answer($id, $data, $key));
    }

    public function manage(Request $request, string $id): JsonResponse
    {
        $challenge = $this->quiz->challenge($id);
        $this->quiz->owner($challenge, $this->key($request));

        return $this->response($this->quiz->projection($challenge, true));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $key = $this->key($request);
        $data = $request->validate(['closed' => ['sometimes', 'boolean'], 'entry_id' => ['sometimes', 'uuid'], 'hidden' => ['required_with:entry_id', 'boolean']]);

        return DB::transaction(function () use ($id, $key, $data) {
            $challenge = $this->quiz->challenge($id, true);
            $this->quiz->owner($challenge, $key);
            if (array_key_exists('closed', $data)) {
                DB::table('friendship_challenges')->where('id', $id)->update(['closed' => $data['closed']]);
            }
            if (isset($data['entry_id'])) {
                DB::table('friendship_entries')->where('challenge_id', $id)->where('id', $data['entry_id'])->update(['hidden' => $data['hidden']]);
            }

            return $this->response($this->quiz->projection($this->quiz->challenge($id), true));
        });
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $key = $this->key($request);

        return DB::transaction(function () use ($id, $key) {
            $this->quiz->owner($this->quiz->challenge($id, true), $key);
            DB::table('friendship_entries')->where('challenge_id', $id)->delete();
            DB::table('friendship_challenges')->where('id', $id)->delete();

            return $this->response(['deleted' => true]);
        });
    }
}
