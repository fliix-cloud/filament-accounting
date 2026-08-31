<x-filament-panels::page>
    @if ($this->line)
        <livewire:filament-accounting.reconciliation-assistant
            :line="$this->line"
            context="page"
            :key="'reconciliation-page-'.$this->line"
        />
    @else
        <x-filament::section>
            <x-slot name="heading">{{ __('filament-accounting::navigation.bank_transactions') }}</x-slot>
            <p>{{ __('filament-accounting::fields.select_line') }}</p>
            <p style="margin-top: .75rem;">
                <a href="{{ \FilamentAccounting\Filament\Resources\BankStatementLineResource::getUrl() }}" class="fi-link">
                    {{ __('filament-accounting::navigation.bank_transactions') }}
                </a>
            </p>
        </x-filament::section>
    @endif
</x-filament-panels::page>
