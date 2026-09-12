<?php

declare(strict_types=1);

namespace App\Domain\Career\Publish;

use App\Domain\Career\Display\CareerPageProjector;
use App\Services\Career\CareerFilePageReader;
use App\Support\PublicProjectionCache;
use RuntimeException;
use Throwable;

/** Explicitly authorized Chinese staging pages; content remains in the installed locale file. */
final class CareerStagingAccountantPublication
{
    public const SLUG = 'accountants-and-auditors';

    public const LOCALE = 'zh-CN';

    public const SLUGS = [self::SLUG, 'actors'];

    private string $slug = self::SLUG;

    public function __construct(
        private readonly CareerGenerationAuthorityLoader $loader,
        private readonly CareerPageProjector $pages,
    ) {}

    public function publish(string $releaseSha, callable $verifyHttp, string $slug = self::SLUG): array
    {
        $this->selectSlug($slug);
        $this->assertStaging($releaseSha);
        // Resolve every display reference before creating a publication candidate.
        $page = $this->pages->read($this->slug, self::LOCALE);
        if (($page['locale'] ?? null) !== self::LOCALE
            || data_get($page, 'subject.canonical_slug') !== $this->slug
            || data_get($page, 'display.contract_version') !== 'career.detail.display.v1'
            || ($this->slug === self::SLUG && data_get($page, 'hero.ai.availability') !== 'available')
            || ($this->slug === 'actors' && ! in_array(data_get($page, 'hero.ai.availability'), ['available', 'missing'], true))
            || count(data_get($page, 'hero.badges', [])) !== 3) {
            throw new RuntimeException('staging_accountant_file_incomplete');
        }

        return $this->withLock(function () use ($releaseSha, $page, $verifyHttp): array {
            $before = $this->loader->loadStrict();
            $items = $before['projection']['items'];
            $target = null;
            foreach ($items as $index => $item) {
                if ($item['slug'] === $this->slug && $item['locale'] === 'zh') {
                    $target = $index;
                }
            }
            if ($target === null) {
                throw new RuntimeException('staging_accountant_publication_record_missing');
            }
            $item = $items[$target];
            if (self::published($item)) {
                $verifyHttp($page);

                return ['status' => 'already_published', 'changed_locale_rows' => 0];
            }
            if ($item['runtime_publish_state'] !== 'blocked' || ($item['blockers'] ?? []) !== []) {
                throw new RuntimeException('staging_accountant_unexpected_publication_state');
            }
            $items[$target] = array_replace($item, [
                'public_resolution_type' => 'public_canonical_job',
                'runtime_publish_state' => 'published',
                'detail_route_enabled' => true,
                'canonical_url' => 'https://fermatmind.com/zh/career/jobs/'.$this->slug,
                'canonical_self' => true,
                'robots_indexable' => true,
                'release_gate_pass' => true,
            ]);
            // Dataset, search, sitemap and llms flags, English and all other rows stay identical.
            $activePath = $this->root().'/'.CareerGenerationAuthorityLoader::ACTIVE_POINTER_FILENAME;
            $oldBytes = $this->read($activePath);
            $candidate = $this->candidate($before, $items, $releaseSha, $page, $oldBytes);
            $this->loader->loadGeneration($candidate['generation_id']);
            $this->replaceActive($oldBytes, $candidate['bytes']);
            try {
                $this->loader->loadStrict();
                PublicProjectionCache::forget(CareerFilePageReader::cacheKey($page));
                $verifyHttp($page);
            } catch (Throwable $error) {
                $this->replaceActive($candidate['bytes'], $oldBytes);
                $this->loader->loadStrict();
                throw $error;
            }

            return ['status' => 'published', 'changed_locale_rows' => 1];
        });
    }

    /** A later deploy failure may restore only the publication version owned by that release. */
    public function rollback(string $releaseSha, string $slug = self::SLUG): void
    {
        $this->selectSlug($slug);
        $this->assertStaging($releaseSha);
        $this->withLock(function () use ($releaseSha): void {
            $active = $this->loader->loadStrict();
            if ($active['pointer']['generation_id'] !== $this->generationId($releaseSha)) {
                return;
            }
            $previousId = $active['pointer']['lineage']['previous_generation_id'];
            $this->loader->loadGeneration($previousId);
            $oldBytes = $this->read($this->root().'/generations/'.$previousId.'/generation-pointer.json');
            $activeBytes = $this->read($this->root().'/active-generation.json');
            $this->replaceActive($activeBytes, $oldBytes);
            $this->loader->loadStrict();
        });
    }

