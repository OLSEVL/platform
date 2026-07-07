@php
    $inputId = $inputId ?? 'telegram_bot_token';
    $placeholder = $placeholder ?? '8822018763:AAH...';
    $hint = $hint ?? 'Токен от @BotFather. Пустое поле при сохранении — оставить текущий.';
    $savedToken = old('telegram_bot_token', $cfg['telegram_bot_token'] ?? '');
@endphp
<div class="space-y-1">
    <label for="{{ $inputId }}" class="block text-xs font-medium text-zinc-400">Bot token</label>
    <div class="flex gap-2">
        <input type="text" name="telegram_bot_token" id="{{ $inputId }}" autocomplete="off"
            value="{{ $savedToken }}"
            placeholder="{{ $placeholder }}"
            class="min-w-0 flex-1 rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100 font-mono"
            data-telegram-token-input>
        <button type="button"
            class="shrink-0 rounded border border-zinc-600 bg-zinc-800 px-2.5 py-2 text-xs font-medium text-zinc-300 hover:bg-zinc-700"
            data-telegram-token-toggle
            data-target="{{ $inputId }}"
            aria-label="Скрыть token"
            title="Показать / скрыть token">
            Скрыть
        </button>
    </div>
    <p class="text-[11px] text-zinc-500">{{ $hint }}</p>
    @error('telegram_bot_token')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
</div>
<script>
(function () {
    const input = document.getElementById(@json($inputId));
    const btn = input?.closest('.flex')?.querySelector('[data-telegram-token-toggle]');
    if (!input || !btn) return;
    btn.addEventListener('click', function () {
        const hidden = input.type === 'password';
        input.type = hidden ? 'text' : 'password';
        btn.textContent = hidden ? 'Скрыть' : 'Показать';
        btn.setAttribute('aria-label', hidden ? 'Скрыть token' : 'Показать token');
    });
})();
</script>
