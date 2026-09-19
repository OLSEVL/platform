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
    /** Префикс inline callback_data для выбора инструмента из меню. */
    public const CALLBACK_PREFIX = 'ins:';

    /** Лимит Telegram Bot API на длину callback_data (байты). */
    private const CALLBACK_DATA_MAX_BYTES = 64;

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
            ->get(['instrument', 'display_name', 'current_payout']);

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
     * Важно: в callback_data кладём имя инструмента (не индекс!). Индекс ломается,
     * когда между показом меню и кликом меняется filtered-список (обычно из‑за
     * обновления current_payout относительно min_payout) — кнопка «EURUSD» внезапно
     * запускала анализ другой пары по тому же порядковому номеру.
     *
     * @return array{
     *   items:list<array{instrument:string,label:string,callback_data:string,payout:?float}>,
     *   total:int
     * }
     */
    public function keyboardItems(Bot $bot): array
    {
        $all = $this->filteredInstruments($bot);
        $items = [];
        foreach ($all as $row) {
            $instrument = (string) $row->instrument;
            // Пропускаем пары, чьё имя не влезает в лимит Telegram callback_data.
            $callbackData = $this->encodeInstrumentCallback($instrument);
            if ($callbackData === null) {
                continue;
            }
            $items[] = [
                'instrument' => $instrument,
                // Текст кнопки: «AUD/NZD OTC (AUDNZD_otc)» — то же правило, что в алертах.
                'label' => \App\Support\TelegramInstrumentLabel::format($instrument),
                'callback_data' => $callbackData,
                'payout' => $row->current_payout !== null ? (float) $row->current_payout : null,
            ];
        }

        return [
            'items' => $items,
            'total' => count($items),
        ];
    }

    /**
     * Собирает callback_data вида «ins:EURUSD_otc». Null — если имя пустое или >64 байт.
     */
    public function encodeInstrumentCallback(string $instrument): ?string
    {
        $instrument = trim($instrument);
        if ($instrument === '') {
            return null;
        }

        $data = self::CALLBACK_PREFIX.$instrument;
        if (strlen($data) > self::CALLBACK_DATA_MAX_BYTES) {
            return null;
        }

        return $data;
    }

    /**
     * Разбирает callback_data «ins:…» → сырое значение после префикса (имя или legacy-индекс).
     */
    public function parseInstrumentCallback(string $data): ?string
    {
        $data = trim($data);
        if (! str_starts_with($data, self::CALLBACK_PREFIX)) {
            return null;
        }

        $raw = trim(substr($data, strlen(self::CALLBACK_PREFIX)));

        return $raw !== '' ? $raw : null;
    }

    /**
     * Резолвит выбор пользователя из callback: имя пары (основной путь) или
     * устаревший числовой индекс из старых сообщений в чате.
     */
    public function resolveCallbackSelection(Bot $bot, string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        // Старые кнопки ещё могут слать «ins:5» — оставляем совместимость, но новые
        // уже шлют имя; индекс хрупок при смене payout-фильтра.
        if (ctype_digit($raw)) {
            return $this->instrumentByIndex($bot, (int) $raw);
        }

        // Имя пары из кнопки: ищем активный инструмент без payout-фильтра,
        // чтобы клик по уже показанной кнопке не «прыгал» на другую пару.
        return $this->resolveActiveInstrument($raw);
    }

    /**
     * @deprecated Индекс нестабилен при смене filtered-списка; оставлен только
     *             для старых inline-кнопок в истории чата.
     */
    public function instrumentByIndex(Bot $bot, int $index): ?string
    {
        $all = $this->filteredInstruments($bot);
        $row = $all->get($index);

        return $row ? (string) $row->instrument : null;
    }

    /**
     * Активный инструмент по имени без фильтров payout/whitelist (deep link из Strong Level).
     */
    public function resolveActiveInstrument(string $instrument): ?string
    {
        $key = trim($instrument);
        if ($key === '') {
            return null;
        }

        $row = PocketInstrument::active()
            ->where('instrument', $key)
            ->first(['instrument']);

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
