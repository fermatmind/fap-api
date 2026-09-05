<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\UrlTruth;

use App\Services\SeoIntel\Sources\CurrentPublicUrlAuthoritySource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class UrlTruthReconciliationRuntimeService
{
    public function __construct(
        private readonly CurrentPublicUrlAuthoritySource $authoritySource,
        private readonly BoundedPublicUrlEvidenceProbe $publicProbe,
        private readonly UrlTruthReconciliationSnapshot $snapshot,
    ) {}

    /** @return array<string,mixed> */
    public function read(
        bool $probeLiveHttp = true,
        ?string $resumeCursor = null,
        int $limit = 50,
        int $concurrency = 4,
        int $timeoutSeconds = 10,
        int $maxRetries = 1,
    ): array {
        $authorityState = 'available';
        try {
            $authority = $this->authoritySource->candidates();
        } catch (Throwable) {
            $authority = [];
            $authorityState = 'measurement_hold';
        }

        [$truthRows, $entityRows] = $this->readUrlTruthTables();
        $probe = [
            'consumer_urls' => ['public_api' => null, 'sitemap' => null, 'llms' => null, 'llms_full' => null],
            'live_http' => [
                'state' => 'measurement_hold',
                'reason' => $probeLiveHttp ? 'authority_source_unavailable' : 'live_probe_disabled',
                'bounded' => true,
                'requested_count' => 0,
                'next_resume_cursor' => $resumeCursor,
                'complete' => false,
            ],
        ];
        if ($probeLiveHttp && $authorityState === 'available') {
            try {
                $probe = $this->publicProbe->collect(
                    $authority,
                    $resumeCursor,
                    $limit,
                    $concurrency,
                    $timeoutSeconds,
                    $maxRetries,
                );
            } catch (Throwable) {
                $probe['live_http']['reason'] = 'bounded_live_probe_unavailable';
            }
        }

        return $this->snapshot->build(
            $authority,
            $truthRows,
            $entityRows,
            $probe['consumer_urls'],
            $probe['live_http'],
            ['authority' => $authorityState],
        );
    }

    /** @return array{0:list<array<string,mixed>>|null,1:list<array<string,mixed>>|null} */
    private function readUrlTruthTables(): array
    {
        $connectionName = (string) config('seo_intel.connection', 'seo_intel');
        try {
            $schema = Schema::connection($connectionName);
            if (! \App\Support\SchemaBaseline::tableExists('seo_urls', $schema->getConnection()->getName()) || ! \App\Support\SchemaBaseline::tableExists('seo_url_entities', $schema->getConnection()->getName())) {
                return [null, null];
            }
            $connection = DB::connection($connectionName);

            return [
                $connection->table('seo_urls')->orderBy('canonical_url_hash')->get()->map(
                    static fn (object $row): array => (array) $row,
                )->all(),
                $connection->table('seo_url_entities')->orderBy('canonical_url_hash')->get()->map(
                    static fn (object $row): array => (array) $row,
                )->all(),
            ];
        } catch (Throwable) {
            return [null, null];
        }
    }
}
