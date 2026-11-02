<div class="what-cook-tool">
    <section class="what-cook-search" aria-labelledby="what-cook-search-title">
        <div class="what-cook-search-header">
            <div>
                <h2 id="what-cook-search-title">{{ __('site.cooking_ingredients_label') }}</h2>
                <p>{{ __('site.cooking_intro') }}</p>
            </div>
            @if ($selectedIngredients->isNotEmpty())
                <button class="text-link" type="button" wire:click="clearIngredients">{{ __('site.cooking_clear_ingredients') }}</button>
            @endif
        </div>

        <label class="what-cook-search-field" for="what-cook-ingredient-search">
            <span class="sr-only">{{ __('site.cooking_search_label') }}</span>
            <input
                id="what-cook-ingredient-search"
                type="search"
                wire:model.live.debounce.250ms="ingredientSearch"
                placeholder="{{ __('site.cooking_search_placeholder') }}"
                autocomplete="off"
                @if ($ingredientOptions->isNotEmpty())
                aria-controls="what-cook-ingredient-options"
                @endif
            >
            <span wire:loading wire:target="ingredientSearch" class="what-cook-search-loading">{{ __('site.cooking_searching') }}</span>
        </label>
        @error('ingredientSearch')<p class="field-error">{{ $message }}</p>@enderror

        @if ($ingredientOptions->isNotEmpty())
            <div id="what-cook-ingredient-options" class="what-cook-options" role="listbox" aria-label="{{ __('site.cooking_search_label') }}">
                @foreach ($ingredientOptions as $ingredient)
                    <button
                        class="what-cook-option"
                        type="button"
                        role="option"
                        aria-selected="false"
                        wire:key="ingredient-option-{{ $ingredient['id'] }}"
                        wire:click="selectIngredient({{ $ingredient['id'] }})"
                        wire:loading.attr="disabled"
                    >{{ $ingredient['name'] }}</button>
                @endforeach
            </div>
        @elseif (mb_strlen(trim($ingredientSearch)) >= 2)
            <p class="what-cook-no-options" role="status">{{ __('site.cooking_no_ingredients') }}</p>
        @endif

        @if ($selectedIngredients->isNotEmpty())
            <ul class="what-cook-selected" aria-label="{{ __('site.cooking_selected_ingredients') }}">
                @foreach ($selectedIngredients as $ingredient)
                    @php($ingredientName = (string) ($ingredient->translate($locale)?->name ?? $ingredient->code))
                    <li wire:key="selected-ingredient-{{ $ingredient->getKey() }}">
                        <span>{{ $ingredientName }}</span>
                        <button
                            type="button"
                            aria-label="{{ __('site.cooking_remove_ingredient', ['ingredient' => $ingredientName]) }}"
                            wire:click="removeIngredient({{ $ingredient->getKey() }})"
                        >&times;</button>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <section class="what-cook-filters" aria-labelledby="what-cook-filters-title">
        <div class="what-cook-filters-heading">
            <h2 id="what-cook-filters-title">{{ __('site.cooking_filters') }}</h2>
            <button class="text-link" type="button" wire:click="clearFilters">{{ __('site.clear_filters') }}</button>
        </div>
        <div class="what-cook-filter-grid">
            <label>
                <span>{{ __('site.filter_category') }}</span>
                <select wire:model.live="category">
                    <option value="">{{ __('site.all_categories') }}</option>
                    @foreach ($categories as $recipeCategory)
                        <option value="{{ $recipeCategory['id'] }}">{{ $recipeCategory['name'] }}</option>
                    @endforeach
                </select>
            </label>
            <label>
                <span>{{ __('site.filter_difficulty') }}</span>
                <select wire:model.live="difficulty">
                    <option value="">{{ __('site.any_difficulty') }}</option>
                    @foreach ($difficulties as $recipeDifficulty)
                        <option value="{{ $recipeDifficulty['code'] }}">{{ $recipeDifficulty['name'] }}</option>
                    @endforeach
                </select>
            </label>
            @if ($dietaryClaims->isNotEmpty())
                <label>
                    <span>{{ __('site.cooking_dietary_filter') }}</span>
                    <select wire:model.live="dietaryClaim">
                        <option value="">{{ __('site.any_dietary_classification') }}</option>
                        @foreach ($dietaryClaims as $claimTag)
                            <option value="{{ $claimTag['code'] }}">{{ $claimTag['name'] }}</option>
                        @endforeach
                    </select>
                </label>
            @endif
            <label>
                <span>{{ __('site.cooking_maximum_time') }}</span>
                <input type="number" min="1" max="1440" inputmode="numeric" wire:model.live.debounce.300ms="maximumMinutes">
                @error('maximumMinutes')<small class="field-error">{{ $message }}</small>@enderror
            </label>
            <label class="what-cook-complete-filter">
                <input type="checkbox" wire:model.live="onlyComplete">
                <span>{{ __('site.cooking_only_complete') }}</span>
            </label>
        </div>
    </section>

    <section class="what-cook-results" aria-labelledby="what-cook-results-title" aria-live="polite">
        <div class="what-cook-results-heading">
            <h2 id="what-cook-results-title">{{ __('site.cooking_matches_heading') }}</h2>
            @if ($results->isNotEmpty())
                <span>{{ trans_choice('site.cooking_result_count', $results->count(), ['count' => $results->count()]) }}</span>
            @endif
        </div>

        @if ($shoppingListUrl)
            <p class="what-cook-shopping-link"><a class="text-link" href="{{ $shoppingListUrl }}">{{ __('site.cooking_open_shopping_list') }}</a></p>
        @endif
        @if (session()->has('cooking-shopping-status'))
            <p class="what-cook-status" role="status">{{ session('cooking-shopping-status') }}</p>
        @endif
        @if (session()->has('cooking-planner-status'))
            <p class="what-cook-status" role="status">
                {{ session('cooking-planner-status') }}
                @if (session()->has('cooking-planner-url'))
                    <a class="text-link" href="{{ session('cooking-planner-url') }}">{{ __('site.cooking_open_planner') }}</a>
                @endif
            </p>
        @endif
        @error('shoppingList')<p class="field-error" role="alert">{{ $message }}</p>@enderror
        @error('substitutions')<p class="field-error" role="alert">{{ $message }}</p>@enderror
        <p class="what-cook-substitution-disclaimer">{{ __('site.cooking_substitution_disclaimer') }}</p>

        @if ($selectedIngredients->isEmpty())
            <div class="what-cook-empty">
                <h3>{{ __('site.cooking_select_prompt') }}</h3>
                <p>{{ __('site.cooking_intro') }}</p>
            </div>
        @elseif ($results->isEmpty())
            <div class="what-cook-empty">
                <h3>{{ __('site.cooking_no_results') }}</h3>
                <p>{{ __('site.cooking_no_results_hint') }}</p>
            </div>
        @else
            <div class="what-cook-result-grid" wire:loading.class="is-loading" wire:target="selectedIngredientIds, onlyComplete, category, difficulty, maximumMinutes, dietaryClaim">
                @foreach ($results as $result)
                    @php($recipe = $result['recipe'])
                    <article class="what-cook-result" wire:key="what-cook-recipe-{{ $recipe->getKey() }}">
                        <div class="what-cook-result-meta">
                            <span>{{ __('site.cooking_match_count', ['matched' => $result['matchedCount'], 'required' => $result['requiredCount']]) }}</span>
                            <span>{{ __('site.cooking_missing_count', ['count' => $result['missingCount']]) }}</span>
                            <strong>{{ __('site.cooking_match_'.(in_array($result['status'], ['possible', 'substitution'], true) ? 'with_substitution' : $result['status'])) }}</strong>
                        </div>
                        <h3><a href="{{ route('recipes.show', ['locale' => $locale, 'slug' => $recipe->slug], false) }}">{{ $recipe->title }}</a></h3>
                        @if (filled($recipe->summary))
                            <p class="what-cook-result-summary">{{ $recipe->summary }}</p>
                        @endif
                        <div class="what-cook-result-details">
                            <span>{{ __('site.cooking_total_time', ['minutes' => $result['totalMinutes']]) }}</span>
                            @if (filled($recipe->difficulty))
                                <span>{{ collect($difficulties)->firstWhere('code', $recipe->difficulty)['name'] ?? $recipe->difficulty }}</span>
                            @endif
                        </div>

                        @foreach ($result['ingredientOptions'] as $ingredientOption)
                            <details class="what-cook-substitution-options" wire:key="substitution-options-{{ $recipe->getKey() }}-{{ $ingredientOption['ingredientId'] }}">
                                <summary>{{ __('site.cooking_substitution_options', ['ingredient' => $ingredientOption['ingredientName']]) }}</summary>
                                <ul>
                                    @foreach ($ingredientOption['options'] as $option)
                                        <li wire:key="substitution-option-{{ $recipe->getKey() }}-{{ $ingredientOption['ingredientId'] }}-{{ $option['id'] }}">
                                            <span>
                                                <strong>{{ $option['name'] }}</strong>
                                                @if ($option['explanation'])<span> — {{ $option['explanation'] }}</span>@endif
                                            </span>
                                            @if ($option['confirmed'])
                                                <span>{{ __('site.cooking_substitution_confirmed') }}</span>
                                                <button type="button" wire:click="removeConfirmedSubstitution({{ $recipe->getKey() }}, {{ $ingredientOption['ingredientId'] }})">
                                                    {{ __('site.cooking_remove_substitution_confirmation') }}
                                                </button>
                                            @elseif ($option['selected'])
                                                <button type="button" wire:click="confirmSubstitution({{ $recipe->getKey() }}, {{ $ingredientOption['ingredientId'] }}, {{ $option['id'] }})">
                                                    {{ __('site.cooking_confirm_substitution') }}
                                                </button>
                                            @else
                                                <small>{{ __('site.cooking_select_substitute_ingredient') }}</small>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            </details>
                        @endforeach

                        @if ($result['missingCount'] === 0)
                            <p class="what-cook-complete-message">{{ __('site.cooking_all_required_available') }}</p>
                        @else
                            <div class="what-cook-missing">
                                <h4>{{ __('site.cooking_missing_label') }}</h4>
                                <ul>
                                    @foreach ($result['missingIngredients'] as $missingIngredient)
                                        <li wire:key="missing-{{ $recipe->getKey() }}-{{ $loop->index }}">{{ $missingIngredient }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                        @if ($result['substitutedCount'] > 0)
                            <p class="what-cook-substitution-quantity-note">{{ __('site.cooking_substitution_quantity_note') }}</p>
                        @endif
                        <div class="what-cook-result-actions">
                            @if ($canAddMissingIngredients && $result['missingCount'] > 0)
                                <button
                                    class="button button-dark what-cook-add-button"
                                    type="button"
                                    wire:click="addMissingIngredients({{ $recipe->getKey() }})"
                                    wire:loading.attr="disabled"
                                    wire:target="addMissingIngredients"
                                >{{ __('site.cooking_add_missing') }}</button>
                            @endif
                            @if ($canAddToPlanner)
                                <button
                                    class="button what-cook-add-button"
                                    type="button"
                                    wire:click="addToMealPlan({{ $recipe->getKey() }})"
                                    wire:loading.attr="disabled"
                                    wire:target="addToMealPlan"
                                >{{ __('site.cooking_add_to_planner') }}</button>
                            @endif
                        </div>

                        <a class="what-cook-recipe-link" href="{{ route('recipes.show', ['locale' => $locale, 'slug' => $recipe->slug], false) }}">{{ __('site.get_recipe') }} <span aria-hidden="true">&rarr;</span></a>
                    </article>
                @endforeach
            </div>
        @endif
    </section>
</div>
