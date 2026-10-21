<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_3;

use App\Http\Controllers\Controller;
use App\Services\Account\AccountAssetLinker;
use App\Services\Auth\FmTokenService;
use App\Services\Auth\GoogleLoginVerifier;
use App\Services\Auth\IdentityService;
use App\Services\Commerce\MembershipService;
use App\Support\PiiCipher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

final class AccountController extends Controller
{
    public function challenge(): JsonResponse
    {
        $clientId = trim((string) config('services.google_login.client_id'));
        if ($clientId === '') {
            return $this->reply(['configured' => false]);
        }
        $id = Str::random(64);
        $nonce = Str::random(64);
        Cache::put('account:login:'.hash('sha256', $id), $nonce, now()->addMinutes(5));

        return $this->reply(['configured' => true, 'client_id' => $clientId, 'challenge_id' => $id, 'nonce' => $nonce]);
    }

    public function google(Request $request, GoogleLoginVerifier $verifier): JsonResponse
    {
        $data = $request->validate(['credential' => 'required|string|max:16000', 'challenge_id' => 'required|string|size:64', 'locale' => 'nullable|in:zh,en']);
        $key = 'account:login:'.hash('sha256', $data['challenge_id']);

        return Cache::lock($key.':lock', 15)->block(2, function () use ($key, $data, $verifier, $request): JsonResponse {
            $nonce = Cache::get($key);
            abort_unless(is_string($nonce), 422, 'Login challenge expired. Please try again.');
            $claims = $verifier->verify($data['credential'], $nonce);
            $claims['locale'] = $data['locale'] ?? 'zh';
            Cache::forget($key);

            return Cache::lock('account:google:'.hash('sha256', $claims['sub']), 15)->block(2, function () use ($claims, $request): JsonResponse {
                $identityUser = app(IdentityService::class)->resolveUserId('google', $claims['sub']);
                $current = app(FmTokenService::class)->validateToken($request->bearerToken() ?? '');
                abort_if(($current['ok'] ?? false) && (int) ($current['org_id'] ?? -1) !== 0, 403, 'Use a public site session.');
                $currentId = ($current['ok'] ?? false) && ($current['org_id'] ?? -1) === 0 ? ($current['user_id'] ?? null) : null;
                if ($identityUser !== null) {
                    abort_if($currentId !== null && $currentId !== $identityUser, 409, 'Google identity belongs to another account. Sign out first.');

                    return $this->session($identityUser);
                }
                $existing = DB::table('users')->where(function ($query) use ($claims): void {
                    $query->whereRaw('LOWER(email) = ?', [$claims['email']])->orWhere('email_hash', app(PiiCipher::class)->emailHash($claims['email']));
                })->first();
                if ($existing && $currentId !== (string) $existing->id) {
                    $ticket = Str::random(64);
                    Cache::put('account:link-ticket:'.hash('sha256', $ticket), ['user_id' => (string) $existing->id, 'claims' => $claims], now()->addMinutes(15));

                    return $this->reply(['status' => 'verification_required', 'verification_ticket' => $ticket]);
                }

                $userId = DB::transaction(function () use ($currentId, $existing, $claims): string {
                    if ($currentId !== null) {
                        $id = $currentId;
                    } elseif ($existing) {
                        $id = (string) $existing->id;
                    } else {
                        $pii = app(PiiCipher::class);
                        $id = (string) DB::table('users')->insertGetId([
                            'name' => $claims['name'] !== '' ? $claims['name'] : 'FermatMind',
                            'email' => $claims['email'], 'email_verified_at' => now(),
                            'email_hash' => $pii->emailHash($claims['email']), 'email_enc' => $pii->encrypt($claims['email']),
                            'password' => Hash::make(Str::random(64)), 'created_at' => now(), 'updated_at' => now(),
                        ]);
                    }
                    $this->bindGoogle($id, $claims);

                    return $id;
                });

                return $this->session($userId);
            });
        });
    }

