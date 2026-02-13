<?php

namespace App\Services\Analytics;

use Illuminate\Support\Carbon;

class EventNormalizer
{
    public static function normalize(array $payload, array $context = []): array
    {
        $props = self::ensureArray($payload['props'] ?? []);
        $meta = self::ensureArray($payload['meta_json'] ?? []);
        $mergedProps = array_merge($props, $meta);

        $eventName = self::firstNonEmpty([
            $payload['event_name'] ?? null,
            $payload['event_code'] ?? null,
            $mergedProps['event_name'] ?? null,
            $mergedProps['event_code'] ?? null,
        ]);

        $occurredRaw = self::firstNonEmpty([
            $mergedProps['occurred_at'] ?? null,
            $mergedProps['ts'] ?? null,
            $payload['occurred_at'] ?? null,
            $payload['ts'] ?? null,
            $context['occurred_at'] ?? null,
        ]);

        $occurredAt = self::parseOccurredAt($occurredRaw) ?? Carbon::now();

        $columns = [
            'event_name' => $eventName,
            'event_code' => $payload['event_code'] ?? null,
            'occurred_at' => $occurredAt,
            'user_id' => self::toInt(self::firstNonEmpty([
                $context['user_id'] ?? null,
                $payload['user_id'] ?? null,
                $mergedProps['user_id'] ?? null,
            ])),
            'anon_id' => self::toString(self::firstNonEmpty([
                $payload['anon_id'] ?? null,
                $mergedProps['anon_id'] ?? null,
                $context['anon_id'] ?? null,
            ])),
            'session_id' => self::toString(self::firstNonEmpty([
                $payload['session_id'] ?? null,
                $mergedProps['session_id'] ?? null,
                $mergedProps['sessionId'] ?? null,
                $context['session_id'] ?? null,
            ])),
            'request_id' => self::toString(self::firstNonEmpty([
                $payload['request_id'] ?? null,
                $mergedProps['request_id'] ?? null,
                $mergedProps['requestId'] ?? null,
                $context['request_id'] ?? null,
            ])),
            'scale_code' => self::toString(self::firstNonEmpty([
                $payload['scale_code'] ?? null,
                $mergedProps['scale_code'] ?? null,
                $mergedProps['scaleCode'] ?? null,
                $context['scale_code'] ?? null,
            ])),
            'scale_version' => self::toString(self::firstNonEmpty([
                $payload['scale_version'] ?? null,
                $mergedProps['scale_version'] ?? null,
                $mergedProps['scaleVersion'] ?? null,
                $context['scale_version'] ?? null,
            ])),
            'attempt_id' => self::toString(self::firstNonEmpty([
                $payload['attempt_id'] ?? null,
                $payload['attempt_uuid'] ?? null,
                $payload['attemptId'] ?? null,
                $payload['attemptID'] ?? null,
                $mergedProps['attempt_id'] ?? null,
                $mergedProps['attempt_uuid'] ?? null,
                $mergedProps['attemptId'] ?? null,
                $mergedProps['attemptID'] ?? null,
                $mergedProps['attempt'] ?? null,
                $context['attempt_id'] ?? null,
            ])),
            'question_id' => self::toString(self::firstNonEmpty([
                $payload['question_id'] ?? null,
                $payload['questionId'] ?? null,
                $mergedProps['question_id'] ?? null,
                $mergedProps['questionId'] ?? null,
            ])),
            'question_index' => self::toInt(self::firstNonEmpty([
                $payload['question_index'] ?? null,
                $payload['questionIndex'] ?? null,
                $mergedProps['question_index'] ?? null,
                $mergedProps['questionIndex'] ?? null,
            ])),
            'duration_ms' => self::toInt(self::firstNonEmpty([
                $payload['duration_ms'] ?? null,
                $payload['durationMs'] ?? null,
                $mergedProps['duration_ms'] ?? null,
                $mergedProps['durationMs'] ?? null,
            ])),
            'is_dropoff' => self::toBoolInt(self::firstNonEmpty([
                $payload['is_dropoff'] ?? null,
                $payload['isDropoff'] ?? null,
                $mergedProps['is_dropoff'] ?? null,
                $mergedProps['isDropoff'] ?? null,
                $mergedProps['dropoff'] ?? null,
            ])),
            'pack_id' => self::toString(self::firstNonEmpty([
                $payload['pack_id'] ?? null,
                $payload['packId'] ?? null,
                $mergedProps['pack_id'] ?? null,
                $mergedProps['packId'] ?? null,
                $mergedProps['content_pack_id'] ?? null,
                $context['pack_id'] ?? null,
            ])),
            'dir_version' => self::toString(self::firstNonEmpty([
                $payload['dir_version'] ?? null,
                $payload['dirVersion'] ?? null,
                $mergedProps['dir_version'] ?? null,
                $mergedProps['dirVersion'] ?? null,
                $context['dir_version'] ?? null,
            ])),
            'pack_semver' => self::toString(self::firstNonEmpty([
                $payload['pack_semver'] ?? null,
                $payload['packSemver'] ?? null,
                $mergedProps['pack_semver'] ?? null,
                $mergedProps['packSemver'] ?? null,
            ])),
            'region' => self::toString(self::firstNonEmpty([
                $payload['region'] ?? null,
                $mergedProps['region'] ?? null,
                $context['region'] ?? null,
            ])),
            'locale' => self::toString(self::firstNonEmpty([
                $payload['locale'] ?? null,
                $mergedProps['locale'] ?? null,
                $context['locale'] ?? null,
            ])),
            'utm_source' => self::toString(self::firstNonEmpty([
                $payload['utm_source'] ?? null,
                $payload['utmSource'] ?? null,
                $mergedProps['utm_source'] ?? null,
                $mergedProps['utmSource'] ?? null,
            ])),
            'utm_medium' => self::toString(self::firstNonEmpty([
                $payload['utm_medium'] ?? null,
                $payload['utmMedium'] ?? null,
                $mergedProps['utm_medium'] ?? null,
                $mergedProps['utmMedium'] ?? null,
            ])),
            'utm_campaign' => self::toString(self::firstNonEmpty([
                $payload['utm_campaign'] ?? null,
                $payload['utmCampaign'] ?? null,
                $mergedProps['utm_campaign'] ?? null,
                $mergedProps['utmCampaign'] ?? null,
            ])),
            'referrer' => self::toString(self::firstNonEmpty([
                $payload['referrer'] ?? null,
                $mergedProps['referrer'] ?? null,
                $mergedProps['referer'] ?? null,
            ])),
            'share_id' => self::toString(self::firstNonEmpty([
                $payload['share_id'] ?? null,
                $payload['shareId'] ?? null,
                $mergedProps['share_id'] ?? null,
                $mergedProps['shareId'] ?? null,
            ])),
            'share_channel' => self::toString(self::firstNonEmpty([
                $payload['share_channel'] ?? null,
                $payload['shareChannel'] ?? null,
                $mergedProps['share_channel'] ?? null,
                $mergedProps['shareChannel'] ?? null,
            ])),
            'share_click_id' => self::toString(self::firstNonEmpty([
                $payload['share_click_id'] ?? null,
                $payload['shareClickId'] ?? null,
                $mergedProps['share_click_id'] ?? null,
                $mergedProps['shareClickId'] ?? null,
            ])),
        ];

        return [
            'columns' => $columns,
            'props' => $mergedProps,
        ];
    }

    private static function ensureArray($value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    private static function firstNonEmpty(array $values)
    {
        foreach ($values as $value) {
            if ($value === null) {
                continue;
            }
            if (is_string($value)) {
                $trimmed = trim($value);
                if ($trimmed === '') {
                    continue;
                }
                return $trimmed;
            }
            return $value;
        }

        return null;
    }

    private static function toString($value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            $trimmed = trim($value);
            return $trimmed === '' ? null : $trimmed;
        }

        return trim((string) $value);
    }

    private static function toInt($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }

    private static function toBoolInt($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (is_numeric($value)) {
            return ((int) $value) ? 1 : 0;
        }
        if (is_string($value)) {
            $lower = strtolower(trim($value));
            if (in_array($lower, ['true', 'yes', 'y'], true)) {
                return 1;
            }
            if (in_array($lower, ['false', 'no', 'n'], true)) {
                return 0;
            }
        }

        return null;
    }

    private static function parseOccurredAt($value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            $num = (float) $value;
            if ($num > 200000000000) {
                return Carbon::createFromTimestampMs((int) $num);
            }
            if ($num > 2000000000) {
                return Carbon::createFromTimestamp((int) $num);
            }

            return Carbon::createFromTimestamp((int) $num);
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
