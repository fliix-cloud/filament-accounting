<x-filament-panels::page>
    <div class="space-y-4">
        <div>
            <span class="font-medium">{{ $session->state->getLabel() }}</span>
            @if ($session->tan_medium_name)
                <div class="text-sm text-gray-500">{{ __('filament-accounting::banking/fints/sca.tan_medium') }}: {{ $session->tan_medium_name }}</div>
            @endif
        </div>

        @if (filled($vopText))
            <x-filament::section>
                <x-slot name="heading">{{ __('filament-accounting::banking/fints/sca.vop_title') }}</x-slot>
                @if ($session->vop_match)
                    <div class="mb-2 font-medium">{{ $session->vop_match->getLabel() }}</div>
                @endif
                <p class="whitespace-pre-wrap text-sm">{{ $vopText }}</p>
                <p class="mt-2 text-sm text-warning-700">{{ __('filament-accounting::banking/fints/sca.vop_warning') }}</p>
            </x-filament::section>
        @endif

        @include('filament-accounting::banking/fints/sca', [
            'challengeText' => $challengeText,
            'challengeUrl' => $challengeUrl,
        ])
    </div>
</x-filament-panels::page>
