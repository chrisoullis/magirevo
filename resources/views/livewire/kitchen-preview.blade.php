<section class="home-kitchen home-container" aria-labelledby="kitchen-preview-title">
    <div class="home-section-heading"><div><span class="home-eyebrow">{{ __('homepage.just_for_you') }}</span><h2 id="kitchen-preview-title">{{ __('homepage.kitchen_heading') }}</h2></div><p>{{ __('homepage.kitchen_intro') }}</p></div>
    <div class="home-kitchen-stats">
        @foreach ([
            'saved' => ['recipes.saved', 'site.recipe_box', 'bookmark', []],
            'shared' => ['recipes.shared', 'sharing.nav', 'users', []],
            'originals' => ['recipes.personal', 'navigation.my_recipes', 'book', ['filter' => 'original']],
            'variations' => ['recipes.personal', 'navigation.my_variations', 'sparkles', ['filter' => 'variation']],
            'households' => ['households.index', 'households.nav', 'home', []],
            'shopping' => ['planner.shopping', 'navigation.shopping_lists', 'shopping', []],
        ] as $key => [$destination, $label, $icon, $parameters])
            @if ($counts[$key] !== null)
                <a class="home-stat" href="{{ route($destination, ['locale' => $locale, ...$parameters], false) }}" wire:key="kitchen-stat-{{ $key }}"><x-ui.icon :name="$icon" /><strong>{{ $counts[$key] }}</strong><span>{{ __($label) }}</span></a>
            @endif
        @endforeach
    </div>
    @if ($canPlan)
        <div class="home-week-preview">
            <div class="home-week-heading"><div><x-ui.icon name="calendar" /><h3>{{ __('homepage.your_week') }}</h3><span>{{ $start->locale($locale)->translatedFormat('j M') }} – {{ $start->addDays(6)->locale($locale)->translatedFormat('j M') }}</span></div><a href="{{ route('planner.index', ['locale' => $locale], false) }}">{{ __('homepage.open_planner') }}<x-ui.icon name="arrow" /></a></div>
            <div class="home-week-grid">
                @foreach ($days as $day)
                    @php($count = (int) ($planned[$day->toDateString()] ?? 0))
                    <a href="{{ route('planner.index', ['locale' => $locale, 'week' => $start->toDateString()], false) }}" @class(['home-day', 'is-today' => $day->isToday()]) wire:key="day-{{ $day->toDateString() }}" @if($day->isToday()) aria-current="date" @endif>
                        <time datetime="{{ $day->toDateString() }}"><span>{{ $day->locale($locale)->translatedFormat('D') }}</span><strong>{{ $day->day }}</strong></time>
                        <span>{{ $count ? trans_choice('homepage.meals_planned', $count, ['count' => $count]) : __('homepage.plan_day') }}</span>
                    </a>
                @endforeach
            </div>
            <p class="home-week-note">{{ __('homepage.personal_week_note') }}</p>
        </div>
    @endif
</section>
