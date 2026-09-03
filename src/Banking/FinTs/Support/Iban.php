<?php

namespace FilamentAccounting\Banking\FinTs\Support;

final class Iban
{
    public static function normalize(string $iban): string
    {
        return strtoupper(preg_replace('/\s+/', '', $iban) ?? '');
    }

    public static function isValid(string $iban): bool
    {
        $iban = self::normalize($iban);

        if (! preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{10,30}$/', $iban)) {
            return false;
        }

        $rearranged = substr($iban, 4).substr($iban, 0, 4);
        $numeric = '';

        foreach (str_split($rearranged) as $char) {
            $numeric .= ctype_alpha($char) ? (string) (ord($char) - 55) : $char;
        }

        return self::mod97($numeric) === 1;
    }

    public static function isValidBic(?string $bic): bool
    {
        if ($bic === null || $bic === '') {
            return true;
        }

        return (bool) preg_match('/^[A-Z]{4}[A-Z]{2}[A-Z0-9]{2}([A-Z0-9]{3})?$/', strtoupper($bic));
    }

    private static function mod97(string $numeric): int
    {
        $remainder = 0;

        foreach (str_split($numeric, 7) as $chunk) {
            $remainder = (int) (((string) $remainder.$chunk)) % 97;
        }

        return $remainder;
    }
}
