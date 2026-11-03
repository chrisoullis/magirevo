<div class="flex flex-col gap-6" @if ($household) wire:poll.30s @endif>
    <header class="flex flex-col gap-2">
        <a class="text-link inline-flex min-h-11 items-center" href="{{ $household ? route('households.planner', ['locale' => $locale, 'household' => $household->id, 'week' => $start->toDateString()], false) : route('planner.index', ['locale' => $locale, 'week' => $start->toDateString()], false) }}">&larr; {{ __('planner.shopping.back_to_plan') }}</a>
        <h1 class="text-3xl leading-tight break-words">{{ $household ? __('planner.shopping.household_heading', ['name' => $household->name]) : __('planner.shopping.heading') }}</h1>
        <p>{{ __('planner.shopping.week_of', ['date' => $start->locale($locale)->translatedFormat('j F Y')]) }}</p>
    </header>

    @if (session('status'))
        <p class="status-message" role="status">{{ session('status') }}</p>
    @endif
    @error('conflict')<p class="field-error" role="alert">{{ $message }}</p>@enderror

    @if (! $plan)
        <p>{{ __('planner.shopping.no_plan') }}</p>
    @else
        @if ($canEdit)
            <div class="flex flex-col gap-1">
                <button class="button button-dark min-h-11 self-start" type="button" wire:click="generate" wire:loading.attr="disabled">{{ $list?->last_generated_at ? __('planner.shopping.regenerate') : __('planner.shopping.generate') }}</button>
                <p class="text-sm">{{ __('planner.shopping.generate_hint') }}</p>
            </div>
        @endif

        @if ($items->isEmpty())
            <p>{{ __('planner.shopping.empty') }}</p>
        @else
            <ul class="flex flex-col divide-y divide-current/15 rounded border border-current/20" aria-label="{{ __('planner.shopping.items') }}">
                @foreach ($items as $item)
                    <li class="flex flex-wrap items-center gap-3 px-3 py-2" wire:key="item-{{ $item['id'] }}-{{ $item['version'] }}">
                        <input id="item-{{ $item['id'] }}" class="size-6 shrink-0" type="checkbox" @checked($item['checked']) @disabled(! $canEdit)
                               wire:click="setChecked({{ $item['id'] }}, {{ $item['version'] }}, {{ $item['checked'] ? 'false' : 'true' }})">
                        <label class="flex min-h-11 min-w-0 flex-1 flex-col justify-center {{ $item['checked'] ? 'opacity-70' : '' }}" for="item-{{ $item['id'] }}">
                            <span class="break-words {{ $item['checked'] ? 'line-through' : '' }}">
                                {{ $item['name'] }}@if ($item['amount']) <span class="font-semibold">&mdash; {{ $item['amount'] }}</span>@endif
                            </span>
                            @if ($item['optional'])<span class="text-sm">{{ __('planner.shopping.optional') }}</span>@endif
                            @if ($item['manual'])<span class="text-sm">{{ __('planner.shopping.manual') }}</span>@endif
                            @if ($item['stale'])<span class="text-sm">{{ __('planner.shopping.no_longer_needed') }}</span>@endif
                        </label>
                        @if ($item['ingredient_url'])
                            <a class="ingredient-reference min-h-11 inline-flex items-center" href="{{ $item['ingredient_url'] }}">{{ __('ingredients.about_named', ['name' => $item['name']]) }}</a>
                        @endif
                        @if ($item['extra'])
                            <p class="w-full text-sm font-semibold" role="status">{{ __('planner.shopping.still_to_buy', ['amount' => $item['extra']]) }}</p>
                        @endif
                        @if ($canEdit && $item['manual'])
                            <button class="text-link min-h-11" type="button" wire:click="removeManual({{ $item['id'] }}, {{ $item['version'] }})">{{ __('planner.remove') }}</button>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($canEdit)
            <form class="flex flex-col gap-3 rounded border border-current/20 p-4" wire:submit="addManual">
                <h2 class="text-xl">{{ __('planner.shopping.add_heading') }}</h2>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <div class="flex flex-col gap-1 sm:col-span-3">
                        <label class="font-semibold" for="manual-name">{{ __('planner.shopping.item_name') }}</label>
                        <input id="manual-name" class="min-h-11 rounded border border-current/30 px-3" type="text" maxlength="120" wire:model="manualName">
                        @error('manualName')<p class="field-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="flex flex-col gap-1">
                        <label class="font-semibold" for="manual-quantity">{{ __('planner.shopping.quantity') }}</label>
                        <input id="manual-quantity" class="min-h-11 rounded border border-current/30 px-3" type="text" inputmode="decimal" wire:model="manualQuantity">
                        @error('manualQuantity')<p class="field-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="flex flex-col gap-1">
                        <label class="font-semibold" for="manual-unit">{{ __('planner.shopping.unit') }}</label>
                        <select id="manual-unit" class="min-h-11 rounded border border-current/30 px-2" wire:model="manualUnit">
                            <option value="">&mdash;</option>
                            @foreach ($units as $unit)
                                <option value="{{ $unit->id }}">{{ $unit->translateOrDefault($locale)?->name }}</option>
                            @endforeach
                        </select>
                        @error('manualUnit')<p class="field-error">{{ $message }}</p>@enderror
                    </div>
                </div>
                <button class="button button-dark min-h-11 self-start" type="submit">{{ __('planner.shopping.add') }}</button>
            </form>
        @endif
    @endif
</div>
