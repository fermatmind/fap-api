<?php

declare(strict_types=1);

namespace App\Filament\Ops\Support;

use App\Models\Article;
use App\Services\Cms\SeoContentPackage\SeoContentPackageExistingArticleUpdater;
use App\Support\ArticleSourceTargetSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JsonException;

final class ArticleExistingPackageWorkspace
{
    public const MAX_INPUT_BYTES = 3000000;

    public function __construct(private readonly SeoContentPackageExistingArticleUpdater $updater) {}

    /** Import a native existing-article package into the selected working revision only. */
    public function import(Article $selected, string $input): array
    {
        abort_unless(ContentAccess::canWrite(), 403);
        $this->require(strlen($input) <= self::MAX_INPUT_BYTES, 'input_too_large');
        $envelope = $this->json($input);
        $this->require(($envelope['format'] ?? null) === 'article.existing-update.transport.v1', 'invalid_format');
        $manifestBytes = $this->decode($envelope['manifest_base64'] ?? null);
        $this->require($this->digestMatches($manifestBytes, $envelope['manifest_sha256'] ?? null), 'manifest_digest_mismatch');
        $manifest = $this->json($manifestBytes);
        $files = $this->verifiedFiles($manifest, $envelope['files'] ?? null);
        $this->assertNativeFileReferences($files);

        // Uploaded bytes never name a server path. All files live in one private,
        // bounded workspace and are removed on success, rejection, or exception.
        $root = storage_path('app/private/article-update-inputs/'.Str::uuid());
        try {
            File::makeDirectory($root, 0700, true);
            foreach ($files as $path => $bytes) {
                File::ensureDirectoryExists(dirname($root.'/'.$path), 0700);
                $this->require(file_put_contents($root.'/'.$path, $bytes) === strlen($bytes), 'temporary_file_write_failed');
            }

            return DB::transaction(function () use ($selected, $manifest, $root): array {
                $article = Article::withoutGlobalScopes()->whereKey($selected->getKey())->lockForUpdate()->firstOrFail();
                try {
                    ArticleSourceTargetSnapshot::assertPublicationHash($article, (string) ($manifest['expected_article_state_sha256'] ?? ''), true);
                } catch (\RuntimeException) {
                    $this->require(false, 'article_update_baseline_changed');
                }
                $this->assertTarget($article, $manifest);
                $options = $this->options($root, $manifest);
                $plan = $this->updater->planFromDirectory($options);
                $this->assertPlan($plan);
                $result = $this->updater->updateWorkingRevisionFromDirectory([...$options, 'execute' => true, 'dry_run' => false]);
                $this->assertPlan($result);

                // Native code owns the import gate, review status and transaction.
                // No package payload or server directory is returned to Livewire.
                return $result['articles'][0];
            });
        } finally {
            File::deleteDirectory($root);
        }
    }

    private function assertTarget(Article $article, array $manifest): void
    {
        $expected = [
            'article_id' => (int) $article->id,
            'org_id' => 0,
            'expected_org_id' => 0,
            'locale' => (string) $article->locale,
            'expected_slug' => (string) $article->slug,
            'translation_group_id' => (string) $article->translation_group_id,
            'expected_working_revision_id' => (int) $article->working_revision_id,
            'expected_published_revision_id' => (int) $article->published_revision_id,
        ];
        $this->require((int) $article->org_id === 0 && $article->published_revision_id !== null, 'invalid_selected_article');
        foreach ($expected as $key => $value) {
            $this->require(array_key_exists($key, $manifest) && $manifest[$key] === $value, 'target_mismatch:'.$key);
        }
        foreach (['expected_working_body_sha256', 'expected_published_body_sha256'] as $key) {
            $this->require(is_string($manifest[$key] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $manifest[$key]) === 1, 'baseline_required:'.$key);
        }
        $this->require(is_string($manifest['expected_canonical'] ?? null) && $manifest['expected_canonical'] !== '', 'canonical_required');
    }

    private function options(string $root, array $manifest): array
    {
        return array_intersect_key($manifest, array_flip([
            'article_id', 'locale', 'translation_group_id', 'expected_slug', 'expected_canonical',
            'expected_org_id', 'expected_working_revision_id', 'expected_published_revision_id',
            'expected_working_body_sha256', 'expected_published_body_sha256',
        ])) + [
            'package' => $root, 'dry_run' => true, 'execute' => false,
            'slug_lock' => true, 'canonical_lock' => true, 'schema_hold' => true,
            'hreflang_hold' => true, 'search_hold' => true, 'no_revalidation' => true,
            'no_sitemap' => true, 'no_llms' => true,
        ];
    }

