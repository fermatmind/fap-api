<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Controllers\API\V0_3\ScalesController;
use App\Http\Controllers\API\V0_3\ScalesLookupController;
use App\Services\Content\BigFivePackLoader;
use App\Services\Content\ClinicalComboPackLoader;
use App\Services\Content\EnneagramPackLoader;
use App\Services\Content\Eq60PackLoader;
use App\Services\Content\QuestionsService;
use App\Services\Content\RiasecPackLoader;
use App\Services\Content\Sds20PackLoader;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Throwable;

final class MbtiPrewarm extends Command
{
    protected $signature = 'mbti:prewarm
        {--slug=mbti-personality-test-16-personality-types : MBTI landing slug to prewarm}
        {--locales=zh,en : Comma separated locales to prewarm}
        {--forms=mbti_93,mbti_144 : Comma separated MBTI form codes to prewarm}
        {--scales=MBTI,BIG5_OCEAN,ENNEAGRAM,RIASEC,EQ_60,IQ_RAVEN : Comma separated question scale codes to prewarm}';

    protected $description = 'Prewarm MBTI lookup plus hot public question caches for the hottest locales/forms.';

    public function handle(): int
    {
        $slug = trim((string) $this->option('slug'));
        $rawForms = (string) $this->option('forms');
        $rawScales = (string) $this->option('scales');
        $locales = $this->parseCsvOption((string) $this->option('locales'));
        $forms = $this->parseCsvOption($rawForms);
        $scales = array_values(array_unique(array_map(
            static fn (string $scale): string => strtoupper($scale),
            $this->parseCsvOption($rawScales)
        )));
        $usesDefaultForms = $rawForms === 'mbti_93,mbti_144';

        if ($slug === '' || $locales === [] || $forms === [] || $scales === []) {
            $this->error('slug, locales, forms and scales must not be empty.');

            return self::FAILURE;
        }

        $lookupController = app(ScalesLookupController::class);
        $scalesController = app(ScalesController::class);
        $questionsService = app(QuestionsService::class);
        $bigFivePackLoader = app(BigFivePackLoader::class);
        $clinicalPackLoader = app(ClinicalComboPackLoader::class);
        $sds20PackLoader = app(Sds20PackLoader::class);
        $eq60PackLoader = app(Eq60PackLoader::class);
        $enneagramPackLoader = app(EnneagramPackLoader::class);
        $riasecPackLoader = app(RiasecPackLoader::class);

        $failed = false;

        foreach ($locales as $locale) {
            if (in_array('MBTI', $scales, true)) {
                $lookupRequest = Request::create('/api/v0.3/scales/lookup', 'GET', [
                    'slug' => $slug,
                    'locale' => $locale,
                ]);

                try {
                    $lookupResponse = $lookupController->lookup($lookupRequest);
                    $this->line(sprintf(
                        'lookup locale=%s status=%d cache=%s',
                        $locale,
                        $lookupResponse->getStatusCode(),
                        $lookupResponse->headers->get('X-FAP-Cache', 'n/a')
                    ));
                    if ($lookupResponse->getStatusCode() !== 200) {
                        $failed = true;
                    }
                } catch (Throwable $e) {
                    $this->error(sprintf('lookup locale=%s failed: %s', $locale, $e->getMessage()));
                    $failed = true;
                }
            }

            foreach ($scales as $scaleCode) {
                foreach ($this->formsForScaleAndLocale($scaleCode, $locale, $forms, $usesDefaultForms) as $formCode) {
                    $questionLocale = $this->normalizeQuestionLocale($locale);
                    $questionParams = [
                        'locale' => $questionLocale,
                    ];
                    if ($formCode !== null) {
                        $questionParams['form_code'] = $formCode;
                    }
                    $questionsRequest = Request::create('/api/v0.3/scales/'.$scaleCode.'/questions', 'GET', $questionParams);

                    try {
                        $questionsResponse = $scalesController->questions(
                            $questionsRequest,
                            $scaleCode,
                            $questionsService,
                            $bigFivePackLoader,
                            $clinicalPackLoader,
                            $sds20PackLoader,
                            $eq60PackLoader,
                            $enneagramPackLoader,
                            $riasecPackLoader
                        );
                        $this->line(sprintf(
                            'questions scale=%s locale=%s form=%s status=%d cache=%s',
                            $scaleCode,
                            $questionLocale,
                            $formCode ?? 'default',
                            $questionsResponse->getStatusCode(),
                            $questionsResponse->headers->get('X-FAP-Cache', 'n/a')
                        ));
                        if ($questionsResponse->getStatusCode() !== 200) {
                            $failed = true;
                        }
                    } catch (Throwable $e) {
                        $this->error(sprintf(
                            'questions scale=%s locale=%s form=%s failed: %s',
                            $scaleCode,
                            $questionLocale,
                            $formCode ?? 'default',
                            $e->getMessage()
                        ));
                        $failed = true;
                    }
                }
            }
        }

        if ($failed) {
            $this->error('Question prewarm completed with failures.');

            return self::FAILURE;
        }

        $this->info('Question prewarm completed successfully.');

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function parseCsvOption(string $raw): array
    {
        $items = array_map(
            static fn (string $item): string => trim($item),
            explode(',', $raw)
        );

        return array_values(array_filter($items, static fn (string $item): bool => $item !== ''));
    }

    private function normalizeQuestionLocale(string $locale): string
    {
        $locale = strtolower(trim($locale));

        return match ($locale) {
            'zh', 'zh-cn', 'zh_cn' => 'zh-CN',
            'en', 'en-us', 'en_us' => 'en',
            default => $locale !== '' ? $locale : 'zh-CN',
        };
    }

    /**
     * @param  list<string>  $forms
     * @return list<string|null>
     */
    private function formsForScaleAndLocale(string $scaleCode, string $locale, array $forms, bool $usesDefaultForms): array
    {
        if ($scaleCode === 'MBTI' && ! $usesDefaultForms) {
            return $forms;
        }

        return match ($scaleCode) {
            'MBTI' => match ($this->normalizeQuestionLocale($locale)) {
                'zh-CN' => ['mbti_93'],
                'en' => ['mbti_144'],
                default => $forms,
            },
            'BIG5_OCEAN' => ['big5_120', 'big5_90'],
            'ENNEAGRAM' => ['enneagram_likert_105', 'enneagram_forced_choice_144'],
            'RIASEC' => ['riasec_60', 'riasec_140'],
            default => [null],
        };
    }
}
