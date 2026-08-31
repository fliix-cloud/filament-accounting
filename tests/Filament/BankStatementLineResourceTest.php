<?php

namespace FilamentAccounting\Tests\Filament;

use Filament\Actions\Action;
use Filament\Support\Enums\Width;
use Filament\Tables\Table;
use FilamentAccounting\Banking\Data\BankStatementLineData;
use FilamentAccounting\Filament\Pages\ReconciliationPage;
use FilamentAccounting\Filament\Resources\BankStatementLineResource;
use FilamentAccounting\Filament\Resources\BankStatementLineResource\Pages\ListBankStatementLines;
use FilamentAccounting\Models\BankStatementLine;
use FilamentAccounting\Services\ImportBankStatementLines;
use FilamentAccounting\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class BankStatementLineResourceTest extends TestCase
{
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

        $this->assertSame(['reconcile', 'viewAssignment', 'openSource'], $actions->keys()->all());

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
    }
}
