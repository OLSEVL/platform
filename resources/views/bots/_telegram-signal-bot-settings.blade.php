@php
    $telegramUiKey = $telegramUiKey ?? 'signal-bot';
    $telegramBotUsername = $telegramBotUsername ?? 'strong_level_bot';
    $telegramNotifyHint = $telegramNotifyHint ?? 'По умолчанию — при каждой созданной сделке.';
    $telegramLlmHint = $telegramLlmHint ?? 'Inline-кнопка под алертом: LLM-анализ Chart Analyst в этом же чате. Нужен poll (кнопка ниже).';
    $telegramChartHint = $telegramChartHint ?? 'С графиком: свечи M1, зона подтверждения M1×3 и маркер входа.';
@endphp

<div class="space-y-3 rounded border border-zinc-800 bg-zinc-900/40 p-4">
    <p class="text-sm font-medium text-zinc-300">Telegram</p>
    <p class="text-[11px] text-zinc-500">Push при новом сигнале и paper-сделке (cron <code class="text-zinc-400">bots:scan</code>). Token и chat_id — как у других ботов, один получатель.</p>
    <label class="flex items-start gap-2">
        <input type="checkbox" name="telegram_enabled" id="telegram_enabled_{{ $telegramUiKey }}" value="1"
            class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
            @checked(old('telegram_enabled', $cfg['telegram_enabled'] ?? false))>
        <span>
            <span class="text-sm font-medium text-zinc-300">Отправлять алерты в Telegram</span>
            <span class="mt-0.5 block text-[11px] font-normal text-zinc-500">Без галочки сделки создаются в платформе, но сообщения в Telegram не уходят.</span>
        </span>
    </label>
    <div class="grid gap-4 sm:grid-cols-2">
        <div class="space-y-1">
            <label for="telegram_notify_on_{{ $telegramUiKey }}" class="block text-xs font-medium text-zinc-400">Когда уведомлять</label>
            @php $telegramNotifyOn = old('telegram_notify_on', $cfg['telegram_notify_on'] ?? 'signal_trade'); @endphp
            <select name="telegram_notify_on" id="telegram_notify_on_{{ $telegramUiKey }}" required
                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                <option value="signal_trade" @selected($telegramNotifyOn === 'signal_trade')>Новый сигнал + paper-сделка</option>
                <option value="off" @selected($telegramNotifyOn === 'off')>Не уведомлять (даже если Telegram включён)</option>
            </select>
            <p class="text-[11px] text-zinc-500">{{ $telegramNotifyHint }} «Не уведомлять» — для отладки без спама.</p>
            @error('telegram_notify_on')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
        </div>
        <div class="space-y-1">
            <label for="telegram_alert_format_{{ $telegramUiKey }}" class="block text-xs font-medium text-zinc-400">Формат сообщения</label>
            @php $telegramAlertFormat = old('telegram_alert_format', $cfg['telegram_alert_format'] ?? 'brief'); @endphp
            <select name="telegram_alert_format" id="telegram_alert_format_{{ $telegramUiKey }}" required
                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                <option value="brief" @selected($telegramAlertFormat === 'brief')>Краткий</option>
                <option value="detailed" @selected($telegramAlertFormat === 'detailed')>Развёрнутый</option>
                <option value="card" @selected($telegramAlertFormat === 'card')>Как на карточке</option>
            </select>
            <p class="text-[11px] text-zinc-500">Краткий: пара, сторона, экспирация. Развёрнутый: + confidence, M5/M1 метрики, причины.</p>
            @error('telegram_alert_format')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
        </div>
        <div class="space-y-1">
            <label for="telegram_send_chart_{{ $telegramUiKey }}" class="block text-xs font-medium text-zinc-400">График в алерте</label>
            @php
                $telegramSendChart = old('telegram_send_chart');
                if ($telegramSendChart === null) {
                    $telegramSendChart = ($cfg['telegram_send_chart'] ?? true) ? '1' : '0';
                }
            @endphp
            <select name="telegram_send_chart" id="telegram_send_chart_{{ $telegramUiKey }}" required
                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                <option value="1" @selected((string) $telegramSendChart === '1')>Текст + график M1 (PNG)</option>
                <option value="0" @selected((string) $telegramSendChart === '0')>Только текст (без картинки)</option>
            </select>
            <p class="text-[11px] text-zinc-500">{{ $telegramChartHint }}</p>
            @error('telegram_send_chart')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
        </div>
        <div class="space-y-1 sm:col-span-2">
            <label class="flex items-start gap-2">
                <input type="checkbox" name="telegram_analyst_llm_enabled" id="telegram_analyst_llm_enabled_{{ $telegramUiKey }}" value="1"
                    class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                    @checked(old('telegram_analyst_llm_enabled', $cfg['telegram_analyst_llm_enabled'] ?? true))>
                <span>
                    <span class="text-sm font-medium text-zinc-300">Кнопка «{{ \App\Services\Telegram\StrongLevelTelegramLlmButton::BUTTON_LABEL }}»</span>
                    <span class="mt-0.5 block text-[11px] font-normal text-zinc-500">{{ $telegramLlmHint }}</span>
                </span>
            </label>
            @error('telegram_analyst_llm_enabled')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
        </div>
        <div class="space-y-1">
            <label for="telegram_analyst_bot_id_{{ $telegramUiKey }}" class="block text-xs font-medium text-zinc-400">Бот Chart Analyst</label>
            @php $selectedAnalystId = old('telegram_analyst_bot_id', $cfg['telegram_analyst_bot_id'] ?? ''); @endphp
            <select name="telegram_analyst_bot_id" id="telegram_analyst_bot_id_{{ $telegramUiKey }}"
                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                <option value="">Авто (первый Chart Analyst)</option>
                @foreach ($chartAnalystBots ?? [] as $analystBot)
                    <option value="{{ $analystBot->id }}" @selected((string) $selectedAnalystId === (string) $analystBot->id)>
                        #{{ $analystBot->id }} — {{ $analystBot->name }}@if(!$analystBot->is_active) (выкл.)@endif
                    </option>
                @endforeach
            </select>
            @error('telegram_analyst_bot_id')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
        </div>
        <div class="space-y-1 sm:col-span-2">
            <label for="telegram_analyst_llm_question_{{ $telegramUiKey }}" class="block text-xs font-medium text-zinc-400">Вопрос к LLM по кнопке</label>
            <textarea name="telegram_analyst_llm_question" id="telegram_analyst_llm_question_{{ $telegramUiKey }}" rows="2"
                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">{{ old('telegram_analyst_llm_question', $cfg['telegram_analyst_llm_question'] ?? \App\Services\Telegram\StrongLevelTelegramLlmButton::DEFAULT_LLM_QUESTION) }}</textarea>
            <p class="text-[11px] text-zinc-500">Подставляется в RunTelegramAnalystAnalysisJob вместо шага «ваш вопрос».</p>
            @error('telegram_analyst_llm_question')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
        </div>
        <div class="space-y-1 sm:col-span-2">
            <p id="telegram-process-status-{{ $telegramUiKey }}" class="text-xs text-zinc-500">Проверка poll…</p>
        </div>
        <div class="space-y-1">
            @include('bots._telegram-bot-token-field', [
                'inputId' => 'telegram_bot_token_'.$telegramUiKey,
                'hint' => 'Токен от @BotFather (напр. @'.$telegramBotUsername.'). Пустое поле при сохранении — оставить текущий.',
            ])
        </div>
        <div class="space-y-1">
            <label for="telegram_chat_id_{{ $telegramUiKey }}" class="block text-xs font-medium text-zinc-400">Chat ID</label>
            <input type="text" name="telegram_chat_id" id="telegram_chat_id_{{ $telegramUiKey }}"
                value="{{ old('telegram_chat_id', $cfg['telegram_chat_id'] ?? '') }}"
                placeholder="123456789"
                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100 font-mono">
            <p class="text-[11px] text-zinc-500">Один chat_id — личный чат, группа или канал.</p>
            @error('telegram_chat_id')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
        </div>
        <div class="space-y-1 flex flex-col justify-end gap-2">
            <button type="button" id="telegram-discover-btn-{{ $telegramUiKey }}"
                class="rounded border border-zinc-600 bg-zinc-800 px-3 py-2 text-xs font-medium text-zinc-200 hover:bg-zinc-700">
                Получить chat_id
            </button>
            <button type="button" id="telegram-test-btn-{{ $telegramUiKey }}"
                class="rounded border border-blue-700 bg-blue-950/50 px-3 py-2 text-xs font-medium text-blue-200 hover:bg-blue-900/50">
                Отправить тест
            </button>
            <button type="button" id="telegram-restart-btn-{{ $telegramUiKey }}"
                class="rounded border border-amber-700 bg-amber-950/40 px-3 py-2 text-xs font-medium text-amber-200 hover:bg-amber-900/50">
                Перезапустить poll + queue
            </button>
        </div>
    </div>
    <p id="telegram-action-status-{{ $telegramUiKey }}" class="text-xs text-zinc-500"></p>
    <p class="text-[11px] text-zinc-500">
        Напишите боту
        <a href="https://t.me/{{ $telegramBotUsername }}" target="_blank" rel="noopener" class="text-blue-400 hover:underline">@{{ $telegramBotUsername }}</a>
        → /start, сохраните token, затем «Получить chat_id» (нажмите кнопку и сразу /start в Telegram — poll на 15 сек останавливается).
    </p>
