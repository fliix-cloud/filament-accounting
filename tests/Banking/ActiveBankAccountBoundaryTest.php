<?php

namespace FilamentAccounting\Tests\Banking;

use FilamentAccounting\Banking\Data\BankStatementLineData;
use FilamentAccounting\Enums\SplitPurpose;
use FilamentAccounting\Exceptions\AccountingException;
use FilamentAccounting\Exceptions\ReconciliationException;
use FilamentAccounting\Filament\Resources\AccountingBankAccountResource;
use FilamentAccounting\Filament\Resources\BankStatementLineResource;
use FilamentAccounting\Models\AccountingBankAccount;
use FilamentAccounting\Reconciliation\ReconciliationAssistantQuery;
use FilamentAccounting\Services\FinalizeReconciliation;
use FilamentAccounting\Services\ImportBankStatementLines;
use FilamentAccounting\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class ActiveBankAccountBoundaryTest extends TestCase
{
    #[Test]
    public function account_settings_show_disabled_accounts_but_transactions_remain_active_only(): void
    {
        $entity = $this->makeEntity();
        $active = $this->makeBankAccount($entity);
        $inactive = AccountingBankAccount::query()->create([
            'legal_entity_id' => $entity->getKey(),
            'display_name' => 'Inactive',
            'iban' => 'DE02120300000000202051',
            'currency' => 'EUR',
            'ledger_account_id' => $active->ledger_account_id,
            'source' => 'synthetic',
            'external_account_id' => 'inactive-account',
            'is_active' => true,
        ]);

        $line = app(ImportBankStatementLines::class)->handle($inactive, [
            new BankStatementLineData(
                externalId: 'inactive-line',
                amountMinor: 1234,
                currency: 'EUR',
                driverKey: 'synthetic',
                sourceAccountExternalId: 'inactive-account',
                bookingDate: '2026-09-02',
            ),
        ]);
        $this->assertSame(1, $line->upserted);

        $inactive->update(['is_active' => false]);

        $this->assertEqualsCanonicalizing(
            [$active->id, $inactive->id],
            AccountingBankAccountResource::getEloquentQuery()->pluck('id')->all(),
        );
        $this->assertSame([], BankStatementLineResource::getEloquentQuery()->pluck('id')->all());
        $this->assertNull(app(ReconciliationAssistantQuery::class)->statementLine(
            (string) $inactive->statementLines()->sole()->uuid,
        ));
    }

    #[Test]
    public function imports_fail_closed_for_an_inactive_bank_account(): void
    {
        $account = $this->makeBankAccount($this->makeEntity());
        $account->update(['is_active' => false]);

        $this->expectException(AccountingException::class);
        $this->expectExceptionMessage(__('filament-accounting::errors.bank_account_inactive'));

        app(ImportBankStatementLines::class)->handle($account, []);
    }

    #[Test]
    public function reconciliation_fails_closed_when_an_account_is_deactivated_after_import(): void
    {
        $this->actingAs($this->makeUser());
        $account = $this->makeBankAccount($this->makeEntity());
        app(ImportBankStatementLines::class)->handle($account, [
            new BankStatementLineData(
                externalId: 'later-inactive-line',
                amountMinor: 1234,
                currency: 'EUR',
                driverKey: 'synthetic',
                sourceAccountExternalId: 'acc-1',
                bookingDate: '2026-09-02',
            ),
        ]);
        $line = $account->statementLines()->sole();
        $account->update(['is_active' => false]);

        $this->expectException(ReconciliationException::class);
        $this->expectExceptionMessage(__('filament-accounting::errors.bank_account_inactive'));

        app(FinalizeReconciliation::class)->handle($line, [[
            'purpose' => SplitPurpose::Suspense->value,
            'amount_minor' => 1234,
            'reason' => 'inactive boundary',
        ]]);
    }
}
