@php
    $session = $this->scaSession();
    $isOpen = filled($this->scaSessionUuid) && $session && $session->state->isOpen();
    $shouldPoll = $isOpen && in_array($session->state->value, ['needs_decoupled', 'needs_polling'], true);
@endphp

@if ($shouldPoll)
    <div wire:poll.5s="pollScaChallenge" style="display:none" aria-hidden="true"></div>
@endif

@if ($isOpen)
    <div
        role="dialog"
        aria-modal="true"
        aria-labelledby="filament-accounting-sca-title"
        style="position:fixed;inset:0;z-index:100;display:flex;align-items:center;justify-content:center;background:rgba(15,23,42,.55);padding:1rem;"
    >
        <div style="width:100%;max-width:32rem;max-height:90vh;overflow:auto;border-radius:0.75rem;background:#fff;padding:1.5rem;box-shadow:0 25px 50px -12px rgba(0,0,0,.35);">
            <h2 id="filament-accounting-sca-title" style="margin:0;font-size:1.125rem;font-weight:600;">
                {{ __('filament-accounting::banking/fints/sca.title') }}
            </h2>
            <p style="margin:0.5rem 0 0;font-size:0.875rem;color:#4b5563;">
                {{ match ($session->state) {
                    \FilamentAccounting\Banking\FinTs\Enums\ScaSessionState::NeedsDecoupled => __('filament-accounting::banking/fints/sca.waiting_app'),
                    \FilamentAccounting\Banking\FinTs\Enums\ScaSessionState::NeedsPolling => __('filament-accounting::banking/fints/sca.waiting_bank'),
                    \FilamentAccounting\Banking\FinTs\Enums\ScaSessionState::NeedsTan => __('filament-accounting::banking/fints/sca.enter_tan'),
                    \FilamentAccounting\Banking\FinTs\Enums\ScaSessionState::NeedsVop => __('filament-accounting::banking/fints/sca.vop_warning'),
                    default => $session->state->getLabel(),
                } }}
            </p>

            <div style="margin-top:1rem;">
                @include('filament-accounting::banking/fints/modals.sca', [
                    'session' => $session,
                    'challengeText' => $session->encrypted_challenge_text,
                    'challengeUrl' => filled($session->encrypted_challenge_payload)
                        ? route('filament-accounting.fints.sca.challenge', $session->uuid)
                        : null,
                    'vopText' => $session->vop_information,
                    'shouldPoll' => false,
                ])
            </div>

            <div style="margin-top:1.5rem;display:flex;flex-wrap:wrap;gap:0.75rem;">
                @if ($session->state === \FilamentAccounting\Banking\FinTs\Enums\ScaSessionState::NeedsTan)
                    <input
                        type="password"
                        wire:model="scaTan"
                        placeholder="{{ __('filament-accounting::banking/fints/sca.tan') }}"
                        style="border:1px solid #d1d5db;border-radius:0.5rem;padding:0.5rem 0.75rem;"
                    />
                    <x-filament::button wire:click="submitScaTan" color="primary">
                        {{ __('filament-accounting::banking/fints/actions.submit_tan') }}
                    </x-filament::button>
                @endif

                @if ($session->state === \FilamentAccounting\Banking\FinTs\Enums\ScaSessionState::NeedsVop)
                    <x-filament::button wire:click="confirmScaVop" color="warning">
                        {{ __('filament-accounting::banking/fints/actions.confirm_vop') }}
                    </x-filament::button>
                @endif

                @if ($session->state === \FilamentAccounting\Banking\FinTs\Enums\ScaSessionState::NeedsDecoupled)
                    <x-filament::button wire:click="confirmScaInApp" color="primary">
                        {{ __('filament-accounting::banking/fints/actions.confirmed_in_app') }}
                    </x-filament::button>
                @endif

                <x-filament::button wire:click="closeScaModal" color="gray">
                    {{ __('filament-accounting::banking/fints/actions.close') }}
                </x-filament::button>
            </div>
        </div>
    </div>
@endif
