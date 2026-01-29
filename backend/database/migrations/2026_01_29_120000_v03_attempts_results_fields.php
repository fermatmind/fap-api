<?php

use Database\Migrations\Concerns\HasIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require_once __DIR__ . '/Concerns/HasIndex.php';

return new class extends Migration
{
    use HasIndex;

    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        $isSqlite = $driver === 'sqlite';

        if (Schema::hasTable('attempts')) {
            Schema::table('attempts', function (Blueprint $table) {
                if (!Schema::hasColumn('attempts', 'org_id')) {
                    $table->unsignedBigInteger('org_id')->default(0);
                }
                if (!Schema::hasColumn('attempts', 'scale_code')) {
                    $table->string('scale_code', 32);
                }
                if (!Schema::hasColumn('attempts', 'scale_version')) {
                    $table->string('scale_version', 16);
                }
                if (!Schema::hasColumn('attempts', 'pack_id')) {
                    $table->string('pack_id', 128)->nullable();
                }
                if (!Schema::hasColumn('attempts', 'dir_version')) {
                    $table->string('dir_version', 128)->nullable();
                }
                if (!Schema::hasColumn('attempts', 'content_package_version')) {
                    $table->string('content_package_version', 64)->nullable();
                }
                if (!Schema::hasColumn('attempts', 'scoring_spec_version')) {
                    $table->string('scoring_spec_version', 64)->nullable();
                }
                if (!Schema::hasColumn('attempts', 'started_at')) {
                    $table->timestamp('started_at')->nullable();
                }
                if (!Schema::hasColumn('attempts', 'submitted_at')) {
                    $table->timestamp('submitted_at')->nullable();
                }
                if (!Schema::hasColumn('attempts', 'duration_ms')) {
                    $table->integer('duration_ms')->default(0);
                }
                if (!Schema::hasColumn('attempts', 'answers_digest')) {
                    $table->string('answers_digest', 64)->nullable();
                }
            });

            $indexName = 'attempts_org_scale_pack_dir_idx';
            if (!$this->indexExists('attempts', $indexName)) {
                Schema::table('attempts', function (Blueprint $table) use ($indexName) {
                    $table->index(['org_id', 'scale_code', 'pack_id', 'dir_version'], $indexName);
                });
            }

            $indexName = 'attempts_org_anon_idx';
            if (!$this->indexExists('attempts', $indexName)) {
                Schema::table('attempts', function (Blueprint $table) use ($indexName) {
                    $table->index(['org_id', 'anon_id'], $indexName);
                });
            }

            $indexName = 'attempts_org_user_idx';
            if (!$this->indexExists('attempts', $indexName)) {
                Schema::table('attempts', function (Blueprint $table) use ($indexName) {
                    $table->index(['org_id', 'user_id'], $indexName);
                });
            }
        }

        if (Schema::hasTable('results')) {
            Schema::table('results', function (Blueprint $table) use ($isSqlite) {
                if (!Schema::hasColumn('results', 'org_id')) {
                    $table->unsignedBigInteger('org_id')->default(0);
                }
                if (!Schema::hasColumn('results', 'attempt_id')) {
                    $table->uuid('attempt_id');
                }
                if (!Schema::hasColumn('results', 'scale_code')) {
                    $table->string('scale_code', 32);
                }
                if (!Schema::hasColumn('results', 'result_json')) {
                    if ($isSqlite) {
                        $table->text('result_json')->nullable();
                    } else {
                        $table->json('result_json')->nullable();
                    }
                }
                if (!Schema::hasColumn('results', 'pack_id')) {
                    $table->string('pack_id', 128)->nullable();
                }
                if (!Schema::hasColumn('results', 'dir_version')) {
                    $table->string('dir_version', 128)->nullable();
                }
                if (!Schema::hasColumn('results', 'scoring_spec_version')) {
                    $table->string('scoring_spec_version', 64)->nullable();
                }
                if (!Schema::hasColumn('results', 'report_engine_version')) {
                    $table->string('report_engine_version', 32)->default('v1.2');
                }
            });

            $indexName = 'results_org_id_attempt_id_unique';
            $legacyIndexName = 'results_org_attempt_unique';
            if (!$this->indexExists('results', $indexName) && !$this->indexExists('results', $legacyIndexName)) {
                Schema::table('results', function (Blueprint $table) use ($indexName) {
                    $table->unique(['org_id', 'attempt_id'], $indexName);
                });
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('attempts')) {
            $indexName = 'attempts_org_scale_pack_dir_idx';
            if ($this->indexExists('attempts', $indexName)) {
                Schema::table('attempts', function (Blueprint $table) use ($indexName) {
                    $table->dropIndex($indexName);
                });
            }

            $indexName = 'attempts_org_anon_idx';
            if ($this->indexExists('attempts', $indexName)) {
                Schema::table('attempts', function (Blueprint $table) use ($indexName) {
                    $table->dropIndex($indexName);
                });
            }

            $indexName = 'attempts_org_user_idx';
            if ($this->indexExists('attempts', $indexName)) {
                Schema::table('attempts', function (Blueprint $table) use ($indexName) {
                    $table->dropIndex($indexName);
                });
            }

            Schema::table('attempts', function (Blueprint $table) {
                if (Schema::hasColumn('attempts', 'answers_digest')) {
                    $table->dropColumn('answers_digest');
                }
                if (Schema::hasColumn('attempts', 'duration_ms')) {
                    $table->dropColumn('duration_ms');
                }
                if (Schema::hasColumn('attempts', 'content_package_version')) {
                    $table->dropColumn('content_package_version');
                }
                if (Schema::hasColumn('attempts', 'org_id')) {
                    $table->dropColumn('org_id');
                }
            });
        }

        if (Schema::hasTable('results')) {
            $indexName = 'results_org_id_attempt_id_unique';
            $legacyIndexName = 'results_org_attempt_unique';
            if ($this->indexExists('results', $indexName)) {
                Schema::table('results', function (Blueprint $table) use ($indexName) {
                    $table->dropUnique($indexName);
                });
            } elseif ($this->indexExists('results', $legacyIndexName)) {
                Schema::table('results', function (Blueprint $table) use ($legacyIndexName) {
                    $table->dropUnique($legacyIndexName);
                });
            }

            Schema::table('results', function (Blueprint $table) {
                if (Schema::hasColumn('results', 'report_engine_version')) {
                    $table->dropColumn('report_engine_version');
                }
                if (Schema::hasColumn('results', 'scoring_spec_version')) {
                    $table->dropColumn('scoring_spec_version');
                }
                if (Schema::hasColumn('results', 'dir_version')) {
                    $table->dropColumn('dir_version');
                }
                if (Schema::hasColumn('results', 'pack_id')) {
                    $table->dropColumn('pack_id');
                }
                if (Schema::hasColumn('results', 'result_json')) {
                    $table->dropColumn('result_json');
                }
                if (Schema::hasColumn('results', 'org_id')) {
                    $table->dropColumn('org_id');
                }
            });
        }
    }

};
