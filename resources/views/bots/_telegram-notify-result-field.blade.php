{{--
  Общий тумблер: после экспирации paper-сделки слать WIN/LOSS/DRAW в Telegram.
  Требует: telegram_enabled + наличие со сделками (не alert_only / не notify_on=off).
--}}
@php
    $telegramNotifyResultId = $telegramNotifyResultId ?? 'telegram_notify_result';
@endphp
<input type="hidden" name="telegram_notify_result" value="0">
<label class="flex items-start gap-2">
    <input type="checkbox" name="telegram_notify_result" id="{{ $telegramNotifyResultId }}" value="1"
        class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
        @checked(old('telegram_notify_result', $cfg['telegram_notify_result'] ?? true))>
    <span>
        <span class="text-sm font-medium text-zinc-300">Писать результат сделки (WIN / LOSS)</span>
        <span class="mt-0.5 block text-[11px] font-normal text-zinc-500">
            После экспирации paper-сделки — отдельное сообщение ✅ WIN / ❌ LOSS / ➖ DRAW.
        </span>
    </span>
</label>
@include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('telegram_notify_result')])
