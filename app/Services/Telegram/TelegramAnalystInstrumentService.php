<?php

namespace App\Services\Telegram;

use App\Models\Bot;
use App\Models\PocketInstrument;
use Illuminate\Support\Collection;

/**
 * Список активных инструментов для Telegram-меню аналитика:
 * те же фильтры, что у scan в BotEngineService (min_payout, whitelist/blacklist).
 */
class TelegramAnalystInstrumentService
{
    /**
     * @return Collection<int, PocketInstrument>
     */
    public function filteredInstruments(Bot $bot): Collection
    {
        /** @var array<string, mixed> $cfg */
        $cfg = is_array($bot->config) ? $bot->config : [];
        $minPayout = (float) ($cfg['min_payout'] ?? 0);
        $allowed = $this->instrumentSet($cfg['allowed_instruments'] ?? null);
        $blocked = $this->instrumentSet($cfg['blocked_instruments'] ?? null);

        $rows = PocketInstrument::active()
            ->orderBy('instrument')
            ->get(['instrument', 'current_payout']);

        return $rows->filter(function (PocketInstrument $row) use ($minPayout, $allowed, $blocked): bool {
            $key = (string) $row->instrument;
            if ($allowed !== [] && ! isset($allowed[$key])) {
                return false;
            }
            if (isset($blocked[$key])) {
                return false;
            }
            $payout = $row->current_payout !== null ? (float) $row->current_payout : null;
            if ($payout === null || $payout < $minPayout) {
                return false;
            }

            return true;
        })->values();
    }

    /**
     * Все отфильтрованные инструменты для inline-клавиатуры (без пагинации).
     *
     * @return array{
     *   items:list<array{index:int,instrument:string,payout:?float}>,
     *   total:int
     * }
     */
    public function keyboardItems(Bot $bot): array
    {
        $all = $this->filteredInstruments($bot);
        $items = [];
        foreach ($all as $index => $row) {
            $items[] = [
                'index' => $index,
                'instrument' => (string) $row->instrument,
                'payout' => $row->current_payout !== null ? (float) $row->current_payout : null,
            ];
        }

        return [
            'items' => $items,
            'total' => count($items),
        ];
    }

    public function instrumentByIndex(Bot $bot, int $index): ?string
    {
        $all = $this->filteredInstruments($bot);
        $row = $all->get($index);

        return $row ? (string) $row->instrument : null;
    }

    /**
     * @return array<string, true>
     */
    private function instrumentSet(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $set = [];
        foreach ($raw as $item) {
            $key = trim((string) $item);
            if ($key !== '') {
                $set[$key] = true;
            }
        }

        return $set;
    }

    /**
     * Парсит textarea «по одному на строку или через запятую» в массив для config.
     *
     * @return list<string>
     */
    public static function parseInstrumentListInput(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }
        $parts = preg_split('/[\s,;]+/', trim($raw)) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $key = trim((string) $part);
            if ($key !== '') {
                $out[] = $key;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  list<string>|null  $list
     */
    public static function formatInstrumentListForInput(?array $list): string
    {
        if (! is_array($list) || $list === []) {
            return '';
        }

        return implode("\n", array_map(static fn ($v) => trim((string) $v), $list));
    }
}
