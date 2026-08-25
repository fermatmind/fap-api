<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'seo_intel';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        if ($schema->hasTable('seo_urls')) {
            $schema->table('seo_urls', function (Blueprint $table): void {
                $table->string('page_family', 64)->nullable()->after('page_entity_type');
                $table->char('authority_revision', 64)->nullable()->after('source_authority');
                $table->char('canonical_revision', 64)->nullable()->after('authority_revision');
            });

            $this->backfillUrlTraceability();
        }

        if (! $schema->hasTable('seo_url_entities')) {
            return;
        }

        $schema->table('seo_url_entities', function (Blueprint $table): void {
            $table->string('page_family', 64)->nullable()->after('page_entity_type');
            $table->char('authority_revision', 64)->nullable()->after('authority_status');
            $table->char('canonical_revision', 64)->nullable()->after('authority_revision');
            $table->string('binding_status', 64)->nullable()->after('canonical_revision');
            $table->char('current_binding_key', 64)->nullable()->after('binding_status');
            $table->unsignedBigInteger('superseded_by_id')->nullable()->after('current_binding_key');
            $table->timestamp('retired_at')->nullable()->after('superseded_by_id');
        });

        $this->backfillEntityBindings();

        $schema->table('seo_url_entities', function (Blueprint $table): void {
            $table->unique('current_binding_key', 'seo_url_entities_current_binding_unique');
            $table->index(['binding_status', 'locale'], 'seo_url_entities_status_locale_idx');
        });
    }

    private function backfillUrlTraceability(): void
    {
        $connection = DB::connection($this->connection);

        $connection->table('seo_urls')
            ->select(['id', 'canonical_url_hash', 'page_entity_type', 'entity_id_or_slug', 'locale', 'source_authority', 'lastmod_at'])
            ->orderBy('id')
            ->chunkById(250, function ($rows) use ($connection): void {
                foreach ($rows as $row) {
                    $connection->table('seo_urls')->where('id', $row->id)->update([
                        'page_family' => (string) $row->page_entity_type,
                        'authority_revision' => $this->authorityRevision($row),
                        'canonical_revision' => (string) $row->canonical_url_hash,
                    ]);
                }
            });
    }

    private function backfillEntityBindings(): void
    {
        $connection = DB::connection($this->connection);
        $rows = $connection->table('seo_url_entities')->orderBy('id')->get()->all();
        $groups = [];

        foreach ($rows as $row) {
            $groups[$this->identityKey($row)][] = $row;
        }

        foreach ($groups as $identityKey => $bindings) {
            usort($bindings, fn (object $left, object $right): int => $this->survivorRank($right) <=> $this->survivorRank($left));
            $survivor = null;

            foreach ($bindings as $binding) {
                if (! $this->isRetiredStatus((string) $binding->authority_status)) {
                    $survivor = $binding;
                    break;
                }
            }

            foreach ($bindings as $binding) {
                $isCurrent = $survivor !== null && (int) $binding->id === (int) $survivor->id;
                $connection->table('seo_url_entities')->where('id', $binding->id)->update([
                    'page_family' => (string) $binding->page_entity_type,
                    'authority_revision' => $this->authorityRevision($binding),
                    'canonical_revision' => (string) $binding->canonical_url_hash,
                    'binding_status' => $isCurrent ? 'current' : ($survivor === null ? 'retired' : 'superseded_duplicate'),
                    'current_binding_key' => $isCurrent ? $identityKey : null,
                    'superseded_by_id' => $isCurrent ? null : $survivor?->id,
                    'retired_at' => $isCurrent ? null : now(),
                ]);
            }
        }
    }

    private function identityKey(object $row): string
    {
        return hash('sha256', json_encode([
            (string) $row->page_entity_type,
            (string) $row->entity_id_or_slug,
            (string) $row->locale,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function authorityRevision(object $row): string
    {
        return hash('sha256', json_encode([
            (string) ($row->source_authority ?? $row->entity_source ?? ''),
            (string) $row->page_entity_type,
            (string) ($row->entity_id_or_slug ?? ''),
            (string) $row->locale,
            (string) ($row->source_updated_at ?? $row->lastmod_at ?? ''),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function survivorRank(object $row): string
    {
        $active = $this->isRetiredStatus((string) $row->authority_status) ? '0' : '1';

        return implode('|', [
            $active,
            (string) ($row->source_updated_at ?? ''),
            (string) ($row->updated_at ?? ''),
            str_pad((string) $row->id, 20, '0', STR_PAD_LEFT),
        ]);
    }

    private function isRetiredStatus(string $status): bool
    {
        $status = strtolower($status);

        foreach (['private', 'draft', 'unpublished', 'pending', 'retired', 'superseded', 'blocked', 'noindex'] as $token) {
            if (str_contains($status, $token)) {
                return true;
            }
        }

        return false;
    }

    public function down(): void
    {
        // Forward-only expand migration: old application versions ignore these nullable columns.
    }
};
