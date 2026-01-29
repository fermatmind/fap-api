<?php

namespace App\Services\Content;

use App\Services\Assets\AssetUrlResolver;
use Illuminate\Support\Facades\Log;

final class AssetsMapper
{
    public function __construct(private AssetUrlResolver $resolver)
    {
    }

    /**
     * Map questions document assets to full URLs without changing structure.
     */
    public function mapQuestionsDoc(
        array $doc,
        string $packId,
        string $dirVersion,
        ?string $assetsBaseUrl = null
    ): array {
        if ($this->isList($doc)) {
            return $this->mapQuestionsList($doc, $packId, $dirVersion, $assetsBaseUrl);
        }

        if (isset($doc['items']) && is_array($doc['items'])) {
            $doc['items'] = $this->mapQuestionsList($doc['items'], $packId, $dirVersion, $assetsBaseUrl);
            return $doc;
        }

        if (isset($doc['questions']) && is_array($doc['questions'])) {
            $doc['questions'] = $this->mapQuestionsList($doc['questions'], $packId, $dirVersion, $assetsBaseUrl);
            return $doc;
        }

        if (isset($doc['data']) && is_array($doc['data'])) {
            $doc['data'] = $this->mapQuestionsList($doc['data'], $packId, $dirVersion, $assetsBaseUrl);
            return $doc;
        }

        return $doc;
    }

    private function mapQuestionsList(
        array $items,
        string $packId,
        string $dirVersion,
        ?string $assetsBaseUrl
    ): array {
        $out = [];
        foreach ($items as $i => $q) {
            if (!is_array($q)) {
                $out[$i] = $q;
                continue;
            }

            try {
                $out[$i] = $this->mapQuestion($q, $packId, $dirVersion, $assetsBaseUrl, $i);
            } catch (\Throwable $e) {
                Log::error('[AssetsMapper] question assets mapping failed', [
                    'pack_id' => $packId,
                    'dir_version' => $dirVersion,
                    'question_index' => $i,
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }
        }

        return $this->isList($items) ? array_values($out) : $out;
    }

    private function mapQuestion(
        array $q,
        string $packId,
        string $dirVersion,
        ?string $assetsBaseUrl,
        int $index
    ): array {
        $qid = $q['question_id'] ?? ($q['id'] ?? $index);
        $qidLabel = is_scalar($qid) ? (string) $qid : (string) $index;

        if (array_key_exists('assets', $q)) {
            $q['assets'] = $this->mapAssetsNode(
                $q['assets'],
                "question.assets (qid={$qidLabel})",
                $packId,
                $dirVersion,
                $assetsBaseUrl
            );
        }

        $stem = $q['stem'] ?? null;
        if (is_array($stem) && array_key_exists('assets', $stem)) {
            $stem['assets'] = $this->mapAssetsNode(
                $stem['assets'],
                "stem.assets (qid={$qidLabel})",
                $packId,
                $dirVersion,
                $assetsBaseUrl
            );
            $q['stem'] = $stem;
        }

        $opts = $q['options'] ?? null;
        if (is_array($opts)) {
            foreach ($opts as $i => $opt) {
                if (!is_array($opt) || !array_key_exists('assets', $opt)) continue;
                $opt['assets'] = $this->mapAssetsNode(
                    $opt['assets'],
                    "options[{$i}].assets (qid={$qidLabel})",
                    $packId,
                    $dirVersion,
                    $assetsBaseUrl
                );
                $opts[$i] = $opt;
            }
            $q['options'] = $opts;
        }

        return $q;
    }

    private function mapAssetsNode(
        mixed $assets,
        string $context,
        string $packId,
        string $dirVersion,
        ?string $assetsBaseUrl
    ): array {
        if (!is_array($assets)) {
            throw new \RuntimeException("{$context} must be object(map)");
        }

        $out = [];
        foreach ($assets as $k => $v) {
            if (!is_string($v)) {
                throw new \RuntimeException("{$context}.{$k} must be string");
            }
            $out[$k] = $this->resolver->resolve($packId, $dirVersion, $v, null, $assetsBaseUrl);
        }

        return $out;
    }

    private function isList(array $arr): bool
    {
        return $arr === [] || array_keys($arr) === range(0, count($arr) - 1);
    }
}
