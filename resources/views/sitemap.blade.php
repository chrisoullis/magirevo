<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    @foreach ($locales as $locale)
        <url><loc>{{ route('home.localized', ['locale' => $locale]) }}</loc></url>
        <url><loc>{{ route('recipes.what-can-i-cook', ['locale' => $locale]) }}</loc></url>
        <url><loc>{{ route('ingredients.index', ['locale' => $locale]) }}</loc></url>
    @endforeach
    @foreach ($recipes as $recipe)
        @foreach ($locales as $locale)
            @php($translation = $recipe->translate($locale))
            <url><loc>{{ route('recipes.show', ['locale' => $locale, 'slug' => $translation->slug]) }}</loc><lastmod>{{ $recipe->updated_at->toAtomString() }}</lastmod></url>
        @endforeach
    @endforeach
    @foreach ($ingredients as $ingredient)
        @foreach ($locales as $locale)
            @if ($ingredient->hasPublicTranslation($locale))
                <url><loc>{{ route('ingredients.show', ['locale' => $locale, 'slug' => $ingredient->getTranslation($locale, false)->slug]) }}</loc><lastmod>{{ $ingredient->updated_at->toAtomString() }}</lastmod></url>
            @endif
        @endforeach
    @endforeach
</urlset>
