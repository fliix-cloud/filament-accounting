<?php

namespace FilamentAccounting\Banking\FinTs\Filament\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Panel;
use FilamentAccounting\Banking\FinTs\Data\ScaOutcome;
use FilamentAccounting\Banking\FinTs\Enums\ScaSessionState;
use FilamentAccounting\Banking\FinTs\Models\StrongAuthenticationSession;
use FilamentAccounting\Banking\FinTs\Ownership\LegalEntityBankScope as OwnerScope;
use FilamentAccounting\Banking\FinTs\Services\StrongAuthenticationCoordinator;
use FilamentAccounting\Banking\FinTs\Support\FintsUi;
use FilamentAccounting\Contracts\AccountingActorResolver as BankActorResolver;
use FilamentAccounting\Contracts\AccountingAuthorizer as BankAuthorizer;
use FilamentAccounting\Filament\Resources\AccountingBankAccountResource;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Locked;

class StrongAuthentication extends Page
{
    protected static ?string $slug = 'bank/sca';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament-accounting::banking/fints/pages.sca';

    #[Locked]
    public StrongAuthenticationSession $record;

    public string $tan = '';

    public static function getRoutePath(Panel $panel): string
    {
        return '/bank/sca/{record}';
    }

    public static function getRelativeRouteName(Panel $panel): string
    {
        return 'bank.sca';
    }

    public function mount(OwnerScope $owners, BankAuthorizer $authorizer): void
    {
        $authorizer->authorize('confirm_bank_sca', $this->record);

        abort_unless(
            $owners->connections()->whereKey($this->record->bank_connection_id)->exists(),
            404,
        );

        app(StrongAuthenticationCoordinator::class)->expireIfNeeded($this->record);
        $this->record->refresh();
    }

    public function getTitle(): string|Htmlable
    {
        return __('filament-accounting::banking/fints/sca.title');
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $challengeUrl = filled($this->record->encrypted_challenge_payload)
            ? route('filament-accounting.fints.sca.challenge', $this->record->uuid)
            : null;

        return [
            'session' => $this->record,
            'challengeText' => $this->record->encrypted_challenge_text,
            'challengeUrl' => $challengeUrl,
            'vopText' => $this->record->vop_information,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('submitTan')
                ->label(__('filament-accounting::banking/fints/actions.submit_tan'))
                ->visible(fn (): bool => $this->record->state === ScaSessionState::NeedsTan)
                ->schema([
                    TextInput::make('tan')
                        ->label(__('filament-accounting::banking/fints/sca.tan'))
                        ->password()
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $this->run(fn (StrongAuthenticationCoordinator $c, $connection, $actor) => $c->submitTan($this->record->uuid, $data['tan'], $connection, $actor));
                }),
            Action::make('confirmVop')
                ->label(__('filament-accounting::banking/fints/actions.confirm_vop'))
                ->color('warning')
                ->visible(fn (): bool => $this->record->state === ScaSessionState::NeedsVop)
                ->requiresConfirmation()
                ->action(function (): void {
                    $this->run(fn (StrongAuthenticationCoordinator $c, $connection, $actor) => $c->confirmVop($this->record->uuid, $connection, $actor));
                }),
            Action::make('confirmedInApp')
                ->label(__('filament-accounting::banking/fints/actions.confirmed_in_app'))
                ->visible(fn (): bool => $this->record->state === ScaSessionState::NeedsDecoupled)
                ->action(function (): void {
                    $this->run(fn (StrongAuthenticationCoordinator $c, $connection, $actor) => $c->checkDecoupled($this->record->uuid, $connection, $actor));
                }),
            Action::make('poll')
                ->label(__('filament-accounting::banking/fints/actions.poll'))
                ->visible(fn (): bool => in_array($this->record->state, [ScaSessionState::NeedsPolling, ScaSessionState::NeedsDecoupled], true))
                ->action(function (): void {
                    $this->run(function (StrongAuthenticationCoordinator $c, $connection, $actor) {
                        return $this->record->state === ScaSessionState::NeedsPolling
                            ? $c->poll($this->record->uuid, $connection, $actor)
                            : $c->checkDecoupled($this->record->uuid, $connection, $actor);
                    });
                }),
        ];
    }

    /**
     * @param  callable(StrongAuthenticationCoordinator, mixed, mixed): ScaOutcome  $callback
     */
    private function run(callable $callback): void
    {
        $owners = app(OwnerScope::class);
        $actor = app(BankActorResolver::class)->resolve();
        app(BankAuthorizer::class)->authorize('confirm_bank_sca', $this->record);
        $connection = $owners->connections()->whereKey($this->record->bank_connection_id)->firstOrFail();
        $outcome = FintsUi::run(fn (): ScaOutcome => $callback(app(StrongAuthenticationCoordinator::class), $connection, $actor));
        $this->record->refresh();

        if ($outcome->isDone()) {
            Notification::make()->title(__('filament-accounting::banking/fints/notifications.sca_completed'))->success()->send();
            $this->redirect($this->record->return_url ?: AccountingBankAccountResource::getUrl());
        }
    }
}
