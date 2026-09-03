<?php

namespace FilamentAccounting\Banking\FinTs\Support;

use Fhp\Model\SEPAAccount;

final class AccountFingerprint
{
    public static function for(SEPAAccount $account): string
    {
        $iban = Iban::normalize((string) $account->getIban());

        if ($iban !== '') {
            return hash('sha256', 'iban:'.$iban);
        }

        return hash('sha256', implode('|', [
            'account',
            (string) $account->getBlz(),
            (string) $account->getAccountNumber(),
            (string) $account->getSubAccount(),
        ]));
    }
}
