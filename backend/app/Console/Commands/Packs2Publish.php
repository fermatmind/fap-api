<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Content\Publisher\ContentPackV2Publisher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class Packs2Publish extends Command
{
    protected $signature = 'packs2:publish {--pack=} {--pack-version=v1} {--activate=1} {--source_commit=} {--source-dir=} {--compile=1} {--force-new-release=0} {--compare-and-swap=0} {--expected-previous-release-id=}';

    protected $description = 'Publish compiled content pack to packs2 storage and optionally activate it.';

    public function handle(ContentPackV2Publisher $publisher): int
    {
        $pack = strtoupper(trim((string) $this->option('pack')));
        $packVersion = trim((string) $this->option('pack-version'));

        if ($pack === '') {
            $this->error('--pack is required.');

            return self::FAILURE;
        }
        if ($packVersion === '') {
            $this->error('--pack-version is required.');

            return self::FAILURE;
        }

        if ((int) $this->option('compile') === 1) {
            $lintCode = $this->call('content:lint', [
                '--pack' => $pack,
                '--pack-version' => $packVersion,
            ]);
            if ($lintCode !== 0) {
                return $lintCode;
            }

            $compileCode = $this->call('content:compile', [
                '--pack' => $pack,
                '--pack-version' => $packVersion,
            ]);
            if ($compileCode !== 0) {
                return $compileCode;
            }
        }

        if (in_array($pack, [
            \App\Services\Content\BigFivePrivateResultCompileService::PACK_ID,
            \App\Services\Content\EnneagramPrivateResultCompileService::PACK_ID,
            \App\Services\Content\Eq60PackLoader::PACK_ID,
            \App\Services\Content\RiasecPrivateResultCompileService::PACK_ID,
        ], true)) {
            $compiledDir = trim((string) $this->option('source-dir'));
            if ($compiledDir === '') {
                $compiledDir = match ($pack) {
                    \App\Services\Content\RiasecPrivateResultCompileService::PACK_ID => base_path('content_assets/riasec/compiled'),
                    \App\Services\Content\EnneagramPrivateResultCompileService::PACK_ID => base_path('content_packs/ENNEAGRAM/v2/compiled'),
                    default => base_path("content_packs/{$pack}/{$packVersion}/compiled"),
                };
            }
            $manifest = json_decode((string) file_get_contents($compiledDir.'/manifest.json'), true);
            $compiledHash = strtolower(trim((string) ($manifest['compiled_hash'] ?? '')));
            $activeReleaseId = DB::table('content_pack_activations')
                ->where('pack_id', $pack)
                ->where('pack_version', $packVersion)
                ->value('release_id');
            $activeHash = is_string($activeReleaseId) && $activeReleaseId !== ''
                ? strtolower(trim((string) DB::table('content_pack_releases')->where('id', $activeReleaseId)->value('compiled_hash')))
                : '';
            if ((int) $this->option('force-new-release') !== 1
                && $compiledHash !== '' && hash_equals($compiledHash, $activeHash)) {
                $this->info('active canonical private result release already matches compiled hash.');

                return self::SUCCESS;
            }
        }

        $release = $publisher->publishCompiled($pack, $packVersion, [
            'source_commit' => trim((string) $this->option('source_commit')),
            'created_by' => 'packs2:publish',
            'source_compiled_dir' => trim((string) $this->option('source-dir')),
        ]);

        if ((int) $this->option('activate') === 1) {
            $publisher->activateRelease(
                (string) ($release['id'] ?? ''),
                trim((string) $this->option('expected-previous-release-id')),
                (int) $this->option('compare-and-swap') === 1,
            );
            $this->info('activated release_id='.(string) ($release['id'] ?? ''));
            if ($pack === \App\Services\Content\EnneagramPrivateResultCompileService::PACK_ID) {
                $zh = app(\App\Services\Content\EnneagramPrivateResultPackLoader::class)->load('zh-CN');
                $en = app(\App\Services\Content\EnneagramPrivateResultPackLoader::class)->load('en');
                if (data_get($zh, 'authority.release_id') !== ($release['id'] ?? null)
                    || data_get($zh, 'authority.source_hash') !== data_get($en, 'authority.source_hash')
                    || data_get($zh, 'authority.compiled_hash') !== data_get($en, 'authority.compiled_hash')) {
                    throw new \RuntimeException('ENNEAGRAM_PRIVATE_RESULT_ACTIVATION_READBACK_MISMATCH');
                }
                $this->line('source_hash='.(string) data_get($zh, 'authority.source_hash'));
                $this->line('compiled_hash='.(string) data_get($zh, 'authority.compiled_hash'));
                $this->line('locale_inventory=zh-CN,en');
                $this->line('form_projection_inventory=e105,fc144');
                $this->line('interpretation_state_inventory=clear,close_call,diffuse,low_quality');
                $this->line('surface_inventory=api,report,snapshot,pdf,print,history,compare,share,technical_note,observation,secondary');
            }
            if ($pack === \App\Services\Content\Eq60PackLoader::PACK_ID) {
                $zh = app(\App\Services\Content\Eq60PackLoader::class)->authority($packVersion, 'zh-CN');
                $en = app(\App\Services\Content\Eq60PackLoader::class)->authority($packVersion, 'en');
                if (($zh['release_id'] ?? null) !== ($release['id'] ?? null)
                    || ($zh['source_hash'] ?? null) !== ($en['source_hash'] ?? null)
                    || ($zh['compiled_hash'] ?? null) !== ($en['compiled_hash'] ?? null)) {
                    throw new \RuntimeException('EQ_60_PRIVATE_RESULT_ACTIVATION_READBACK_MISMATCH');
                }
                $this->line('source_hash='.(string) ($zh['source_hash'] ?? ''));
                $this->line('compiled_hash='.(string) ($zh['compiled_hash'] ?? ''));
                $this->line('locale_inventory=zh-CN,en');
                $this->line('compiled_inventory=8');
                $this->line('surface_inventory=api,report,snapshot,pdf,history,share,journey,agent,secondary');
            }
        }

        $this->line('release_id='.(string) ($release['id'] ?? ''));
        $this->line('pack='.(string) ($release['to_pack_id'] ?? $pack));
        $this->line('pack_version='.(string) ($release['pack_version'] ?? $packVersion));
        $this->line('manifest_hash='.(string) ($release['manifest_hash'] ?? ''));

        return self::SUCCESS;
    }
}
