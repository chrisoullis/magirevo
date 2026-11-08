@props(['id'])
<details id="{{ $id }}" {{ $attributes->class('kitchen-dropdown') }} data-navigation-disclosure>
    <summary class="kitchen-dropdown-trigger" aria-controls="{{ $id }}-panel">{{ $trigger }}<x-ui.icon name="chevron" /></summary>
    <div id="{{ $id }}-panel" class="kitchen-dropdown-panel">{{ $slot }}</div>
</details>
