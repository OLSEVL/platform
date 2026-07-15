<?php

namespace App\Console\Commands;

use App\Services\Mirror\MirrorWarmPoolService;
use Illuminate\Console\Command;

/**
 * После рестарта pocket-options-proxy кэш WS пуст — прогреваем всех с включённым зеркалом.
 */
class MirrorWarmPoolCommand extends Command
{
    protected $signature = 'mirror:warm-pool';

    protected $description = 'Прогреть sticky WS-каналы зеркала для пользователей с включённым mirroring';

    public function handle(MirrorWarmPoolService $warmPool): int
    {
        $warmed = $warmPool->warmAllEnabled();
        $this->info("Warmed {$warmed} mirror session(s).");

        return self::SUCCESS;
    }
}
