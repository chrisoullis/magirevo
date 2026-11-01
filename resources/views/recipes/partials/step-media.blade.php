@if ($step['images'] !== [])
    <div class="step-media-gallery">
        @foreach ($step['images'] as $image)
            <figure>
                <img src="{{ $image['url'] }}" alt="{{ $image['alt_text'] }}" @if ($image['width']) width="{{ $image['width'] }}" @endif @if ($image['height']) height="{{ $image['height'] }}" @endif loading="lazy" decoding="async">
                @if ($image['caption'])<figcaption>{{ $image['caption'] }}</figcaption>@endif
            </figure>
        @endforeach
    </div>
@endif
@if ($step['video'])
    <figure class="step-video">
        <div class="step-video-frame">
            <iframe src="{{ $step['video']['embed_url'] }}" title="{{ $step['video']['title'] ?: __('site.step_video_alt', ['number' => $step['number']]) }}" loading="lazy" referrerpolicy="strict-origin-when-cross-origin" allow="encrypted-media; picture-in-picture" allowfullscreen></iframe>
        </div>
        @if ($step['video']['caption'])<figcaption>{{ $step['video']['caption'] }}</figcaption>@endif
    </figure>
@endif
