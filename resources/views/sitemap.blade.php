<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    @foreach ($locales as $locale)
        <url><loc>{{ route('home.localized', ['locale' => $locale]) }}</loc></url>
        <url><loc>{{ route('recipes.what-can-i-cook', ['locale' => $locale]) }}</loc></url>
    @endforeach
    @foreach ($recipes as $recipe)
        @foreach ($locales as $locale)
            @php($translation = $recipe->translate($locale))
            <url><loc>{{ route('recipes.show', ['locale' => $locale, 'slug' => $translation->slug]) }}</loc><lastmod>{{ $recipe->updated_at->toAtomString() }}</lastmod></url>
        @endforeach
    @endforeach
</urlset>