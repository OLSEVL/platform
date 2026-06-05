{{-- Таймаут ожидания ответа OpenRouter chat/completions (сек) — в config бота как openrouter_timeout_sec. --}}
@php
    $cfg = isset($cfg) && is_array($cfg) ? $cfg : [];
    $defaultTimeout = (int) ($defaultTimeout ?? 180);
    $maxTimeout = (int) config('services.openrouter.timeout_sec_max', 600);
@endphp
<div class="space-y-1">
    <label for="openrouter_timeout_sec" class="block text-xs font-medium text-zinc-400">
        Таймаут ожидания ответа OpenRouter (сек)
    </label>
    <input type="number" name="openrouter_timeout_sec" id="openrouter_timeout_sec"
        min="30" max="{{ $maxTimeout }}" step="1" required
        value="{{ old('openrouter_timeout_sec', $cfg['openrouter_timeout_sec'] ?? $defaultTimeout) }}"
        class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
    <p class="text-[11px] text-zinc-500">
        Сколько секунд ждать ответ модели (анализ с большим JSON свечей может занимать 1–3 мин).
        Максимум {{ $maxTimeout }} с.
    </p>
    @error('openrouter_timeout_sec')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
</div>
