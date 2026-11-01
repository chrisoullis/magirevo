<x-filament-widgets::widget>
    <x-filament::section :heading="__('dashboard.errors.heading')" :description="__('dashboard.errors.description')">
        @if ($summary === null)
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('dashboard.errors.no_log') }}</p>
        @elseif ($summary['recent'] === [])
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('dashboard.errors.empty') }}</p>
        @else
            <ul class="divide-y divide-gray-200 dark:divide-white/10">
                @foreach ($summary['recent'] as $entry)
                    <li class="flex flex-col gap-1 py-2 sm:flex-row sm:items-start sm:gap-3">
                        <span class="shrink-0 text-xs text-gray-500 dark:text-gray-400">{{ $entry['at'] }}</span>
                        <x-filament::badge color="danger" class="shrink-0">{{ $entry['level'] }}</x-filament::badge>
                        <span class="break-words text-sm text-gray-950 dark:text-white">{{ $entry['message'] }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
