<?php

namespace App\Http\Controllers;

use App\Models\PocketInstrument;
use App\Models\ProxyEndpoint;
use App\Services\ProxyRotationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class InstrumentController extends Controller
{
    public function index()
    {
        // Читаем порог stale для payout из app_settings.
        // Если настройка ещё не создана миграцией/вручную, используем безопасный дефолт 5 минут.
        $payoutStaleMinutesThreshold = (int) (
            DB::table('app_settings')
                ->where('key', 'payout_stale_minutes_threshold')
                ->value('value') ?? 5
        );
        $payoutStaleMinutesThreshold = max(1, min(1440, $payoutStaleMinutesThreshold));

        $proxyRotation = app(ProxyRotationService::class);

        return view('instruments.index', [
            'instruments' => PocketInstrument::query()
                ->with('proxyEndpoint')
                ->orderByDesc('priority')
                ->orderBy('instrument')
                ->get(),
            'payoutStaleMinutesThreshold' => $payoutStaleMinutesThreshold,
            'proxyPoolCapacity' => $proxyRotation->getPoolCapacitySummary(),
        ]);
    }

    public function create()
    {
        return view('instruments.form', [
            'instrument' => null,
            'proxyEndpoints' => ProxyEndpoint::query()
                ->orderBy('status')
                ->orderBy('url')
                ->get(['id', 'url', 'status']),
        ]);
    }

    public function store(Request $request)
    {
        $rules = [
            'instrument' => 'required|string|max:63|unique:pocket_instruments,instrument',
            'display_name' => 'nullable|string|max:255',
            // 1 = валютные пары, 2 = криптовалюты.
            'type' => ['required', 'integer', Rule::in([
                PocketInstrument::TYPE_CURRENCY,
                PocketInstrument::TYPE_CRYPTO,
                PocketInstrument::TYPE_STOCK,
            ])],
            'priority' => 'nullable|integer',
            'session_token' => 'nullable|string|max:4096',
            'uid' => 'nullable|string|max:63',
            'is_demo' => 'boolean',
            'is_active' => 'boolean',
            'proxy_endpoint_id' => 'nullable|integer|exists:proxy_endpoints,id',
        ];
        if ($request->filled('session_token')) {
            $rules['uid'] = 'required_with:session_token|string|max:63';
        }
        $v = $request->validate($rules);
        PocketInstrument::create([
            'instrument' => $v['instrument'],
            'display_name' => $v['display_name'] ?? null,
            'type' => (int) $v['type'],
            'priority' => (int) ($v['priority'] ?? 0),
            'session_token' => $v['session_token'] ?? null,
            'uid' => $v['uid'] ?? null,
            'is_demo' => $request->boolean('is_demo'),
            'is_active' => $request->boolean('is_active', true),
            'proxy_endpoint_id' => $v['proxy_endpoint_id'] ?? null,
        ]);
        return redirect()->route('instruments.index')->with('success', 'Инструмент добавлен.');
    }

    public function edit(PocketInstrument $instrument)
    {
        return view('instruments.form', [
            'instrument' => $instrument,
            'session_token_placeholder' => $instrument->session_token ? 'Пусто = не менять' : '',
            'proxyEndpoints' => ProxyEndpoint::query()
                ->orderBy('status')
                ->orderBy('url')
                ->get(['id', 'url', 'status']),
        ]);
    }

    public function update(Request $request, PocketInstrument $instrument)
    {
        $rules = [
            'instrument' => ['required', 'string', 'max:63', Rule::unique('pocket_instruments', 'instrument')->ignore($instrument->id)],
            'display_name' => 'nullable|string|max:255',
            'type' => ['required', 'integer', Rule::in([
                PocketInstrument::TYPE_CURRENCY,
                PocketInstrument::TYPE_CRYPTO,
                PocketInstrument::TYPE_STOCK,
            ])],
            'priority' => 'nullable|integer',
            'session_token' => 'nullable|string|max:4096',
            'uid' => 'nullable|string|max:63',
            'is_demo' => 'boolean',
            'is_active' => 'boolean',
            'proxy_endpoint_id' => 'nullable|integer|exists:proxy_endpoints,id',
        ];
        if ($request->filled('session_token')) {
            $rules['uid'] = 'required_with:session_token|string|max:63';
        }
        $v = $request->validate($rules);
        $data = [
            'instrument' => $v['instrument'],
            'display_name' => $v['display_name'] ?? null,
            'type' => (int) $v['type'],
            'priority' => (int) ($v['priority'] ?? 0),
            'is_demo' => $request->boolean('is_demo'),
            'is_active' => $request->boolean('is_active'),
            'proxy_endpoint_id' => $v['proxy_endpoint_id'] ?? null,
        ];
        if ($request->filled('session_token')) {
            $data['session_token'] = $v['session_token'];
            $data['uid'] = $v['uid'] ?? null;
        }
        $instrument->update($data);
        return redirect()->route('instruments.index')->with('success', 'Инструмент обновлён.');
    }

    public function destroy(PocketInstrument $instrument)
    {
        $instrument->delete();
        return redirect()->route('instruments.index')->with('success', 'Инструмент удалён.');
    }

    public function replaceProxy(PocketInstrument $instrument, ProxyRotationService $rotationService)
    {
        $newProxy = $rotationService->rotateInstrumentProxy($instrument, false);
        if (! $newProxy) {
            return back()->with('error', $rotationService->formatPoolExhaustedMessage());
        }

        return back()->with('success', 'Прокси инструмента успешно заменён.');
    }

    public function replaceProxyAndMarkDead(PocketInstrument $instrument, ProxyRotationService $rotationService)
    {
        $newProxy = $rotationService->rotateInstrumentProxy($instrument, true);
        if (! $newProxy) {
            return back()->with('error', $rotationService->formatPoolExhaustedMessage()
                .' Текущий прокси не помечен dead — сначала нужна свободная замена.');
        }

        return back()->with('success', 'Текущий прокси помечен нерабочим и заменён.');
    }

    public function removeProxy(PocketInstrument $instrument)
    {
        // Явно отвязываем инструмент от proxy endpoint:
        // ingest будет работать без прокси (direct), т.к. proxy_url в API станет null.
        $instrument->update([
            'proxy_endpoint_id' => null,
        ]);

        return back()->with('success', 'Прокси удалён. Инструмент переведён на работу без прокси.');
    }

    public function toggleActive(PocketInstrument $instrument)
    {
        // Мгновенный переключатель активности инструмента из таблицы:
        // это позволяет оперативно выключать/включать стрим без перехода в форму редактирования.
        // Логика предельно простая: инвертируем текущее состояние флага is_active.
        $nextState = ! (bool) $instrument->is_active;
        $instrument->update([
            'is_active' => $nextState,
        ]);

        return back()->with(
            'success',
            $nextState
                ? 'Инструмент включён.'
                : 'Инструмент выключен.'
        );
    }
}
