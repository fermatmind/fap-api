<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\PersonalityResultIntroduction;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class MbtiResultIntroductionPublisher
{
    /** @return array{package_hash:string,rows:list<array<string,mixed>>} */
    public function package(): array
    {
        $rows = [];
        $fileHashes = [];
        foreach (['zh-CN', 'en'] as $locale) {
            $bytes = file_get_contents(base_path('content_assets/personality_public/mbti_result_introductions.'.$locale.'.v1.json'));
            $fileHashes[] = hash('sha256', $bytes);
            $document = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
            if ($document['schema'] !== 'mbti_result_introductions.v1' || $document['locale'] !== $locale || count($document['introductions']) !== 32) {
                throw new RuntimeException('Invalid MBTI introduction inventory.');
            }
            $seen = [];
            foreach ($document['introductions'] as $intro) {
                $code = $intro['full_code'];
                $paragraphs = $intro['paragraphs'];
                if (! preg_match('/^[EI][SN][TF][JP]-[AT]$/D', $code) || isset($seen[$code]) || ! is_array($paragraphs) || count($paragraphs) !== 2) {
                    throw new RuntimeException('Invalid MBTI introduction identity or paragraphs.');
                }
                foreach ($paragraphs as $paragraph) {
                    if (! is_string($paragraph) || trim($paragraph) === '') {
                        throw new RuntimeException('Empty MBTI introduction paragraph.');
                    }
                }
                $seen[$code] = true;
                $rows[] = [
                    'full_code' => $code,
                    'locale' => $locale,
                    'paragraphs' => array_values($paragraphs),
                    'content_hash' => hash('sha256', json_encode(array_values($paragraphs), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
                    'revision' => 1,
                    'status' => 'published',
                    'published_at' => '2026-09-12 00:00:00',
                ];
            }
        }

        return ['package_hash' => hash('sha256', implode('', $fileHashes)), 'rows' => $rows];
    }

    /** @return array{package_hash:string,record_count:int,created:int,writes:bool} */
    public function publish(string $expectedHash, bool $write): array
    {
        $package = $this->package();
        if (! hash_equals($package['package_hash'], $expectedHash)) {
            throw new RuntimeException('MBTI introduction package hash mismatch.');
        }

        return DB::transaction(function () use ($package, $write): array {
            $missing = [];
            foreach ($package['rows'] as $row) {
                $current = PersonalityResultIntroduction::query()
                    ->where('full_code', $row['full_code'])->where('locale', $row['locale'])
                    ->lockForUpdate()->first();
                if ($current === null) {
                    $missing[] = $row;
                } elseif ($current->paragraphs !== $row['paragraphs'] || $current->content_hash !== $row['content_hash'] || $current->revision !== 1 || $current->status !== 'published' || $current->published_at?->format('Y-m-d H:i:s') !== $row['published_at']) {
                    // This first release never overwrites an editor's existing asset.
                    throw new RuntimeException('Conflicting MBTI introduction: '.$row['full_code'].' '.$row['locale']);
                }
            }
            if ($write) {
                foreach ($missing as $row) {
                    PersonalityResultIntroduction::query()->create($row);
                }
                foreach ($package['rows'] as $row) {
                    $actual = PersonalityResultIntroduction::publishedFor($row['full_code'], $row['locale']);
                    if ($actual?->paragraphs !== $row['paragraphs'] || $actual?->content_hash !== $row['content_hash']) {
                        throw new RuntimeException('MBTI introduction readback mismatch.');
                    }
                }
            }

            return ['package_hash' => $package['package_hash'], 'record_count' => count($package['rows']), 'created' => $write ? count($missing) : 0, 'writes' => $write];
        });
    }
}
