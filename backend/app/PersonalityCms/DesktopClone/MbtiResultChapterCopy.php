<?php

declare(strict_types=1);

namespace App\PersonalityCms\DesktopClone;

use Illuminate\Support\Facades\Validator;
use RuntimeException;

/** Authored zh-CN copy compiled into the existing database publication package. */
final class MbtiResultChapterCopy
{
    public const CHAPTERS = ['career', 'growth', 'relationships'];

    /** @return array<string, array<string, mixed>> */
    public function load(): array
    {
        $path = base_path('content_assets/personality_public/mbti_result_chapters.zh-CN.v1.json');
        $package = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        Validator::make($package, [
            'schema' => ['required', 'in:mbti_result_chapter_copy.v1'],
            'locale' => ['required', 'in:zh-CN'],
            'revision' => ['required', 'integer', 'in:1'],
            'rows' => ['required', 'array', 'size:32'],
            'rows.*' => ['required', 'array:full_code,chapters,faq'],
            'rows.*.full_code' => ['required', 'string', 'distinct:strict', 'regex:/^[EI][SN][TF][JP]-[AT]$/D'],
            'rows.*.chapters' => ['required', 'array:career,growth,relationships', 'size:3'],
            'rows.*.chapters.*' => ['required', 'array', 'size:2'],
            'rows.*.chapters.*.*' => ['required', 'string', 'min:100', 'max:230'],
            'rows.*.faq' => ['required', 'array', 'size:4'],
            'rows.*.faq.*' => ['required', 'array:question,answer'],
            'rows.*.faq.*.question' => ['required', 'string', 'max:80'],
            'rows.*.faq.*.answer' => ['required', 'string', 'min:100', 'max:260'],
        ])->validate();

        return array_column($package['rows'], null, 'full_code');
    }

    /** @param array<string,mixed> $content @param array<string,mixed> $copy @return array<string,mixed> */
    public function apply(array $content, array $copy): array
    {
        foreach (self::CHAPTERS as $chapter) {
            if (! isset($content['chapters'][$chapter]['intro'])) {
                throw new RuntimeException('Missing existing MBTI chapter: '.$chapter);
            }
            $content['chapters'][$chapter]['intro'] = $copy['chapters'][$chapter];
        }
        $content['faq'] = $copy['faq'];

        return $content;
    }

    /** @param array<string,mixed> $content @return array<string,mixed> */
    public static function withoutEditorialSlots(array $content): array
    {
        unset($content['faq']);
        foreach (self::CHAPTERS as $chapter) {
            unset($content['chapters'][$chapter]['intro']);
        }

        return $content;
    }
}
