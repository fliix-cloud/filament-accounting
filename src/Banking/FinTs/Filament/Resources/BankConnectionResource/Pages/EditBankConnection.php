<?php

namespace FilamentAccounting\Banking\FinTs\Filament\Resources\BankConnectionResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use FilamentAccounting\Banking\FinTs\Data\ScaOutcome;
use FilamentAccounting\Banking\FinTs\Enums\ScaOperationType;
use FilamentAccounting\Banking\FinTs\Filament\Concerns\InteractsWithScaChallenge;
use FilamentAccounting\Banking\FinTs\Filament\Resources\BankConnectionResource;
use FilamentAccounting\Banking\FinTs\Models\BankConnection;
use FilamentAccounting\Banking\FinTs\Services\AccountSyncService;
use FilamentAccounting\Banking\FinTs\Services\BankConnectionService;
use FilamentAccounting\Banking\FinTs\Support\BankQuirks;
use FilamentAccounting\Banking\FinTs\Support\EndpointValidator;
use FilamentAccounting\Banking\FinTs\Support\FintsUi;
use FilamentAccounting\Banking\FinTs\Support\ProductRegistration;
use FilamentAccounting\Contracts\AccountingActorResolver as BankActorResolver;
use FilamentAccounting\Models\AccountingBankAccount as BankAccount;

class EditBankConnection extends EditRecord
{
    use InteractsWithScaChallenge;

    protected static string $resource = BankConnectionResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();
        if ($record instanceof BankConnection) {
            // username/customer_id are $hidden for JSON, so attributesToArray() omits them.
            $data['username'] = $record->username;
            $data['customer_id'] = $record->customer_id;
        }

