<!doctype html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title>{{ $recipe->title }} | My Kitchen</title>
    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endif
</head>
<body class="print-body" data-auto-print>
    <div class="print-toolbar">
        <a class="button" href="{{ $backUrl }}">{{ __('site.back_to_recipe') }}</a>
        <button class="button button-dark" type="button" data-print-now>{{ __('site.print_now') }}</button>
    </div>
    <main class="print-recipe">
        <header class="print-header">
            <h1>{{ $recipe->title }}</h1>
            @if ($recipe->summary)<p class="print-summary">{{ $recipe->summary }}</p>@endif
            <ul class="print-facts" aria-label="{{ __('site.print_times') }}">
                @if ($recipe->prep_minutes)<li>{{ __('site.prep') }}: {{ __('site.minutes_total', ['minutes' => $recipe->prep_minutes]) }}</li>@endif
                @if ($recipe->cook_minutes)<li>{{ __('site.cook') }}: {{ __('site.minutes_total', ['minutes' => $recipe->cook_minutes]) }}</li>@endif
                <li>{{ __('site.print_servings') }}: {{ $servings }}</li>
            </ul>
            @if ($cover)
                <img class="print-cover" src="{{ $recipe->coverUrl() }}" alt="{{ $recipe->coverAlt() }}" width="320" height="240">
            @endif
        </header>
        <section class="print-ingredients" aria-labelledby="print-ingredients-title">
            <h2 id="print-ingredients-title">{{ __('site.ingredients') }}</h2>
            @foreach ($groups as $group)
                @if ($group['name'])<h3>{{ $group['name'] }}</h3>@endif
                <ul>
                    @foreach ($group['items'] as $item)
                        @if ($item['line_type'] === 'recipe')
                            <li>@include('recipes.partials.components', ['components' => $item['components'], 'cooking' => false, 'printing' => true])</li>
                            @continue
                        @endif
                        <li>
                            @if ($item['quantity'])<strong>{{ $item['quantity'] }}</strong>@endif
                            {{ $item['name'] }}@if ($item['note']), {{ $item['note'] }}@endif
                            @if ($item['optional'])<em>({{ __('site.optional') }})</em>@endif
                        </li>
                    @endforeach
                </ul>
            @endforeach
        </section>
        <section class="print-steps" aria-labelledby="print-steps-title">
            <h2 id="print-steps-title">{{ __('site.print_instructions') }}</h2>
            @php
                $stepSections ??= [['title' => null, 'description' => null, 'steps' => $steps]];
            @endphp
            @foreach ($stepSections as $section)
                @if ($section['title'])
                    <h3>{{ $section['title'] }}</h3>
                    @if ($section['description'])<p>{{ $section['description'] }}</p>@endif
                @endif
                <ol>
                    @foreach ($section['steps'] as $step)
                        <li value="{{ $step['number'] }}">{{ $step['text'] }}@if ($step['minutes']) <em>({{ __('site.step_minutes', ['minutes' => $step['minutes']]) }})</em>@endif</li>
                    @endforeach
                </ol>
            @endforeach
        </section>
        <footer class="print-footer">
            <p>{{ __('site.print_source') }}@if ($cover?->credit) &middot; {{ __('site.print_photo_credit', ['credit' => $cover->credit]) }}@endif</p>
        </footer>
    </main>
</body>
</html>
