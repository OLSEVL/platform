{{--
  Общий блок Telegram + Chart Analyst LLM для binary CALL/PUT ботов
  (pinbar / williams / three_soldier / cci / rsi_trade / rvi).
  Ids с суффиксом _ose, чтобы не конфликтовать с BB/RSI partials на одной странице.
--}}
<div class="space-y-4 rounded border border-zinc-700/80 bg-zinc-900/40 p-4">
    <div class="space-y-1">
        <h3 class="text-sm font-semibold text-zinc-200">Telegram-алерты</h3>
        <p class="text-[11px] leading-snug text-zinc-500">
            Когда бот открыл бумажную сделку, можно сразу получить сообщение в Telegram: короткий текст и по желанию картинки графика (1 минута и/или 5 минут). Так вы видите сигнал, даже не сидя у экрана.
        </p>
    </div>
    <label class="flex items-start gap-2">
        <input type="checkbox" name="telegram_enabled" id="telegram_enabled_ose" value="1"
            class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
            @checked(old('telegram_enabled', $cfg['telegram_enabled'] ?? false))>
        <span>
            <span class="text-sm font-medium text-zinc-300">Включить Telegram</span>
            @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('telegram_enabled')])
        </span>
    </label>
    @include('bots._telegram-notify-result-field', [
        'cfg' => $cfg,
        'telegramNotifyResultId' => 'telegram_notify_result_ose',
    ])
    <div class="grid gap-4 sm:grid-cols-2">
        <div class="space-y-1">
            <label for="telegram_notify_on_ose" class="block text-xs font-medium text-zinc-400">Когда уведомлять</label>
            @php $telegramNotifyOn = old('telegram_notify_on', $cfg['telegram_notify_on'] ?? 'signal_trade'); @endphp
            <select name="telegram_notify_on" id="telegram_notify_on_ose" required
                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                <option value="signal_trade" @selected($telegramNotifyOn === 'signal_trade')>Новый сигнал + paper-сделка</option>
                <option value="off" @selected($telegramNotifyOn === 'off')>Не уведомлять</option>
            </select>
            @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('telegram_notify_on')])
        </div>
        <div class="space-y-1">
            <label for="telegram_alert_format_ose" class="block text-xs font-medium text-zinc-400">Формат сообщения</label>
            @php $telegramAlertFormat = old('telegram_alert_format', $cfg['telegram_alert_format'] ?? 'brief'); @endphp
            <select name="telegram_alert_format" id="telegram_alert_format_ose" required
                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                <option value="brief" @selected($telegramAlertFormat === 'brief')>Краткий</option>
                <option value="detailed" @selected($telegramAlertFormat === 'detailed')>Развёрнутый</option>
                <option value="card" @selected($telegramAlertFormat === 'card')>Как на карточке</option>
            </select>
            @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('telegram_alert_format')])
        </div>
        <div class="space-y-1">
            <label for="telegram_send_chart_ose" class="block text-xs font-medium text-zinc-400">График M1</label>
            @php $telegramSendChart = old('telegram_send_chart', ($cfg['telegram_send_chart'] ?? true) ? '1' : '0'); @endphp
            <select name="telegram_send_chart" id="telegram_send_chart_ose" required
                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                <option value="1" @selected((string) $telegramSendChart === '1')>Текст + график M1</option>
                <option value="0" @selected((string) $telegramSendChart === '0')>Только текст</option>
            </select>
            @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('telegram_send_chart')])
        </div>
        @if (\App\Support\ImpulseLvl1minConfig::is((string) ($bot->type ?? '')))
            <div class="space-y-1">
                <label for="telegram_chart_candles_ose" class="block text-xs font-medium text-zinc-400">Свечей на PNG {{ \App\Support\ImpulseLvl1minConfig::isS15((string) ($bot->type ?? '')) ? 'S15' : 'M1' }}</label>
                <input type="number" name="telegram_chart_candles" id="telegram_chart_candles_ose"
                    min="20" max="80" required
                    value="{{ old('telegram_chart_candles', $cfg['telegram_chart_candles'] ?? 50) }}"
                    class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                <p class="text-[11px] text-zinc-500">Как TELEGRAM_CHART_CANDLES в updateBotM1 (дефолт 50). Сетка + золотая рамка сигнальной свечи.</p>
            </div>
        @endif
        <div class="space-y-1">
            <label for="telegram_send_chart_m5_ose" class="block text-xs font-medium text-zinc-400">График M5</label>
            @php $telegramSendChartM5 = old('telegram_send_chart_m5', ($cfg['telegram_send_chart_m5'] ?? true) ? '1' : '0'); @endphp
            <select name="telegram_send_chart_m5" id="telegram_send_chart_m5_ose" required
                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                <option value="1" @selected((string) $telegramSendChartM5 === '1')>Отправлять PNG M5</option>
                <option value="0" @selected((string) $telegramSendChartM5 === '0')>Только M1</option>
            </select>
            @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('telegram_send_chart_m5')])
        </div>
        <div class="space-y-1 sm:col-span-2">
            <label class="flex items-start gap-2">
                <input type="checkbox" name="telegram_analyst_llm_enabled" id="telegram_analyst_llm_enabled_ose" value="1"
                    class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                    @checked(old('telegram_analyst_llm_enabled', $cfg['telegram_analyst_llm_enabled'] ?? true))>
                <span>
                    <span class="text-sm font-medium text-zinc-300">Кнопка «{{ \App\Services\Telegram\StrongLevelTelegramLlmButton::BUTTON_LABEL }}»</span>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('telegram_analyst_llm_enabled')])
                </span>
            </label>
        </div>
        <div class="space-y-1">
            <label for="telegram_analyst_bot_id_ose" class="block text-xs font-medium text-zinc-400">Бот Chart Analyst</label>
            @php $selectedAnalystId = old('telegram_analyst_bot_id', $cfg['telegram_analyst_bot_id'] ?? ''); @endphp
            <select name="telegram_analyst_bot_id" id="telegram_analyst_bot_id_ose"
                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                <option value="">Авто (первый Chart Analyst)</option>
                @foreach ($chartAnalystBots ?? [] as $analystBot)
                    <option value="{{ $analystBot->id }}" @selected((string) $selectedAnalystId === (string) $analystBot->id)>
                        #{{ $analystBot->id }} — {{ $analystBot->name }}@if(!$analystBot->is_active) (выкл.)@endif
                    </option>
                @endforeach
            </select>
            @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('telegram_analyst_bot_id')])
        </div>
        <div class="space-y-1 sm:col-span-2">
            <label for="telegram_analyst_llm_question_ose" class="block text-xs font-medium text-zinc-400">Вопрос к LLM</label>
            <textarea name="telegram_analyst_llm_question" id="telegram_analyst_llm_question_ose" rows="2"
                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">{{ old('telegram_analyst_llm_question', $cfg['telegram_analyst_llm_question'] ?? \App\Services\Telegram\StrongLevelTelegramLlmButton::DEFAULT_LLM_QUESTION) }}</textarea>
            @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('telegram_analyst_llm_question')])
        </div>
        <div class="space-y-1 sm:col-span-2">
            <p id="telegram-process-status-ose" class="text-xs text-zinc-500">Проверка poll…</p>
        </div>
        <div class="space-y-1 sm:col-span-2">
            @include('bots._telegram-bot-token-field', [
                'inputId' => 'telegram_bot_token_ose',
                'hint' => 'Токен Telegram-бота. Пустое поле — оставить текущий.',
            ])
        </div>
        <div class="space-y-1">
            <label for="telegram_chat_id_ose" class="block text-xs font-medium text-zinc-400">Chat ID</label>
            <input type="text" name="telegram_chat_id" id="telegram_chat_id_ose"
                value="{{ old('telegram_chat_id', $cfg['telegram_chat_id'] ?? '') }}"
                placeholder="123456789"
                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100 font-mono">
            @include('partials.field-hint', ['text' => 'Номер чата, куда слать сообщения (ваш личный chat id или id группы). Можно получить кнопкой «Получить chat_id» ниже, написав боту в Telegram.'])
        </div>
                @include('bots._telegram-auto-subscribe-field', [
            'cfg' => $cfg,
            'telegramAutoSubscribeId' => 'telegram_auto_subscribe_ose',
        ])
        <div class="space-y-1 flex flex-col justify-end gap-2">
            <button type="button" id="telegram-discover-btn-ose"
                class="rounded border border-zinc-600 bg-zinc-800 px-3 py-2 text-xs font-medium text-zinc-200 hover:bg-zinc-700">Получить chat_id</button>
            <button type="button" id="telegram-test-btn-ose"
                class="rounded border border-blue-700 bg-blue-950/50 px-3 py-2 text-xs font-medium text-blue-200 hover:bg-blue-900/50">Отправить тест</button>
            <button type="button" id="telegram-restart-btn-ose"
                class="rounded border border-amber-700 bg-amber-950/40 px-3 py-2 text-xs font-medium text-amber-200 hover:bg-amber-900/50">Перезапустить poll</button>
        </div>
    </div>
    <p id="telegram-action-status-ose" class="text-xs text-zinc-500"></p>
