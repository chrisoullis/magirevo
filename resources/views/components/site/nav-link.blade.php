@props(['link', 'icon' => true])
<a href="{{ $link['url'] }}" {{ $attributes->class(['kitchen-nav-link', 'is-active' => $link['active']]) }} @if($link['active']) aria-current="page" @endif>
    @if ($icon)<x-ui.icon :name="$link['icon']" />@endif
    <span>{{ $link['label'] }}</span>
</a>
