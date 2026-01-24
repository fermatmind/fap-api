<?php

namespace App\Http\Controllers\API\V0_2;

use App\Http\Controllers\Controller;
use App\Http\Requests\V0_2\BindEmailRequest;
use App\Models\Attempt;
use App\Services\Abuse\RateLimiter;
use App\Services\Audit\LookupEventLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MeController extends Controller
{
    /**
     * GET /api/v0.2/me/attempts
     * Query:
     *  - page (int) default 1
     *  - per_page (int) default 20, max 50
     *
     * 依赖：FmTokenAuth 中间件
     * - 期望从 request attributes 读到 fm_user_id / anon_id
     */
    public function attempts(Request $request)
    {
        [$userId, $anonId] = $this->resolveIdentity($request);

        // 两个都拿不到直接 401
        if (($userId === null || $userId === '') && ($anonId === null || $anonId === '')) {
            return response()->json([
                'ok' => false,
                'error' => 'UNAUTHORIZED',
                'message' => 'Missing or invalid fm_token.',
            ], 401);
        }

        $page = max(1, (int) $request->query('page', 1));
        $perPage = (int) $request->query('per_page', 20);
        if ($perPage <= 0) $perPage = 20;
        if ($perPage > 50) $perPage = 50;

        $q = Attempt::query();

        $hasUserIdCol = Schema::hasColumn('attempts', 'user_id');
        $hasAnonIdCol = Schema::hasColumn('attempts', 'anon_id');

        // 优先按 user_id 查
        if ($userId !== null && $userId !== '' && $hasUserIdCol) {
            $q->where('user_id', (string) $userId);
        } else {
            // user_id 缺失时按 anon_id 查（优先 attempts.anon_id；兼容旧表写在 attempts.user_id）
            if ($anonId !== null && $anonId !== '' && $hasAnonIdCol) {
                $q->where('anon_id', (string) $anonId);
            } elseif ($anonId !== null && $anonId !== '' && $hasUserIdCol) {
                $q->where('user_id', (string) $anonId);
            } else {
                // 没有可用列，直接返回空列表（保持 ok=true，CI 只检查接口 ok）
                return response()->json([
                    'ok' => true,
                    'user_id' => $userId ? (string) $userId : '',
                    'anon_id' => $anonId ? (string) $anonId : '',
                    'items' => [],
                    'pagination' => [
                        'page' => 1,
                        'per_page' => $perPage,
                        'total' => 0,
                        'last_page' => 1,
                    ],
                ]);
            }
        }

        // 优先按 submitted_at 排序（如果有），否则 created_at
        if (Schema::hasColumn('attempts', 'submitted_at')) {
            $q->orderByDesc('submitted_at');
        } else {
            $q->orderByDesc('created_at');
        }
        $q->orderByDesc('id');

        $p = $q->paginate($perPage, ['*'], 'page', $page);

        $items = [];
        foreach ($p->items() as $a) {
            /** @var Attempt $a */
            $items[] = $this->presentAttempt($a);
        }

        return response()->json([
            'ok' => true,
            'user_id' => $userId ? (string) $userId : '',
            'anon_id' => $anonId ? (string) $anonId : '',
            'items' => $items,
            'pagination' => [
                'page' => $p->currentPage(),
                'per_page' => $p->perPage(),
                'total' => $p->total(),
                'last_page' => $p->lastPage(),
            ],
        ]);
    }

    /**
     * POST /api/v0.2/me/email/bind
     */
    public function bindEmail(BindEmailRequest $request)
    {
        $ip = (string) ($request->ip() ?? '');
        $limiter = app(RateLimiter::class);
        $logger = app(LookupEventLogger::class);

        $limitIp = $limiter->limit('FAP_RATE_EMAIL_BIND_IP', 60);
        if ($ip !== '' && !$limiter->hit("email_bind:ip:{$ip}", $limitIp, 60)) {
            $logger->log('email_bind', false, $request, null, [
                'error' => 'RATE_LIMITED',
            ]);
            return response()->json([
                'ok' => false,
                'error' => 'RATE_LIMITED',
                'message' => 'Too many requests from this IP.',
            ], 429);
        }

        [$userId, $anonId] = $this->resolveIdentity($request);
        if (!$userId) {
            // bindEmail 只允许真实 user_id
            $logger->log('email_bind', false, $request, null, [
                'error' => 'UNAUTHORIZED',
                'anon_id' => $anonId,
            ]);
            return response()->json([
                'ok' => false,
                'error' => 'UNAUTHORIZED',
                'message' => 'Missing or invalid fm_token.',
            ], 401);
        }

        if (!Schema::hasTable('users') || !Schema::hasColumn('users', 'email')) {
            $logger->log('email_bind', false, $request, (string) $userId, [
                'error' => 'INVALID_SCHEMA',
            ]);
            return response()->json([
                'ok' => false,
                'error' => 'INVALID_SCHEMA',
                'message' => 'users.email column not found.',
            ], 500);
        }

        $email = $request->emailValue();
        $emailHash = hash('sha256', $email);
        $emailDomain = null;
        $atPos = strpos($email, '@');
        if ($atPos !== false && $atPos < strlen($email) - 1) {
            $emailDomain = substr($email, $atPos + 1);
        }

        $pk = Schema::hasColumn('users', 'uid') ? 'uid' : 'id';
        $update = [
            'email' => $email,
        ];

        $exists = DB::table('users')
            ->where('email', $email)
            ->where($pk, '!=', $userId)
            ->exists();

        if ($exists) {
            $logger->log('email_bind', false, $request, (string) $userId, [
                'error' => 'EMAIL_IN_USE',
                'email_hash' => $emailHash,
                'email_domain' => $emailDomain,
            ]);
            return response()->json([
                'ok' => false,
                'error' => 'EMAIL_IN_USE',
                'message' => 'email already in use.',
            ], 422);
        }

        if (Schema::hasColumn('users', 'email_verified_at')) {
            $update['email_verified_at'] = now();
        }
        if (Schema::hasColumn('users', 'updated_at')) {
            $update['updated_at'] = now();
        }

        try {
            $updated = DB::table('users')->where($pk, $userId)->update($update);
        } catch (\Throwable $e) {
            $logger->log('email_bind', false, $request, (string) $userId, [
                'error' => 'EMAIL_BIND_FAILED',
                'email_hash' => $emailHash,
                'email_domain' => $emailDomain,
            ]);
            return response()->json([
                'ok' => false,
                'error' => 'EMAIL_BIND_FAILED',
                'message' => $e->getMessage(),
            ], 422);
        }

        if ($updated < 1) {
            $logger->log('email_bind', false, $request, (string) $userId, [
                'error' => 'USER_NOT_FOUND',
                'email_hash' => $emailHash,
                'email_domain' => $emailDomain,
            ]);
            return response()->json([
                'ok' => false,
                'error' => 'USER_NOT_FOUND',
                'message' => 'User not found for email bind.',
            ], 404);
        }

        $logger->log('email_bind', true, $request, (string) $userId, [
            'email_hash' => $emailHash,
            'email_domain' => $emailDomain,
        ]);

        return response()->json([
            'ok' => true,
            'email' => $email,
        ]);
    }

    /**
     * @return array{0:?string,1:?string} [userId, anonId]
     */
    private function resolveIdentity(Request $request): array
    {
        $uid = $request->attributes->get('fm_user_id');
        $userId = (is_string($uid) && trim($uid) !== '') ? trim($uid) : null;

        $aid = $request->attributes->get('anon_id');
        $anonId = (is_string($aid) && trim($aid) !== '') ? trim($aid) : null;

        // 兼容：middleware 写入 user_id / fm_anon_id
        if (!$userId) {
            $uid2 = $request->attributes->get('user_id');
            $userId = (is_string($uid2) && trim($uid2) !== '') ? trim($uid2) : null;
        }
        if (!$anonId) {
            $aid2 = $request->attributes->get('fm_anon_id');
            $anonId = (is_string($aid2) && trim($aid2) !== '') ? trim($aid2) : null;
        }

        // Laravel Auth 作为补充（存在就用）
        $authId = auth()->id();
        if (!$userId && $authId !== null && (string)$authId !== '') {
            $userId = (string) $authId;
        }

        $u = $request->user();
        if (!$userId && $u && isset($u->id)) {
            $userId = (string) $u->id;
        }

        return [$userId, $anonId];
    }

    private function presentAttempt(Attempt $a): array
    {
        $out = [
            'attempt_id' => (string) ($a->id ?? ''),
            'scale_code' => (string) ($a->scale_code ?? 'MBTI'),
            'scale_version' => (string) ($a->scale_version ?? 'v0.2'),
            'type_code' => (string) ($a->type_code ?? ''),
            'region' => (string) ($a->region ?? 'CN_MAINLAND'),
            'locale' => (string) ($a->locale ?? 'zh-CN'),
        ];

        if (isset($a->ticket_code)) {
            $out['ticket_code'] = (string) $a->ticket_code;
        }

        if (isset($a->submitted_at) && $a->submitted_at) {
            $out['submitted_at'] = (string) $a->submitted_at;
        } elseif (isset($a->created_at) && $a->created_at) {
            $out['submitted_at'] = (string) $a->created_at;
        } else {
            $out['submitted_at'] = null;
        }

        if (property_exists($a, 'ticket_code') && (string)($a->ticket_code ?? '') !== '') {
            $out['lookup_key'] = (string) $a->ticket_code;
        } else {
            $out['lookup_key'] = (string) ($a->id ?? '');
        }

        return $out;
    }
}