</div>
<script>
(function () {
    const discoverBtn = document.getElementById('telegram-discover-btn-ose');
    const testBtn = document.getElementById('telegram-test-btn-ose');
    const restartBtn = document.getElementById('telegram-restart-btn-ose');
    const statusEl = document.getElementById('telegram-action-status-ose');
    const processStatusEl = document.getElementById('telegram-process-status-ose');
    const chatInput = document.getElementById('telegram_chat_id_ose');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const discoverUrl = @json(route('bots.telegram.discover-chat', $bot));
    const testUrl = @json(route('bots.telegram.test', $bot));
    const slStatusUrl = @json(route('bots.telegram.strong-level-status', $bot));
    const slRestartUrl = @json(route('bots.telegram.strong-level-restart', $bot));

    function setStatus(text, isError) {
        if (!statusEl) return;
        statusEl.textContent = text || '';
        statusEl.className = 'text-xs ' + (isError ? 'text-rose-400' : 'text-emerald-300');
    }

    function renderProcessStatus(data) {
        if (!processStatusEl || !data) return;
        const poll = data.poll_running ? 'poll ✓' : 'poll ✗';
        const queue = data.queue_running ? 'queue ✓' : 'queue ✗';
        const color = data.poll_running && data.queue_running ? 'text-emerald-300' : 'text-amber-300';
        processStatusEl.textContent = 'Процессы: ' + poll + ', ' + queue;
        processStatusEl.className = 'text-xs ' + color;
    }

    async function refreshProcessStatus() {
        if (!processStatusEl) return;
        try {
            const res = await fetch(slStatusUrl, { headers: { 'Accept': 'application/json' } });
            const data = await res.json();
            if (res.ok && data.ok) renderProcessStatus(data);
        } catch (e) { /* ignore */ }
    }
    refreshProcessStatus();

    if (restartBtn) {
        restartBtn.addEventListener('click', async function () {
            setStatus('Перезапуск…', false);
            restartBtn.disabled = true;
            try {
                const res = await fetch(slRestartUrl, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                });
                const data = await res.json();
                setStatus(data.ok ? (data.message || 'OK') : (data.error || 'Ошибка'), !data.ok);
                if (data.status) renderProcessStatus(data.status);
            } catch (e) {
                setStatus('Сеть: ' + e.message, true);
            } finally {
                restartBtn.disabled = false;
            }
        });
    }

    if (discoverBtn) {
        discoverBtn.addEventListener('click', async function () {
            setStatus('Ждём /start…', false);
            discoverBtn.disabled = true;
            try {
                const res = await fetch(discoverUrl, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                });
                const data = await res.json();
                if (!res.ok || !data.ok) {
                    setStatus(data.error || data.hint || 'Ошибка', true);
                    return;
                }
                const chats = data.chats || [];
                if (chats.length === 0) {
                    setStatus('Чатов не найдено — напишите /start боту', true);
                    return;
                }
                if (chatInput && chats[0].id) chatInput.value = chats[0].id;
                setStatus('Найден chat_id: ' + chats[0].id, false);
            } catch (e) {
                setStatus('Сеть: ' + e.message, true);
            } finally {
                discoverBtn.disabled = false;
            }
        });
    }

    if (testBtn) {
        testBtn.addEventListener('click', async function () {
            setStatus('Отправка…', false);
            try {
                const res = await fetch(testUrl, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                });
                const data = await res.json();
                setStatus(data.ok ? 'Тест отправлен' : (data.error || 'Ошибка'), !data.ok);
            } catch (e) {
                setStatus('Сеть: ' + e.message, true);
            }
        });
    }
})();
</script>
