<?php

declare(strict_types=1);

namespace App\Filament\Ops\Support;

use App\Filament\Ops\Resources\PersonalityProfileResource;
use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileVariant;
use App\Models\PersonalityProfileVariantRevision;
use App\Models\PersonalityProfileVariantSection;
use App\Services\Audit\AuditLogger;
use App\Services\Cms\MbtiEnglishVariantSectionEnrichmentService as Copy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Updates the two legacy reader prompts on the selected English profile only. */
final class MbtiEnglishResultGuidanceWorkspace
{
    private const SNAPSHOT = 'mbti_english_reader_guidance_v1';

    public function prepare(PersonalityProfile $selected, bool $restore = false): array
    {
        [$profile, $variants, $rows] = $this->load($selected);
        $changes = $this->changes($variants, $rows, $restore);

        return [
            'target_hash' => $this->fingerprint($profile, $variants, $rows, $restore),
            'preview' => json_encode(array_map(fn (array $change): array => [
                'type' => $change['type'], 'section' => $change['row']->section_key,
                'changes' => $change['preview'],
            ], $changes), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ];
    }

    public function commit(PersonalityProfile $selected, string $expectedHash, bool $restore = false): array
    {
        return DB::transaction(function () use ($selected, $expectedHash, $restore): array {
            [$profile, $variants, $rows] = $this->load($selected, true);
            $hash = $this->fingerprint($profile, $variants, $rows, $restore);
            if (! preg_match('/^[a-f0-9]{64}$/', $expectedHash) || ! hash_equals($hash, $expectedHash)) {
                $this->reject('The selected result content changed. Reopen the preview.');
            }
            $changes = $this->changes($variants, $rows, $restore);
            $before = $variants->mapWithKeys(fn ($variant): array => [$variant->id => $this->variantState($variant, $rows)]);
            foreach ($changes as $change) {
                $unchanged = array_diff_key($change['row']->attributesToArray(), array_flip(['body_md', 'payload_json', 'updated_at']));
                $change['row']->setRawAttributes(array_replace($change['row']->getAttributes(), $change['attributes']));
                $change['row']->save();
                $change['row']->refresh();
                if ($this->canonical($unchanged) !== $this->canonical(array_diff_key($change['row']->attributesToArray(), array_flip(['body_md', 'payload_json', 'updated_at'])))) {
                    $this->reject('A field outside the selected reader prompts changed. No changes were committed.');
                }
                foreach ($change['attributes'] as $key => $value) {
                    if ($this->fieldValue($change['row'], $key) !== ($key === 'payload_json' ? $this->jsonValue($value) : $value)) {
                        $this->reject('Result content readback failed. No changes were committed.');
                    }
                }
            }
            foreach ($changes === [] ? [] : $variants as $variant) {
                PersonalityProfileVariantRevision::query()->create([
                    'personality_profile_variant_id' => $variant->id,
                    'revision_no' => ((int) $variant->revisions()->max('revision_no')) + 1,
                    'snapshot_json' => [self::SNAPSHOT => [
                        'mode' => $restore ? 'restore' : 'update',
                        'group' => $hash,
                        'before' => $before[$variant->id],
                        'after' => $this->variantState($variant, $rows),
                        'copy_sha256' => $this->copyHash(),
                    ]],
                    'note' => 'English result reading guidance: '.($restore ? 'restore' : 'update'),
                    'created_by_admin_user_id' => auth((string) config('admin.guard', 'admin'))->id(),
                    'created_at' => now(),
                ]);
            }
            if ($changes !== []) {
                app(AuditLogger::class)->log(request(), 'mbti_english_result_guidance_updated', 'personality_profile', (string) $profile->id,
                    ['before_sha256' => $hash, 'copy_sha256' => $this->copyHash(), 'mode' => $restore ? 'restore' : 'update', 'updated_sections' => count($changes)],
                    reason: 'english_result_reader_guidance', result: 'success');
            }

            return ['updated_sections' => count($changes), 'mode' => $restore ? 'restore' : 'update'];
        });
    }

    private function load(PersonalityProfile $selected, bool $lock = false): array
    {
        abort_unless(PersonalityProfileResource::canEdit($selected), 403);
        $guard = (string) config('admin.guard', 'admin');
        abort_if((bool) config('admin.totp.enabled', true)
            && (int) session('ops_admin_totp_verified_user_id', 0) !== (int) auth($guard)->id(), 403);
        $profile = PersonalityProfile::withoutGlobalScopes()->whereKey($selected->getKey())
            ->when($lock, fn ($query) => $query->lockForUpdate())->firstOrFail();
        if ((int) $profile->org_id !== 0 || $profile->locale !== 'en' || $profile->scale_code !== PersonalityProfile::SCALE_CODE_MBTI
            || ! in_array($profile->type_code, PersonalityProfile::BASE_TYPE_CODES, true)
            || $profile->status !== 'published' || ! $profile->is_public
            || ($profile->published_at !== null && $profile->published_at->isFuture())) {
            $this->reject('Select an already-published global English MBTI profile.');
        }
        $variants = PersonalityProfileVariant::withoutGlobalScopes()->where('personality_profile_id', $profile->id)
            ->orderBy('runtime_type_code')->when($lock, fn ($query) => $query->lockForUpdate())->get();
        if ($variants->count() !== 2 || $variants->pluck('runtime_type_code')->all() !== [$profile->type_code.'-A', $profile->type_code.'-T']
            || $variants->contains(fn ($variant): bool => (int) $variant->org_id !== 0 || ! $variant->is_published
                || ($variant->published_at !== null && $variant->published_at->isFuture()))) {
            $this->reject('Both published A/T variants are required.');
        }
        $rows = PersonalityProfileVariantSection::withoutGlobalScopes()->whereIn('personality_profile_variant_id', $variants->modelKeys())
            ->whereIn('section_key', ['overview', 'trait_overview'])->orderBy('id')
            ->when($lock, fn ($query) => $query->lockForUpdate())->get();
        foreach ($variants as $variant) {
            $selectedRows = $rows->where('personality_profile_variant_id', $variant->id);
            if ($selectedRows->count() !== 2 || $selectedRows->contains(fn ($row): bool => (int) $row->org_id !== 0 || ! $row->is_enabled)
                || $selectedRows->pluck('section_key')->sort()->values()->all() !== ['overview', 'trait_overview']) {
                $this->reject('The selected published overview sections are incomplete.');
            }
        }

        return [$profile, $variants, $rows];
    }

    private function changes(Collection $variants, Collection $rows, bool $restore): array
    {
        $changes = [];
        $restoreGroup = null;
        foreach ($variants as $variant) {
            $saved = null;
            if ($restore) {
                $revision = $variant->revisions()->where('note', 'like', 'English result reading guidance:%')->orderByDesc('revision_no')->first();
                $saved = $revision?->snapshot_json[self::SNAPSHOT] ?? null;
                if (! is_array($saved) || ($saved['mode'] ?? '') !== 'update'
                    || $this->canonical($saved['after'] ?? null) !== $this->canonical($this->variantState($variant, $rows))) {
                    $this->reject('The last guidance update cannot be restored over newer content.');
                }
                if (! is_string($saved['group'] ?? null) || ($restoreGroup !== null && $restoreGroup !== $saved['group'])) {
                    $this->reject('The saved guidance revisions do not belong to the same update.');
                }
                $restoreGroup = $saved['group'];
            }
            foreach ($rows->where('personality_profile_variant_id', $variant->id) as $row) {
                $attributes = [];
                $preview = [];
                if ($restore) {
                    $old = $saved['before']['sections'][$row->section_key] ?? null;
                    if (! is_array($old) || (int) ($old['id'] ?? 0) !== (int) $row->id) {
                        $this->reject('The saved guidance revision does not match this section.');
                    }
                    foreach (['body_md', 'payload_json'] as $key) {
                        if ($this->fieldValue($row, $key) !== ($key === 'payload_json' ? $this->jsonValue($old[$key] ?? null) : ($old[$key] ?? null))) {
                            $attributes[$key] = $old[$key] ?? null;
                            $preview[$key] = ['before' => $this->fieldValue($row, $key), 'after' => $attributes[$key]];
                        }
                    }
                } elseif ($row->section_key === 'overview') {
                    $legacy = "This page is meant to answer practical search intent: what this type is, how the {$variant->variant_code} side changes the pattern, what relationships tend to need, which work environments fit, and when taking the test can clarify your next move.";
                    if (substr_count((string) $row->body_md, $legacy) === 1) {
                        $attributes['body_md'] = str_replace($legacy, Copy::OVERVIEW_READER_GUIDANCE, (string) $row->body_md);
                        $preview['ending'] = ['before' => $legacy, 'after' => Copy::OVERVIEW_READER_GUIDANCE];
                    }
                } else {
                    $rawPayload = (string) $row->getRawOriginal('payload_json');
                    $payload = json_decode($rawPayload, false, 512, JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR);
                    $legacy = 'This preference shapes search intent around traits, relationships, career fit, and daily decisions.';
                    if ($payload instanceof \stdClass && is_array($payload->dimensions ?? null)) {
                        foreach ($payload->dimensions as $index => &$dimension) {
                            if ($dimension instanceof \stdClass && in_array($dimension->id ?? null, ['EI', 'SN', 'TF', 'JP'], true)
                                && ($dimension->description ?? null) === $legacy) {
                                $dimension->description = Copy::PREFERENCE_READER_GUIDANCE;
                                $preview['dimensions.'.$index.'.description'] = ['before' => $legacy, 'after' => Copy::PREFERENCE_READER_GUIDANCE];
                            }
                        }
                        unset($dimension);
                        if ($preview !== []) {
                            // Patch only exact description tokens; never reserialize unknown JSON values.
                            $pattern = '/("description"\s*:\s*)'.preg_quote(json_encode($legacy, JSON_THROW_ON_ERROR), '/').'/';
                            $replacement = json_encode(Copy::PREFERENCE_READER_GUIDANCE, JSON_THROW_ON_ERROR);
                            $patched = preg_replace_callback($pattern, fn (array $match): string => $match[1].$replacement, $rawPayload, -1, $count);
                            if ($patched === null || $count !== count($preview)
                                || $this->canonical($payload) !== $this->jsonValue($patched)) {
                                $this->reject('The selected descriptions cannot be updated without changing other JSON.');
                            }
                            $attributes['payload_json'] = $patched;
                        }
                    }
                }
                if ($attributes !== []) {
                    $changes[] = ['row' => $row, 'type' => $variant->runtime_type_code, 'attributes' => $attributes, 'preview' => $preview];
                }
            }
        }

        return $changes;
    }

    private function variantState(PersonalityProfileVariant $variant, Collection $rows): array
    {
        return ['variant' => $variant->attributesToArray(), 'sections' => $rows->where('personality_profile_variant_id', $variant->id)
            ->mapWithKeys(fn ($row): array => [$row->section_key => array_replace($row->attributesToArray(), ['payload_json' => $row->getRawOriginal('payload_json')])])->all()];
    }

    private function fingerprint(PersonalityProfile $profile, Collection $variants, Collection $rows, bool $restore): string
    {
        return hash('sha256', $this->canonical(['profile' => $profile->attributesToArray(),
            'variants' => $variants->map(fn ($variant): array => $this->variantState($variant, $rows))->all(),
            'copy' => $this->copyHash(), 'restore' => $restore,
            'executor' => hash_file('sha256', __FILE__),
            'revision_heads' => $variants->map(fn ($variant): mixed => $variant->revisions()
                ->where('note', 'like', 'English result reading guidance:%')->max('id'))->all(),
        ]));
    }

    private function copyHash(): string
    {
        return hash('sha256', Copy::OVERVIEW_READER_GUIDANCE."\n".Copy::PREFERENCE_READER_GUIDANCE);
    }

    private function fieldValue(PersonalityProfileVariantSection $row, string $key): mixed
    {
        return $key === 'payload_json' ? $this->jsonValue($row->getRawOriginal($key)) : $row->getAttribute($key);
    }

    private function jsonValue(?string $value): ?string
    {
        return $value === null ? null : $this->canonical(json_decode($value, false, 512, JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR));
    }

    private function canonical(mixed $value): string
    {
        $sort = function (mixed $item) use (&$sort): mixed {
            if ($item instanceof \stdClass) {
                $properties = get_object_vars($item);
                ksort($properties);

                return (object) array_map($sort, $properties);
            }
            if (! is_array($item)) {
                return $item;
            }
            if (! array_is_list($item)) {
                ksort($item);
            }

            return array_map($sort, $item);
        };

        return json_encode($sort($value), JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['target_hash' => $message]);
    }
}
