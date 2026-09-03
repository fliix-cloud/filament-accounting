<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">{{ __('filament-accounting::banking/fints/actions.resume_sca') }}</x-slot>
        <ul class="space-y-1 text-sm">
            @forelse ($sessions as $session)
                <li>
                    <a class="text-primary-600 underline" href="{{ \FilamentAccounting\Banking\FinTs\Filament\Pages\StrongAuthentication::getUrl(['record' => $session]) }}">
                        {{ $session->state->getLabel() }}
                    </a>
                </li>
            @empty
                <li>—</li>
            @endforelse
        </ul>
    </x-filament::section>
</x-filament-widgets::widget>
