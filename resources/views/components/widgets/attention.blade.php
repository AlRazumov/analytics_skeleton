@props(['tiles'])
@php
    /** @var list<array{label: string, count: int, hint: string, url: string}> $tiles */
@endphp

@if ($tiles !== [])
    <h2>Требует внимания</h2>
    <div class="attention">
        @foreach ($tiles as $tile)
            <a class="attention__tile" href="{{ $tile['url'] }}">
                <span class="attention__count">{{ \App\Core\Support\Format::num($tile['count'], 0) }}</span>
                <span class="attention__label">{{ $tile['label'] }}</span>
                <span class="attention__hint">{{ $tile['hint'] }}</span>
            </a>
        @endforeach
    </div>
@endif
