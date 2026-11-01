<div class="flex flex-col gap-6" @if ($household) wire:poll.30s @endif>
    <header class="flex flex-col gap-2">
        <a class="text-link inline-flex min-h-11 items-center" href="{{ $household ? route('households.planner', ['locale' => $locale, 'household' => $household->id, 'week' => $start->toDateString()], false) : route('planner.index', ['locale' => $locale, 'week' => $start->toDateString()], false) }}">&larr; {{ __('planner.shopping.back_to_plan') }}</a>
        <h1 class="text-3xl leading-tight break-words">{{ $household ? __('planner.templates.household_heading', ['name' => $household->name]) : __('planner.templates.heading') }}</h1>
        <p>{{ __('planner.templates.intro', ['date' => $start->locale($locale)->translatedFormat('j F Y')]) }}</p>
    </header>

    @if (session('status'))
        <p class="status-message" role="status">{{ session('status') }}</p>
    @endif
    @error('conflict')<p class="field-error" role="alert">{{ $message }}</p>@enderror

    @if ($canEdit)
        <form class="flex flex-col gap-3 rounded border border-current/20 p-4" wire:submit="saveCurrentWeek">
            <h2 class="text-xl">{{ __('planner.templates.save_heading') }}</h2>
            <label class="font-semibold" for="template-name">{{ __('planner.templates.name') }}</label>
            <input id="template-name" class="min-h-11 rounded border border-current/30 px-3" type="text" maxlength="100" wire:model="templateName">
            @error('templateName')<p class="field-error">{{ $message }}</p>@enderror
            <button class="button button-dark min-h-11 self-start" type="submit">{{ __('planner.templates.save') }}</button>
        </form>
    @endif

    @if ($templates->isEmpty())
        <p>{{ __('planner.templates.empty') }}</p>
    @else
        <ul class="flex flex-col gap-3" aria-label="{{ __('planner.templates.list') }}">
            @foreach ($templates as $template)
                <li class="flex flex-col gap-2 rounded border border-current/20 p-3" wire:key="template-{{ $template->id }}-{{ $template->version }}">
                    @if ($renamingId === $template->id)
                        <form class="flex flex-col gap-2" wire:submit="saveRename">
                            <label class="font-semibold" for="rename-{{ $template->id }}">{{ __('planner.templates.name') }}</label>
                            <input id="rename-{{ $template->id }}" class="min-h-11 rounded border border-current/30 px-3" type="text" maxlength="100" wire:model="renameValue">
                            @error('renameValue')<p class="field-error">{{ $message }}</p>@enderror
                            <div class="flex gap-2">
                                <button class="button button-dark min-h-11" type="submit">{{ __('planner.save') }}</button>
                                <button class="button min-h-11" type="button" wire:click="cancelRename">{{ __('planner.cancel') }}</button>
                            </div>
                        </form>
                    @else
                        <h2 class="text-lg break-words">{{ $template->name }}</h2>
                        <p class="text-sm">{{ trans_choice('planner.templates.meals_count', $template->entries_count) }}</p>
                        <div class="flex flex-wrap gap-2">
                            <button class="text-link min-h-11" type="button" wire:click="toggle({{ $template->id }})" aria-expanded="{{ $expandedId === $template->id ? 'true' : 'false' }}">{{ $expandedId === $template->id ? __('planner.templates.hide') : __('planner.templates.show') }}</button>
                            @if ($canEdit)
                                <button class="button button-dark min-h-11" type="button" wire:click="apply({{ $template->id }})" wire:loading.attr="disabled">{{ __('planner.templates.apply') }}</button>
                                <button class="text-link min-h-11" type="button" wire:click="startRename({{ $template->id }})">{{ __('planner.templates.rename') }}</button>
                                <button class="text-link min-h-11" type="button" wire:click="remove({{ $template->id }}, {{ $template->version }})" wire:confirm="{{ __('planner.templates.confirm_delete') }}">{{ __('planner.remove') }}</button>
                            @endif
                        </div>
                        @if ($expandedId === $template->id)
                            <div class="flex flex-col gap-2">
                                @forelse ($expandedEntries as $day => $items)
                                    <section>
                                        <h3 class="font-semibold">{{ $days[$day] }}</h3>
                                        <ul class="ml-4 list-disc">
                                            @foreach ($items as $item)
                                                <li wire:key="te-{{ $item['id'] }}">
                                                    {{ $item['meal']->label() }}:
                                                    @if ($item['available'])
                                                        <span class="break-words">{{ $item['title'] }}</span>
                                                    @else
                                                        <span class="italic">{{ __('planner.recipe_unavailable') }}</span>
                                                    @endif
                                                    ({{ trans_choice('planner.servings_count', $item['servings']) }})
                                                </li>
                                            @endforeach
                                        </ul>
                                    </section>
                                @empty
                                    <p class="text-sm">{{ __('planner.nothing_planned') }}</p>
                                @endforelse
                            </div>
                        @endif
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</div>
