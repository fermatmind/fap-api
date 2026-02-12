<?php
declare(strict_types=1);
namespace App\Services\Report\Composer;
use App\DTO\ResolvedPack;
use App\Services\Content\ContentPack;
use App\Services\Content\ContentPacksIndex;
use App\Services\ContentPackResolver;
use Illuminate\Support\Facades\Log;
class ReportPackChainLoader
{
    public function __construct(
        private readonly ContentPackResolver $resolver,
        private readonly ContentPacksIndex $packsIndex,
    ) {
    }
    public function buildPackChain(ReportComposeContext $ctx): array
    {
        $contentPackageVersion = $this->normalizeRequestedVersion($ctx->dirVersion) ?? '';
        if ($contentPackageVersion === '') {
            $contentPackageVersion = $this->normalizeRequestedVersion($ctx->packId) ?? '';
        }
        if ($ctx->packId !== '' && $ctx->dirVersion !== '') {
            $found = $this->packsIndex->find($ctx->packId, $ctx->dirVersion);
            if (($found['ok'] ?? false) === true) {
                $item = is_array($found['item'] ?? null) ? $found['item'] : [];
                if ($contentPackageVersion === '') {
                    $contentPackageVersion = (string) ($item['content_package_version'] ?? '');
                }
            }
        }
        if ($contentPackageVersion === '') {
            $contentPackageVersion = (string) ($ctx->options['content_package_version'] ?? '');
        }
        $resolved = $this->resolver->resolve(
            $ctx->scaleCode,
            $ctx->region,
            $ctx->locale,
            $contentPackageVersion,
            $ctx->dirVersion
        );
        return $this->toContentPackChain($resolved);
    }
    public function loadRules(ReportComposeContext $ctx, array $chain): ?array
    {
        return $this->loadReportRulesDocFromPackChain($chain);
    }
    public function loadSectionPolicies(ReportComposeContext $ctx, array $chain): ?array
    {
        return $this->loadSectionPoliciesDocFromPackChain($chain);
    }
    public function loadOverridesDocs(ReportComposeContext $ctx, array $chain): array
    {
        $docs = [];
        $idx = 0;
        foreach ($chain as $p) {
            if (!$p instanceof ContentPack) {
                continue;
            }
            $assetVal = $p->assets()['overrides'] ?? null;
            if (!is_array($assetVal) || $assetVal === []) {
                continue;
            }
            $orderedPaths = $this->getOverridesOrderedPaths($assetVal);
            foreach ($orderedPaths as $rel) {
                if (!is_string($rel) || trim($rel) === '') {
                    continue;
                }
                $abs = rtrim($p->basePath(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $rel;
                if (!is_file($abs)) {
                    continue;
                }
                $json = json_decode((string) file_get_contents($abs), true);
                if (!is_array($json)) {
                    continue;
                }
                if (!is_array($json['rules'] ?? null) && is_array($json['overrides'] ?? null)) {
                    $json['rules'] = $json['overrides'];
                }
                $src = [
                    'idx' => $idx,
                    'pack_id' => $p->packId(),
                    'version' => $p->version(),
                    'file' => basename($rel),
                    'rel' => $rel,
                    'path' => $abs,
                ];
                $json['__src'] = $src;
                if (is_array($json['rules'] ?? null)) {
                    foreach ($json['rules'] as &$r) {
                        if (is_array($r)) {
                            $r['__src'] = $src;
                        }
                    }
                    unset($r);
                }
                $docs[] = $json;
                $idx++;
            }
        }
        return $docs;
    }
    public function loadTypeProfile(ReportComposeContext $ctx, array $chain, string $typeCode): ?array
    {
        $doc = $this->loadJsonDocFromPackChain($chain, 'type_profiles', 'type_profiles.json');
        if (!is_array($doc)) {
            return null;
        }
        $items = is_array($doc['items'] ?? null) ? $doc['items'] : $doc;
        if (!is_array($items)) {
            return null;
        }
        if (is_array($items[$typeCode] ?? null)) {
            return $items[$typeCode];
        }
        $isList = array_keys($items) === range(0, count($items) - 1);
        if ($isList) {
            foreach ($items as $row) {
                if (!is_array($row)) {
                    continue;
                }
                if ((string) ($row['type_code'] ?? '') === $typeCode) {
                    return $row;
                }
            }
        }
        return null;
    }
    public function loadCard(ReportComposeContext $ctx, array $chain, string $relPath): ?array
    {
        foreach ($chain as $p) {
            if (!$p instanceof ContentPack) {
                continue;
            }
            $abs = rtrim($p->basePath(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($relPath, '/');
            if (!is_file($abs)) {
                continue;
            }
            $json = json_decode((string) file_get_contents($abs), true);
            if (is_array($json)) {
                return $json;
            }
        }
        return null;
    }
    public function getOverridesOrderBucketsFromPackChain(array $chain): array
    {
        foreach ($chain as $p) {
            if (!$p instanceof ContentPack) {
                continue;
            }
            $assetVal = $p->assets()['overrides'] ?? null;
            if (!is_array($assetVal) || $assetVal === []) {
                continue;
            }
            return $this->getOverridesOrderBuckets($assetVal);
        }
        return ['highlights_legacy', 'unified'];
    }
    public function packIdToDir(string $packId): string
    {
        $s = trim($packId);
        if ($s === '') {
            return '';
        }
        if (substr_count($s, '.') >= 3) {
            $parts = explode('.', $s);
            $scale = $parts[0] ?? 'MBTI';
            $region = strtoupper($parts[1] ?? 'GLOBAL');
            $locale = $parts[2] ?? 'en';
            $ver = implode('.', array_slice($parts, 3));
            return "{$scale}/{$region}/{$locale}/{$ver}";
        }
        if (str_contains($s, '/')) {
            return trim($s, '/');
        }
        return $s;
    }
    private function normalizeRequestedVersion($requested): ?string
    {
        if (!is_string($requested) || $requested === '') {
            return null;
        }
        if (substr_count($requested, '.') >= 3) {
            $parts = explode('.', $requested);
            return implode('.', array_slice($parts, 3));
        }
        $pos = strripos($requested, '-v');
        if ($pos !== false) {
            return substr($requested, $pos + 1);
        }
        return $requested;
    }
    private function toContentPackChain(ResolvedPack $rp): array
    {
        $make = static function (array $manifest, string $baseDir): ContentPack {
            return new ContentPack(
                packId: (string) ($manifest['pack_id'] ?? ''),
                scaleCode: (string) ($manifest['scale_code'] ?? ''),
                region: (string) ($manifest['region'] ?? ''),
                locale: (string) ($manifest['locale'] ?? ''),
                version: (string) ($manifest['content_package_version'] ?? ''),
                basePath: $baseDir,
                manifest: $manifest,
            );
        };
        $out = [];
        $out[] = $make($rp->manifest ?? [], (string) ($rp->baseDir ?? ''));
        $fbs = $rp->fallbackChain ?? [];
        if (is_array($fbs)) {
            foreach ($fbs as $fb) {
                if (!is_array($fb)) {
                    continue;
                }
                $m = is_array($fb['manifest'] ?? null) ? $fb['manifest'] : [];
                $d = (string) ($fb['base_dir'] ?? '');
                if ($m && $d !== '') {
                    $out[] = $make($m, $d);
                }
            }
        }
        return $out;
    }
    private function loadJsonDocFromPackChain(array $chain, string $assetKey, string $wantedBasename): ?array
    {
        foreach ($chain as $p) {
            if (!$p instanceof ContentPack) {
                continue;
            }
            $paths = $this->flattenAssetPaths($p->assets()[$assetKey] ?? null);
            foreach ($paths as $rel) {
                if (!is_string($rel) || trim($rel) === '') {
                    continue;
                }
                if (basename($rel) !== $wantedBasename) {
                    continue;
                }
                $abs = rtrim($p->basePath(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $rel;
                if (!is_file($abs)) {
                    continue;
                }
                $json = json_decode((string) file_get_contents($abs), true);
                if (is_array($json)) {
                    return $json;
                }
            }
        }
        return null;
    }
    private function loadReportRulesDocFromPackChain(array $chain): ?array
    {
        foreach ($chain as $p) {
            if (!$p instanceof ContentPack) {
                continue;
            }
            $abs = rtrim($p->basePath(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'report_rules.json';
            if (!is_file($abs)) {
                continue;
            }
            $j = json_decode((string) file_get_contents($abs), true);
            if (!is_array($j)) {
                continue;
            }
            $j['__src'] = [
                'pack_id' => $p->packId(),
                'version' => $p->version(),
                'file' => 'report_rules.json',
                'rel' => 'report_rules.json',
                'path' => $abs,
            ];
            return $j;
        }
        return null;
    }
    private function loadSectionPoliciesDocFromPackChain(array $chain): ?array
    {
        foreach ($chain as $p) {
            if (!$p instanceof ContentPack) {
                continue;
            }
            $abs = rtrim($p->basePath(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'report_section_policies.json';
            if (!is_file($abs)) {
                continue;
            }
            $j = json_decode((string) file_get_contents($abs), true);
            if (!is_array($j)) {
                continue;
            }
            $j['__src'] = [
                'pack_id' => $p->packId(),
                'version' => $p->version(),
                'file' => 'report_section_policies.json',
                'rel' => 'report_section_policies.json',
                'path' => $abs,
            ];
            return $j;
        }
        return null;
    }
    private function flattenAssetPaths($assetVal): array
    {
        if (!is_array($assetVal)) {
            return [];
        }
        if ($this->isListArray($assetVal)) {
            return array_values(array_filter($assetVal, fn ($x) => is_string($x) && trim($x) !== ''));
        }
        $out = [];
        foreach ($assetVal as $k => $v) {
            if ($k === 'order') {
                continue;
            }
            $list = is_array($v) ? $v : [$v];
            foreach ($list as $x) {
                if (is_string($x) && trim($x) !== '') {
                    $out[] = $x;
                }
            }
        }
        return array_values(array_unique($out));
    }
    private function isListArray(array $a): bool
    {
        if ($a === []) {
            return true;
        }
        return array_keys($a) === range(0, count($a) - 1);
    }
    private function getOverridesOrderedPaths(array $assetVal): array
    {
        if ($this->isListArray($assetVal)) {
            return array_values(array_filter($assetVal, fn ($x) => is_string($x) && trim($x) !== ''));
        }
        $order = $assetVal['order'] ?? null;
        $out = [];
        if (is_array($order) && $order !== []) {
            foreach ($order as $bucket) {
                if (!is_string($bucket) || $bucket === '') {
                    continue;
                }
                $v = $assetVal[$bucket] ?? null;
                if (!is_array($v)) {
                    continue;
                }
                foreach ($v as $path) {
                    if (is_string($path) && trim($path) !== '') {
                        $out[] = $path;
                    }
                }
            }
            return array_values(array_unique($out));
        }
        foreach ($assetVal as $k => $v) {
            if ($k === 'order' || !is_array($v)) {
                continue;
            }
            foreach ($v as $path) {
                if (is_string($path) && trim($path) !== '') {
                    $out[] = $path;
                }
            }
        }
        return array_values(array_unique($out));
    }
    private function getOverridesOrderBuckets(array $assetVal): array
    {
        if ($this->isListArray($assetVal)) {
            return ['unified'];
        }
        $order = $assetVal['order'] ?? null;
        if (is_array($order) && $order !== []) {
            $out = [];
            foreach ($order as $x) {
                if (is_string($x) && trim($x) !== '') {
                    $out[] = $x;
                }
            }
            return $out ?: ['highlights_legacy', 'unified'];
        }
        $out = [];
        foreach ($assetVal as $k => $_) {
            if ($k === 'order') {
                continue;
            }
            if (is_string($k) && trim($k) !== '') {
                $out[] = $k;
            }
        }
        return $out ?: ['highlights_legacy', 'unified'];
    }
}
