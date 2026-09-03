<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">{{ __('filament-accounting::banking/fints/fields.booked_balance') }}</x-slot>
        <ul class="space-y-1 text-sm">
            @forelse ($accounts as $account)
                <li>{{ $account->displayName() }}: {{ $account->formattedBalance($account->booked_balance_minor) }}</li>
            @empty
                <li>—</li>
            @endforelse
        </ul>
    </x-filament::section>
</x-filament-widgets::widget>
