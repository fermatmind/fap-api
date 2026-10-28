<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Models\Event;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class EntertainmentActivity
{
    public const EVENTS = ['fun_start', 'fun_complete', 'fun_result_view', 'fun_share'];

    public const CATALOG_CACHE = 'entertainment-activity:public-catalog:v1';

    public function catalogState(bool $refresh = false): array
    {
        if ($refresh) {
            Cache::forget(self::CATALOG_CACHE);
        }

        return Cache::remember(self::CATALOG_CACHE, 60, function (): array {
            try {
                // Fixed first-party URL, no user context, credentials, cookies or answer assets.
                $data = Http::acceptJson()->timeout(2)->get('https://fermatmind.com/api/entertainment-catalog')->throw()->json();
                $validator = Validator::make(is_array($data) ? $data : [], [
                    'schema' => ['required', 'in:entertainment.catalog.v1'],
                    'items' => ['required', 'array', 'min:1', 'max:2000'],
                    'items.*' => ['required', 'array:slug,title,path,privacy,roles'],
                    'items.*.slug' => ['required', 'string', 'max:128', 'distinct', 'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/'],
                    'items.*.title' => ['required', 'array:en,zh'],
                    'items.*.title.en' => ['required', 'string', 'max:300'],
                    'items.*.title.zh' => ['required', 'string', 'max:300'],
                    'items.*.path' => ['required', 'string', 'max:200', 'regex:~\A/tests/(?:explore|play)/[a-z0-9-]+\z~'],
                    'items.*.privacy' => ['required', 'in:analytics_consent,explicit_opt_in'],
                    'items.*.roles' => ['required', 'array', 'min:1', 'max:3'],
                    'items.*.roles.*' => ['required', 'in:single,creator,friend,solo'],
                ]);
                if ($validator->fails()) {
                    throw new \UnexpectedValueException('Invalid public entertainment registry');
                }
                foreach ($data['items'] as $item) {
                    $roles = $item['slug'] === 'friendship-quiz' ? ['creator', 'friend', 'solo'] : ['single'];
                    if ($item['roles'] !== $roles || ! str_ends_with($item['path'], '/'.$item['slug'])) {
                        throw new \UnexpectedValueException('Invalid public entertainment identity');
                    }
                }
                Cache::put(self::CATALOG_CACHE.':last-known', $data['items'], now()->addWeek());

                return ['items' => $data['items'], 'source' => 'live'];
            } catch (\Throwable) {
                $known = Cache::get(self::CATALOG_CACHE.':last-known');

                return ['items' => is_array($known) ? $known : json_decode(file_get_contents(resource_path('entertainment/activity-catalog.json')), true, 512, JSON_THROW_ON_ERROR),
                    'source' => is_array($known) ? 'last_known' : 'baseline'];
            }
        });
    }

    public function catalog(): array
    {
        return $this->catalogState()['items'];
    }

    public function record(Request $request, int $orgId): void
    {
        $catalog = $this->catalog();
        if (is_string($request->input('payload.slug')) && ! in_array($request->input('payload.slug'), array_column($catalog, 'slug'), true)) {
            $catalog = $this->catalogState(true)['items'];
        }
        $data = $request->validate([
            'requestId' => ['required', 'uuid'],
            'eventName' => ['required', Rule::in(self::EVENTS)],
            'anonymousId' => ['nullable', 'in:'],
            'payload' => ['required', 'array:slug,form_code,locale,entry_surface,target_action,consent_state,is_internal,is_qa,is_bot,environment,traffic_quality'],
            'payload.slug' => ['required', Rule::in(array_column($catalog, 'slug'))],
            'payload.form_code' => ['required', 'string', 'max:64', 'regex:/\A[A-Za-z0-9._-]+\z/'],
            'payload.locale' => ['required', Rule::in(['en', 'zh'])],
            'payload.entry_surface' => ['required', Rule::in(['single', 'creator', 'friend', 'solo'])],
            'payload.target_action' => ['required', Rule::in(['none', 'copy', 'system', 'image', 'link_open'])],
            'payload.consent_state' => ['required', Rule::in(['analytics_granted', 'activity_opt_in'])],
            'payload.is_internal' => ['sometimes', 'boolean'],
            'payload.is_qa' => ['sometimes', 'boolean'],
            'payload.is_bot' => ['sometimes', 'boolean'],
            'payload.environment' => ['sometimes', 'string', 'max:32'],
            'payload.traffic_quality' => ['sometimes', 'string', 'max:32'],
        ]);
        $p = $data['payload'];
        $definition = collect($catalog)->firstWhere('slug', $p['slug']);
        abort_unless(in_array($p['entry_surface'], $definition['roles'], true), 422, 'INVALID_PLAY_ROLE');
        abort_unless($definition['privacy'] !== 'explicit_opt_in' || $p['consent_state'] === 'activity_opt_in', 422, 'ACTIVITY_OPT_IN_REQUIRED');
        abort_unless(($data['eventName'] === 'fun_share') === ($p['target_action'] !== 'none'), 422, 'INVALID_SHARE_METHOD');
        $key = 'entertainment-activity:'.$orgId.':'.$data['requestId'];
        if (! Cache::add($key, true, now()->addDay())) {
            return;
        }
        try {
            // This stream deliberately has no participant, IP, session, URL, answer or result identity.
            $excluded = ! empty($p['is_internal']) || ! empty($p['is_qa']) || ! empty($p['is_bot'])
                || (($p['environment'] ?? 'production') !== 'production')
                || in_array($p['traffic_quality'] ?? '', ['internal', 'qa', 'bot', 'synthetic', 'suspected'], true);
            Event::query()->create([
                'id' => (string) Str::uuid(), 'event_code' => $data['eventName'], 'event_name' => $data['eventName'],
                'org_id' => $orgId, 'scale_code' => 'ENTERTAINMENT', 'locale' => $p['locale'],
                'occurred_at' => CarbonImmutable::now('UTC'),
                'meta_json' => ['schema' => 'entertainment.activity.v1', 'slug' => $p['slug'], 'version' => $p['form_code'],
                    'role' => $p['entry_surface'], 'method' => $p['target_action'], 'excluded' => $excluded],
            ]);
        } catch (\Throwable $error) {
            Cache::forget($key);
            throw $error;
        }
    }
}
