{{-- Интервал запуска bots:scan: общий для всех торговых ботов. Chart Analyst это поле не видит. --}}
@php
    $scanIntervalSec = (int) old(
        'scan_interval_sec',
        $cfg['scan_interval_sec'] ?? \App\Support\BotScanCoordinator::DEFAULT_SCAN_INTERVAL_SEC
    );
    if (! in_array($scanIntervalSec, \App\Support\BotScanCoordinator::ALLOWED_SCAN_INTERVALS_SEC, true)) {
        $scanIntervalSec = \App\Support\BotScanCoordinator::SCAN_INTERVAL_M1;
    }
@endphp
<div class="space-y-1">
    <label for="scan_interval_sec" class="block text-sm font-medium text-zinc-300">Интервал скана</label>
    <select name="scan_interval_sec" id="scan_interval_sec" required
        class="w-full max-w-md rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
        <option value="{{ \App\Support\BotScanCoordinator::SCAN_INTERVAL_S5 }}" @selected($scanIntervalSec === \App\Support\BotScanCoordinator::SCAN_INTERVAL_S5)>
            5 с — каждые 5 секунд (MaxBot live)
        </option>
        <option value="{{ \App\Support\BotScanCoordinator::SCAN_INTERVAL_S15 }}" @selected($scanIntervalSec === \App\Support\BotScanCoordinator::SCAN_INTERVAL_S15)>
            15 с
        </option>
        <option value="{{ \App\Support\BotScanCoordinator::SCAN_INTERVAL_S30 }}" @selected($scanIntervalSec === \App\Support\BotScanCoordinator::SCAN_INTERVAL_S30)>
            30 с — S30
        </option>
        <option value="{{ \App\Support\BotScanCoordinator::SCAN_INTERVAL_M1 }}" @selected($scanIntervalSec === \App\Support\BotScanCoordinator::SCAN_INTERVAL_M1)>
            60 с — M1
        </option>
    </select>
    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('scan_interval_sec')])
    @error('scan_interval_sec')
        <p class="text-sm text-rose-400">{{ $message }}</p>
    @enderror
</div>