    /** Compare the complete API page, including all display fields, body items and sources. */
    public static function assertResponse(int $status, mixed $body, array $expected): void
    {
        if ($status !== 200 || ! is_array($body)
            || ($body['bundle_kind'] ?? null) !== 'career_job_detail'
            || ! in_array(data_get($expected, 'subject.canonical_slug'), self::SLUGS, true)
            || data_get($body, 'identity.canonical_slug') !== data_get($expected, 'subject.canonical_slug')
            || data_get($body, 'locale_policy.requested_locale') !== self::LOCALE
            || CareerGenerationCanonicalJson::sha256($body['career_page'] ?? null)
                !== CareerGenerationCanonicalJson::sha256($expected)) {
            throw new RuntimeException('staging_accountant_api_smoke_failed');
        }
    }

    public static function assertWebResponse(int $status, string $html, array $page, string $revision): void
    {
        $rendered = preg_replace('~<script\b[^>]*>.*?</script>~is', '', $html) ?? '';
        $normalize = static fn (string $text): string => preg_replace('/\s+/u', '', html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '';
        if ($status !== 200 || ! preg_match('/^[a-f0-9]{40}$/', $revision)
            || ! str_contains($rendered, 'data-career-renderer-release="'.$revision.'"')
            || ! str_contains($rendered, 'data-career-production-template="career-production-v1"')) {
            throw new RuntimeException('staging_accountant_web_smoke_failed');
        }
        foreach (['career-production-ai-gauge', 'career-production-hero-badges', 'career-dossier-toc', 'career-display-faq'] as $marker) {
            if (! str_contains($rendered, 'data-testid="'.$marker.'"')) {
                throw new RuntimeException('staging_accountant_web_smoke_failed');
            }
        }
        $expected = [$page['subject']['name'], ...$page['hero']['badges'], $page['display']['components']['hero']['quick_answer'],
            $page['display']['components']['definition_block'], $page['display']['components']['faq_block']['items'][0]['question']];
        foreach ($page['hero']['metrics'] as $metric) {
            if ($metric['availability'] === 'available') {
                $expected[] = $metric['fact']['display_value'];
            }
        }
        $visible = $normalize($rendered);
        foreach ($expected as $text) {
            if (! is_string($text) || trim($text) === '' || ! str_contains($visible, $normalize($text))) {
                throw new RuntimeException('staging_accountant_web_smoke_failed');
            }
        }
    }

    private function candidate(array $before, array $items, string $sha, array $page, string $oldBytes): array
    {
        $id = $this->generationId($sha);
        $directory = $this->root().'/generations/'.$id;
        if (file_exists($directory) || is_link($directory) || ! mkdir($directory, 0750)) {
            throw new RuntimeException('staging_accountant_candidate_collision');
        }
        $pointer = $before['pointer'];
        $authority = $pointer['authority'];
        $projection = $before['projection'];
        $projection['items'] = $items;
        // Recount existing summary fields without changing the published URL inventory flags.
        foreach ($projection['counts'] ?? [] as $key => $value) {
            if (in_array($key, ['blocked', 'published_candidate', 'published', 'quarantined'], true)) {
                $projection['counts'][$key] = count(array_filter($items, fn (array $row): bool => $row['runtime_publish_state'] === $key));
            } elseif ($key === 'canonical_published') {
                $projection['counts'][$key] = count(array_filter($items, fn (array $row): bool => ($row['public_resolution_type'] ?? null) === 'public_canonical_job' && $row['runtime_publish_state'] === 'published'));
            } elseif (array_key_exists($key, $items[0]) && is_bool($items[0][$key])) {
                $projection['counts'][$key] = count(array_filter($items, fn (array $row): bool => ($row[$key] ?? false) === true));
            }
        }
        $artifacts = [];
        foreach (['projection' => $projection, 'ledger' => $before['ledger']] as $kind => $document) {
            $name = $kind === 'projection' ? CareerRuntimePublishProjectionExporter::PROJECTION_FILENAME : CareerFullReleaseLedgerProjectionService::LEDGER_FILENAME;
            $identity = ($kind === 'projection' ? 'career-runtime-publish-projection@' : 'career-full-release-ledger@').$id;
            $document['generation_id'] = $id;
            $document['artifact_identity'] = $identity;
            $document['generation_authority'] = $authority;
            $bytes = CareerGenerationCanonicalJson::encode($document)."\n";
            $this->writeNew($directory.'/'.$name, $bytes);
            $artifacts[$kind] = ['identity' => $identity, 'path' => 'generations/'.$id.'/'.$name, 'sha256' => hash('sha256', $bytes)];
        }
        $publicItems = array_filter($items, fn (array $row): bool => $row['runtime_publish_state'] === 'published');
        $previousId = $pointer['generation_id'];
        $pointer = array_replace($pointer, [
            'generation_id' => $id,
            'artifact_format' => CareerGenerationAuthorityLoader::ARTIFACT_FORMAT_GENERATION_NATIVE,
            'artifacts' => $artifacts,
            'counts' => ['public_slug_count' => count(array_unique(array_column($publicItems, 'slug'))), 'public_locale_row_count' => count($publicItems)],
            'lineage' => ['previous_generation_id' => $previousId, 'previous_pointer_sha256' => CareerGenerationCanonicalJson::sha256(json_decode($oldBytes, true, 512, JSON_THROW_ON_ERROR))],
            'rollback' => ['eligible' => true, 'previous_generation_id' => $previousId],
            'timestamps' => ['created_at' => gmdate('Y-m-d\TH:i:s\Z'), 'activated_at' => gmdate('Y-m-d\TH:i:s\Z')],
            'activation_receipt' => ['identity' => 'activation:'.$id, 'sha256' => CareerGenerationCanonicalJson::sha256([
                'release_sha' => $sha, 'slug' => $this->slug, 'locale' => self::LOCALE,
                'source_content_sha256' => $page['source_content_sha256'], 'display_sha256' => CareerGenerationCanonicalJson::sha256($page['display']),
            ])],
            'revocation_receipt' => null,
        ]);
        $bytes = CareerGenerationCanonicalJson::encode([
            'schema_version' => CareerGenerationAuthorityLoader::POINTER_SCHEMA_VERSION,
            'payload_sha256' => CareerGenerationCanonicalJson::sha256($pointer), 'payload' => $pointer,
        ])."\n";
        $this->writeNew($directory.'/generation-pointer.json', $bytes);

        return ['generation_id' => $id, 'bytes' => $bytes];
    }

    /** The separately checked staging file page is outside the legacy bilingual directory/cache cohort. */
    public static function hasDedicatedStagingSmoke(array $item): bool
    {
        return in_array($item['slug'] ?? null, self::SLUGS, true) && ($item['locale'] ?? null) === 'zh'
            && self::published($item) && ($item['dataset_visible'] ?? null) === false
            && ($item['search_visible'] ?? null) === false;
    }

    private static function published(array $item): bool
    {
        return $item['runtime_publish_state'] === 'published' && ($item['detail_route_enabled'] ?? false) === true
            && ($item['robots_indexable'] ?? false) === true && ($item['release_gate_pass'] ?? false) === true;
    }

    private function selectSlug(string $slug): void
    {
        if (! in_array($slug, self::SLUGS, true)) {
            throw new RuntimeException('staging_accountant_target_invalid');
        }
        $this->slug = $slug;
    }

    private function assertStaging(string $sha): void
    {
        if (! app()->environment('staging') || ! preg_match('/^[a-f0-9]{40}$/', $sha)) {
            throw new RuntimeException('staging_accountant_environment_or_revision_invalid');
        }
    }

    private function generationId(string $sha): string
    {
        return ($this->slug === self::SLUG ? 'staging-accountant-zh-' : 'staging-actor-zh-').$sha;
    }

    private function root(): string
    {
        return storage_path('app/private/career_generation_authority');
    }

    private function withLock(callable $operation): mixed
    {
        $this->loader->loadStrict();
        $path = $this->root().'/.staging-accountant-publication.lock';
        if (is_link($path)) {
            throw new RuntimeException('staging_accountant_lock_invalid');
        }
        $lock = fopen($path, 'c');
        if ($lock === false) {
            throw new RuntimeException('staging_accountant_lock_unavailable');
        }
        try {
            if (! flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('staging_accountant_publication_busy');
            }

            return $operation();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function read(string $path): string
    {
        if (is_link($path) || ! is_file($path) || ($bytes = file_get_contents($path)) === false) {
            throw new RuntimeException('staging_accountant_pointer_unreadable');
        }

        return $bytes;
    }

    private function writeNew(string $path, string $bytes): void
    {
        $file = fopen($path, 'x');
        if ($file === false) {
            throw new RuntimeException('staging_accountant_candidate_write_failed');
        }
        try {
            if (fwrite($file, $bytes) !== strlen($bytes) || ! fflush($file) || ! fsync($file)) {
                throw new RuntimeException('staging_accountant_candidate_write_failed');
            }
        } finally {
            fclose($file);
        }
        chmod($path, 0640);
    }

    private function replaceActive(string $expected, string $bytes): void
    {
        $active = $this->root().'/active-generation.json';
        if ($this->read($active) !== $expected) {
            throw new RuntimeException('staging_accountant_active_pointer_changed');
        }
        $candidate = $this->root().'/.active-generation.json.candidate.'.bin2hex(random_bytes(8));
        $this->writeNew($candidate, $bytes);
        if ($this->read($active) !== $expected || ! rename($candidate, $active)) {
            unlink($candidate);
            throw new RuntimeException('staging_accountant_pointer_switch_failed');
        }
    }
}
