@foreach ($components as $component)
    <section class="nested-component" data-component-path="{{ $component['path'] }}" data-component-revision="{{ $component['revision_id'] }}">
        <h3>{{ $component['title'] }}</h3>
        <p><strong>{{ $component['amount'] }}</strong>@if ($component['note']) — {{ $component['note'] }}@endif</p>
        @if (! ($printing ?? false) && $component['preview_url'])
            <a href="{{ $component['preview_url'] }}">{{ __('nested_public.view_recipe') }}</a>
        @endif
        <details @if ($printing ?? false) open @endif>
            <summary>{{ __('nested_public.show_ingredients') }}</summary>
            @if ($component['ingredients'] === [])<p>{{ __('nested_public.manual') }}</p>@endif
            <ul>@foreach ($component['ingredients'] as $ingredient)<li>{{ $ingredient['amount'] }} {{ $ingredient['name'] }}</li>@endforeach</ul>
        </details>
        <details data-component-scratch @if ($printing ?? false) open @endif>
            <summary>{{ __('nested_public.scratch') }}</summary>
            <ol>
                @foreach ($component['steps'] as $nestedStep)
                    <li data-nested-step data-minutes="{{ $nestedStep['minutes'] ?? 0 }}">
                        <p>{{ $nestedStep['text'] }}</p>
                        @if ($cooking)
                            <label><input type="checkbox" data-nested-done> {{ __('nested_public.done') }}</label>
                            @if ($nestedStep['minutes'])
                                <button type="button" class="button" data-nested-timer>{{ __('site.step_minutes', ['minutes' => $nestedStep['minutes']]) }}</button>
                                <output data-nested-clock aria-live="polite"></output>
                            @endif
                        @endif
                    </li>
                @endforeach
            </ol>
        </details>
        @if ($component['can_use_prepared'] && ! ($printing ?? false))
            <label><input type="checkbox" data-component-prepared> {{ __('nested_public.prepared') }}</label>
        @endif
    </section>
@endforeach
