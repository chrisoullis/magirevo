<x-filament-widgets::widget>
    <x-filament::section :heading="$this->heading()" :description="$this->description()" :icon="$this->icon()" icon-color="gray">
        <div class="flex flex-col items-center gap-2 py-6 text-center">
            <x-filament::icon :icon="$this->icon()" class="h-8 w-8 text-gray-400" />
            <p class="text-sm font-medium text-gray-950 dark:text-white">{{ __('dashboard.unavailable.title') }}</p>
            <p class="max-w-md text-sm text-gray-500 dark:text-gray-400">{{ $this->reason() }}</p>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
