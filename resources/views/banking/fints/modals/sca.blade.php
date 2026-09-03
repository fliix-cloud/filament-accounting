<div @class(['space-y-4', 'filament-accounting-sca-modal']) @if ($shouldPoll ?? false) wire:poll.3s="pollScaChallenge" @endif>
    @if ($session)
        <div>
            <span class="font-medium">{{ $session->state->getLabel() }}</span>
            @if ($session->tan_medium_name)
                <div class="text-sm text-gray-500 dark:text-gray-400">{{ __('filament-accounting::banking/fints/sca.tan_medium') }}: {{ $session->tan_medium_name }}</div>
            @endif
            @if ($session->expires_at)
                <div class="text-sm text-gray-500 dark:text-gray-400">{{ __('filament-accounting::banking/fints/sca.expires') }}: {{ $session->expires_at->timezone(config('app.timezone'))->format('H:i:s') }}</div>
            @endif
        </div>

        @if (($session->state->value ?? null) === 'needs_decoupled')
            <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('filament-accounting::banking/fints/sca.poll_hint') }}</p>
        @endif

        @if (filled($vopText ?? null))
            <div class="rounded-lg bg-warning-50 p-4 text-sm text-warning-800 dark:bg-warning-400/10 dark:text-warning-400">
                <div class="font-medium">{{ __('filament-accounting::banking/fints/sca.vop_title') }}</div>
                @if ($session->vop_match)
                    <div class="mt-1 font-medium">{{ $session->vop_match->getLabel() }}</div>
                @endif
                <p class="mt-2 whitespace-pre-wrap">{{ $vopText }}</p>
            </div>
        @endif

        @include('filament-accounting::banking/fints/sca', [
            'challengeText' => $challengeText ?? null,
            'challengeUrl' => $challengeUrl ?? null,
        ])
    @endif
</div>
