@props(['name'])
<svg {{ $attributes->class('kitchen-icon') }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
    @switch($name)
        @case('search')<circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5"/>@break
        @case('clock')<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>@break
        @case('check')<path d="m5 12 4 4L19 6"/><circle cx="12" cy="12" r="10"/>@break
        @case('leaf')<path d="M20 4c-8-1-15 2-15 9a6 6 0 0 0 6 6c7 0 10-7 9-15Z"/><path d="M4 21 15 10M8 17v-5m0 5h5"/>@break
        @case('book')<path d="M12 5v15M3 4c4-1 6-1 9 1 3-2 5-2 9-1v15c-4-1-6-1-9 1-3-2-5-2-9-1V4Z"/><path d="M6 8h3m-3 4h3m6-4h3m-3 4h3"/>@break
        @case('calendar')<rect x="3" y="5" width="18" height="16" rx="3"/><path d="M7 3v4m10-4v4M3 11h18m-13 4h1m6 0h1m-8 3h1"/>@break
        @case('bookmark')<path d="M6 4a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v17l-6-4-6 4V4Z"/>@break
        @case('users')<circle cx="9" cy="8" r="3"/><path d="M3 21v-3a6 6 0 0 1 12 0v3m1-16a3 3 0 0 1 0 6m3 10v-3a6 6 0 0 0-2-4"/>@break
        @case('shopping')<path d="m4 8 2 12h12l2-12H4Zm4 0 4-6 4 6m-7 4 1 5m5-5-1 5"/>@break
        @case('sparkles')<path d="m12 3 2.3 6.7L21 12l-6.7 2.3L12 21l-2.3-6.7L3 12l6.7-2.3L12 3Zm7-1v4m-2-2h4"/>@break
        @case('home')<path d="m3 10 9-7 9 7M5 9v12h14V9m-10 12v-7h6v7"/>@break
        @case('plus')<path d="M12 5v14M5 12h14"/>@break
        @case('chevron')<path d="m7 10 5 5 5-5"/>@break
        @case('menu')<path d="M4 6h16M4 12h16M4 18h16"/>@break
        @case('user')<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>@break
        @case('arrow')<path d="M5 12h14m-5-5 5 5-5 5"/>@break
        @case('logout')<path d="M9 4H4v16h5m5-12 4 4-4 4m-6-4h13"/>@break
    @endswitch
</svg>
