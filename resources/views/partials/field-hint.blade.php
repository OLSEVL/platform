{{-- Единая подсказка под полем настроек. Ожидает: $text (string). --}}
@if (! empty($text))
    <p class="{{ \App\Support\SettingsFieldHints::CSS }}">{{ $text }}</p>
@endif
