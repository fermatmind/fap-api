<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Models\Event;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class EntertainmentActivity
{
    public const EVENTS = ['fun_start', 'fun_complete', 'fun_result_view', 'fun_share'];

    public function catalog(): array
    {
        return json_decode(file_get_contents(resource_path('entertainment/activity-catalog.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    public function record(Request $request, int $orgId): void
    {
        $data = $request->validate([
            'requestId' => ['required', 'uuid'],
            'eventName' => ['required', Rule::in(self::EVENTS)],
            'anonymousId' => ['nullable', 'in:'],
            'payload' => ['required', 'array:slug,form_code,locale,entry_surface,target_action,consent_state,is_internal,is_qa,is_bot,environment,traffic_quality'],
            'payload.slug' => ['required', Rule::in(array_column($this->catalog(), 'slug'))],
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
        $definition = collect($this->catalog())->firstWhere('slug', $p['slug']);
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
