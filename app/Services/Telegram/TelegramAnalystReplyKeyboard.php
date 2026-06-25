<?php

namespace App\Services\Telegram;

/**
 * Кнопки и текстовые команды Telegram-аналитика (/menu, /skip и reply-клавиатура).
 *
 * Reply-клавиатура остаётся внизу чата; inline-кнопки дублируют действия там,
 * где уже используется inline_keyboard (список инструментов).
 */
class TelegramAnalystReplyKeyboard
{
    /** Текстовая команда — список инструментов. */
    public const CMD_MENU = '/menu';

    /** Текстовая команда — анализ без дополнительного вопроса. */
    public const CMD_SKIP = '/skip';

    /** Текст кнопки «вернуться к выбору инструмента» (эквивалент /menu). */
    public const BTN_MENU = '📋 Меню';

    /** Текст кнопки «анализ без вопроса» (эквивалент /skip). */
    public const BTN_SKIP = '⏭ Пропустить';

    /** Текст кнопки обновления списка инструментов в inline-меню. */
    public const BTN_REFRESH = '🔄 Обновить';

    /**
     * Пользователь нажал «Меню» или ввёл команду вручную.
     */
    public static function isMenuTrigger(string $text): bool
    {
        $normalized = self::normalize($text);

        return in_array($normalized, [
            self::CMD_MENU,
            '/start',
            'меню',
            'start',
            self::normalize(self::BTN_MENU),
        ], true);
    }

    /**
     * Пользователь нажал «Пропустить» или ввёл /skip.
     */
    public static function isSkipTrigger(string $text): bool
    {
        $normalized = self::normalize($text);

        return in_array($normalized, [
            self::CMD_SKIP,
            'skip',
            'пропустить',
            '-',
            self::normalize(self::BTN_SKIP),
        ], true);
    }

    /** Подсказка в сообщениях: кнопка или текстовая команда меню. */
    public static function menuHint(): string
    {
        return '«'.self::BTN_MENU.'» или '.self::CMD_MENU;
    }

    /** Подсказка в сообщениях: кнопка или текстовая команда пропуска вопроса. */
    public static function skipHint(): string
    {
        return '«'.self::BTN_SKIP.'» или '.self::CMD_SKIP;
    }

    /**
     * Основная клавиатура: всегда доступен возврат в меню инструментов.
     *
     * @return array<string, mixed>
     */
    public static function mainMarkup(): array
    {
        return self::buildReplyKeyboard([[self::BTN_MENU]]);
    }

    /**
     * Клавиатура на шаге вопроса: пропустить или вернуться в меню.
     *
     * @return array<string, mixed>
     */
    public static function awaitQuestionMarkup(): array
    {
        return self::buildReplyKeyboard([[self::BTN_SKIP, self::BTN_MENU]]);
    }

    /**
     * Одна inline-кнопка «Меню» (callback cmd:menu).
     *
     * @return list<array{text:string,callback_data:string}>
     */
    public static function inlineMenuRow(): array
    {
        return [['text' => self::BTN_MENU, 'callback_data' => 'cmd:menu']];
    }

    /**
     * Inline-кнопки «Пропустить» и «Меню» под сообщением с вопросом.
     *
     * @return list<list<array{text:string,callback_data:string}>>
     */
    public static function inlineAwaitQuestionRows(): array
    {
        return [
            [
                ['text' => self::BTN_SKIP, 'callback_data' => 'cmd:skip'],
                ['text' => self::BTN_MENU, 'callback_data' => 'cmd:menu'],
            ],
        ];
    }

    /**
     * Нижний ряд inline-меню инструментов: обновить список.
     *
     * @return list<array{text:string,callback_data:string}>
     */
    public static function inlineInstrumentFooterRow(): array
    {
        return [['text' => self::BTN_REFRESH, 'callback_data' => 'cmd:menu']];
    }

    /**
     * @param  list<list<string>>  $rows
     * @return array<string, mixed>
     */
    private static function buildReplyKeyboard(array $rows): array
    {
        $keyboard = [];
        foreach ($rows as $row) {
            $keyboard[] = array_map(static fn (string $label): array => ['text' => $label], $row);
        }

        return [
            'keyboard' => $keyboard,
            'resize_keyboard' => true,
            'is_persistent' => true,
        ];
    }

    private static function normalize(string $text): string
    {
        return mb_strtolower(trim($text));
    }
}
