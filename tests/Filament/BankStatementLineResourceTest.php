<?php

namespace FilamentAccounting\Tests\Filament;

use Filament\Actions\Action;
use Filament\Support\Enums\Width;
use Filament\Tables\Table;
use FilamentAccounting\Banking\Data\BankStatementLineData;
use FilamentAccounting\Filament\Pages\ReconciliationPage;
use FilamentAccounting\Filament\Resources\BankStatementLineResource;
use FilamentAccounting\Filament\Resources\BankStatementLineResource\Pages\ListBankStatementLines;
use FilamentAccounting\Models\AccountingBankAccount;
use FilamentAccounting\Models\BankStatementLine;
use FilamentAccounting\Services\ImportBankStatementLines;
use FilamentAccounting\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;

class BankStatementLineResourceTest extends TestCase
{
    #[Test]
    public function it_requires_an_active_account_and_constrains_the_table_to_it(): void
    {
        app()->setLocale('de');
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $giro = $this->makeBankAccount($entity);
        $savings = AccountingBankAccount::query()->create([
            'legal_entity_id' => $entity->getKey(),
            'display_name' => 'Tagesgeld',
            'iban' => 'DE02120300000000202051',
            'currency' => 'EUR',
            'ledger_account_id' => $giro->ledger_account_id,
            'driver_key' => 'synthetic',
            'external_account_id' => 'acc-2',
            'is_active' => true,
        ]);

        app(ImportBankStatementLines::class)->handle($giro, [
            new BankStatementLineData(
                externalId: 'giro-line',
                amountMinor: 123943,
                currency: 'EUR',
                driverKey: 'synthetic',
                sourceAccountExternalId: 'acc-1',
                bookingDate: '2026-03-10',
                sourceStatus: 'pending',
            ),
        ]);
        app(ImportBankStatementLines::class)->handle($savings, [
            new BankStatementLineData(
                externalId: 'savings-line',
                amountMinor: 500,
                currency: 'EUR',
                driverKey: 'synthetic',
                sourceAccountExternalId: 'acc-2',
                bookingDate: '2026-03-11',
                sourceStatus: 'booked',
            ),
        ]);

        $page = app(ListBankStatementLines::class);
        $page->accountId = null;

        $this->assertFalse($page->hasSelectedAccount());
        $this->assertSame([], $page->constrainToSelectedAccount(BankStatementLineResource::getEloquentQuery())->pluck('id')->all());

        $page->updatedAccountId($giro->id);

        $this->assertTrue($page->hasSelectedAccount());
        $this->assertSame(['giro-line'], $page->constrainToSelectedAccount(BankStatementLineResource::getEloquentQuery())->pluck('external_id')->all());
        $this->assertSame($giro->id, session('filament-accounting.bank_transactions_account_id'));
        $this->assertSame(1, $page->selectedAccountSummary()['pending_count']);
        $this->assertSame('1.239,43 €', $page->selectedAccountSummary()['pending_amount']);
    }

    #[Test]
    public function it_auto_selects_the_only_active_account(): void
    {
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $active = $this->makeBankAccount($entity);
        AccountingBankAccount::query()->create([
            'legal_entity_id' => $entity->getKey(),
            'display_name' => 'Inactive',
            'iban' => 'DE02120300000000202051',
            'currency' => 'EUR',
            'ledger_account_id' => $active->ledger_account_id,
            'driver_key' => 'synthetic',
            'external_account_id' => 'inactive',
            'is_active' => false,
        ]);

        $page = app(ListBankStatementLines::class);
        (new ReflectionMethod(ListBankStatementLines::class, 'resolveAccountId'))->invoke($page);

        $this->assertSame($active->id, $page->accountId);
        $this->assertTrue($page->hasSelectedAccount());
        $this->assertSame([$active->id], $page->selectableAccounts()->pluck('id')->all());
    }

