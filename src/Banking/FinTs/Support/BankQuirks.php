<?php

namespace FilamentAccounting\Banking\FinTs\Support;

/**
 * Isolates known upstream bank workarounds so UI/services do not scatter BLZ checks.
 */
final class BankQuirks
{
    public static function normalizeBankCode(string $bankCode, string $url): string
    {
        $bankCode = trim($bankCode);
        $url = trim($url);

        if ($url === 'https://hbci-01.hypovereinsbank.de/bank/hbci' && $bankCode === '71120078') {
            return '70020270';
        }

        return $bankCode;
    }

    public static function isIngDiba(string $bankCode): bool
    {
        return trim($bankCode) === '50010517';
    }
}
