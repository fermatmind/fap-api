<?php

namespace App\Http\Controllers\API\V0_2;

use App\Http\Controllers\Controller;
use App\Http\Requests\V0_2\BindEmailRequest;
use App\Models\Attempt;
use App\Services\Abuse\RateLimiter;
use App\Services\Audit\LookupEventLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
     * Depends on FmTokenAuth middleware:
     * - reads fm_user_id (numeric) and/or anon_id from request attributes
     *
     * Behavior:
     * - prefer user_id; fall back to anon_id when user_id is missing
     */
    public function attempts(Request $request)
    {
        $userId = $this->resolveUserId($request);   // numeric string or null
        $anonId = $this->resolveAnonId($request);   // string or null

        if ($userId === null && $anonId === null) {
            return response()->json([
                'ok' => false,
                'error' => 'unauthorized',
                'message' => 'Missing or invalid fm_token.',
            ], 401);
        }

        $page = max(1, (int) $request->query('page', 1));
        $perPage = (int) $request->query('per_page', 20);
        if ($perPage <= 0) $perPage = 20;
        if ($perPage > 50) $perPage = 50;

        $q = Attempt::query();

        if ($userId !== null) {
            $q->where('user_id', $userId);
        } else {
            // user_id 缺失时走 anon_id
            if (Schema::hasColumn('attempts', 'anon_id')) {
                $q->where('anon_id', $anonId);
            } else {
                // schema 不支持就直接 401（或返回空）
                return response()->json([
                    'ok' => false,
                    'error' => 'INVALID_SCHEMA',
                    'message' => 'attempts.anon_id column not found.',
                ], 500);
            }
        }

        // order by submitted_at when exists, else created_at
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
            'user_id' => $userId ?? '',
            'anon_id' => $anonId ?? '',
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

        $userId = $this->resolveUserId($request);
        if ($userId === null) {
            $logger->log('email_bind', false, $request, null, [
                'error' => 'UNAUTHORIZED',
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
     * GET /api/v0.2/me/data/sleep
     * Returns last 30 days aggregated sleep samples by day.
     */
    public function sleepData(Request $request)
    {
        $identity = $this->resolveIdentity($request);
        if (!$identity['ok']) {
            return response()->json($identity['error'], 401);
        }

        if (!Schema::hasTable('sleep_samples')) {
            return response()->json([
                'ok' => false,
                'error' => 'INVALID_SCHEMA',
                'message' => 'sleep_samples table not found.',
            ], 500);
        }

        $userId = $identity['user_id'];
        if ($userId === null) {
            return response()->json([
                'ok' => true,
                'items' => [],
                'note' => 'anon_id present but no user_id bound to samples.',
            ]);
        }

        $rows = DB::table('sleep_samples')
            ->where('user_id', (int) $userId)
            ->where('recorded_at', '>=', now()->subDays(30))
            ->orderByDesc('recorded_at')
            ->get();

        $items = $this->aggregateByDay($rows, function ($row) {
            $value = $this->decodeValueJson($row->value_json ?? null);
            $minutes = $this->extractNumeric($value, ['duration_minutes', 'duration_min', 'duration', 'total_minutes']);
            return $minutes !== null ? $minutes : 0.0;
        }, 'total_minutes');

        return response()->json([
            'ok' => true,
            'items' => $items,
        ]);
    }

    /**
     * GET /api/v0.2/me/data/mood
     * Uses health_samples domain=mood or returns empty array.
     */
    public function moodData(Request $request)
    {
        $identity = $this->resolveIdentity($request);
        if (!$identity['ok']) {
            return response()->json($identity['error'], 401);
        }

        if (!Schema::hasTable('health_samples')) {
            return response()->json([
                'ok' => true,
                'items' => [],
                'note' => 'health_samples table not found; mood data empty.',
            ]);
        }

        $userId = $identity['user_id'];
        if ($userId === null) {
            return response()->json([
                'ok' => true,
                'items' => [],
                'note' => 'anon_id present but no user_id bound to samples.',
            ]);
        }

        $rows = DB::table('health_samples')
            ->where('user_id', (int) $userId)
            ->where('domain', 'mood')
            ->where('recorded_at', '>=', now()->subDays(30))
            ->orderByDesc('recorded_at')
            ->get();

        $items = $this->aggregateByDay($rows, function ($row) {
            $value = $this->decodeValueJson($row->value_json ?? null);
            $score = $this->extractNumeric($value, ['score', 'value', 'mood']);
            return $score !== null ? $score : 0.0;
        }, 'avg_score', true);

        return response()->json([
            'ok' => true,
            'items' => $items,
        ]);
    }

    /**
     * GET /api/v0.2/me/data/screen-time
     */
    public function screenTimeData(Request $request)
    {
        $identity = $this->resolveIdentity($request);
        if (!$identity['ok']) {
            return response()->json($identity['error'], 401);
        }

        if (!Schema::hasTable('screen_time_samples')) {
            return response()->json([
                'ok' => false,
                'error' => 'INVALID_SCHEMA',
                'message' => 'screen_time_samples table not found.',
            ], 500);
        }

        $userId = $identity['user_id'];
        if ($userId === null) {
            return response()->json([
                'ok' => true,
                'items' => [],
                'note' => 'anon_id present but no user_id bound to samples.',
            ]);
        }

        $rows = DB::table('screen_time_samples')
            ->where('user_id', (int) $userId)
            ->where('recorded_at', '>=', now()->subDays(30))
            ->orderByDesc('recorded_at')
            ->get();

        $items = $this->aggregateByDay($rows, function ($row) {
            $value = $this->decodeValueJson($row->value_json ?? null);
            $minutes = $this->extractNumeric($value, ['total_screen_minutes', 'screen_minutes', 'minutes']);
            return $minutes !== null ? $minutes : 0.0;
        }, 'total_minutes');

        return response()->json([
            'ok' => true,
            'items' => $items,
        ]);
    }

    private function resolveUserId(Request $request): ?string
    {
        // 1) middleware attributes
        $uid = $request->attributes->get('fm_user_id');
        if (is_string($uid)) {
            $uid = trim($uid);
            if ($uid !== '' && preg_match('/^\d+$/', $uid)) return $uid;
        }

        $uid2 = $request->attributes->get('user_id');
        if (is_string($uid2)) {
            $uid2 = trim($uid2);
            if ($uid2 !== '' && preg_match('/^\d+$/', $uid2)) return $uid2;
        }

        // 2) Laravel Auth (safe fallback)
        $authId = Auth::id();
        if ($authId !== null) {
            $s = trim((string) $authId);
            if ($s !== '' && preg_match('/^\d+$/', $s)) return $s;
        }

        // 3) request->user()
        $u = $request->user();
        if ($u && isset($u->id)) {
            $s = trim((string) $u->id);
            if ($s !== '' && preg_match('/^\d+$/', $s)) return $s;
        }

        return null;
    }

    private function resolveAnonId(Request $request): ?string
    {
        $anon = $request->attributes->get('anon_id');
        if (is_string($anon)) {
            $anon = trim($anon);
            if ($anon !== '') return $anon;
        }

        $anon2 = $request->attributes->get('fm_anon_id');
        if (is_string($anon2)) {
            $anon2 = trim($anon2);
            if ($anon2 !== '') return $anon2;
        }

        // 再兜底：部分客户端会显式传 anon_id
        $h = trim((string) $request->header('X-Anon-Id', ''));
        if ($h !== '') return $h;

        $q = trim((string) $request->query('anon_id', ''));
        if ($q !== '') return $q;

        return null;
    }

    private function resolveIdentity(Request $request): array
    {
        $userId = $this->resolveUserId($request);
        $anonId = $this->resolveAnonId($request);

        if ($userId === null && $anonId === null) {
            return [
                'ok' => false,
                'error' => [
                    'ok' => false,
                    'error' => 'unauthorized',
                    'message' => 'Missing or invalid fm_token.',
                ],
            ];
        }

        return [
            'ok' => true,
            'user_id' => $userId,
            'anon_id' => $anonId,
        ];
    }

    private function aggregateByDay($rows, callable $valueExtractor, string $metricKey, bool $average = false): array
    {
        $bucket = [];
        foreach ($rows as $row) {
            $day = substr((string) ($row->recorded_at ?? ''), 0, 10);
            if ($day === '') {
                continue;
            }
            if (!isset($bucket[$day])) {
                $bucket[$day] = [
                    'date' => $day,
                    'count' => 0,
                    $metricKey => 0.0,
                ];
            }
            $bucket[$day]['count']++;
            $bucket[$day][$metricKey] += (float) $valueExtractor($row);
        }

        $items = array_values($bucket);
        usort($items, function ($a, $b) {
            return strcmp((string) $b['date'], (string) $a['date']);
        });

        if ($average) {
            foreach ($items as &$item) {
                if ($item['count'] > 0) {
                    $item[$metricKey] = $item[$metricKey] / $item['count'];
                }
            }
        }

        return $items;
    }

    private function decodeValueJson($value): array
    {
        if ($value === null) {
            return [];
        }
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function extractNumeric(array $value, array $keys): ?float
    {
        foreach ($keys as $k) {
            if (array_key_exists($k, $value)) {
                $v = $value[$k];
                if (is_numeric($v)) {
                    return (float) $v;
                }
            }
        }
        return null;
    }

    private function presentAttempt(Attempt $a): array
    {
        $out = [
            'attempt_id'    => (string) ($a->id ?? ''),
            'scale_code'    => (string) ($a->scale_code ?? 'MBTI'),
            'scale_version' => (string) ($a->scale_version ?? 'v0.2'),
            'type_code'     => (string) ($a->type_code ?? ''),
            'region'        => (string) ($a->region ?? 'CN_MAINLAND'),
            'locale'        => (string) ($a->locale ?? 'zh-CN'),
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

        if (property_exists($a, 'ticket_code') && (string) ($a->ticket_code ?? '') !== '') {
            $out['lookup_key'] = (string) $a->ticket_code;
        } else {
            $out['lookup_key'] = (string) ($a->id ?? '');
        }

        return $out;
    }
}
