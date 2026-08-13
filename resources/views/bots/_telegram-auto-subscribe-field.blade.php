@php
    // Уникальный id чекбокса, если на странице несколько Telegram-блоков.
    $telegramAutoSubscribeId = $telegramAutoSubscribeId ?? 'telegram_auto_subscribe';
    $cfgArr = is_array($cfg) ? $cfg : [];
    $primaryChatId = trim((string) ($cfgArr['telegram_chat_id'] ?? ''));
    $subscribers = \App\Services\Telegram\TelegramChatAccess::normalizeSubscribers($cfgArr['telegram_subscribers'] ?? null);
    $recipients = \App\Services\Telegram\TelegramChatAccess::recipientChatIds($cfgArr);
    $subscribersCount = count($subscribers);
    $recipientCount = count($recipients);
@endphp
<div class="space-y-2 sm:col-span-2">
    <label class="flex items-start gap-2">
        <input type="checkbox" name="telegram_auto_subscribe" id="{{ $telegramAutoSubscribeId }}" value="1"
            class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
            @checked(old('telegram_auto_subscribe', $cfgArr['telegram_auto_subscribe'] ?? true))>
        <span>
            <span class="text-sm font-medium text-zinc-300">Автоподписка новых чатов</span>
            <span class="mt-0.5 block text-[11px] font-normal text-zinc-500">
                Включено (по умолчанию): любой, кто напишет боту <code class="text-zinc-400">/start</code>, попадает в список получателей алертов и может жать кнопки.
                Выключено: новые чаты не добавляются — работают только уже сохранённые id.
                Отписка в Telegram: <code class="text-zinc-400">/stop</code>.
            </span>
        </span>
    </label>
    @error('telegram_auto_subscribe')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror

    {{-- Список, кому сейчас уходят алерты / доступны кнопки --}}
    <div class="rounded border border-zinc-800 bg-zinc-950/50 px-3 py-2">
        <p class="text-xs font-medium text-zinc-300">
            Подписчики Telegram
            <span class="font-normal text-zinc-500">(получателей: {{ $recipientCount }}, по /start: {{ $subscribersCount }})</span>
        </p>

        @if ($primaryChatId !== '')
            <p class="mt-1.5 text-[11px] text-zinc-500">Основной Chat ID из настроек:</p>
            <p class="font-mono text-xs text-emerald-300/90">{{ $primaryChatId }}</p>
        @else
            <p class="mt-1.5 text-[11px] text-amber-300/80">Основной Chat ID не задан.</p>
        @endif

        @if ($subscribersCount > 0)
            <p class="mt-2 text-[11px] text-zinc-500">Подписались через /start:</p>
            <ul class="mt-1 space-y-0.5">
                @foreach ($subscribers as $subChatId)
                    <li class="font-mono text-xs text-zinc-200">
                        {{ $subChatId }}
                        @if ($primaryChatId !== '' && $subChatId === $primaryChatId)
                            <span class="text-[10px] text-zinc-500">(совпадает с основным)</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        @else
            <p class="mt-2 text-[11px] text-zinc-500">
                Пока никто не подписался через /start. После /start chat id появится здесь (нужен работающий poll).
            </p>
        @endif
    </div>
</div>
