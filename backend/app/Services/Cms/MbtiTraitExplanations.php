<?php

declare(strict_types=1);

namespace App\Services\Cms;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class MbtiTraitExplanations
{
    public const SCHEMA = 'mbti_trait_explanations.v1';

    /** Validate both package imports and authoritative database reads. */
    public function validate(mixed $document): array
    {
        if (! is_array($document) || ($document['schema'] ?? null) !== self::SCHEMA
            || ($document['locale'] ?? null) !== 'zh-CN' || ($document['revision'] ?? null) !== 1
            || ! is_array($document['entries'] ?? null) || count($document['entries']) !== 55) {
            throw new RuntimeException('Invalid MBTI trait catalog.');
        }
        $expected = [];
        foreach (['EI', 'SN', 'TF', 'JP', 'AT'] as $axis) {
            $expected[$axis.':balanced:50:50'] = true;
            foreach (str_split($axis) as $pole) {
                foreach ([[51, 59], [60, 69], [70, 79], [80, 89], [90, 100]] as [$min, $max]) {
                    $expected[$axis.':'.$pole.':'.$min.':'.$max] = true;
                }
            }
        }
        $texts = [];
        foreach ($document['entries'] as $entry) {
            if (! is_array($entry) || ! is_string($entry['axis_code'] ?? null)
                || ! is_string($entry['pole'] ?? null) || ! is_int($entry['min'] ?? null)
                || ! is_int($entry['max'] ?? null)) {
                throw new RuntimeException('Invalid MBTI trait identity.');
            }
            $key = $entry['axis_code'].':'.$entry['pole'].':'.$entry['min'].':'.$entry['max'];
            if (! isset($expected[$key])) {
                throw new RuntimeException('Missing, duplicate or unsupported MBTI trait band.');
            }
            unset($expected[$key]);
            foreach (['a', 'b'] as $part) {
                $text = $entry[$part] ?? null;
                if (! is_string($text) || trim($text) !== $text || mb_strlen($text) < 20
                    || preg_match('/[<>]/u', $text) || isset($texts[$text])) {
                    throw new RuntimeException('Invalid or duplicate MBTI trait copy.');
                }
                $texts[$text] = true;
            }
            if (count($entry) !== 6) {
                throw new RuntimeException('Unsupported MBTI trait field.');
            }
        }
        if ($expected !== [] || count($document) !== 5) {
            throw new RuntimeException('Incomplete MBTI trait catalog.');
        }

        $expectedCodes = [];
        foreach (str_split('EI') as $ei) {
            foreach (str_split('SN') as $sn) {
                foreach (str_split('TF') as $tf) {
                    foreach (str_split('JP') as $jp) {
                        foreach (str_split('AT') as $at) {
                            $expectedCodes[$ei.$sn.$tf.$jp.'-'.$at] = true;
                        }
                    }
                }
            }
        }
        if (! is_array($document['overviews'] ?? null) || count($document['overviews']) !== 32) {
            throw new RuntimeException('Invalid MBTI overview inventory.');
        }
        foreach ($document['overviews'] as $overview) {
            $code = $overview['full_code'] ?? '';
            if (! isset($expectedCodes[$code]) || count($overview) !== 2 || ! is_array($overview['paragraphs'] ?? null) || count($overview['paragraphs']) !== 2) {
                throw new RuntimeException('Invalid MBTI overview identity.');
            }
            unset($expectedCodes[$code]);
            foreach ($overview['paragraphs'] as $paragraph) {
                if (! is_string($paragraph) || mb_strlen($paragraph) < 60 || preg_match('/[<>]/u', $paragraph)) {
                    throw new RuntimeException('Invalid MBTI overview copy.');
                }
            }
        }

        return $document;
    }

    public function package(): array
    {
        $bytes = file_get_contents(base_path('content_assets/personality_public/mbti_trait_explanations.zh-CN.v1.json'));

        $overviewBytes = file_get_contents(base_path('content_assets/personality_public/mbti_trait_overviews.zh-CN.v1.json'));
        $overviews = json_decode($overviewBytes, true, flags: JSON_THROW_ON_ERROR);
        if (($overviews['schema'] ?? null) !== 'mbti_trait_overviews.v1' || ($overviews['locale'] ?? null) !== 'zh-CN') {
            throw new RuntimeException('Invalid MBTI overview package.');
        }
        $document = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
        $document['overviews'] = $overviews['overviews'] ?? null;

        return ['hash' => hash('sha256', hash('sha256', $bytes).hash('sha256', $overviewBytes)), 'document' => $this->validate($document)];
    }

    public function published(): ?array
    {
        $row = DB::table('mbti_trait_catalogs')->where('locale', 'zh-CN')->where('status', 'published')
            ->whereNotNull('published_at')->where('published_at', '<=', now())->first();
        if ($row === null) {
            return null;
        }
        if (! hash_equals($row->content_hash, hash('sha256', $row->document))) {
            throw new RuntimeException('MBTI trait catalog integrity mismatch.');
        }

        return [...$this->validate(json_decode($row->document, true, flags: JSON_THROW_ON_ERROR)), 'content_hash' => $row->content_hash];
    }

    /** Publish an exact package atomically; never overwrite an existing different catalog. */
    public function publish(string $expectedHash, bool $write): array
    {
        $package = $this->package();
        if (! hash_equals($package['hash'], $expectedHash)) {
            throw new RuntimeException('MBTI trait package hash mismatch.');
        }
        $document = json_encode($package['document'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return DB::transaction(function () use ($document, $write): array {
            $row = DB::table('mbti_trait_catalogs')->where('locale', 'zh-CN')->lockForUpdate()->first();
            if ($row !== null && ($row->document !== $document || $row->status !== 'published'
                || $row->content_hash !== hash('sha256', $document) || $row->published_at === null)) {
                throw new RuntimeException('Existing MBTI trait catalog conflicts with publication package.');
            }
            if ($write && $row === null) {
                DB::table('mbti_trait_catalogs')->insert([
                    'locale' => 'zh-CN', 'document' => $document, 'content_hash' => hash('sha256', $document),
                    'status' => 'published', 'published_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            if ($write && ($this->published()['content_hash'] ?? null) !== hash('sha256', $document)) {
                throw new RuntimeException('MBTI trait publication readback mismatch.');
            }

            return ['entries' => 55, 'overviews' => 32, 'created' => $write && $row === null ? 1 : 0, 'writes' => $write];
        });
    }
}
