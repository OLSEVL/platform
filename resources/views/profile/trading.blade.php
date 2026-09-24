@extends('profile._layout')
@section('title', 'Профиль — Торговля')

@section('profile_content')
<div class="rounded border border-zinc-800 bg-zinc-900/60 p-5 space-y-5">
    <div>
        <h2 class="text-lg font-medium text-slate-100">Торговля</h2>
        <p class="mt-1 text-sm text-zinc-400">
            При включённом зеркалировании каждая paper-сделка бота отправляет один ордер на Pocket Option
            с теми же парой, направлением и длительностью. SSID и demo/real — <strong>только из этой формы</strong>;
            токены в разделе «Инструменты» используются только для котировок (ingest), не для зеркала.
        </p>
    </div>

    <div class="rounded border border-zinc-700 bg-zinc-950/50 px-4 py-3 text-xs space-y-1">
        <p class="font-medium text-zinc-300">Статус зеркалирования</p>
        <p class="{{ ($systemStatus['proxy_ok'] ?? false) ? 'text-emerald-400' : 'text-rose-400' }}">
            Прокси {{ ($systemStatus['proxy_ok'] ?? false) ? 'доступен' : 'недоступен' }}
            @if ($systemStatus['proxy_ok'] ?? false)
                — place-order: {{ ($systemStatus['proxy_has_place_order'] ?? false) ? 'да' : 'нет (перезапустите proxy)' }}
            @endif
        </p>
        <p class="{{ (($systemStatus['pending_jobs'] ?? 0) === 0) ? 'text-emerald-400' : 'text-amber-300' }}">
            Задач в очереди: {{ $systemStatus['pending_jobs'] ?? 0 }}
            @if (($systemStatus['pending_jobs'] ?? 0) > 0)
                (нужен <code class="text-amber-200">php artisan queue:work</code> или сервис platform-queue)
            @endif
        </p>
        <p class="{{ ($systemStatus['ssid_format_ok'] ?? false) ? 'text-emerald-400' : 'text-rose-400' }}">
            SSID (формат PO): {{ ($systemStatus['ssid_format_ok'] ?? false) ? 'OK (session + uid + isDemo)' : 'неверный — вставьте auth из DevTools и сохраните' }}
        </p>
        @if (!empty($systemStatus['last_mirror_error']))
            <p class="text-rose-300">Последняя ошибка зеркала: {{ $systemStatus['last_mirror_error'] }}</p>
        @endif
    </div>

    @include('profile._mirror-warm-status', ['mirrorWarmStatus' => $mirrorWarmStatus ?? []])

    @if (session('status'))
        <p class="text-sm text-emerald-400">{{ session('status') }}</p>
    @endif
    @if ($errors->any())
        <div class="rounded border border-rose-800 bg-rose-950/40 px-4 py-3 text-sm text-rose-300">
            <p class="font-medium">Не удалось сохранить:</p>
            <ul class="mt-1 list-disc pl-5 text-xs">
                @foreach ($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Отдельная форма сброса мартингейла (нельзя вкладывать <form> в <form> — ломает «Сохранить»). --}}
    <form id="profile-trading-reset-martingale" method="POST" action="{{ route('profile.trading.reset-martingale') }}" class="hidden">
        @csrf
    </form>

    <form method="POST" action="{{ route('profile.trading.update') }}" class="space-y-5">
        @csrf
        @method('PUT')

        <div class="space-y-2">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <label for="pocket_ssid" class="text-xs font-medium text-zinc-400">SSID (Pocket Option)</label>
                <button type="button" id="btn-test-po-auth"
                    class="rounded border border-zinc-600 bg-zinc-800/80 px-3 py-1.5 text-xs font-medium text-zinc-100 hover:bg-zinc-700 transition-colors disabled:opacity-50 disabled:cursor-not-allowed">
                    Проверить SSID
                </button>
            </div>
            <textarea name="pocket_ssid" id="pocket_ssid" rows="4"
                placeholder="42[&quot;auth&quot;,{&quot;session&quot;:...}]"
                class="w-full rounded border border-zinc-700 bg-zinc-950 px-3 py-2 font-mono text-xs text-zinc-100 placeholder-zinc-600 focus:ring-2 focus:ring-blue-600 focus:border-transparent"
                autocomplete="off">{{ old('pocket_ssid', $settings->pocket_ssid) }}</textarea>
            <div id="po-auth-result" class="hidden rounded border px-3 py-2 text-xs" role="status" aria-live="polite"></div>
            @error('pocket_ssid')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
            <p class="text-xs text-zinc-500">
                Pocket Option принимает только полное сообщение <code class="text-zinc-400">42["auth",...]</code> — не один hex session.
                Если вставить только <code class="text-zinc-400">fac48ae8…</code>, платформа соберёт auth сама (нужен <code class="text-zinc-400">uid</code> из DevTools, иначе сохранение отклонится).
                Лучше копировать целиком из DevTools: Network → WS → кадр <code class="text-zinc-400">42["auth",...]</code>.
            </p>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div class="space-y-1 sm:col-span-2">
                <label for="order_amounts" class="block text-xs font-medium text-zinc-400">Цепочка сумм на PO ($), мартингейл</label>
                <input type="text" name="order_amounts" id="order_amounts" required
                    value="{{ old('order_amounts', $settings->orderAmountsInputString()) }}"
                    placeholder="1, 2, 2, 4, 8, 10"
                    class="w-full rounded border border-zinc-700 bg-zinc-950 px-3 py-2 text-sm text-zinc-100 font-mono">
                <p class="text-[11px] text-zinc-500">
                    После проигрыша (зеркало на PO) — следующее число; после выигрыша — снова первое. В конце списка — снова с начала.
                    Разделители: запятая или «;». Десятичная — точка (2.5). Каждая сумма от 1 до 20000.
                </p>
                @error('order_amounts')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                <div class="mt-2 flex flex-wrap items-center gap-3 rounded border border-zinc-700 bg-zinc-950/60 px-3 py-2 text-xs">
                    <span class="text-zinc-400">
                        Текущий шаг:
                        <span class="font-medium text-zinc-100">
                            @if(($martingale['total'] ?? 0) > 0)
                                {{ ($martingale['step'] ?? 0) + 1 }} из {{ $martingale['total'] }} → {{ $martingale['amount_label'] ?? '—' }}
                            @else
                                —
                            @endif
                        </span>
                    </span>
                    <button type="submit"
                        form="profile-trading-reset-martingale"
                        class="rounded border border-zinc-600 bg-zinc-800 px-2 py-1 text-xs text-zinc-200 hover:bg-zinc-700">
                        Сбросить на первую сумму
                    </button>
                </div>
                <label class="flex items-start gap-2 text-sm text-zinc-200 cursor-pointer sm:col-span-2">
                    <input type="hidden" name="mirror_wait_previous_settled" value="0">
                    <input type="checkbox" name="mirror_wait_previous_settled" value="1"
                        class="mt-0.5 rounded border-zinc-600 bg-zinc-950 text-blue-600 focus:ring-blue-500"
                        @checked(old('mirror_wait_previous_settled', $settings->mirror_wait_previous_settled))>
                    <span>
                        <span class="font-medium">Одна сделка на PO</span>
                        <span class="mt-0.5 block text-[11px] font-normal text-zinc-500">
                            Пока на платформе не закрыта (win/loss) предыдущая успешно зеркальная сделка —
                            новый ордер на PO не отправляется. Так цепочка сумм мартингейла успевает
                            сдвинуться до следующей отправки. Без запроса к Pocket Option.
                        </span>
                    </span>
                </label>
            </div>
            <div class="space-y-3 pt-2">
                <label class="flex items-center gap-2 text-sm text-zinc-200 cursor-pointer">
                    <input type="hidden" name="is_demo" value="0">
                    <input type="checkbox" name="is_demo" value="1"
                        class="rounded border-zinc-600 bg-zinc-950 text-blue-600 focus:ring-blue-500"
                        @checked(old('is_demo', $settings->is_demo))>
                    Demo-счёт
                </label>
                <label class="flex items-center gap-2 text-sm text-zinc-200 cursor-pointer">
                    <input type="hidden" name="mirror_trades_enabled" value="0">
                    <input type="checkbox" name="mirror_trades_enabled" value="1"
                        class="rounded border-zinc-600 bg-zinc-950 text-blue-600 focus:ring-blue-500"
                        @checked(old('mirror_trades_enabled', $settings->mirror_trades_enabled))>
                    Включить зеркалирование
                </label>
            </div>
        </div>

        {{-- Цвета свечей: дефолт как на Pocket Option; каждый пользователь настраивает себе. --}}
        <div class="rounded border border-zinc-700 bg-zinc-950/40 px-4 py-4 space-y-3">
            <div>
                <h3 class="text-sm font-medium text-slate-100">Цвета свечей на графике</h3>
                <p class="mt-1 text-xs text-zinc-500">
                    По умолчанию — как на Pocket Option (зелёный #22ff00, красный #ff0000).
                    Doji (open = close) красится цветом медвежьей свечи.
                </p>
            </div>
            @php
                $candleDefaults = \App\Support\ChartCandleColors::defaults();
                $upVal = old('candle_up_color', $settings->candle_up_color ?: $candleDefaults['up']);
                $downVal = old('candle_down_color', $settings->candle_down_color ?: $candleDefaults['down']);
            @endphp
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="space-y-1">
                    <label for="candle_up_color" class="block text-xs font-medium text-zinc-400">Бычья (рост)</label>
                    <div class="flex items-center gap-2">
                        <input type="color" id="candle_up_color_picker"
                            value="{{ $upVal }}"
                            class="h-9 w-12 cursor-pointer rounded border border-zinc-700 bg-zinc-950 p-0.5"
                            aria-label="Выбор цвета бычьей свечи"
                            oninput="document.getElementById('candle_up_color').value = this.value">
                        <input type="text" name="candle_up_color" id="candle_up_color"
                            value="{{ $upVal }}"
                            maxlength="7" pattern="#[0-9A-Fa-f]{3,6}"
                            class="flex-1 rounded border border-zinc-700 bg-zinc-950 px-3 py-2 font-mono text-sm text-zinc-100"
                            autocomplete="off"
                            oninput="if(/^#[0-9A-Fa-f]{6}$/.test(this.value)) document.getElementById('candle_up_color_picker').value = this.value">
                    </div>
                    @error('candle_up_color')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                </div>
                <div class="space-y-1">
                    <label for="candle_down_color" class="block text-xs font-medium text-zinc-400">Медвежья (падение) / doji</label>
                    <div class="flex items-center gap-2">
                        <input type="color" id="candle_down_color_picker"
                            value="{{ $downVal }}"
                            class="h-9 w-12 cursor-pointer rounded border border-zinc-700 bg-zinc-950 p-0.5"
                            aria-label="Выбор цвета медвежьей свечи"
                            oninput="document.getElementById('candle_down_color').value = this.value">
                        <input type="text" name="candle_down_color" id="candle_down_color"
                            value="{{ $downVal }}"
                            maxlength="7" pattern="#[0-9A-Fa-f]{3,6}"
                            class="flex-1 rounded border border-zinc-700 bg-zinc-950 px-3 py-2 font-mono text-sm text-zinc-100"
                            autocomplete="off"
                            oninput="if(/^#[0-9A-Fa-f]{6}$/.test(this.value)) document.getElementById('candle_down_color_picker').value = this.value">
                    </div>
                    @error('candle_down_color')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                </div>
            </div>
            <button type="button" id="btn-reset-candle-colors"
                class="rounded border border-zinc-600 bg-zinc-800 px-3 py-1.5 text-xs text-zinc-200 hover:bg-zinc-700"
                data-up="{{ $candleDefaults['up'] }}"
                data-down="{{ $candleDefaults['down'] }}">
                Сбросить к цветам PO
            </button>
        </div>

        <div class="rounded border border-amber-900/50 bg-amber-950/25 px-4 py-3 text-xs text-amber-100/85 space-y-1">
            <p>Нужны <code class="text-amber-200">platform-pocket-proxy</code> и <code class="text-amber-200">platform-queue</code> (или <code class="text-amber-200">queue:work</code>).</p>
            <p class="text-amber-200/90">SSID — из DevTools <strong>того же</strong> кабинета PO, где смотрите сделки.
                Зеркало сейчас на <strong>{{ $settings->is_demo ? 'DEMO' : 'REAL' }}</strong> — на сайте PO переключите тот же режим (Demo/Real), иначе сделок в истории не будет.</p>
            <p class="text-amber-200/80">Пул прокси для ротации IP — на странице
                <a href="{{ route('profile.mirror-proxies.edit') }}" class="underline hover:text-amber-100">Прокси зеркала</a>.</p>
        </div>

        <button type="submit"
            class="rounded bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-500 transition-colors">
            Сохранить
        </button>
    </form>
</div>

<script>
(function () {
    // Сброс color picker + text к дефолту Pocket Option без submit.
    const resetBtn = document.getElementById('btn-reset-candle-colors');
    if (resetBtn) {
        resetBtn.addEventListener('click', function () {
            const up = resetBtn.getAttribute('data-up') || '#22ff00';
            const down = resetBtn.getAttribute('data-down') || '#ff0000';
            const upText = document.getElementById('candle_up_color');
            const downText = document.getElementById('candle_down_color');
            const upPick = document.getElementById('candle_up_color_picker');
            const downPick = document.getElementById('candle_down_color_picker');
            if (upText) upText.value = up;
            if (downText) downText.value = down;
            if (upPick) upPick.value = up;
            if (downPick) downPick.value = down;
        });
    }

    const btn = document.getElementById('btn-test-po-auth');
    const out = document.getElementById('po-auth-result');
    const ssidEl = document.getElementById('pocket_ssid');
    const isDemoEl = document.querySelector('input[name="is_demo"][type="checkbox"]');
    if (!btn || !out || !ssidEl) return;

    function showResult(ok, message) {
        out.classList.remove('hidden', 'border-emerald-800', 'bg-emerald-950/40', 'text-emerald-300',
            'border-rose-800', 'bg-rose-950/40', 'text-rose-300', 'border-zinc-700', 'bg-zinc-900/50', 'text-zinc-300');
        if (ok) {
            out.classList.add('border-emerald-800', 'bg-emerald-950/40', 'text-emerald-300');
        } else {
            out.classList.add('border-rose-800', 'bg-rose-950/40', 'text-rose-300');
        }
        out.textContent = message;
    }

    btn.addEventListener('click', async function () {
        const ssid = ssidEl.value.trim();
        if (!ssid) {
            showResult(false, 'Вставьте SSID в поле выше.');
            return;
        }

        btn.disabled = true;
        showResult(false, 'Проверка подключения к Pocket Option…');
        out.classList.remove('border-rose-800', 'bg-rose-950/40', 'text-rose-300');
        out.classList.add('border-zinc-700', 'bg-zinc-900/50', 'text-zinc-300');

        try {
            const res = await fetch(@json(route('profile.trading.test-connection')), {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': @json(csrf_token()),
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    pocket_ssid: ssid,
                    is_demo: isDemoEl ? isDemoEl.checked : true,
                }),
            });
            const data = await res.json().catch(() => ({}));

            if (data.ok) {
                let msg = 'SSID рабочий — Pocket Option принял авторизацию.';
                if (data.proxy_used) msg += ' Подключение через прокси ingest.';
                showResult(true, msg);
            } else if (data.format_ok === false) {
                showResult(false, data.error || 'Неверный формат SSID.');
            } else {
                showResult(false, (data.error || 'SSID не принят Pocket Option.')
                    + ' Обновите auth из DevTools того кабинета, где смотрите сделки.');
            }
        } catch (e) {
            showResult(false, 'Не удалось связаться с сервером или прокси (порт 3000).');
        } finally {
            btn.disabled = false;
        }
    });
})();
</script>
@endsection
