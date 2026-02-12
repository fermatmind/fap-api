<?php

declare(strict_types=1);

namespace App\Services\Report\Composer;

use App\Models\Attempt;
use App\Models\Result;
use App\Support\OrgContext;

final class ReportComposeContext
{
    public function __construct(
        public readonly int $orgId,
        public readonly string $scaleCode,
        public readonly string $packId,
        public readonly string $dirVersion,
        public readonly string $region,
        public readonly string $locale,
        public readonly string $attemptId,
        public readonly Attempt $attempt,
        public readonly ?Result $result,
        public readonly bool $explain,
        public readonly bool $persist,
        public readonly bool $strict,
        public readonly array $options,
    ) {
    }

    public static function fromAttempt(Attempt $attempt, ?Result $result, array $opts): self
    {
        $orgId = array_key_exists('org_id', $opts) && is_numeric($opts['org_id'])
            ? max(0, (int) $opts['org_id'])
            : max(0, (int) app(OrgContext::class)->orgId());

        $packId = (string) ($attempt->pack_id ?? $result?->pack_id ?? '');
        $dirVersion = (string) ($attempt->dir_version ?? $result?->dir_version ?? '');
        $scaleCode = (string) ($attempt->scale_code ?? $result?->scale_code ?? 'MBTI');
        if ($scaleCode === '') {
            $scaleCode = 'MBTI';
        }

        $region = (string) ($attempt->region ?? '');
        if ($region === '') {
            $region = (string) config('content_packs.default_region', 'CN_MAINLAND');
        }

        $locale = (string) ($attempt->locale ?? '');
        if ($locale === '') {
            $locale = (string) config('content_packs.default_locale', 'zh-CN');
        }

        return new self(
            orgId: $orgId,
            scaleCode: $scaleCode,
            packId: $packId,
            dirVersion: $dirVersion,
            region: $region,
            locale: $locale,
            attemptId: (string) $attempt->id,
            attempt: $attempt,
            result: $result,
            explain: (bool) ($opts['explain'] ?? false),
            persist: array_key_exists('persist', $opts) ? (bool) $opts['persist'] : true,
            strict: (bool) ($opts['strict'] ?? false),
            options: $opts,
        );
    }
}