        $data['pin'] = '';
        $data['active_account_ids'] = $record instanceof BankConnection
            ? $record->accounts()
                ->where('is_available', true)
                ->where('is_enabled', true)
                ->pluck('id')
                ->map(fn ($id): string => (string) $id)
                ->all()
            : [];
        unset($data['owner_type'], $data['owner_id'], $data['created_by_type'], $data['created_by_id']);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['owner_type'], $data['owner_id'], $data['created_by_type'], $data['created_by_id'], $data['active_account_ids']);
        if (! filled($data['pin'] ?? null)) {
            unset($data['pin']);
        }
        if (isset($data['endpoint_url'])) {
            $data['endpoint_url'] = EndpointValidator::validate((string) $data['endpoint_url']);
        }

        $record = $this->getRecord();
        if ($record instanceof BankConnection && array_key_exists('tan_mode_id', $data)) {
            $mode = $record->tanModeFromCache(filled($data['tan_mode_id'] ?? null) ? (string) $data['tan_mode_id'] : null);
            $data['tan_mode_name'] = $mode['name'] ?? null;
            if (! ($mode['needs_medium'] ?? false)) {
                $data['tan_medium_name'] = null;
            }
        }

        return $data;
    }

    protected function afterSave(): void
    {
        $ids = collect($this->data['active_account_ids'] ?? [])
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->all();

        // Bank availability and the user's local choice are deliberately
        // independent. Editing the connection must never claim that a missing
        // account exists at the bank again.
        $accounts = BankAccount::query()
            ->where('bank_connection_id', $this->connection()->id)
            ->get();

        foreach ($accounts as $account) {
            $enabled = $account->is_available
                ? in_array((int) $account->getKey(), $ids, true)
                : $account->is_enabled;

            if ($account->is_enabled === $enabled) {
                continue;
            } else {
                $account->is_enabled = $enabled;
                $account->save();
            }
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('test')
                ->label(__('filament-accounting::banking/fints/actions.test_connection'))
                ->disabled(fn (): bool => ! ProductRegistration::isConfigured() || ! $this->hasTanSelection())
                ->tooltip(fn (): ?string => $this->liveBankActionTooltip())
                ->action(function (BankConnectionService $service, BankActorResolver $actors): void {
                    $this->save(shouldRedirect: false, shouldSendSavedNotification: false);
                    $connection = $this->connection()->fresh() ?? $this->connection();
                    try {
                        $outcome = $service->test(
                            $connection,
                            $actors->resolve(),
                            static::getUrl(['record' => $connection]),
                        );
                    } catch (\Throwable $e) {
                        FintsUi::notifyFailure($e);
                        $failed = $connection->fresh();
                        if ($failed instanceof BankConnection) {
                            $this->record = $failed;
                        }

                        throw new Halt;
                    }
                    $fresh = $connection->fresh();
                    if ($fresh instanceof BankConnection) {
                        $this->record = $fresh;
                    }
                    if ($this->openSca($outcome)) {
                        return;
                    }
                    Notification::make()->title(__('filament-accounting::banking/fints/notifications.connection_tested'))->success()->send();
                }),
            Action::make('discoverTan')
                ->label(__('filament-accounting::banking/fints/actions.discover_tan'))
                ->disabled(fn (): bool => ! ProductRegistration::isConfigured())
                ->tooltip(fn (): ?string => ProductRegistration::isConfigured()
                    ? null
                    : __('filament-accounting::banking/fints/notifications.product_id_missing'))
                ->action(function (BankConnectionService $service): void {
                    $modes = FintsUi::run(fn (): array => $service->discoverTanModes($this->connection()));
                    $fresh = $this->connection()->fresh();
                    if ($fresh instanceof BankConnection) {
                        $this->record = $fresh;
                    }
                    $this->refreshFormData(['tan_mode_id', 'tan_medium_name']);
                    Notification::make()
                        ->title(__('filament-accounting::banking/fints/notifications.tan_modes_discovered'))
                        ->body(__('filament-accounting::banking/fints/notifications.tan_modes_discovered_body', [
                            'count' => count($modes),
                        ]))
                        ->success()
                        ->send();
                }),
            Action::make('syncAccounts')
                ->label(__('filament-accounting::banking/fints/actions.sync_accounts'))
                ->disabled(fn (): bool => ! ProductRegistration::isConfigured())
                ->tooltip(fn (): ?string => ProductRegistration::isConfigured()
                    ? null
                    : __('filament-accounting::banking/fints/notifications.product_id_missing'))
                ->action(function (AccountSyncService $service, BankActorResolver $actors): void {
                    $outcome = FintsUi::run(fn (): ScaOutcome => $service->sync(
                        $this->connection(),
                        $actors->resolve(),
                        static::getUrl(['record' => $this->connection()]),
                    ));
                    if ($this->openSca($outcome)) {
                        return;
                    }
                    $this->fillForm();
                    Notification::make()->title(__('filament-accounting::banking/fints/notifications.accounts_synced'))->success()->send();
                }),
            DeleteAction::make(),
        ];
    }

    protected function afterScaCompleted(ScaOutcome $outcome): void
    {
        $connection = $this->connection()->fresh() ?? $this->connection();
        if ($outcome->session?->operation_type === ScaOperationType::TestConnection) {
            $connection = app(BankConnectionService::class)->markSuccessful($connection);
        }
        $this->record = $connection;
        $this->fillForm();
    }

    private function connection(): BankConnection
    {
        $record = $this->getRecord();

        if (! $record instanceof BankConnection) {
            throw new \RuntimeException('Expected a bank connection record.');
        }

        return $record;
    }

    private function hasTanSelection(): bool
    {
        if (BankQuirks::isIngDiba((string) $this->connection()->bank_code)) {
            return true;
        }

        $mode = $this->data['tan_mode_id'] ?? $this->connection()->tan_mode_id;

        return filled($mode);
    }

    private function liveBankActionTooltip(): ?string
    {
        if (! ProductRegistration::isConfigured()) {
            return __('filament-accounting::banking/fints/notifications.product_id_missing');
        }

        if (! $this->hasTanSelection()) {
            return __('filament-accounting::banking/fints/errors.tan_mode_required');
        }

        return null;
    }
}