    public function sendGoogleLink(Request $request): JsonResponse
    {
        $data = $request->validate(['verification_ticket' => 'required|string|size:64', 'locale' => 'nullable|in:zh,en']);
        $key = 'account:link-ticket:'.hash('sha256', $data['verification_ticket']);
        $payload = Cache::get($key);
        abort_unless(is_array($payload), 422, 'Verification request expired.');
        $this->sendVerification($payload['claims']['email'], ['purpose' => 'google_link', ...$payload], $data['locale'] ?? 'zh');
        Cache::forget($key);

        return $this->reply(['verification_email_sent' => true]);
    }

    public function recoverEmail(Request $request): JsonResponse
    {
        $userId = $this->userId($request);
        $data = $request->validate(['email' => 'required|email|max:254', 'locale' => 'nullable|in:zh,en']);
        $email = mb_strtolower(trim($data['email']));
        $this->sendVerification($email, ['purpose' => 'asset_link', 'user_id' => $userId, 'email_hash' => app(PiiCipher::class)->emailHash($email)], $data['locale'] ?? 'zh');

        return $this->reply(['verification_email_sent' => true]);
    }

    public function confirmEmail(Request $request, AccountAssetLinker $linker): JsonResponse
    {
        $data = $request->validate(['token' => 'required|string|size:64']);
        $key = 'account:email:'.hash('sha256', $data['token']);

        return Cache::lock($key.':lock', 10)->block(2, function () use ($key, $request, $linker): JsonResponse {
            $payload = Cache::get($key);
            abort_unless(is_array($payload), 422, 'Verification link expired or already used.');
            if ($payload['purpose'] === 'google_link') {
                DB::transaction(fn () => $this->bindGoogle($payload['user_id'], $payload['claims']));
                Cache::forget($key);

                return $this->session($payload['user_id']);
            }
            $userId = $this->validatedBearerUserId($request);
            abort_unless(hash_equals($payload['user_id'], $userId), 403, 'Sign in to the account that requested this link.');
            $result = $linker->linkEmail($userId, $payload['email_hash']);
            Cache::forget($key);

            return $this->reply($result);
        });
    }

    public function show(Request $request): JsonResponse
    {
        abort_unless((int) $request->attributes->get('fm_org_id') === 0, 403);
        $userId = $request->attributes->get('fm_user_id');

        return $this->reply(['account' => $userId ? $this->account((string) $userId) : null, 'preview' => app()->environment('local')]);
    }

    public function update(Request $request): JsonResponse
    {
        $userId = $this->userId($request);
        $data = $request->validate(['name' => 'required|string|max:100', 'locale' => 'required|in:zh,en']);
        DB::transaction(function () use ($userId, $data): void {
            DB::table('users')->where('id', $userId)->update(['name' => trim($data['name']), 'updated_at' => now()]);
            $identity = DB::table('identities')->where('user_id', $userId)->where('provider', 'google')->lockForUpdate()->first();
            if ($identity) {
                $meta = json_decode($identity->meta_json ?? '{}', true) ?: [];
                $meta['preferred_locale'] = $data['locale'];
                DB::table('identities')->where('id', $identity->id)->update(['meta_json' => json_encode($meta), 'updated_at' => now()]);
            }
        });

        return $this->reply(['account' => $this->account($userId)]);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->userId($request);
        DB::table('auth_tokens')->where('token_hash', hash('sha256', $request->bearerToken() ?? ''))->update(['revoked_at' => now(), 'updated_at' => now()]);

        return $this->reply([]);
    }

