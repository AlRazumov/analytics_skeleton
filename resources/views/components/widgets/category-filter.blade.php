@props(['options', 'selected' => null, 'keep' => []])
@php
    /** @var list<array{key: string, label: string}> $options */
    /** @var array<string, string|null> $keep — остальные параметры страницы (period, base), null пропускается */
@endphp
{{-- Фильтр ?category=: GET-форма на текущую страницу, без JS. Пустой список вариантов (флаг categories выключен) — ничего. --}}
@if ($options !== [])
    <form method="GET" action="{{ url()->current() }}" class="category-filter">
        @foreach ($keep as $name => $value)
            @if (is_string($value))
                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
            @endif
        @endforeach
        <label for="category-filter">Категория:</label>
        <select id="category-filter" name="category">
            <option value="">Все категории</option>
            @foreach ($options as $option)
                <option value="{{ $option['key'] }}" @selected($option['key'] === $selected)>{{ $option['label'] }}</option>
            @endforeach
        </select>
        <button type="submit">Показать</button>
    </form>
@endif
