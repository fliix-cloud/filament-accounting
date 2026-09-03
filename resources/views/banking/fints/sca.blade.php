<div class="space-y-4">
    @if (filled($challengeText ?? null))
        <div class="rounded-lg bg-gray-50 p-4 text-sm dark:bg-gray-900">
            <div class="font-medium">{{ __('filament-accounting::banking/fints/sca.bank_instructions') }}</div>
            <p class="mt-2 whitespace-pre-wrap">{{ $challengeText }}</p>
        </div>
    @endif

    @if (filled($challengeUrl ?? null))
        <img src="{{ $challengeUrl }}" alt="{{ __('filament-accounting::banking/fints/sca.challenge_image') }}" class="max-w-sm" />
    @endif
</div>