    private function verifiedFiles(array $manifest, mixed $input): array
    {
        $declared = $manifest['files'] ?? null;
        $this->require(is_array($declared) && array_is_list($declared) && count($declared) >= 7 && count($declared) <= 12, 'invalid_file_manifest');
        $this->require(is_array($input) && array_is_list($input) && count($input) === count($declared), 'file_inventory_mismatch');
        $uploads = [];
        foreach ($input as $file) {
            $this->require(is_array($file) && is_string($file['path'] ?? null), 'invalid_file');
            $path = $file['path'];
            $this->require(! array_key_exists($path, $uploads), 'duplicate_path');
            $uploads[$path] = $file['content_base64'] ?? null;
        }
        $files = [];
        $total = 0;
        foreach ($declared as $file) {
            $this->require(is_array($file) && is_string($file['path'] ?? null), 'invalid_file');
            $path = $file['path'];
            $this->require(strlen($path) <= 200 && preg_match('~^(?:manifest\.json|contracts/(?:ARTICLE_IDENTITY_LOCK|PRIVATE_URL_GUARD)\.json|review/(?:claim_gate|operator_review)\.md|pages/[A-Za-z0-9_-]+\.md|cms/CMS_(?:IMPORT_UPDATE_DRAFT|FIELDS_UPDATE)_[A-Za-z0-9_-]+\.json)$~D', $path) === 1, 'invalid_package_path');
            $this->require(! array_key_exists($path, $files) && array_key_exists($path, $uploads), 'file_inventory_mismatch');
            $bytes = $this->decode($uploads[$path]);
            $this->require(is_int($file['bytes'] ?? null) && strlen($bytes) === $file['bytes'] && strlen($bytes) <= 1000000, 'file_size_mismatch');
            $this->require($this->digestMatches($bytes, $file['sha256'] ?? null), 'file_digest_mismatch');
            $total += strlen($bytes);
            $this->require($total <= 2000000, 'package_too_large');
            $files[$path] = $bytes;
        }

        return $files;
    }

    private function digestMatches(string $bytes, mixed $digest): bool
    {
        return is_string($digest) && preg_match('/^[a-f0-9]{64}$/D', $digest) === 1 && hash_equals($digest, hash('sha256', $bytes));
    }

    private function assertNativeFileReferences(array $files): void
    {
        foreach ($files as $path => $bytes) {
            if ($path !== 'manifest.json' && ! str_starts_with($path, 'cms/')) {
                continue;
            }
            $data = $this->json($bytes);
            $references = [];
            if (array_key_exists('body_markdown_file', $data)) {
                $references[] = $data['body_markdown_file'];
            }
            foreach ((array) ($data['pages'] ?? []) as $page) {
                $this->require(is_array($page), 'invalid_page_reference');
                if (array_key_exists('file', $page)) {
                    $references[] = $page['file'];
                }
            }
            foreach ($references as $reference) {
                $this->require(is_string($reference) && str_starts_with($reference, 'pages/') && array_key_exists($reference, $files), 'invalid_page_reference');
            }
        }
    }

    private function decode(mixed $encoded): string
    {
        $this->require(is_string($encoded), 'invalid_file_encoding');
        $bytes = base64_decode($encoded, true);
        $this->require(is_string($bytes), 'invalid_file_encoding');

        return $bytes;
    }

    private function json(string $bytes): array
    {
        try {
            $value = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->require(false, 'invalid_json');
        }
        $this->require(is_array($value) && ! array_is_list($value), 'invalid_json_object');

        return $value;
    }

    private function assertPlan(array $plan): void
    {
        if (($plan['ok'] ?? false) !== true) {
            $codes = array_map(static fn (array $error): string => (string) ($error['code'] ?? 'native_validation_failed'), $plan['errors'] ?? []);
            $this->require(false, implode(', ', $codes) ?: 'native_validation_failed');
        }
    }

    private function require(bool $condition, string $code): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['package_json' => __('ops.resources.articles.package_import.rejected', ['code' => $code])]);
        }
    }
}
