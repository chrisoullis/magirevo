<aside class="ingredients-panel" id="ingredients" data-ingredients-panel data-recipe-id="{{ $recipeId }}" data-state-version="{{ $stateVersion }}" data-servings="{{ $current }}">
    <span class="eyebrow"><span class="eyebrow-dot"></span> {{ __('site.need_eyebrow') }}</span>
    <h2>{{ __('site.ingredients') }}</h2>
    <p class="ingredient-checklist-hint">{{ __('recipe_detail.checklist_hint') }}</p>
    <div class="servings-control" role="group" aria-label="{{ __('site.servings') }}">
        <button type="button" wire:click="decrease" @disabled($current <= $min) aria-label="{{ __('site.decrease_servings') }}">&minus;</button>
        <span class="servings-note" aria-live="polite">{{ __('site.for_servings', ['count' => $current]) }}</span>
        <button type="button" wire:click="increase" @disabled($current >= $max) aria-label="{{ __('site.increase_servings') }}">+</button>
        @if ($isScaled)
            <button type="button" wire:click="resetServings">{{ __('site.reset_servings', ['count' => $base]) }}</button>
        @endif
    </div>
    @if ($isScaled)
        <p class="servings-scaled-note">{{ __('site.scaled_note', ['count' => $base]) }}</p>
    @endif
    @if (session()->has('recipe-substitution-status'))
        <p class="personal-ingredient-change-status" role="status">{{ session('recipe-substitution-status') }}</p>
    @endif
    <div class="ingredient-tools">
        <button type="button" class="ingredient-reset" data-ingredient-reset hidden>{{ __('site.reset_ingredients') }}</button>
    </div>
    @foreach ($groups as $groupIndex => $group)
        @if ($group['name'])<h3>{{ $group['name'] }}</h3>@endif
        <ul class="ingredient-list" data-ingredient-list>
            @foreach ($group['items'] as $itemIndex => $item)
                @if ($item['line_type'] === 'recipe')
                    <li wire:key="component-{{ $item['line_uuid'] }}" data-component-row>
                        @include('recipes.partials.components', ['components' => $item['components'], 'cooking' => false])
                    </li>
                    @continue
                @endif
                @php($ingredientKey = $groupIndex.'-'.$itemIndex)
                <li wire:key="ingredient-{{ $ingredientKey }}">
                    <input type="checkbox" id="ingredient-{{ $recipeId }}-{{ $ingredientKey }}" data-ingredient-key="{{ $ingredientKey }}" data-recipe-ingredient-id="{{ $item['recipe_ingredient_id'] ?? '' }}" aria-label="{{ $item['name'] }}">
                    <div class="ingredient-line-copy">
                        <label class="sr-only" for="ingredient-{{ $recipeId }}-{{ $ingredientKey }}">{{ $item['name'] }}</label>
                        <span class="ingredient-text">
                            @if ($item['image_url'])<a href="{{ $item['encyclopedia_url'] }}" tabindex="-1" aria-hidden="true"><img class="ingredient-inline-photo" src="{{ $item['image_url'] }}" alt="" width="48" height="48" loading="lazy" decoding="async" onerror="this.hidden=true"></a>@endif
                            @if ($item['quantity'])<strong>{{ $item['quantity'] }}</strong>@endif
                            <x-ingredient-link :name="$item['name']" :url="$item['encyclopedia_url']" />@if ($item['note']), {{ $item['note'] }}@endif
                            @if ($item['optional'])<em>({{ __('site.optional') }})</em>@endif
                        </span>
                    </div>
                    @if ($item['substitutions'] !== [])
                        <details class="ingredient-substitution-suggestions">
                            <summary>{{ __('site.ingredient_substitution_suggestions') }}</summary>
                            <p class="ingredient-substitution-disclaimer">{{ __('site.cooking_substitution_disclaimer') }}</p>
                            <ul>
                                @foreach ($item['substitutions'] as $substitution)
                                    <li>
                                        <strong><x-ingredient-link :name="$substitution['name']" :url="$substitution['url']" /></strong>
                                        @if ($substitution['explanation'])<span>{{ $substitution['explanation'] }}</span>@endif
                                    </li>
                                @endforeach
                            </ul>
                        </details>
                    @endif
                    @if ($canPersonalize)
                        <div class="personal-ingredient-change">
                            @if ($item['personalChange'])
                                <p>
                                    <strong>{{ __('site.personal_substitution') }}</strong>
                                    <x-ingredient-link :name="$item['personalChange']['name']" :url="$item['personalChange']['url']" />
                                    @if ($item['personalChange']['note'])<span> — {{ $item['personalChange']['note'] }}</span>@endif
                                </p>
                                <div class="personal-ingredient-change-actions">
                                    <button type="button" wire:click="editPersonalChange({{ $item['recipe_ingredient_id'] }})">{{ __('site.personal_substitution_edit') }}</button>
                                    <button type="button" wire:click="deletePersonalChange({{ $item['recipe_ingredient_id'] }})">{{ __('site.personal_substitution_delete') }}</button>
                                </div>
                            @else
                                <button type="button" wire:click="editPersonalChange({{ $item['recipe_ingredient_id'] }})">{{ __('site.personal_substitution_add') }}</button>
                            @endif
                            @if ($editingRecipeIngredientId === $item['recipe_ingredient_id'])
                                <form class="personal-ingredient-change-form" wire:submit="savePersonalChange({{ $item['recipe_ingredient_id'] }})">
                                    <label for="ingredient-search-{{ $item['recipe_ingredient_id'] }}">{{ __('site.personal_substitution_search') }}</label>
                                    <input
                                        id="ingredient-search-{{ $item['recipe_ingredient_id'] }}"
                                        type="search"
                                        wire:model.live.debounce.300ms="ingredientSearch"
                                        maxlength="100"
                                        autocomplete="off"
                                    >
                                    <label for="ingredient-alternative-{{ $item['recipe_ingredient_id'] }}">{{ __('site.personal_substitution_alternative') }}</label>
                                    <select id="ingredient-alternative-{{ $item['recipe_ingredient_id'] }}" wire:model="alternativeIngredientId" required>
                                        <option value="">{{ __('site.personal_substitution_choose') }}</option>
                                        @foreach ($ingredientOptions as $ingredientId => $ingredientName)
                                            <option value="{{ $ingredientId }}">{{ $ingredientName }}</option>
                                        @endforeach
                                    </select>
                                    @error('alternativeIngredientId')<p class="field-error">{{ $message }}</p>@enderror
                                    <label for="ingredient-note-{{ $item['recipe_ingredient_id'] }}">{{ __('site.personal_substitution_note') }}</label>
                                    <textarea id="ingredient-note-{{ $item['recipe_ingredient_id'] }}" wire:model="personalNote" rows="2" maxlength="500"></textarea>
                                    @error('personalNote')<p class="field-error">{{ $message }}</p>@enderror
                                    <div class="personal-ingredient-change-actions">
                                        <button class="button button-dark" type="submit" wire:loading.attr="disabled">{{ __('site.personal_substitution_save') }}</button>
                                        <button type="button" wire:click="cancelPersonalChange">{{ __('site.personal_substitution_cancel') }}</button>
                                    </div>
                                </form>
                            @endif
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>
    @endforeach
</aside>
