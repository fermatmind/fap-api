<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Controllers\API\V0_5\Cms\PersonalityController;
use App\Models\PersonalityProfile;
use Illuminate\Console\Command;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

final class PersonalityWarmPublicReadModels extends Command
{
    public const MAX_DETAIL_BYTES = 524288;

    protected $signature = 'personality:warm-public-read-models
        {--types= : Comma-separated MBTI base or A/T runtime type codes; defaults to all 32 variants}
        {--locales=en,zh-CN : Comma-separated public locales}';

    protected $description = 'Warm bounded public MBTI A/T detail and SEO read models after a publish or update.';

    public function handle(PersonalityController $controller): int
    {
        $types = $this->types((string) $this->option('types'));
        $locales = $this->csv((string) $this->option('locales'));
        if ($types === [] || $locales === [] || array_diff($locales, PersonalityProfile::SUPPORTED_LOCALES) !== []) {
            $this->error('types and locales must contain supported MBTI A/T routes and public locales.');

            return self::FAILURE;
        }

        $failed = false;
        foreach ($locales as $locale) {
            foreach ($types as $type) {
                try {
                    $detail = $controller->show($this->request($type, $locale), strtolower($type));
                    $seo = $controller->seo($this->request($type.'/seo', $locale), strtolower($type));
                    $bytes = strlen((string) $detail->getContent());
                    $detailCache = $this->cacheState($detail);
                    $seoCache = $this->cacheState($seo);
                    $ok = $this->isFreshReadback($detail)
                        && $this->isFreshReadback($seo)
                        && $bytes <= self::MAX_DETAIL_BYTES;
                    $this->line(sprintf(
                        'type=%s locale=%s detail=%d detail_cache=%s seo=%d seo_cache=%s bytes=%d budget=%s',
                        $type,
                        $locale,
                        $detail->getStatusCode(),
                        $detailCache,
                        $seo->getStatusCode(),
                        $seoCache,
                        $bytes,
                        $ok ? 'pass' : 'fail',
                    ));
                    $failed = $failed || ! $ok;
                } catch (Throwable $e) {
                    $this->error(sprintf('type=%s locale=%s failed=%s', $type, $locale, $e->getMessage()));
                    $failed = true;
                }
            }
        }

        $this->{$failed ? 'error' : 'info'}($failed
            ? 'Personality public read-model warmup completed with failures.'
            : 'Personality public read-model warmup completed successfully.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function isFreshReadback(JsonResponse $response): bool
    {
        return $response->getStatusCode() === 200
            && in_array($this->cacheState($response), ['miss', 'fresh'], true);
    }

    private function cacheState(JsonResponse $response): string
    {
        return (string) $response->headers->get('X-Fermat-Public-Read-Cache', 'unknown');
    }

    private function request(string $path, string $locale): Request
    {
        return Request::create('/api/v0.5/personality/'.strtolower($path), 'GET', [
            'locale' => $locale,
            'org_id' => 0,
            'scale_code' => PersonalityProfile::SCALE_CODE_MBTI,
        ]);
    }

    /** @return list<string> */
    private function types(string $raw): array
    {
        $requested = $this->csv($raw);
        if ($requested === []) {
            return array_values(array_merge(...array_map(
                static fn (string $base): array => [$base.'-A', $base.'-T'],
                PersonalityProfile::BASE_TYPE_CODES,
            )));
        }

        $types = [];
        foreach ($requested as $type) {
            $type = strtoupper($type);
            if (in_array($type, PersonalityProfile::BASE_TYPE_CODES, true)) {
                array_push($types, $type.'-A', $type.'-T');
            } elseif (preg_match('/^[EI][SN][TF][JP]-[AT]$/', $type) === 1) {
                $types[] = $type;
            }
        }

        return array_values(array_unique($types));
    }

    /** @return list<string> */
    private function csv(string $raw): array
    {
        return array_values(array_filter(array_map(
            static fn (string $value): string => trim($value),
            explode(',', $raw),
        ), static fn (string $value): bool => $value !== ''));
    }
}
