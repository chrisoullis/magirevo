@php($readiness = $this->reviewData())
<div class="studio-readiness"><h2>{{ __('studio.readiness') }}</h2><p>{{ __('studio.dietary_notice') }}</p>@if ($readiness['problems'])<ul>@foreach ($readiness['problems'] as $problem)<li>{{ $problem }}</li>@endforeach</ul>@else<p>{{ __('studio.ready') }}</p>@endif</div>
