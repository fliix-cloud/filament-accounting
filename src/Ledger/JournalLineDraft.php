<?php

namespace FilamentAccounting\Ledger;

final readonly class JournalLineDraft
{
    public function __construct(
        public int $ledgerAccountId,
        public int $debitMinor,
        public int $creditMinor,
        public string $currency,
        public int $baseDebitMinor,
        public int $baseCreditMinor,
        public ?string $description = null,
        public ?string $taxCode = null,
        public ?int $taxRuleVersionId = null,
    ) {}

    public static function debit(int $ledgerAccountId, int $minor, string $currency, ?string $description = null, ?string $taxCode = null, ?int $taxRuleVersionId = null): self
    {
        return new self($ledgerAccountId, $minor, 0, $currency, $minor, 0, $description, $taxCode, $taxRuleVersionId);
    }

    public static function credit(int $ledgerAccountId, int $minor, string $currency, ?string $description = null, ?string $taxCode = null, ?int $taxRuleVersionId = null): self
    {
        return new self($ledgerAccountId, 0, $minor, $currency, 0, $minor, $description, $taxCode, $taxRuleVersionId);
    }
}
