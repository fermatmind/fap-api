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
use App\Services\Content\Sds20PackLoader;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Throwable;

final class MbtiPrewarm extends Command
{
    protected $signature = 'mbti:prewarm
        {--slug=mbti-personality-test-16-personality-types : MBTI landing slug to prewarm}
        {--locales=zh,en : Comma separated locales to prewarm}
        {--forms=mbti_93,mbti_144 : Comma separated form codes to prewarm}';

    protected $description = 'Prewarm MBTI lookup and questions caches for the hottest public locales/forms.';

    public function handle(): int
    {
        $slug = trim((string) $this->option('slug'));
        $locales = $this->parseCsvOption((string) $this->option('locales'));
        $forms = $this->parseCsvOption((string) $this->option('forms'));

        if ($slug === '' || $locales === [] || $forms === []) {
            $this->error('slug, locales and forms must not be empty.');

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

        $failed = false;

        foreach ($locales as $locale) {
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

            foreach ($forms as $formCode) {
                $questionLocale = $this->normalizeQuestionLocale($locale);
                $questionsRequest = Request::create('/api/v0.3/scales/MBTI/questions', 'GET', [
                    'locale' => $questionLocale,
                    'form_code' => $formCode,
                ]);

                try {
                    $questionsResponse = $scalesController->questions(
                        $questionsRequest,
                        'MBTI',
                        $questionsService,
                        $bigFivePackLoader,
                        $clinicalPackLoader,
                        $sds20PackLoader,
                        $eq60PackLoader,
                        $enneagramPackLoader
                    );
                    $this->line(sprintf(
                        'questions locale=%s form=%s status=%d cache=%s',
                        $questionLocale,
                        $formCode,
                        $questionsResponse->getStatusCode(),
                        $questionsResponse->headers->get('X-FAP-Cache', 'n/a')
                    ));
                    if ($questionsResponse->getStatusCode() !== 200) {
                        $failed = true;
                    }
                } catch (Throwable $e) {
                    $this->error(sprintf(
                        'questions locale=%s form=%s failed: %s',
                        $questionLocale,
                        $formCode,
                        $e->getMessage()
                    ));
                    $failed = true;
                }
            }
        }

        if ($failed) {
            $this->error('MBTI prewarm completed with failures.');

            return self::FAILURE;
        }

        $this->info('MBTI prewarm completed successfully.');

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
}
