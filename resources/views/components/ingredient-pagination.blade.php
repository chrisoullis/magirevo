@if ($paginator->hasPages())
    <nav class="ingredient-pagination" aria-label="{{ __('ingredients.pagination') }}">
        @if ($paginator->onFirstPage())
            <span aria-disabled="true">{{ __('ingredients.previous') }}</span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" rel="prev" @if ($livewire ?? false) wire:click.prevent="previousPage" @endif>{{ __('ingredients.previous') }}</a>
        @endif
        <span>{{ __('ingredients.page', ['current' => $paginator->currentPage(), 'last' => $paginator->lastPage()]) }}</span>
        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" rel="next" @if ($livewire ?? false) wire:click.prevent="nextPage" @endif>{{ __('ingredients.next') }}</a>
        @else
            <span aria-disabled="true">{{ __('ingredients.next') }}</span>
        @endif
    </nav>
@endif
