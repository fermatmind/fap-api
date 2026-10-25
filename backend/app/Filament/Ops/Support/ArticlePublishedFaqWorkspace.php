<?php

declare(strict_types=1);

namespace App\Filament\Ops\Support;

use App\Console\Commands\UpdateArticlePublicReaderMetadata;
use App\Models\Article;
use App\Support\CanonicalTranslationPayloadHash as Hash;
use App\Support\Rbac\PermissionNames;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** @review-surface article */
final class ArticlePublishedFaqWorkspace
{
    public static function canUpdate(): bool
    {
        $actor = auth((string) config('admin.guard', 'admin'))->user();

        return ContentAccess::canRelease() && $actor !== null
            && ($actor->hasPermission(PermissionNames::ADMIN_OWNER) || $actor->hasPermission(PermissionNames::ADMIN_CONTENT_PUBLISH));
    }

    public function prepare(Article $selected): array
    {
        $article = $this->selected($selected);
        $state = UpdateArticlePublicReaderMetadata::state($article);

        return ['target_hash' => Hash::hash($state), 'faq_items' => array_map(
            static fn (array $item): array => [
                'question' => $item['question'] ?? $item['q'],
                'answer' => $item['answer'] ?? $item['a'],
            ], $this->items($article))];
    }

    public function update(Article $selected, array $data): array
    {
        $article = $this->selected($selected);
        $state = UpdateArticlePublicReaderMetadata::state($article);
        $this->require(hash_equals(Hash::hash($state), (string) ($data['target_hash'] ?? '')), 'article_faq_state_changed');
        $this->require(ContentReleaseFollowUp::articleCacheConfigured(), 'article_cache_configuration_required');
        $items = $this->items($article);
        $submitted = $data['faq_items'] ?? null;
        $this->require(is_array($submitted) && array_is_list($submitted) && count($submitted) === count($items), 'article_faq_inventory_changed');
        foreach ($items as $index => &$item) {
            $input = $submitted[$index];
            $this->require(is_array($input) && array_diff(array_keys($input), ['question', 'answer']) === []
                && ($input['question'] ?? null) === ($item['question'] ?? $item['q']), 'article_faq_question_changed');
            $answer = $input['answer'] ?? null;
            $this->require(is_string($answer) && trim($answer) !== '' && mb_strlen($answer) <= 10000, 'article_faq_answer_invalid');
            $item[array_key_exists('answer', $item) ? 'answer' : 'a'] = $answer;
        }
        unset($item);
        $schema = (array) $article->seoMeta->schema_json;
        $schema['editorial_package_v1']['answer_surface_v1']['faq_items'] = $items;
        $package = ['schema' => 'article_public_reader_metadata.v1',
            'identity' => $article->only(['id', 'org_id', 'slug', 'locale', 'published_revision_id', 'working_revision_id']),
            'before_sha256' => Hash::hash($state),
            'candidate' => ['schema_json' => $schema, 'article_fields' => []]];
        $bytes = json_encode($package, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $sha = hash('sha256', $bytes);
        $path = storage_path('app/private/article-faq-inputs/'.Str::uuid().'.json');
        File::ensureDirectoryExists(dirname($path), 0700);
        try {
            $this->require(file_put_contents($path, $bytes) === strlen($bytes), 'article_faq_input_failed');
            $options = ['--file' => $path, '--sha256' => $sha];
            $this->run($options);
            $options += ['--execute' => true, '--confirm' => $sha,
                '--admin-user-id' => (int) auth((string) config('admin.guard', 'admin'))->id()];
            if (app()->environment('production')) {
                $revision = dirname(base_path()).'/REVISION';
                $options['--deployed-sha'] = is_file($revision) ? trim((string) file_get_contents($revision)) : '';
            }
            $this->run($options);
        } finally {
            File::delete($path);
        }
        try {
            ContentReleaseFollowUp::dispatchArticlePathsStrict($article->fresh(['publishedRevision', 'seoMeta']), 'article_published_faq_updated');

            return ['ok' => true, 'metadata' => 'committed', 'cache_closeout' => 'success'];
        } catch (\Throwable) {
            return ['ok' => false, 'metadata' => 'committed', 'cache_closeout' => 'failed'];
        }
    }

    private function selected(Article $selected): Article
    {
        abort_unless(self::canUpdate(), 403);
        $guard = (string) config('admin.guard', 'admin');
        abort_if((bool) config('admin.totp.enabled', true)
            && (int) session('ops_admin_totp_verified_user_id', 0) !== (int) auth($guard)->id(), 403);
        $article = Article::withoutGlobalScopes()->whereKey($selected->getKey())->firstOrFail();
        $this->require((int) $article->org_id === 0 && (int) $article->id !== 15
            && in_array($article->locale, ['en', 'zh-CN'], true) && $article->deleted_at === null
            && $article->status === 'published' && $article->is_public
            && $article->published_revision_id !== null
            && $article->working_revision_id === $article->published_revision_id, 'article_faq_published_target_required');
        $article->load(['publishedRevision', 'seoMeta']);

        return $article;
    }

    /** Existing question order and opaque item metadata remain immutable in this action. */
    private function items(Article $article): array
    {
        $schema = (array) $article->seoMeta->schema_json;
        $metadata = array_replace_recursive((array) data_get($article->cover_image_variants, 'editorial_package_v1', []),
            (array) ($schema['editorial_package_v1'] ?? []));
        $visibility = $metadata['answer_surface_visibility'] ?? null;
        $this->require(($metadata['answer_surface_policy'] ?? null) === 'editor_supplied'
            && is_string($visibility) && trim($visibility) !== '' && $visibility !== 'disabled', 'article_faq_surface_not_editable');
        $items = data_get($schema, 'editorial_package_v1.answer_surface_v1.faq_items',
            data_get($metadata, 'answer_surface_v1.faq_items', []));
        $this->require(is_array($items) && array_is_list($items) && count($items) > 0 && count($items) <= 9, 'article_faq_inventory_not_supported');
        foreach ($items as $item) {
            $this->require(is_array($item) && is_string($item['question'] ?? $item['q'] ?? null)
                && is_string($item['answer'] ?? $item['a'] ?? null), 'article_faq_inventory_not_supported');
        }

        return $items;
    }

    private function run(array $options): void
    {
        $exit = Artisan::call('articles:update-public-reader-metadata', $options);
        $result = json_decode(trim(Artisan::output()), true);
        $this->require($exit === 0 && is_array($result) && ($result['ok'] ?? false) === true,
            (string) ($result['error'] ?? 'article_faq_native_update_failed'));
    }

    private function require(bool $condition, string $code): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['faq_items' => $code]);
        }
    }
}
