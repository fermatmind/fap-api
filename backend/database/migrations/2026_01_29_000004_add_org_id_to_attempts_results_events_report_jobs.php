<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('attempts')) {
            Schema::table('attempts', function (Blueprint $table) {
                if (!Schema::hasColumn('attempts', 'org_id')) {
                    $table->unsignedBigInteger('org_id')->default(0);
                }
            });
        }

        if (Schema::hasTable('results')) {
            Schema::table('results', function (Blueprint $table) {
                if (!Schema::hasColumn('results', 'org_id')) {
                    $table->unsignedBigInteger('org_id')->default(0);
                }
            });
        }

        if (Schema::hasTable('events')) {
            Schema::table('events', function (Blueprint $table) {
                if (!Schema::hasColumn('events', 'org_id')) {
                    $table->unsignedBigInteger('org_id')->default(0);
                }
            });
        }

        if (Schema::hasTable('report_jobs')) {
            Schema::table('report_jobs', function (Blueprint $table) {
                if (!Schema::hasColumn('report_jobs', 'org_id')) {
                    $table->unsignedBigInteger('org_id')->default(0);
                }
            });
        }

        if (Schema::hasTable('attempts') && Schema::hasColumn('attempts', 'org_id')) {
            DB::table('attempts')->whereNull('org_id')->update(['org_id' => 0]);
        }
        if (Schema::hasTable('results') && Schema::hasColumn('results', 'org_id')) {
            DB::table('results')->whereNull('org_id')->update(['org_id' => 0]);
        }
        if (Schema::hasTable('events') && Schema::hasColumn('events', 'org_id')) {
            DB::table('events')->whereNull('org_id')->update(['org_id' => 0]);
        }
        if (Schema::hasTable('report_jobs') && Schema::hasColumn('report_jobs', 'org_id')) {
            DB::table('report_jobs')->whereNull('org_id')->update(['org_id' => 0]);
        }

        $indexName = 'attempts_org_id_idx';
        if (Schema::hasTable('attempts') && Schema::hasColumn('attempts', 'org_id') && !$this->indexExists('attempts', $indexName)) {
            Schema::table('attempts', function (Blueprint $table) use ($indexName) {
                $table->index('org_id', $indexName);
            });
        }

        $indexName = 'results_org_id_idx';
        if (Schema::hasTable('results') && Schema::hasColumn('results', 'org_id') && !$this->indexExists('results', $indexName)) {
            Schema::table('results', function (Blueprint $table) use ($indexName) {
                $table->index('org_id', $indexName);
            });
        }

        $uniqueName = 'results_org_id_attempt_id_unique';
        $legacyUniqueName = 'results_org_attempt_unique';
        if (Schema::hasTable('results') && Schema::hasColumn('results', 'org_id') && Schema::hasColumn('results', 'attempt_id')
            && !$this->indexExists('results', $uniqueName)
            && !$this->indexExists('results', $legacyUniqueName)) {
            Schema::table('results', function (Blueprint $table) use ($uniqueName) {
                $table->unique(['org_id', 'attempt_id'], $uniqueName);
            });
        }

        $indexName = 'events_org_id_idx';
        if (Schema::hasTable('events') && Schema::hasColumn('events', 'org_id') && !$this->indexExists('events', $indexName)) {
            Schema::table('events', function (Blueprint $table) use ($indexName) {
                $table->index('org_id', $indexName);
            });
        }

        $indexName = 'report_jobs_org_id_idx';
        if (Schema::hasTable('report_jobs') && Schema::hasColumn('report_jobs', 'org_id') && !$this->indexExists('report_jobs', $indexName)) {
            Schema::table('report_jobs', function (Blueprint $table) use ($indexName) {
                $table->index('org_id', $indexName);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('attempts')) {
            $indexName = 'attempts_org_id_idx';
            if ($this->indexExists('attempts', $indexName)) {
                Schema::table('attempts', function (Blueprint $table) use ($indexName) {
                    $table->dropIndex($indexName);
                });
            }
            Schema::table('attempts', function (Blueprint $table) {
                if (Schema::hasColumn('attempts', 'org_id')) {
                    $table->dropColumn('org_id');
                }
            });
        }

        if (Schema::hasTable('results')) {
            $uniqueName = 'results_org_id_attempt_id_unique';
            $legacyUniqueName = 'results_org_attempt_unique';
            if ($this->indexExists('results', $uniqueName)) {
                Schema::table('results', function (Blueprint $table) use ($uniqueName) {
                    $table->dropUnique($uniqueName);
                });
            } elseif ($this->indexExists('results', $legacyUniqueName)) {
                Schema::table('results', function (Blueprint $table) use ($legacyUniqueName) {
                    $table->dropUnique($legacyUniqueName);
                });
            }

            $indexName = 'results_org_id_idx';
            if ($this->indexExists('results', $indexName)) {
                Schema::table('results', function (Blueprint $table) use ($indexName) {
                    $table->dropIndex($indexName);
                });
            }

            Schema::table('results', function (Blueprint $table) {
                if (Schema::hasColumn('results', 'org_id')) {
                    $table->dropColumn('org_id');
                }
            });
        }

        if (Schema::hasTable('events')) {
            $indexName = 'events_org_id_idx';
            if ($this->indexExists('events', $indexName)) {
                Schema::table('events', function (Blueprint $table) use ($indexName) {
                    $table->dropIndex($indexName);
                });
            }
            Schema::table('events', function (Blueprint $table) {
                if (Schema::hasColumn('events', 'org_id')) {
                    $table->dropColumn('org_id');
                }
            });
        }

        if (Schema::hasTable('report_jobs')) {
            $indexName = 'report_jobs_org_id_idx';
            if ($this->indexExists('report_jobs', $indexName)) {
                Schema::table('report_jobs', function (Blueprint $table) use ($indexName) {
                    $table->dropIndex($indexName);
                });
            }
            Schema::table('report_jobs', function (Blueprint $table) {
                if (Schema::hasColumn('report_jobs', 'org_id')) {
                    $table->dropColumn('org_id');
                }
            });
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            $rows = DB::select("PRAGMA index_list('{$table}')");
            foreach ($rows as $row) {
                if ((string) ($row->name ?? '') === $indexName) {
                    return true;
                }
            }
            return false;
        }

        if ($driver === 'pgsql') {
            $rows = DB::select('SELECT indexname FROM pg_indexes WHERE tablename = ?', [$table]);
            foreach ($rows as $row) {
                if ((string) ($row->indexname ?? '') === $indexName) {
                    return true;
                }
            }
            return false;
        }

        $db = DB::getDatabaseName();
        $rows = DB::select(
            "SELECT 1 FROM information_schema.statistics
             WHERE table_schema = ? AND table_name = ? AND index_name = ?
             LIMIT 1",
            [$db, $table, $indexName]
        );
        return !empty($rows);
    }

};
