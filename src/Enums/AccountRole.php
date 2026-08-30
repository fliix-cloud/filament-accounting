<?php

namespace FilamentAccounting\Enums;

enum AccountRole: string
{
    case Bank = 'bank';
    case Receivable = 'receivable';
    case Payable = 'payable';
    case OutputTax = 'output_tax';
    case InputTax = 'input_tax';
    case Revenue = 'revenue';
    case Expense = 'expense';
    case PersonnelExpense = 'personnel_expense';
    case Rounding = 'rounding';
    case ExchangeGain = 'exchange_gain';
    case ExchangeLoss = 'exchange_loss';
    case Suspense = 'suspense';
    case PrivateDrawings = 'private_drawings';
    case PrivateDeposits = 'private_deposits';
}
