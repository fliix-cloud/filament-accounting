<?php

namespace FilamentAccounting\Tests;

use PHPUnit\Framework\Attributes\Test;

class LocalizationTest extends TestCase
{
    #[Test]
    public function critical_keys_exist_in_english_and_german(): void
    {
        $keys = [
            'filament-accounting::navigation.group',
            'filament-accounting::navigation.sections.banking',
            'filament-accounting::navigation.sections.reports',
            'filament-accounting::navigation.sections.master_data',
            'filament-accounting::navigation.sections.settings',
            'filament-accounting::navigation.bank_settings',
            'filament-accounting::navigation.sales_invoices',
            'filament-accounting::navigation.bank_transactions',
            'filament-accounting::fields.amount',
            'filament-accounting::statuses.payment.unpaid',
            'filament-accounting::actions.finalize',
            'filament-accounting::actions.assign_and_post',
            'filament-accounting::actions.split_transaction',
            'filament-accounting::actions.add_bank_account',
            'filament-accounting::fields.bank_account_enabled',
            'filament-accounting::fields.tax_suggestion',
            'filament-accounting::fields.amount_mismatch_confirm',
            'filament-accounting::statuses.amount_match.mismatch',
            'filament-accounting::errors.unbalanced_journal',
            'filament-accounting::errors.split_requires_multiple_allocations',
            'filament-accounting::notifications.reconciliation_finalized',
            'filament-accounting::validation.splits_must_balance',
        ];

        foreach ($keys as $key) {
            $english = __($key, [], 'en');
            $german = __($key, [], 'de');
            $this->assertNotSame($key, $english, "Missing English translation for {$key}");
            $this->assertNotSame($key, $german, "Missing German translation for {$key}");
            $this->assertNotSame('', $english);
            $this->assertNotSame('', $german);
        }

        $this->assertSame('Accounting', __('filament-accounting::navigation.group', [], 'en'));
        $this->assertSame('Buchhaltung', __('filament-accounting::navigation.group', [], 'de'));
    }
}
