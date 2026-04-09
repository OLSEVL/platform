<?php

namespace App\Http\Controllers;

use App\Models\PocketInstrument;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InstrumentController extends Controller
{
    public function index()
    {
        return view('instruments.index', [
            'instruments' => PocketInstrument::query()
//                ->orderByDesc('current_payout')
                ->orderByDesc('priority')
                ->orderBy('instrument')
                ->get()
        ]);
    }

    public function create()
    {
        return view('instruments.form', ['instrument' => null]);
    }

    public function store(Request $request)
    {
        $rules = [
            'instrument' => 'required|string|max:63|unique:pocket_instruments,instrument',
            'display_name' => 'nullable|string|max:255',
            'priority' => 'nullable|integer',
            'session_token' => 'nullable|string|max:4096',
            'uid' => 'nullable|string|max:63',
            'is_demo' => 'boolean',
            'is_active' => 'boolean',
            'proxy_url' => 'nullable|string|max:255',
        ];
        if ($request->filled('session_token')) {
            $rules['uid'] = 'required_with:session_token|string|max:63';
        }
        $v = $request->validate($rules);
        PocketInstrument::create([
            'instrument' => $v['instrument'],
            'display_name' => $v['display_name'] ?? null,
            'priority' => (int) ($v['priority'] ?? 0),
            'session_token' => $v['session_token'] ?? null,
            'uid' => $v['uid'] ?? null,
            'is_demo' => $request->boolean('is_demo'),
            'is_active' => $request->boolean('is_active', true),
            'proxy_url' => $v['proxy_url'] ?? null,
        ]);
        return redirect()->route('instruments.index')->with('success', 'Инструмент добавлен.');
    }

    public function edit(PocketInstrument $instrument)
    {
        return view('instruments.form', [
            'instrument' => $instrument,
            'session_token_placeholder' => $instrument->session_token ? 'Пусто = не менять' : '',
        ]);
    }

    public function update(Request $request, PocketInstrument $instrument)
    {
        $rules = [
            'instrument' => ['required', 'string', 'max:63', Rule::unique('pocket_instruments', 'instrument')->ignore($instrument->id)],
            'display_name' => 'nullable|string|max:255',
            'priority' => 'nullable|integer',
            'session_token' => 'nullable|string|max:4096',
            'uid' => 'nullable|string|max:63',
            'is_demo' => 'boolean',
            'is_active' => 'boolean',
            'proxy_url' => 'nullable|string|max:255',
        ];
        if ($request->filled('session_token')) {
            $rules['uid'] = 'required_with:session_token|string|max:63';
        }
        $v = $request->validate($rules);
        $data = [
            'instrument' => $v['instrument'],
            'display_name' => $v['display_name'] ?? null,
            'priority' => (int) ($v['priority'] ?? 0),
            'is_demo' => $request->boolean('is_demo'),
            'is_active' => $request->boolean('is_active'),
            'proxy_url' => $v['proxy_url'] ?? null,
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
}
