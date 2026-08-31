<?php

namespace FilamentAccounting\Support;

final class Sepa
{
    public static function normalizeIban(string $iban): string
    {
        return strtoupper(preg_replace('/\s+/', '', $iban) ?? '');
    }

    public static function isValidIban(string $iban): bool
    {
        $iban = self::normalizeIban($iban);

        if (preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{10,30}$/', $iban) !== 1) {
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

        return preg_match('/^[A-Z]{4}[A-Z]{2}[A-Z0-9]{2}([A-Z0-9]{3})?$/', strtoupper(trim($bic))) === 1;
    }

    public static function normalizeMandateReference(?string $value): ?string
    {
        $reference = trim((string) $value);

        return $reference === '' ? null : strtoupper($reference);
    }

    public static function isValidMandateReference(?string $value): bool
    {
        if ($value === null || trim($value) === '') {
            return true;
        }

        $reference = trim($value);

        if (mb_strlen($reference) > 35) {
            return false;
        }

        if (str_starts_with($reference, '/') || str_ends_with($reference, '/') || str_contains($reference, '//')) {
            return false;
        }

        return preg_match("/^[A-Za-z0-9\/\-\?:\(\)\.,'\+ ]+$/u", $reference) === 1;
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
