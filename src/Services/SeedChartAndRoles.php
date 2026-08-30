<?php

namespace FilamentAccounting\Services;

use FilamentAccounting\Enums\AccountRole;
use FilamentAccounting\Enums\AccountType;
use FilamentAccounting\Enums\NormalBalance;
use FilamentAccounting\Models\AccountRoleAssignment;
use FilamentAccounting\Models\LedgerAccount;
use FilamentAccounting\Models\LegalEntity;

final class SeedChartAndRoles
{
    /**
     * @var array<string, array{code: string, name: string, type: AccountType, normal: NormalBalance, role: AccountRole}>
     */
    private const ACCOUNTS = [
        'bank' => ['code' => '1200', 'name' => 'Bank', 'type' => AccountType::Asset, 'normal' => NormalBalance::Debit, 'role' => AccountRole::Bank],
        'receivable' => ['code' => '1400', 'name' => 'Trade receivables', 'type' => AccountType::Asset, 'normal' => NormalBalance::Debit, 'role' => AccountRole::Receivable],
        'input_tax' => ['code' => '1576', 'name' => 'Input tax', 'type' => AccountType::Asset, 'normal' => NormalBalance::Debit, 'role' => AccountRole::InputTax],
        'payable' => ['code' => '1600', 'name' => 'Trade payables', 'type' => AccountType::Liability, 'normal' => NormalBalance::Credit, 'role' => AccountRole::Payable],
        'output_tax' => ['code' => '1776', 'name' => 'Output tax', 'type' => AccountType::Liability, 'normal' => NormalBalance::Credit, 'role' => AccountRole::OutputTax],
        'revenue' => ['code' => '8400', 'name' => 'Revenue', 'type' => AccountType::Revenue, 'normal' => NormalBalance::Credit, 'role' => AccountRole::Revenue],
        'expense' => ['code' => '4900', 'name' => 'Operating expenses', 'type' => AccountType::Expense, 'normal' => NormalBalance::Debit, 'role' => AccountRole::Expense],
        'personnel' => ['code' => '4100', 'name' => 'Personnel expenses', 'type' => AccountType::Expense, 'normal' => NormalBalance::Debit, 'role' => AccountRole::PersonnelExpense],
        'rounding' => ['code' => '2150', 'name' => 'Rounding', 'type' => AccountType::Expense, 'normal' => NormalBalance::Debit, 'role' => AccountRole::Rounding],
        'fx_gain' => ['code' => '2660', 'name' => 'Exchange gain', 'type' => AccountType::Revenue, 'normal' => NormalBalance::Credit, 'role' => AccountRole::ExchangeGain],
        'fx_loss' => ['code' => '2154', 'name' => 'Exchange loss', 'type' => AccountType::Expense, 'normal' => NormalBalance::Debit, 'role' => AccountRole::ExchangeLoss],
        'suspense' => ['code' => '1360', 'name' => 'Suspense', 'type' => AccountType::Asset, 'normal' => NormalBalance::Debit, 'role' => AccountRole::Suspense],
        'drawings' => ['code' => '1800', 'name' => 'Private drawings', 'type' => AccountType::Equity, 'normal' => NormalBalance::Debit, 'role' => AccountRole::PrivateDrawings],
        'deposits' => ['code' => '1890', 'name' => 'Private deposits', 'type' => AccountType::Equity, 'normal' => NormalBalance::Credit, 'role' => AccountRole::PrivateDeposits],
    ];

    public function handle(LegalEntity $entity): void
    {
        foreach (self::ACCOUNTS as $definition) {
            $account = LedgerAccount::query()->firstOrCreate(
                [
                    'legal_entity_id' => $entity->getKey(),
                    'code' => $definition['code'],
                ],
                [
                    'name' => $definition['name'],
                    'type' => $definition['type'],
                    'normal_balance' => $definition['normal'],
                    'currency' => $entity->base_currency,
                    'is_active' => true,
                ]
            );

            AccountRoleAssignment::query()->updateOrCreate(
                [
                    'legal_entity_id' => $entity->getKey(),
                    'role' => $definition['role']->value,
                ],
                [
                    'ledger_account_id' => $account->getKey(),
                ]
            );
        }
    }
}
