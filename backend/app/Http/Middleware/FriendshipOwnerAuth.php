<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Friendship\FriendshipQuiz;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class FriendshipOwnerAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = (string) $request->bearerToken();
        abort_unless(preg_match('/^[a-f0-9]{64}$/D', $key) === 1, 401, 'KEY_REQUIRED');
        $quiz = app(FriendshipQuiz::class);
        $quiz->owner($quiz->challenge((string) $request->route('id')), $key);

        return $next($request);
    }
}
