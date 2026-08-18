{{--
    Общие Telegram-поля для стратегий без карточки «сильного уровня».
    Алерт отправляется только после создания реальной paper-сделки; виртуальные
    проверки Strong Trend + DeMarker в Telegram не попадают.

    Автоподписка работает так же, как у остальных ботов: фоновый
    telegram:strong-level-poll слушает /start и пишет chat_id в telegram_subscribers.
--}}
@php
    $telegramBotUsername = $telegramBotUsername ?? match ($bot->type ?? '') {
        'strong_trend_demarker' => 'strong_DeMarker_bot',
        default => '',
    };
@endphp
<div class="space-y-3 rounded border border-zinc-800 bg-zinc-900/40 p-4">
    <p class="text-sm font-medium text-zinc-300">Telegram</p>
    <p class="text-[11px] text-zinc-500">
        Уведомление о новой paper-сделке и, при включённой опции, её результате.
        Получатели: Chat ID из настроек + чаты, которые написали <span class="font-mono">/start</span> (если автоподписка включена).
        Сначала сохраните token.
        Для Sweep / Raid Liquidity к алерту прикрепляется график M1 с фракталами, SWEEP/RAID/PB и точкой входа.
        Для Strong Trend + DeMarker — график свечей с панелью DeMarker.
    </p>

    <label class="flex items-start gap-2">
        <input type="checkbox" name="telegram_enabled" id="telegram_enabled_strategy" value="1"
            class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
            @checked(old('telegram_enabled', $cfg['telegram_enabled'] ?? false))>
        <span>
            <span class="text-sm font-medium text-zinc-300">Отправлять алерты в Telegram</span>
            <span class="mt-0.5 block text-[11px] font-normal text-zinc-500">Сделки продолжают работать, если Telegram выключен или временно недоступен.</span>
        </span>
    </label>

    @include('bots._telegram-notify-result-field', [
        'cfg' => $cfg,
        'telegramNotifyResultId' => 'telegram_notify_result_strategy',
    ])

    <div class="grid gap-4 sm:grid-cols-2">
        <div class="space-y-1">
            <label for="telegram_notify_on_strategy" class="block text-xs font-medium text-zinc-400">Когда уведомлять</label>
            @php $telegramNotifyOn = old('telegram_notify_on', $cfg['telegram_notify_on'] ?? 'signal_trade'); @endphp
            <select name="telegram_notify_on" id="telegram_notify_on_strategy" required
                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                <option value="signal_trade" @selected($telegramNotifyOn === 'signal_trade')>Новая paper-сделка</option>
                <option value="off" @selected($telegramNotifyOn === 'off')>Не уведомлять</option>
            </select>
        </div>
        <div class="space-y-1">
            <label for="telegram_send_chart_strategy" class="block text-xs font-medium text-zinc-400">График в алерте</label>
            @php $telegramSendChart = old('telegram_send_chart', ($cfg['telegram_send_chart'] ?? true) ? '1' : '0'); @endphp
            <select name="telegram_send_chart" id="telegram_send_chart_strategy" required
                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                <option value="1" @selected($telegramSendChart === '1')>Да, PNG со свечами и метками</option>
                <option value="0" @selected($telegramSendChart === '0')>Нет, только текст</option>
            </select>
            <p class="text-[11px] text-zinc-500">Sweep / Raid: FH/FL, свип, рейд, откат и точка входа. DeMarker: свечи + панель осциллятора.</p>
        </div>
        <div class="space-y-1">
            @include('bots._telegram-bot-token-field', [
                'inputId' => 'telegram_bot_token_strategy',
                'hint' => 'Токен от @BotFather. Пустое поле при сохранении оставляет текущий токен.',
            ])
        </div>
        <div class="space-y-1">
            <label for="telegram_chat_id_strategy" class="block text-xs font-medium text-zinc-400">Chat ID</label>
            <input type="text" name="telegram_chat_id" id="telegram_chat_id_strategy"
                value="{{ old('telegram_chat_id', $cfg['telegram_chat_id'] ?? '') }}"
                placeholder="123456789"
                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100 font-mono">
            <p class="text-[11px] text-zinc-500">Основной chat_id. Дополнительно — подписчики по /start.</p>
            @error('telegram_chat_id')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
        </div>
        @include('bots._telegram-auto-subscribe-field', [
            'cfg' => $cfg,
            'telegramAutoSubscribeId' => 'telegram_auto_subscribe_strategy',
        ])
        <div class="space-y-1 sm:col-span-2">
            <p id="telegram-process-status-strategy" class="text-xs text-zinc-500">Проверка poll…</p>
        </div>
        <div class="flex flex-col justify-end gap-2">
            <button type="button" id="telegram-discover-btn-strategy"
                class="rounded border border-zinc-600 bg-zinc-800 px-3 py-2 text-xs font-medium text-zinc-200 hover:bg-zinc-700">
                Получить chat_id
            </button>
            <button type="button" id="telegram-test-btn-strategy"
                class="rounded border border-blue-700 bg-blue-950/50 px-3 py-2 text-xs font-medium text-blue-200 hover:bg-blue-900/50">
                Отправить тест
            </button>
            <button type="button" id="telegram-restart-btn-strategy"
                class="rounded border border-amber-700 bg-amber-950/40 px-3 py-2 text-xs font-medium text-amber-200 hover:bg-amber-900/50">
                Перезапустить poll
            </button>
        </div>
    </div>
    <p id="telegram-action-status-strategy" class="text-xs text-zinc-500"></p>
    <p class="text-[11px] text-zinc-500">
        Сначала сохраните token. Затем напишите <span class="font-mono">/start</span>
        @if ($telegramBotUsername !== '')
            именно
            <a href="https://t.me/{{ $telegramBotUsername }}" target="_blank" rel="noopener" class="text-blue-400 hover:underline">{{ '@'.$telegramBotUsername }}</a>
        @else
            именно этому боту (не @trend1123_bot и не другим ботам платформы)
        @endif.
        Нажмите «Получить chat_id» и сразу ещё раз /start — сервер ждёт сообщение до 15 секунд (poll на это время останавливается).
        Для автоподписки после сохранения должен гореть <span class="font-mono">poll ✓</span>.
    </p>
