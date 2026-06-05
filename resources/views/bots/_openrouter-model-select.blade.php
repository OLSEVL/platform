{{--
    Общий select моделей OpenRouter для торгового LLM и бота-аналитика.
    Показывает название, id и цену in/out за 1M токенов (из каталога OpenRouter).
--}}
@php
    $openrouterModels = isset($openrouterModels) && is_array($openrouterModels) ? $openrouterModels : [];
    $selectedOpenRouterModel = $selectedOpenRouterModel ?? '';
    $selectId = $selectId ?? 'model';
    $selectClass = $selectClass ?? 'w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100';
@endphp
<select name="model" id="{{ $selectId }}" class="{{ $selectClass }}" required>
    @if ($openrouterModels === [])
        <option value="{{ $selectedOpenRouterModel }}" selected>
            {{ $selectedOpenRouterModel }} (fallback)
        </option>
    @else
        @php $hasSelectedInList = false; @endphp
        @foreach ($openrouterModels as $m)
            @php
                $modelId = (string) ($m['id'] ?? '');
                if ($modelId === $selectedOpenRouterModel) {
                    $hasSelectedInList = true;
                }
            @endphp
            @if ($modelId !== '')
                <option value="{{ $modelId }}" @selected($modelId === $selectedOpenRouterModel)>
                    {{ \App\Services\Bots\OpenRouterClient::modelSelectLabel($m) }}
                </option>
            @endif
        @endforeach
        @if (! $hasSelectedInList && $selectedOpenRouterModel !== '')
            <option value="{{ $selectedOpenRouterModel }}" selected>
                {{ $selectedOpenRouterModel }} (custom)
            </option>
        @endif
    @endif
</select>
@if ($openrouterModels === [])
    <p class="text-[11px] text-zinc-500">
        Не удалось загрузить каталог моделей OpenRouter. Сохраняется текущее значение.
    </p>
@else
    <p class="text-[11px] text-zinc-500">Цены из каталога OpenRouter: USD за 1M токенов (in = prompt, out = completion).</p>
@endif
