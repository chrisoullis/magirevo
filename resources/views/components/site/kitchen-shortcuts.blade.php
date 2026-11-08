@php
    $locale = app()->getLocale();
    $household = request()->route('household');
    $links = app(\App\Support\PublicNavigation::class)->links(auth()->user(), $locale);
    $shortcuts = collect($links['kitchen'])->prepend($links['primary'][2]);
    if (auth()->user()?->hasVerifiedEmail() && $links['plannerAllowed']) {
        $shortcuts->push(collect($links['primary'])->firstWhere('icon', 'calendar'));
    }
@endphp
<nav class="kitchen-shortcuts" aria-label="{{ __('modules.shortcuts') }}">
    @foreach ($shortcuts->filter() as $link)
        @php
            if ($household && in_array($link['icon'], ['calendar', 'shopping'], true)) {
                $destination = $link['icon'] === 'calendar' ? 'households.planner' : 'households.shopping';
                $link['url'] = route($destination, ['locale' => $locale, 'household' => $household], false);
                $link['active'] = request()->routeIs($destination);
            }
        @endphp
        <a href="{{ $link['url'] }}" @if($link['active']) aria-current="page" @endif>{{ $link['label'] }}</a>
    @endforeach
</nav>
