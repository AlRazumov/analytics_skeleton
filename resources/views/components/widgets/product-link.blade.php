@props(['id', 'name', 'period' => null])
{{--
    Название товара в таблице — ссылка на карточку товара за тот же месяц (флаг product_card).
    Название, равное id, — товара нет в справочнике (резолверы отдают id вместо названия):
    карточка была бы 404, поэтому без ссылки.
--}}
@if (config('analytics.features.product_card') && $name !== $id)<a href="{{ route('dashboards.product', array_filter(['product' => $id, 'period' => $period])) }}">{{ $name }}</a>@else{{ $name }}@endif
