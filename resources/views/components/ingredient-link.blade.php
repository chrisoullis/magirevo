@props(['name', 'url' => null])
@if ($url)<a {{ $attributes->class(['ingredient-reference']) }} href="{{ $url }}">{{ $name }}</a>@else<span {{ $attributes }}>{{ $name }}</span>@endif
