<section class="variation-shell">
    @can('create', \App\Models\Recipe::class)<p><a class="kitchen-button kitchen-button-primary" href="{{ route('recipes.create', ['locale' => $locale], false) }}">{{ __('navigation.create_recipe') }}</a></p>@endcan
    <h1>{{ __('variations.collection') }}</h1>
    <label for="personal-filter">{{ __('variations.filter') }}</label>
    <select id="personal-filter" wire:model.live="filter">
        @foreach (config('recipe-variations.enabled') ? ['all', 'original', 'variation'] : ['original'] as $value)<option value="{{ $value }}">{{ __('variations.filter_'.$value) }}</option>@endforeach
    </select>
    <div class="recipe-grid">
        @forelse ($recipes as $recipe)
            @php($translation = $recipe->displayTranslation($locale))
            <article class="variation-card" wire:key="personal-{{ $recipe->id }}">
                @if ($recipe->derived_from_recipe_id)<span class="eyebrow">{{ __('variations.my_variation') }}</span>@endif
                <h2>{{ $translation?->title }}</h2>
                <p>{{ __('variations.visibility_'.$recipe->visibility->value) }}</p>
                @if ($translation)<a class="button" href="{{ route('recipes.show', ['locale' => $translation->locale, 'slug' => $translation->slug], false) }}">{{ __('variations.preview') }}</a>@endif
                @if ($recipe->derived_from_recipe_id === null && auth()->user()->can('create', \App\Models\Recipe::class))<a class="button" href="{{ route('recipes.studio.edit', ['locale' => $locale, 'recipe' => $recipe->id], false) }}">{{ __('studio.open') }}</a>@endif
                @can('editVariation', $recipe)<a class="button button-dark" href="{{ route('recipes.variations.edit', ['locale' => $locale, 'recipe' => $recipe->id], false) }}">{{ __('variations.edit') }}</a>@endcan
            </article>
        @empty
            <p>{{ __('variations.empty') }}</p>
        @endforelse
    </div>
    {{ $recipes->links() }}
</section>
