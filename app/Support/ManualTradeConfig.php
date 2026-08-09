<?php

namespace App\Support;

/**
 * Константы ручной торговли с dashboard (бот «Ручная / Manual»).
 */
final class ManualTradeConfig
{
    /** Тип бота-контейнера для paper/mirror ручных сделок. */
    public const BOT_TYPE = 'manual_dashboard';

    public const BOT_NAME = 'Ручная / Manual';

    /** meta_json.source — чтобы settle/wait отличили ручную от зеркала стратегии. */
    public const META_SOURCE = 'manual';

    /**
     * Дефолты трёх тумблеров панели.
     * Wait previous по умолчанию выкл.; мартингейл/сумма с шага — тоже выкл.
     *
     * @return array{
     *   manual_respect_wait_previous: bool,
     *   manual_advance_martingale: bool,
     *   manual_amount_from_martingale: bool
     * }
     */
    public static function defaultFlags(): array
    {
        return [
            'manual_respect_wait_previous' => false,
            'manual_advance_martingale' => false,
            'manual_amount_from_martingale' => false,
        ];
    }
}
