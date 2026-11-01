<aside class="ingredients-panel" id="ingredients" data-ingredients-panel data-recipe-id="{{ $recipeId }}" data-servings="{{ $current }}">
    <span class="eyebrow"><span class="eyebrow-dot"></span> {{ __('site.need_eyebrow') }}</span>
    <h2>{{ __('site.ingredients') }}</h2>
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
    <div class="ingredient-tools">
        <button type="button" class="ingredient-reset" data-ingredient-reset hidden>{{ __('site.reset_ingredients') }}</button>
    </div>
    @foreach ($groups as $groupIndex => $group)
        @if ($group['name'])<h3>{{ $group['name'] }}</h3>@endif
        <ul class="ingredient-list" data-ingredient-list>
            @foreach ($group['items'] as $itemIndex => $item)
                @php($ingredientKey = $groupIndex.'-'.$itemIndex)
                <li wire:key="ingredient-{{ $ingredientKey }}">
                    <input type="checkbox" id="ingredient-{{ $recipeId }}-{{ $ingredientKey }}" data-ingredient-key="{{ $ingredientKey }}">
                    <label for="ingredient-{{ $recipeId }}-{{ $ingredientKey }}">
                        <span class="ingredient-text">
                            @if ($item['quantity'])<strong>{{ $item['quantity'] }}</strong>@endif
                            {{ $item['name'] }}@if ($item['note']), {{ $item['note'] }}@endif
                            @if ($item['optional'])<em>({{ __('site.optional') }})</em>@endif
                        </span>
                    </label>
                </li>
            @endforeach
        </ul>
    @endforeach
</aside>