    public function orders(Request $request): JsonResponse
    {
        $userId = $this->userId($request);
        $page = DB::table('orders')->where('org_id', 0)->where('user_id', $userId)->orderByDesc('created_at')
            ->paginate(20, ['order_no', 'status', 'payment_state', 'grant_state', 'amount_cents', 'currency', 'target_attempt_id', 'created_at']);

        $membership = app(MembershipService::class)->status(0, $userId, null);

        return $this->reply(['items' => $page->items(), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage()], 'membership' => $membership['membership']]);
    }

    public function linkAttempts(Request $request, AccountAssetLinker $linker): JsonResponse
    {
        $userId = $this->userId($request);
        $data = $request->validate(['proofs' => 'required|array|min:1|max:50', 'proofs.*.attempt_id' => 'required|uuid|distinct', 'proofs.*.resume_token' => 'required|string|max:256']);

        return $this->reply($linker->linkAttempts($userId, $data['proofs']));
    }

    private function bindGoogle(string $userId, array $claims): void
    {
        $user = DB::table('users')->where('id', $userId)->lockForUpdate()->first();
        abort_unless($user, 401);
        $result = app(IdentityService::class)->bind($userId, 'google', $claims['sub'], ['email' => $claims['email'], 'preferred_locale' => $claims['locale'] ?? 'zh']);
        abort_unless($result['ok'], (int) ($result['status'] ?? 409), 'Google identity could not be linked.');
    }

    private function account(string $id): array
    {
        $user = DB::table('users')->where('id', $id)->first();
        abort_unless($user, 401);
        $identity = DB::table('identities')->where('user_id', $id)->where('provider', 'google')->first();
        $meta = $identity ? (json_decode($identity->meta_json ?? '{}', true) ?: []) : [];

        return ['id' => $id, 'name' => $user->name, 'email' => $user->email, 'google_connected' => $identity !== null, 'locale' => $meta['preferred_locale'] ?? 'zh'];
    }

    private function session(string $id): JsonResponse
    {
        $account = $this->account($id);
        $session = app(FmTokenService::class)->issueForUser($id, ['provider' => 'google', 'org_id' => 0, 'role' => 'public']);

        return $this->reply(['status' => 'authenticated', 'fm_token' => $session['token'], 'expires_at' => $session['expires_at'], 'account' => $account]);
    }

    private function sendVerification(string $email, array $payload, string $locale): void
    {
        $limitKey = 'account:email-send:'.hash('sha256', ($payload['user_id'] ?? '').'|'.$email);
        $bypass = app()->environment('testing') && config('fap.rate_limits.bypass_in_test_env');
        abort_if(! $bypass && RateLimiter::tooManyAttempts($limitKey, 3), 429, 'Please wait before requesting another verification email.');
        if (! $bypass) {
            RateLimiter::hit($limitKey, 900);
        }
        $token = Str::random(64);
        $key = 'account:email:'.hash('sha256', $token);
        Cache::put($key, $payload, now()->addMinutes(15));
        $url = rtrim((string) config('services.google_login.frontend_url'), '/').'/'.$locale.'/account#verify='.$token;
        $subject = $locale === 'zh' ? '确认费马账号关联' : 'Confirm your FermatMind account link';
        $body = $locale === 'zh'
            ? "请确认你发起了费马账号关联。打开链接后需再次确认，链接15分钟内有效。若非本人操作，请忽略。\n\n".$url
            : "Confirm the FermatMind account link you requested. Open this link and confirm to continue. It expires in 15 minutes. Ignore this message if you did not request it.\n\n".$url;
        try {
            Mail::raw($body, fn ($mail) => $mail->to($email)->subject($subject));
        } catch (\Throwable) {
            Cache::forget($key);
            abort(503, 'Verification email could not be sent. Try again later.');
        }
    }

    private function validatedBearerUserId(Request $request): string
    {
        $actor = app(FmTokenService::class)->validateToken($request->bearerToken() ?? '');
        abort_unless(($actor['ok'] ?? false) && ($actor['org_id'] ?? -1) === 0 && ! empty($actor['user_id']), 401);

        abort_unless(DB::table('users')->where('id', $actor['user_id'])->exists(), 401);

        return (string) $actor['user_id'];
    }

    private function userId(Request $request): string
    {
        abort_unless((int) $request->attributes->get('fm_org_id') === 0 && $request->attributes->get('fm_user_id'), 401);

        return (string) $request->attributes->get('fm_user_id');
    }

    private function reply(array $payload): JsonResponse
    {
        return response()->json(['ok' => true, ...$payload])->header('Cache-Control', 'private, no-store, max-age=0')->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
