<?php

namespace FilamentAccounting\Filament\Resources\LegalEntityResource\Pages;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\Concerns\HasWizard;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard\Step;
use FilamentAccounting\Banking\FinTs\Filament\Resources\BankConnectionResource;
use FilamentAccounting\Enums\LegalEntityState;
use FilamentAccounting\Filament\Resources\LegalEntityResource;
use FilamentAccounting\Models\LegalEntity;
use FilamentAccounting\Ownership\ConfiguredLegalEntityResolver;
use FilamentAccounting\Services\SeedGermanProfile;
use FilamentAccounting\Support\Sepa;

class CreateLegalEntity extends CreateRecord
{
    use HasWizard;

    protected static string $resource = LegalEntityResource::class;

    protected bool $connectFints = false;

    /** @return list<Step> */
    public function getSteps(): array
    {
        return [
            Step::make(__('filament-accounting::setup.company'))
                ->schema([
                    TextInput::make('legal_name')->label(__('filament-accounting::fields.legal_name'))->required(),
                    TextInput::make('trading_name')->label(__('filament-accounting::fields.trading_name')),
                    TextInput::make('address_line1')->label(__('filament-accounting::fields.address_line1'))->required(),
                    TextInput::make('address_line2')->label(__('filament-accounting::fields.address_line2')),
                    TextInput::make('postal_code')->label(__('filament-accounting::fields.postal_code'))->required(),
                    TextInput::make('city')->label(__('filament-accounting::fields.city'))->required(),
                ])->columns(2),
            Step::make(__('filament-accounting::setup.country_and_year'))
                ->schema([
                    Select::make('country_code')
                        ->label(__('filament-accounting::fields.country'))
                        ->options(['DE' => 'Deutschland'])
                        ->default('DE')
                        ->required(),
                    TextInput::make('base_currency')
                        ->label(__('filament-accounting::fields.base_currency'))
                        ->default('EUR')
                        ->maxLength(3)
                        ->required(),
                    TextInput::make('fiscal_year_start_month')
                        ->label(__('filament-accounting::fields.fiscal_year_start'))
                        ->default(1)
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(12)
                        ->required(),
                ])->columns(3),
            Step::make(__('filament-accounting::setup.invoice_details'))
                ->schema([
                    FileUpload::make('invoice_logo_path')
                        ->label(__('filament-accounting::fields.logo_path'))
                        ->disk((string) config('filament-accounting.storage.disk', 'local'))
                        ->directory('accounting/company-logos')
                        ->image(),
                    TextInput::make('default_payment_terms_days')
                        ->label(__('filament-accounting::fields.payment_terms_days'))
                        ->default(14)
                        ->numeric()
                        ->minValue(0)
                        ->required(),
                    TextInput::make('invoice_bank_name')->label(__('filament-accounting::fields.bank_name')),
                    TextInput::make('invoice_iban')
                        ->label(__('filament-accounting::fields.iban'))
                        ->rule(fn (): \Closure => function (string $attribute, mixed $value, \Closure $fail): void {
                            if (filled($value) && ! Sepa::isValidIban((string) $value)) {
                                $fail(__('filament-accounting::validation.iban'));
                            }
                        }),
                    TextInput::make('invoice_bic')
                        ->label(__('filament-accounting::fields.bic'))
                        ->rule(fn (): \Closure => function (string $attribute, mixed $value, \Closure $fail): void {
                            if (! Sepa::isValidBic(filled($value) ? (string) $value : null)) {
                                $fail(__('filament-accounting::validation.bic'));
                            }
                        }),
                ])->columns(2),
            Step::make(__('filament-accounting::setup.tax'))
                ->schema([
                    TextInput::make('tax_number')->label(__('filament-accounting::fields.tax_number')),
                    TextInput::make('vat_id')->label(__('filament-accounting::fields.vat_id')),
                    Placeholder::make('tax_profile')
                        ->label(__('filament-accounting::setup.tax_profile'))
                        ->content(__('filament-accounting::setup.tax_profile_help')),
                ])->columns(2),
            Step::make(__('filament-accounting::setup.banking'))
                ->schema([
                    Toggle::make('connect_fints')
                        ->label(__('filament-accounting::setup.connect_fints'))
                        ->helperText(__('filament-accounting::setup.connect_fints_help')),
                ]),
            Step::make(__('filament-accounting::setup.summary'))
                ->schema([
                    Placeholder::make('summary')
                        ->label(__('filament-accounting::setup.ready'))
                        ->content(fn (Get $get): string => __('filament-accounting::setup.summary_help', [
                            'company' => (string) $get('legal_name'),
                        ])),
                ]),
        ];
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->connectFints = (bool) ($data['connect_fints'] ?? false);
        unset($data['connect_fints']);

        return array_merge($data, [
            'country_code' => 'DE',
            'base_currency' => strtoupper((string) ($data['base_currency'] ?? 'EUR')),
            'locale' => 'de_DE',
            'timezone' => 'Europe/Berlin',
            'accounting_basis' => 'accrual',
            'vat_method' => 'standard',
            'compliance_profile_key' => 'DE',
            'state' => LegalEntityState::Active,
            'invoice_template_key' => 'default',
            'invoice_template_version' => '1',
        ]);
    }

    protected function afterCreate(): void
    {
        $record = $this->record;
        if (! $record instanceof LegalEntity) {
            return;
        }

        app(ConfiguredLegalEntityResolver::class)->bind($record);
        app(SeedGermanProfile::class)->handle($record);
    }

    protected function getRedirectUrl(): string
    {
        if ($this->connectFints) {
            return BankConnectionResource::getUrl('create');
        }

        return LegalEntityResource::getUrl('edit', ['record' => $this->record]);
    }
}
