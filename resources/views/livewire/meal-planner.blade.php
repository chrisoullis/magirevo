<div class="flex flex-col gap-6" @if ($household) wire:poll.30s @endif>
    <header class="flex flex-col gap-2">
        @if ($household)
            <a class="text-link inline-flex min-h-11 items-center" href="{{ route('households.show', ['locale' => $locale, 'household' => $household->id], false) }}">&larr; {{ $household->name }}</a>
        @endif
        <h1 class="text-3xl leading-tight break-words">{{ $household ? __('planner.household_heading', ['name' => $household->name]) : __('planner.heading') }}</h1>
        <p>{{ $household ? __('planner.household_intro') : __('planner.intro') }}</p>
        <div class="flex flex-wrap gap-2">
            <a class="button min-h-11" href="{{ $household ? route('households.shopping', ['locale' => $locale, 'household' => $household->id, 'week' => $start->toDateString()], false) : route('planner.shopping', ['locale' => $locale, 'week' => $start->toDateString()], false) }}">{{ __('planner.shopping.nav') }}</a>
            <a class="button min-h-11" href="{{ $household ? route('households.templates', ['locale' => $locale, 'household' => $household->id, 'week' => $start->toDateString()], false) : route('planner.templates', ['locale' => $locale, 'week' => $start->toDateString()], false) }}">{{ __('planner.templates.nav') }}</a>
            <a class="button min-h-11" href="{{ $household ? route('households.batches', ['locale' => $locale, 'household' => $household->id], false) : route('planner.batches', ['locale' => $locale], false) }}">{{ __('planner.batches.nav') }}</a>
        </div>
    </header>

    @if (session('status'))
        <p class="status-message" role="status">{{ session('status') }}</p>
    @endif
    @error('conflict')<p class="field-error" role="alert">{{ $message }}</p>@enderror

    <nav class="flex flex-wrap items-center justify-between gap-2" aria-label="{{ __('planner.week_nav') }}">
        <button class="button min-h-11" type="button" wire:click="previousWeek">&larr; {{ __('planner.previous_week') }}</button>
        <div class="text-center">
            <p class="font-semibold" aria-live="polite">{{ __('planner.week_of', ['date' => $start->locale($locale)->translatedFormat('j F Y')]) }}</p>
            @unless ($isCurrentWeek)
                <button class="text-link min-h-11" type="button" wire:click="currentWeek">{{ __('planner.this_week') }}</button>
            @endunless
        </div>
        <button class="button min-h-11" type="button" wire:click="nextWeek">{{ __('planner.next_week') }} &rarr;</button>
    </nav>

    @if ($canEdit)
        <form class="flex flex-col gap-3 rounded border border-current/20 p-4" wire:submit="addEntry">
            <h2 class="text-xl">{{ __('planner.add_heading') }}</h2>

            @if ($recipeId && $selectedTitle)
                <p class="flex flex-wrap items-center gap-2">
                    <strong class="break-words">{{ $selectedTitle }}</strong>
                    <button class="text-link min-h-11" type="button" wire:click="clearRecipe">{{ __('planner.change_recipe') }}</button>
                </p>
            @else
                <label class="font-semibold" for="planner-search">{{ __('planner.search_label') }}</label>
                <input id="planner-search" class="min-h-11 rounded border border-current/30 px-3" type="search" wire:model.live.debounce.300ms="search" autocomplete="off">
                @if ($results->isNotEmpty())
                    <ul class="flex flex-col gap-1" aria-label="{{ __('planner.results') }}">
                        @foreach ($results as $recipe)
                            <li wire:key="result-{{ $recipe->id }}">
                                <button class="min-h-11 w-full rounded border border-current/20 px-3 text-left hover:bg-black/5" type="button" wire:click="selectRecipe({{ $recipe->id }})">
                                    {{ $recipe->displayTranslation($locale)?->title }}
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @elseif (mb_strlen(trim($search)) >= 2)
                    <p class="text-sm">{{ $household ? __('planner.no_results_household') : __('planner.no_results') }}</p>
                @endif
            @endif
            @error('recipeId')<p class="field-error">{{ $message }}</p>@enderror
            @error('recipe')<p class="field-error">{{ $message }}</p>@enderror

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <div class="flex flex-col gap-1">
                    <label class="font-semibold" for="planner-date">{{ __('planner.day') }}</label>
                    <select id="planner-date" class="min-h-11 rounded border border-current/30 px-2" wire:model="date">
                        @foreach ($days as $day)
                            <option value="{{ $day->toDateString() }}">{{ $day->locale($locale)->translatedFormat('l j F') }}</option>
                        @endforeach
                    </select>
                    @error('date')<p class="field-error">{{ $message }}</p>@enderror
                </div>
                <div class="flex flex-col gap-1">
                    <label class="font-semibold" for="planner-meal">{{ __('planner.meal') }}</label>
                    <select id="planner-meal" class="min-h-11 rounded border border-current/30 px-2" wire:model="meal">
                        @foreach ($meals as $mealType)
                            <option value="{{ $mealType->value }}">{{ $mealType->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex flex-col gap-1">
                    <label class="font-semibold" for="planner-servings">{{ __('planner.servings') }}</label>
                    <input id="planner-servings" class="min-h-11 rounded border border-current/30 px-3" type="number" min="1" max="99" inputmode="numeric" wire:model="servings">
                    @error('servings')<p class="field-error">{{ $message }}</p>@enderror
                </div>
            </div>

            <label class="font-semibold" for="planner-notes">{{ __('planner.notes') }}</label>
            <input id="planner-notes" class="min-h-11 rounded border border-current/30 px-3" type="text" maxlength="255" wire:model="notes">
            <button class="button button-dark min-h-11" type="submit" wire:loading.attr="disabled">{{ __('planner.add') }}</button>
        </form>
    @endif

    <div class="flex flex-col gap-4">
        @foreach ($days as $day)
            @php($dayEntries = $view->get($day->toDateString(), collect()))
            <section class="rounded border border-current/20 p-4" aria-labelledby="day-{{ $day->toDateString() }}" wire:key="day-{{ $day->toDateString() }}">
                <h2 id="day-{{ $day->toDateString() }}" class="text-lg font-semibold">{{ $day->locale($locale)->translatedFormat('l j F') }}</h2>
                @forelse ($meals as $mealType)
                    @php($items = $dayEntries->get($mealType->value, collect()))
                    @if ($items->isNotEmpty())
                        <h3 class="mt-3 text-sm font-semibold uppercase">{{ $mealType->label() }}</h3>
                        <ul class="flex flex-col gap-2">
                            @foreach ($items as $item)
                                <li class="flex flex-col gap-1" wire:key="entry-{{ $item['id'] }}">
                                    @if ($item['available'])
                                        <a class="font-semibold break-words" href="{{ $item['url'] }}">{{ $item['title'] }}</a>
                                    @else
                                        <span class="italic">{{ __('planner.recipe_unavailable') }}</span>
                                    @endif
                                    @if ($item['leftover'])
                                        <span class="text-sm font-semibold">{{ $item['consumed'] ? __('planner.batches.eaten') : __('planner.batches.leftover_badge') }}</span>
                                    @endif
                                    <span class="text-sm">{{ trans_choice('planner.servings_count', $item['servings']) }}@if ($item['notes']) &middot; {{ $item['notes'] }}@endif</span>
                                    @if ($item['substitutions'] !== [])
                                        <div class="text-sm">
                                            <strong>{{ __('planner.confirmed_substitutions') }}</strong>
                                            <ul>
                                                @foreach ($item['substitutions'] as $substitution)
                                                    <li wire:key="entry-{{ $item['id'] }}-substitution-{{ $loop->index }}">
                                                        {{ $substitution['original'] }} → {{ $substitution['replacement'] }}
                                                        @if ($substitution['explanation']) — {{ $substitution['explanation'] }}@endif
                                                    </li>
                                                @endforeach
                                            </ul>
                                        </div>
                                    @endif

                                    @if ($canEdit && $item['leftover'] && $item['consumed'])
                                        {{-- Eaten leftovers are history and stay read-only. --}}
                                    @elseif ($canEdit)
                                        @if ($editingId === $item['id'])
                                            <form class="flex flex-col gap-2" wire:submit="saveEdit">
                                                <label class="font-semibold" for="edit-servings-{{ $item['id'] }}">{{ __('planner.servings') }}</label>
                                                <input id="edit-servings-{{ $item['id'] }}" class="min-h-11 rounded border border-current/30 px-3" type="number" min="1" max="99" wire:model="editServings">
                                                <label class="font-semibold" for="edit-notes-{{ $item['id'] }}">{{ __('planner.notes') }}</label>
                                                <input id="edit-notes-{{ $item['id'] }}" class="min-h-11 rounded border border-current/30 px-3" type="text" maxlength="255" wire:model="editNotes">
                                                <div class="flex gap-2">
                                                    <button class="button button-dark min-h-11" type="submit">{{ __('planner.save') }}</button>
                                                    <button class="button min-h-11" type="button" wire:click="cancelEdit">{{ __('planner.cancel') }}</button>
                                                </div>
                                            </form>
                                        @else
                                            <div class="flex gap-2">
                                                @if ($item['available'])
                                                    <button class="text-link min-h-11" type="button" wire:click="startEdit({{ $item['id'] }})">{{ __('planner.edit') }}</button>
                                                @endif
                                                @if ($item['leftover'] && $item['available'])
                                                    <button class="text-link min-h-11" type="button" wire:click="consumeLeftover({{ $item['id'] }})">{{ __('planner.batches.mark_eaten') }}</button>
                                                @elseif (! $item['leftover'] && $item['available'] && ! $item['has_batch'])
                                                    <button class="text-link min-h-11" type="button" wire:click="startBatch({{ $item['id'] }})">{{ __('planner.batches.start') }}</button>
                                                @endif
                                                <button class="text-link min-h-11" type="button" wire:click="removeEntry({{ $item['id'] }}, {{ $item['version'] }})">{{ __('planner.remove') }}</button>
                                            </div>
                                        @endif
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                @empty
                @endforelse
                @if ($dayEntries->isEmpty())
                    <p class="text-sm">{{ __('planner.nothing_planned') }}</p>
                @endif
            </section>
        @endforeach
    </div>
</div>
