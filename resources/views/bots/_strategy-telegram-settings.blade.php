{{--
    Общие Telegram-поля для стратегий без карточки «сильного уровня».
    Алерт отправляется только после создания реальной paper-сделки; виртуальные
    проверки Strong Trend + DeMarker в Telegram не попадают.

    Автоподписка работает так же, как у остальных ботов: фоновый
    telegram:strong-level-poll слушает /start и пишет chat_id в telegram_subscribers.
--}}
@php
    $telegramBotUsername = $telegramBotUsername ?? match ($bot->type ?? '') {
        'strong_trend_demarker', \App\Support\DemarkerDualFastConfig::BOT_TYPE => 'strong_DeMarker_bot',
        default => '',
    };
@endphp
<div class="space-y-3 rounded border border-zinc-800 bg-zinc-900/40 p-4">
    <p class="text-sm font-medium text-zinc-300">Telegram</p>
    <p class="text-[11px] text-zinc-500">
        Уведомление о новой paper-сделке и, при включённой опции, её результате.
        Получатели: Chat ID из настроек + чаты, которые написали <span class="font-mono">/start</span> (если автоподписка включена).
        Сначала сохраните token.
        Для Sweep / Raid Liquidity к алерту прикрепляется график M1 с фракталами, SWEEP/RAID/PB и точкой входа
        (и опционально второй — зум на последние N свечей).
        Для Strong Trend + DeMarker — график свечей с панелью DeMarker.
        Для SMC Flow — график M1 с метками UT / 3BR и зоной Order Block.
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
            <p class="text-[11px] text-zinc-500">Sweep / Raid: FH/FL, свип, рейд, откат и точка входа. DeMarker: свечи + панель осциллятора. SMC Flow: UT, 3BR и зона OB. Engulfing: PNG как у Impulse LVL (#47) — сетка, N свечей, рамка последней.</p>
        </div>
        @if (in_array($bot->type ?? '', ['engulfing_liquidity', \App\Support\AuthorFlowConfig::BOT_TYPE], true))
            @php
                $tgChartCfgClass = ($bot->type ?? '') === \App\Support\AuthorFlowConfig::BOT_TYPE
                    ? \App\Support\AuthorFlowConfig::class
                    : \App\Support\EngulfingLiquidityConfig::class;
            @endphp
            <div class="space-y-1">
                <label for="telegram_chart_candles_strategy" class="block text-xs font-medium text-zinc-400">Свечей на PNG (как #47)</label>
                <input type="number" name="telegram_chart_candles" id="telegram_chart_candles_strategy"
                    min="{{ $tgChartCfgClass::MIN_TELEGRAM_CHART_CANDLES }}"
                    max="{{ $tgChartCfgClass::MAX_TELEGRAM_CHART_CANDLES }}"
                    required
                    value="{{ old('telegram_chart_candles', $cfg['telegram_chart_candles'] ?? $tgChartCfgClass::DEFAULT_TELEGRAM_CHART_CANDLES) }}"
                    class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('telegram_chart_candles', $bot->type ?? '')])
                @error('telegram_chart_candles')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
            </div>
            <div class="space-y-1 sm:col-span-2">
                <input type="hidden" name="telegram_show_nearest_level" value="0">
                <label class="flex items-start gap-2">
                    <input type="checkbox" name="telegram_show_nearest_level" id="telegram_show_nearest_level_strategy" value="1"
                        class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                        @checked(old('telegram_show_nearest_level', $cfg['telegram_show_nearest_level'] ?? true))>
                    <span>
                        <span class="text-sm font-medium text-zinc-300">Линия ближайшего уровня (FH/FL)</span>
                        <span class="mt-0.5 block text-[11px] font-normal text-zinc-500">На PNG — горизонталь ближайшей поддержки (FL) для CALL или сопротивления (FH) для PUT. Видно, будет ли отбой от уровня.</span>
                    </span>
                </label>
                @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('telegram_show_nearest_level', $bot->type ?? '')])
            </div>
            <div class="space-y-1">
                <label for="telegram_level_lookback_strategy" class="block text-xs font-medium text-zinc-400">Lookback уровня на PNG</label>
                <input type="number" name="telegram_level_lookback" id="telegram_level_lookback_strategy"
                    min="0" max="200"
                    value="{{ old('telegram_level_lookback', $cfg['telegram_level_lookback'] ?? 0) }}"
                    class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                <p class="text-[11px] text-zinc-500">Сколько баров назад искать фрактал для линии. <span class="font-mono">0</span> = как «Lookback фракталов» в фильтрах стратегии.</p>
                @error('telegram_level_lookback')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
            </div>
        @endif
        @if (($bot->type ?? '') === 'sweep_raid_liquidity')
            <div class="space-y-1">
                <label class="flex items-start gap-2">
                    <input type="hidden" name="telegram_send_chart_recent" value="0">
                    <input type="checkbox" name="telegram_send_chart_recent" id="telegram_send_chart_recent_strategy" value="1"
                        class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                        @checked(old('telegram_send_chart_recent', $cfg['telegram_send_chart_recent'] ?? true))>
                    <span>
                        <span class="text-sm font-medium text-zinc-300">Второй график: зум на последние свечи</span>
                        <span class="mt-0.5 block text-[11px] font-normal text-zinc-500">Компактный PNG только с хвостом рынка — удобнее смотреть метки входа на телефоне.</span>
                    </span>
                </label>
                @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('telegram_send_chart_recent')])
                @error('telegram_send_chart_recent')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
            </div>
            <div class="space-y-1">
                <label for="telegram_chart_recent_bars_strategy" class="block text-xs font-medium text-zinc-400">Свечей на зум-графике</label>
                <input type="number" name="telegram_chart_recent_bars" id="telegram_chart_recent_bars_strategy"
                    min="5" max="90" required
                    value="{{ old('telegram_chart_recent_bars', $cfg['telegram_chart_recent_bars'] ?? 20) }}"
                    class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('telegram_chart_recent_bars')])
                @error('telegram_chart_recent_bars')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
            </div>
        @endif
        @if (in_array($bot->type ?? '', ['strong_trend_demarker', \App\Support\DemarkerDualFastConfig::BOT_TYPE, 'sweep_raid_liquidity', 'engulfing_liquidity', \App\Support\AuthorFlowConfig::BOT_TYPE, 'smc_flow'], true))
            {{-- Те же LLM-поля, что у Candle Reversal + Confirm: кнопка SMC Flow, выбор Chart Analyst и вопрос. --}}
            @php
                $llmCallbackBotHint = match ($bot->type ?? '') {
                    'strong_trend_demarker', \App\Support\DemarkerDualFastConfig::BOT_TYPE => '@strong_DeMarker_bot',
                    'smc_flow' => 'этом Telegram-боте (token SMC Flow)',
                    \App\Support\AuthorFlowConfig::BOT_TYPE => 'этом Telegram-боте (token Author Flow)',
                    default => 'этом Telegram-боте (token Sweep / Raid)',
                };
            @endphp
            <div class="space-y-1 sm:col-span-2">
                <label class="flex items-start gap-2">
                    <input type="checkbox" name="telegram_analyst_llm_enabled" id="telegram_analyst_llm_enabled_strategy" value="1"
                        class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                        @checked(old('telegram_analyst_llm_enabled', $cfg['telegram_analyst_llm_enabled'] ?? true))>
                    <span>
                        <span class="text-sm font-medium text-zinc-300">Кнопка «{{ \App\Services\Telegram\StrongLevelTelegramLlmButton::BUTTON_LABEL }}»</span>
                        <span class="mt-0.5 block text-[11px] font-normal text-zinc-500">Inline-кнопка под алертом: callback в {{ $llmCallbackBotHint }} → LLM Chart Analyst в этом же чате. Нужен poll (кнопка ниже).</span>
                    </span>
                </label>
                @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('telegram_analyst_llm_enabled')])
                @error('telegram_analyst_llm_enabled')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
            </div>
            <div class="space-y-1">
                <label for="telegram_analyst_bot_id_strategy" class="block text-xs font-medium text-zinc-400">Бот Chart Analyst</label>
                @php $selectedAnalystIdStrategy = old('telegram_analyst_bot_id', $cfg['telegram_analyst_bot_id'] ?? ''); @endphp
                <select name="telegram_analyst_bot_id" id="telegram_analyst_bot_id_strategy"
                    class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                    <option value="">Авто (первый Chart Analyst)</option>
                    @foreach ($chartAnalystBots ?? [] as $analystBot)
                        <option value="{{ $analystBot->id }}" @selected((string) $selectedAnalystIdStrategy === (string) $analystBot->id)>
                            #{{ $analystBot->id }} — {{ $analystBot->name }}@if(!$analystBot->is_active) (выкл.)@endif
                        </option>
                    @endforeach
                </select>
                @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('telegram_analyst_bot_id')])
                @error('telegram_analyst_bot_id')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
            </div>
            <div class="space-y-1 sm:col-span-2">
                <label for="telegram_analyst_llm_question_strategy" class="block text-xs font-medium text-zinc-400">Вопрос к LLM по кнопке</label>
                <textarea name="telegram_analyst_llm_question" id="telegram_analyst_llm_question_strategy" rows="2"
                    class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">{{ old('telegram_analyst_llm_question', $cfg['telegram_analyst_llm_question'] ?? \App\Services\Telegram\StrongLevelTelegramLlmButton::DEFAULT_LLM_QUESTION) }}</textarea>
                @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('telegram_analyst_llm_question')])
                @error('telegram_analyst_llm_question')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
            </div>
        @endif
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
                @if (in_array($bot->type ?? '', ['engulfing_liquidity', \App\Support\AuthorFlowConfig::BOT_TYPE, \App\Support\ImpulseLvl1minConfig::BOT_TYPE, \App\Support\ImpulseLvl1minConfig::BOT_TYPE_S15], true))
                    Перезапустить poll + live
                @else
                    Перезапустить poll
                @endif
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
