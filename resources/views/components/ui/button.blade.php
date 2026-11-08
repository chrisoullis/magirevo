@props(['href' => null, 'variant' => 'primary'])
@php($classes = 'kitchen-button '.match ($variant) { 'secondary' => 'kitchen-button-secondary', 'quiet' => 'kitchen-button-quiet', default => 'kitchen-button-primary' })
@if ($href)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>{{ $slot }}</a>
@else
    <button {{ $attributes->merge(['type' => 'button'])->class($classes) }}>{{ $slot }}</button>
@endif
