<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // These versioned editorial assets are immutable. Future revisions use a new
        // forward migration so a fresh database reproduces the published history.
        $rows = [];
        foreach (['zh-CN', 'en'] as $locale) {
            $document = json_decode(
                file_get_contents(base_path('content_assets/personality_public/mbti_result_introductions.'.$locale.'.v1.json')),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
            if ($document['schema'] !== 'mbti_result_introductions.v1' || $document['locale'] !== $locale || count($document['introductions']) !== 32) {
                throw new RuntimeException('Invalid MBTI introduction inventory.');
            }
            $seen = [];
            foreach ($document['introductions'] as $intro) {
                $code = $intro['full_code'];
                $paragraphs = $intro['paragraphs'];
                if (! preg_match('/^[EI][SN][TF][JP]-[AT]$/D', $code) || isset($seen[$code]) || ! is_array($paragraphs) || count($paragraphs) !== 2) {
                    throw new RuntimeException('Invalid MBTI introduction identity or paragraphs.');
                }
                foreach ($paragraphs as $paragraph) {
                    if (! is_string($paragraph) || trim($paragraph) === '') {
                        throw new RuntimeException('Empty MBTI introduction paragraph.');
                    }
                }
                $seen[$code] = true;
                $json = json_encode(array_values($paragraphs), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                $rows[] = [
                    'full_code' => $code,
                    'locale' => $locale,
                    'paragraphs' => $json,
                    'content_hash' => hash('sha256', $json),
                    'revision' => 1,
                    'status' => 'published',
                    'published_at' => '2026-09-12 00:00:00',
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        Schema::create('personality_result_introductions', function (Blueprint $table): void {
            $table->id();
            $table->string('full_code', 6);
            $table->string('locale', 10);
            $table->json('paragraphs');
            $table->char('content_hash', 64);
            $table->unsignedInteger('revision');
            $table->string('status', 16)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['full_code', 'locale'], 'personality_result_intro_identity_unique');
        });
        DB::transaction(fn () => DB::table('personality_result_introductions')->insert($rows));
    }

    public function down(): void
    {
        // Forward-only: retain published editorial data when application code rolls back.
    }
};