</div>
<script>
(function () {
    const uiKey = @json($telegramUiKey);
    const botUsername = @json($telegramBotUsername);
    const discoverBtn = document.getElementById('telegram-discover-btn-' + uiKey);
    const testBtn = document.getElementById('telegram-test-btn-' + uiKey);
    const restartBtn = document.getElementById('telegram-restart-btn-' + uiKey);
    const statusEl = document.getElementById('telegram-action-status-' + uiKey);
    const processStatusEl = document.getElementById('telegram-process-status-' + uiKey);
    const chatInput = document.getElementById('telegram_chat_id_' + uiKey);
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
            else processStatusEl.textContent = 'Не удалось проверить процессы';
        } catch (e) {
            processStatusEl.textContent = 'Ошибка проверки процессов';
        }
    }

    refreshProcessStatus();

    if (restartBtn) {
        restartBtn.addEventListener('click', async function () {
            setStatus('Перезапуск poll и queue worker…', false);
            restartBtn.disabled = true;
            try {
                const res = await fetch(slRestartUrl, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                });
                const data = await res.json();
                if (!res.ok || !data.ok) {
                    setStatus(data.error || data.message || 'Не удалось перезапустить', true);
                    if (data.status) renderProcessStatus(data.status);
                    return;
                }
                setStatus(data.message || 'Перезапущено', false);
                renderProcessStatus(data.status);
            } catch (e) {
                setStatus('Сеть: ' + e.message, true);
            } finally {
                restartBtn.disabled = false;
            }
        });
    }

    if (discoverBtn) {
        discoverBtn.addEventListener('click', async function () {
            setStatus('Останавливаем poll и ждём /start (до 15 сек)…', false);
            discoverBtn.disabled = true;
            try {
                const res = await fetch(discoverUrl, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                });
                const data = await res.json();
                if (!res.ok || !data.ok) {
                    setStatus(data.error || data.hint || 'Ошибка getUpdates', true);
                    return;
                }
                const chats = data.chats || [];
                if (chats.length === 0) {
                    setStatus(data.hint || ('Чатов не найдено — нажмите кнопку и сразу /start @' + botUsername), true);
                    return;
                }
                const first = chats[0];
                if (chatInput && first.id) chatInput.value = first.id;
                const label = first.username ? '@' + first.username : (first.first_name || first.title || first.id);
                setStatus('Найден chat_id: ' + first.id + ' (' + label + '). Сохраните настройки.', false);
            } catch (e) {
                setStatus('Сеть: ' + e.message, true);
            } finally {
                discoverBtn.disabled = false;
            }
        });
    }

    if (testBtn) {
        testBtn.addEventListener('click', async function () {
            setStatus('Отправка теста… (сначала сохраните настройки, если меняли token/chat_id)', false);
            try {
                const res = await fetch(testUrl, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                });
                const data = await res.json();
                if (!res.ok || !data.ok) {
                    setStatus(data.error || 'Не удалось отправить', true);
                    return;
                }
                setStatus('Тест отправлен', false);
            } catch (e) {
                setStatus('Сеть: ' + e.message, true);
            }
        });
    }
})();
</script>
