<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Friendship\FriendshipBrowserGrants;
use App\Services\Friendship\FriendshipQuiz;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class FriendshipOwnerAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = app(FriendshipBrowserGrants::class)->key($request, (string) $request->route('id'), 'owner');
        $quiz = app(FriendshipQuiz::class);
        $quiz->owner($quiz->challenge((string) $request->route('id')), $key);

        return $next($request);
    }
}
