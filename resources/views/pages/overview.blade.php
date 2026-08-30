<x-filament-panels::page>
    @unless ($entity)
        <x-filament::section>
            <x-slot name="heading">{{ __('filament-accounting::navigation.legal_entities') }}</x-slot>
            <p>{{ __('filament-accounting::errors.unauthorized', ['ability' => 'view']) }}</p>
        </x-filament::section>
    @endunless
</x-filament-panels::page>