    #[Test]
    public function it_groups_all_pending_lines_first_and_then_booked_lines_by_date(): void
    {
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $bank = $this->makeBankAccount($entity);

        app(ImportBankStatementLines::class)->handle($bank, [
            new BankStatementLineData(
                externalId: 'pending-old',
                amountMinor: -100,
                currency: 'EUR',
                driverKey: 'synthetic',
                sourceAccountExternalId: 'acc-1',
                bookingDate: '2026-03-01',
                sourceStatus: 'pending',
            ),
            new BankStatementLineData(
                externalId: 'booked-new',
                amountMinor: 200,
                currency: 'EUR',
                driverKey: 'synthetic',
                sourceAccountExternalId: 'acc-1',
                bookingDate: '2026-03-20',
                sourceStatus: 'booked',
            ),
            new BankStatementLineData(
                externalId: 'pending-new',
                amountMinor: -300,
                currency: 'EUR',
                driverKey: 'synthetic',
                sourceAccountExternalId: 'acc-1',
                bookingDate: '2026-03-15',
                sourceStatus: 'pending',
            ),
            new BankStatementLineData(
                externalId: 'booked-old',
                amountMinor: 400,
                currency: 'EUR',
                driverKey: 'synthetic',
                sourceAccountExternalId: 'acc-1',
                bookingDate: '2026-03-10',
                sourceStatus: 'booked',
            ),
        ]);

        $records = BankStatementLine::query()->get()->keyBy('external_id');
        $group = BankStatementLineResource::table(Table::make(new ListBankStatementLines))->getDefaultGroup();

        $this->assertNotNull($group);
        $this->assertSame('__pending__', $group->getStringKey($records->get('pending-old')));
        $this->assertSame('__pending__', $group->getStringKey($records->get('pending-new')));
        $this->assertSame('2026-03-20', $group->getStringKey($records->get('booked-new')));
        $this->assertSame('2026-03-10', $group->getStringKey($records->get('booked-old')));

        $ordered = $group->orderQuery(BankStatementLine::query(), 'asc')
            ->pluck('external_id')
            ->all();

        $this->assertSame([
            'pending-new',
            'pending-old',
            'booked-new',
            'booked-old',
        ], $ordered);
    }

    #[Test]
    public function an_unassigned_transaction_has_one_reconciliation_entry_point(): void
    {
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $bank = $this->makeBankAccount($entity);

        app(ImportBankStatementLines::class)->handle($bank, [
            new BankStatementLineData(
                externalId: 'single-reconciliation-entry-point',
                amountMinor: 1190,
                currency: 'EUR',
                driverKey: 'synthetic',
                sourceAccountExternalId: 'acc-1',
                bookingDate: '2026-03-10',
                sourceStatus: 'booked',
            ),
        ]);

        $line = BankStatementLine::query()
            ->where('external_id', 'single-reconciliation-entry-point')
            ->firstOrFail();

        filament()->setCurrentPanel(filament()->getPanel('admin'));

        $table = BankStatementLineResource::table(Table::make(new ListBankStatementLines));
        $actions = collect($table->getRecordActions())->keyBy(
            fn (Action $action): string => $action->getName(),
        );

        $this->assertSame(['reconcile', 'viewAssignment'], $actions->keys()->all());

        /** @var Action $reconcile */
        $reconcile = $actions->get('reconcile');
        $reconcile->record($line);

        $this->assertTrue($reconcile->isVisible());
        $this->assertSame(__('filament-accounting::actions.reconcile'), $reconcile->getLabel());
        $this->assertNull($reconcile->getUrl());
        $this->assertTrue($reconcile->shouldOpenModal());
        $this->assertSame(Width::ScreenTwoExtraLarge, $reconcile->getModalWidth());

        $content = $reconcile->getModalContent();
        $this->assertNotNull($content);
        $this->assertSame($line->uuid, $content->getData()['line']);
        $this->assertSame(
            ReconciliationPage::getUrl(['line' => $line->uuid]),
            $content->getData()['fallbackUrl'],
        );

        $this->get(ReconciliationPage::getUrl(['line' => $line->uuid]))
            ->assertOk()
            ->assertSeeLivewire('filament-accounting.reconciliation-assistant');
    }
}
