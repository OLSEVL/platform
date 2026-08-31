<?php

namespace App\Services\Bots;

use App\Models\Bot;
use App\Models\BotTrade;
use App\Support\HybridAnalystConfig;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Выбор source-бота для гибрида по лучшему paper winrate за окно.
 * Кэш ~60 сек — пересчёт на каждый сигнал не нагружает БД.
 */
class HybridAnalystLeaderService
{
    /**
     * Id текущего лидера (или предыдущего, если нет qualifying кандидатов / ничья в пользу прошлого).
     * 0 = лидера нет.
     *
     * @param  array<string, mixed>  $cfg
     */
    public function resolveLeaderBotId(Bot $analystBot, array $cfg): int
    {
        $cacheKey = 'hybrid_leader:'.$analystBot->id;
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && isset($cached['bot_id'])) {
            return max(0, (int) $cached['bot_id']);
        }

        $resolved = $this->computeLeader($analystBot, $cfg);
        Cache::put($cacheKey, [
            'bot_id' => $resolved['bot_id'],
            'winrate' => $resolved['winrate'],
            'trades' => $resolved['trades'],
            'at' => Carbon::now()->toIso8601String(),
        ], HybridAnalystConfig::LEADER_CACHE_TTL_SEC);

        // Persist previous leader только при смене — fallback после сброса кэша / малой выборки.
        $prev = HybridAnalystConfig::previousLeaderBotId($cfg);
        if ($resolved['bot_id'] > 0 && $resolved['bot_id'] !== $prev) {
            $this->persistLeaderBotId($analystBot, $resolved['bot_id']);
        }

        return $resolved['bot_id'];
    }

    /**
     * Сброс кэша лидера (после сохранения настроек гибрида).
     */
    public function forgetCache(int $analystBotId): void
    {
        Cache::forget('hybrid_leader:'.$analystBotId);
    }

    /**
     * @param  array<string, mixed>  $cfg
     * @return array{bot_id:int,winrate:?float,trades:int}
     */
    public function computeLeader(Bot $analystBot, array $cfg): array
    {
        $windowSec = HybridAnalystConfig::leaderWindowSec($cfg);
        $minTrades = HybridAnalystConfig::leaderMinTrades($cfg);
        $previousId = HybridAnalystConfig::previousLeaderBotId($cfg);
        $from = Carbon::now()->subSeconds($windowSec);

        $candidateIds = HybridAnalystConfig::applySourceBotQuery(
            Bot::query()
                ->where('user_id', $analystBot->user_id)
                ->where('is_active', true)
                ->where('id', '!=', $analystBot->id)
        )
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if ($candidateIds === []) {
            return ['bot_id' => 0, 'winrate' => null, 'trades' => 0];
        }

        $stats = DB::table('bot_trades')
            ->whereIn('bot_id', $candidateIds)
            ->where('entry_time', '>=', $from)
            ->whereIn('result', [BotTrade::RESULT_WIN, BotTrade::RESULT_LOSS])
            ->groupBy('bot_id')
            ->selectRaw("
                bot_id,
                SUM(CASE WHEN result = 'win' THEN 1 ELSE 0 END) as wins,
                SUM(CASE WHEN result = 'loss' THEN 1 ELSE 0 END) as losses
            ")
            ->get();

        /** @var list<array{bot_id:int,wins:int,losses:int,trades:int,winrate:float}> $qualified */
        $qualified = [];
        foreach ($stats as $row) {
            $wins = (int) $row->wins;
            $losses = (int) $row->losses;
            $trades = $wins + $losses;
            if ($trades < $minTrades) {
                continue;
            }
            $qualified[] = [
                'bot_id' => (int) $row->bot_id,
                'wins' => $wins,
                'losses' => $losses,
                'trades' => $trades,
                'winrate' => $trades > 0 ? ($wins * 100.0 / $trades) : 0.0,
            ];
        }

        // Мало сделок у всех — держим прошлого лидера, если он ещё active-кандидат.
        if ($qualified === []) {
            if ($previousId > 0 && in_array($previousId, $candidateIds, true)) {
                return ['bot_id' => $previousId, 'winrate' => null, 'trades' => 0];
            }

            return ['bot_id' => 0, 'winrate' => null, 'trades' => 0];
        }

        usort($qualified, static function (array $a, array $b) use ($previousId): int {
            // Выше winrate побеждает.
            if (abs($a['winrate'] - $b['winrate']) > 0.0001) {
                return $b['winrate'] <=> $a['winrate'];
            }
            // Ничья по WR: предпочитаем прошлого лидера.
            if ($a['bot_id'] === $previousId) {
                return -1;
            }
            if ($b['bot_id'] === $previousId) {
                return 1;
            }
            // Иначе больше сделок, затем меньший id (стабильность).
            if ($a['trades'] !== $b['trades']) {
                return $b['trades'] <=> $a['trades'];
            }

            return $a['bot_id'] <=> $b['bot_id'];
        });

        $best = $qualified[0];

        return [
            'bot_id' => $best['bot_id'],
            'winrate' => round($best['winrate'], 2),
            'trades' => $best['trades'],
        ];
    }

    private function persistLeaderBotId(Bot $analystBot, int $leaderBotId): void
    {
        try {
            $cfg = is_array($analystBot->config) ? $analystBot->config : [];
            if ((int) ($cfg['hybrid_leader_bot_id'] ?? 0) === $leaderBotId) {
                return;
            }
            $cfg['hybrid_leader_bot_id'] = $leaderBotId;
            $analystBot->config = $cfg;
            $analystBot->save();
        } catch (\Throwable $e) {
            Log::warning('hybrid_leader_persist_failed', [
                'analyst_bot_id' => $analystBot->id,
                'leader_bot_id' => $leaderBotId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
