<?php
// file: backend/app/Console/Commands/FapResolvePack.php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\ContentPackResolver;

class FapResolvePack extends Command
{
    protected $signature = 'fap:resolve-pack
        {scale_code}
        {region}
        {locale}
        {version}';

    protected $description = 'Resolve a content pack by (scale_code, region, locale, version) with fallback chain';

    public function handle(ContentPackResolver $resolver): int
    {
        $scale  = (string)$this->argument('scale_code');
        $region = (string)$this->argument('region');
        $locale = (string)$this->argument('locale');
        $ver    = $this->normalizeVersion((string)$this->argument('version'));

        $rp = $resolver->resolve($scale, $region, $locale, $ver);

        $this->info("RESOLVED pack_id={$rp->packId}");
        $this->line("base_dir={$rp->baseDir}");
        $this->line("manifest.version=" . ($rp->manifest['content_package_version'] ?? ''));
        $this->line("fallback_chain=" . implode(' -> ', array_map(fn($x)=>$x['pack_id'], $rp->fallbackChain)));

        $this->line("---- trace ----");
        $this->line(json_encode($rp->trace, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));

        return 0;
    }

    private function normalizeVersion(string $version): string
    {
        $version = trim($version);
        if ($version === '') {
            return $version;
        }

        if (preg_match('/^MBTI-CN-(v[0-9][A-Za-z0-9._-]*)$/', $version, $m) === 1) {
            return $m[1];
        }

        return $version;
    }
}
