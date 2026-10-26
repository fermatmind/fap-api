<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class FriendshipPrune extends Command
{
    protected $signature = 'friendship:prune';

    protected $description = 'Delete expired friendship quizzes and their entries in bounded batches';

    public function handle(): int
    {
        $count = DB::transaction(function () {
            $ids = DB::table('friendship_challenges')->where('expires_at', '<=', now())->orderBy('expires_at')->limit(1000)->lockForUpdate()->pluck('id');
            DB::table('friendship_entries')->whereIn('challenge_id', $ids)->delete();

            return DB::table('friendship_challenges')->whereIn('id', $ids)->delete();
        });
        $this->info('Expired quizzes deleted: '.$count);

        return self::SUCCESS;
    }
}