</div>

<script>
(() => {
    // API discover-chat возвращает {ok, chats:[{id,...}], hint}, а не плоский chat_id.
    const status = document.getElementById('telegram-action-status-strategy');
    const processStatusEl = document.getElementById('telegram-process-status-strategy');
    const chatId = document.getElementById('telegram_chat_id_strategy');
    const discoverBtn = document.getElementById('telegram-discover-btn-strategy');
    const testBtn = document.getElementById('telegram-test-btn-strategy');
    const restartBtn = document.getElementById('telegram-restart-btn-strategy');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const slStatusUrl = @json(route('bots.telegram.strong-level-status', $bot));
    const slRestartUrl = @json(route('bots.telegram.strong-level-restart', $bot));
    const setStatus = (text, error = false) => {
        status.textContent = text;
        status.className = `text-xs ${error ? 'text-rose-400' : 'text-emerald-300'}`;
    };
    const renderProcessStatus = (data) => {
        if (!processStatusEl || !data) return;
        const poll = data.poll_running ? 'poll ✓' : 'poll ✗';
        const color = data.poll_running ? 'text-emerald-300' : 'text-amber-300';
        processStatusEl.textContent = 'Процесс автоподписки: ' + poll
            + (Array.isArray(data.poll_pids) && data.poll_pids.length ? ' (pid ' + data.poll_pids.join(', ') + ')' : '');
        processStatusEl.className = 'text-xs ' + color;
    };
    const refreshProcessStatus = async () => {
        if (!processStatusEl) return;
        try {
            const res = await fetch(slStatusUrl, { headers: { Accept: 'application/json' } });
            const data = await res.json();
            if (res.ok && data.ok) renderProcessStatus(data);
            else processStatusEl.textContent = 'Не удалось проверить poll';
        } catch (e) {
            processStatusEl.textContent = 'Ошибка проверки poll';
        }
    };
    const request = async (url) => {
        const response = await fetch(url, {
            method: 'POST',
            headers: {'X-CSRF-TOKEN': csrf, Accept: 'application/json'},
        });
        const data = await response.json();
        if (!response.ok || !data.ok) {
            throw new Error(data.error || data.hint || 'Не удалось выполнить запрос');
        }
        return data;
    };
    refreshProcessStatus();
    restartBtn?.addEventListener('click', async () => {
        setStatus('Перезапуск poll…');
        restartBtn.disabled = true;
        try {
            const data = await request(slRestartUrl);
            setStatus(data.message || 'Poll перезапущен.');
            if (data.status) renderProcessStatus(data.status);
            else await refreshProcessStatus();
        } catch (error) {
            setStatus(error.message, true);
            await refreshProcessStatus();
        } finally {
            restartBtn.disabled = false;
        }
    });
    discoverBtn?.addEventListener('click', async () => {
        setStatus('Останавливаем poll и ждём /start (до 15 сек)…');
        discoverBtn.disabled = true;
        try {
            const data = await request('{{ route('bots.telegram.discover-chat', $bot) }}');
            const chats = data.chats || [];
            if (chats.length === 0) {
                setStatus(data.hint || 'Чатов не найдено — сохраните token, напишите /start этому боту и нажмите кнопку снова.', true);
                await refreshProcessStatus();
                return;
            }
            const first = chats[0];
            if (chatId && first.id) {
                chatId.value = first.id;
            }
            const label = first.username ? '@' + first.username : (first.first_name || first.title || first.id);
            setStatus('Найден chat_id: ' + first.id + ' (' + label + '). Нажмите «Сохранить».');
            await refreshProcessStatus();
        } catch (error) {
            setStatus(error.message, true);
            await refreshProcessStatus();
        } finally {
            discoverBtn.disabled = false;
        }
    });
    testBtn?.addEventListener('click', async () => {
        setStatus('Отправка тестового сообщения…');
        try {
            await request('{{ route('bots.telegram.test', $bot) }}');
            setStatus('Тестовое сообщение отправлено.');
        } catch (error) {
            setStatus(error.message, true);
        }
    });
})();
</script>
