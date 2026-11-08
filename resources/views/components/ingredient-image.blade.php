@props(['ingredient', 'thumbnail' => true, 'eager' => false, 'sizes' => '(min-width: 1100px) 300px, (min-width: 700px) 33vw, 50vw'])
@php
    $assignment = $ingredient->publicPrimaryImage;
    $small = $assignment?->thumbnail;
    $large = $assignment?->media;
    $responsive = $small && $large && $small->width && $large->width && $small->width < $large->width;
@endphp
<img {{ $attributes }} src="{{ $ingredient->encyclopediaImageUrl($thumbnail) }}" alt="{{ $ingredient->encyclopediaImageAlt(app()->getLocale()) }}"
    @if ($responsive) srcset="{{ $assignment->publicUrl('thumbnail') }} {{ $small->width }}w, {{ $assignment->publicUrl() }} {{ $large->width }}w" sizes="{{ $sizes }}" @endif
    width="{{ ($thumbnail ? $small?->width : $large?->width) ?? 480 }}" height="{{ ($thumbnail ? $small?->height : $large?->height) ?? 320 }}"
    loading="{{ $eager ? 'eager' : 'lazy' }}" @if($eager) fetchpriority="high" @endif decoding="async"
    onerror="this.onerror=null;this.removeAttribute('srcset');this.src='{{ asset('images/ingredient-placeholder.svg') }}'">
