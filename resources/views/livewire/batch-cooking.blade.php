<div class="flex flex-col gap-6" @if ($household) wire:poll.30s @endif>
    <header class="flex flex-col gap-2">
        <a class="text-link inline-flex min-h-11 items-center" href="{{ $household ? route('households.planner', ['locale' => $locale, 'household' => $household->id], false) : route('planner.index', ['locale' => $locale], false) }}">&larr; {{ __('planner.batches.back_to_plan') }}</a>
        <h1 class="text-3xl leading-tight break-words">{{ $household ? __('planner.batches.household_heading', ['name' => $household->name]) : __('planner.batches.heading') }}</h1>
        <p>{{ __('planner.batches.intro') }}</p>
    </header>

    @if (session('status'))
        <p class="status-message" role="status">{{ session('status') }}</p>
    @endif
    @if ($errors->any())
        <div class="field-error" role="alert">
            @foreach ($errors->all() as $message)
                <p>{{ $message }}</p>
            @endforeach
        </div>
    @endif

    @if ($canEdit)
        <form class="flex flex-col gap-3 rounded border border-current/20 p-4" wire:submit="create">
            <h2 class="text-xl">{{ __('planner.batches.new_heading') }}</h2>
            @if ($recipeId === null)
                <label class="font-semibold" for="batch-search">{{ __('planner.batches.recipe_search') }}</label>
                <input id="batch-search" class="min-h-11 rounded border border-current/30 px-3" type="search" wire:model.live.debounce.300ms="search" autocomplete="off">
                <ul class="flex flex-col gap-1">
                    @foreach ($results as $recipe)
                        <li wire:key="result-{{ $recipe->id }}">
                            <button class="text-link min-h-11 text-start break-words" type="button" wire:click="selectRecipe({{ $recipe->id }})">{{ $recipe->displayTranslation($locale)?->title }}</button>
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="font-semibold">{{ \App\Models\Recipe::find($recipeId)?->displayTranslation($locale)?->title }}</p>
                <label class="font-semibold" for="batch-portions">{{ __('planner.batches.portions') }}</label>
                <input id="batch-portions" class="min-h-11 rounded border border-current/30 px-3" type="number" min="1" max="200" wire:model="portions">
                <label class="font-semibold" for="batch-date">{{ __('planner.batches.cooked_on') }}</label>
                <input id="batch-date" class="min-h-11 rounded border border-current/30 px-3" type="date" wire:model="cookedOn">
                <label class="font-semibold" for="batch-notes">{{ __('planner.batches.notes') }}</label>
                <input id="batch-notes" class="min-h-11 rounded border border-current/30 px-3" type="text" maxlength="255" wire:model="notes">
                <button class="button button-dark min-h-11 self-start" type="submit">{{ __('planner.batches.create') }}</button>
            @endif
        </form>
    @endif

    @if ($batches->isEmpty())
        <p>{{ __('planner.batches.empty') }}</p>
    @else
        <ul class="flex flex-col gap-3" aria-label="{{ __('planner.batches.list') }}">
            @foreach ($batches as $batch)
                <li class="flex flex-col gap-2 rounded border border-current/20 p-3" wire:key="batch-{{ $batch['id'] }}-{{ $batch['version'] }}">
                    <h2 class="text-lg break-words">
                        @if ($batch['available'])
                            {{ $batch['title'] }}
                        @else
                            <span class="italic">{{ __('planner.batches.unavailable') }}</span>
                        @endif
                    </h2>
                    <p class="text-sm">{{ __('planner.batches.cooked', ['date' => $batch['cooked_on']->locale($locale)->translatedFormat('j F Y')]) }}@if ($batch['available'] && $batch['notes']) &middot; {{ $batch['notes'] }}@endif</p>
                    <p>{{ __('planner.batches.summary', ['prepared' => $batch['prepared'], 'reserved' => $batch['reserved'], 'consumed' => $batch['consumed'], 'free' => $batch['free']]) }}</p>

                    @if ($batch['leftovers']->isNotEmpty())
                        <h3 class="text-sm font-semibold uppercase">{{ __('planner.batches.scheduled') }}</h3>
                        <ul class="flex flex-col gap-1">
                            @foreach ($batch['leftovers'] as $leftover)
                                <li class="flex flex-wrap items-center gap-x-3" wire:key="leftover-{{ $leftover['id'] }}-{{ $leftover['version'] }}">
                                    <span>{{ $leftover['date']->locale($locale)->translatedFormat('j M') }} &middot; {{ $leftover['meal']->label() }} &middot; {{ trans_choice('planner.servings_count', $leftover['servings']) }}</span>
                                    @if ($leftover['consumed'])
                                        <span class="font-semibold">{{ __('planner.batches.eaten') }}</span>
                                    @elseif ($canEdit)
                                        <button class="text-link min-h-11" type="button" wire:click="markEaten({{ $leftover['id'] }})">{{ __('planner.batches.mark_eaten') }}</button>
                                    @endif
                                    @if ($canEdit && ! $leftover['consumed'])
                                        <button class="text-link min-h-11" type="button" wire:click="removeLeftover({{ $leftover['id'] }}, {{ $leftover['version'] }})">{{ __('planner.batches.remove_leftover') }}</button>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    @if ($canEdit && $batch['available'])
                        @if ($activeBatchId === $batch['id'])
                            <form class="flex flex-col gap-2" wire:submit="scheduleLeftover">
                                <h3 class="font-semibold">{{ __('planner.batches.schedule_heading') }}</h3>
                                <label for="lo-date-{{ $batch['id'] }}">{{ __('planner.batches.date') }}</label>
                                <input id="lo-date-{{ $batch['id'] }}" class="min-h-11 rounded border border-current/30 px-3" type="date" wire:model="leftoverDate">
                                <label for="lo-meal-{{ $batch['id'] }}">{{ __('planner.batches.meal') }}</label>
                                <select id="lo-meal-{{ $batch['id'] }}" class="min-h-11 rounded border border-current/30 px-3" wire:model="leftoverMeal">
                                    @foreach ($meals as $mealType)
                                        <option value="{{ $mealType->value }}">{{ $mealType->label() }}</option>
                                    @endforeach
                                </select>
                                <label for="lo-portions-{{ $batch['id'] }}">{{ __('planner.batches.portions') }}</label>
                                <input id="lo-portions-{{ $batch['id'] }}" class="min-h-11 rounded border border-current/30 px-3" type="number" min="1" max="200" wire:model="leftoverPortions">
                                <button class="button button-dark min-h-11 self-start" type="submit">{{ __('planner.batches.schedule') }}</button>
                            </form>
                            <form class="flex flex-col gap-2" wire:submit="consume">
                                <h3 class="font-semibold">{{ __('planner.batches.eat_heading') }}</h3>
                                <label for="eat-portions-{{ $batch['id'] }}">{{ __('planner.batches.portions') }}</label>
                                <input id="eat-portions-{{ $batch['id'] }}" class="min-h-11 rounded border border-current/30 px-3" type="number" min="1" max="200" wire:model="eatPortions">
                                <button class="button min-h-11 self-start" type="submit">{{ __('planner.batches.eat') }}</button>
                            </form>
                        @else
                            <button class="button min-h-11 self-start" type="button" wire:click="open({{ $batch['id'] }})">{{ __('planner.batches.schedule_heading') }}</button>
                        @endif
                    @endif

                    @if ($canEdit && $batch['consumed'] === 0)
                        <button class="text-link min-h-11 self-start" type="button" wire:click="cancel({{ $batch['id'] }}, {{ $batch['version'] }})">{{ __('planner.batches.cancel') }}</button>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</div>
