<?php

namespace FilamentAccounting\Banking\FinTs\Support;

final class SepaIdentifier
{
    public static function normalize(string $value): string
    {
        return strtoupper(preg_replace('/\s+/', '', trim($value)) ?? '');
    }

    public static function isValidCreditorIdentifier(string $value): bool
    {
        $identifier = self::normalize($value);

        if (strlen($identifier) < 8 || strlen($identifier) > 35) {
            return false;
        }

        if (preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{3}[A-Z0-9]+$/', $identifier) !== 1) {
            return false;
        }

        if (str_starts_with($identifier, 'DE') && strlen($identifier) !== 18) {
            return false;
        }

        // The creditor business code (positions 5-7) is excluded from the checksum.
        $checkValue = substr($identifier, 7).substr($identifier, 0, 4);
        $numeric = '';
        foreach (str_split($checkValue) as $character) {
            $numeric .= ctype_alpha($character)
                ? (string) (ord($character) - 55)
                : $character;
        }

        $remainder = 0;
        foreach (str_split($numeric) as $digit) {
            $remainder = ($remainder * 10 + (int) $digit) % 97;
        }

        return $remainder === 1;
    }

    public static function isValidMandateReference(string $value): bool
    {
        $reference = trim($value);

        if ($reference === '' || mb_strlen($reference) > 35) {
            return false;
        }

        if (str_starts_with($reference, '/') || str_ends_with($reference, '/') || str_contains($reference, '//')) {
            return false;
        }

        return preg_match("/^[A-Za-z0-9\/\-\?:\(\)\.,'\+ ]+$/u", $reference) === 1;
    }

    public static function externalId(?string $value = null): string
    {
        $candidate = self::normalize((string) $value);
        $candidate = preg_replace('/[^A-Z0-9]/', '', $candidate) ?? '';

        if ($candidate !== '') {
            return substr($candidate, 0, 35);
        }

        return strtoupper(bin2hex(random_bytes(16)));
    }
}
