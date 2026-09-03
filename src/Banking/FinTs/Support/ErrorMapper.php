<?php

namespace FilamentAccounting\Banking\FinTs\Support;

use Fhp\CurlException;
use Fhp\Protocol\ServerException;
use Fhp\UnsupportedException;
use FilamentAccounting\Banking\FinTs\Exceptions\AmbiguousSubmissionException;
use FilamentAccounting\Banking\FinTs\Exceptions\AuthenticationException;
use FilamentAccounting\Banking\FinTs\Exceptions\BankRejectedException;
use FilamentAccounting\Banking\FinTs\Exceptions\FinTsException;
use FilamentAccounting\Banking\FinTs\Exceptions\FintsValidationException;
use FilamentAccounting\Banking\FinTs\Exceptions\NetworkException;
use FilamentAccounting\Banking\FinTs\Exceptions\RetryableException;
use FilamentAccounting\Banking\FinTs\Exceptions\ScaExpiredException;
use FilamentAccounting\Banking\FinTs\Exceptions\UnsupportedCapabilityException;

final class ErrorMapper
{
    public static function map(\Throwable $e): FinTsException
    {
        if ($e instanceof FinTsException) {
            return $e;
        }

        if ($e instanceof \InvalidArgumentException || $e instanceof \RuntimeException) {
            $message = $e->getMessage();

            // This is an expected local guard, not an unknown bank failure. Keep
            // its explicit message so callers can distinguish "wait" from error.
            if (str_contains($message, 'Polling is not yet permitted by the bank')) {
                return new FintsValidationException($message, 0, $e);
            }

            if (str_contains($message, 'selectTanMode()')) {
                return new FintsValidationException(__('filament-accounting::banking/fints/errors.tan_mode_required'), 0, $e);
            }

            if (str_contains($message, 'tanMedium is mandatory')) {
                return new FintsValidationException(__('filament-accounting::banking/fints/errors.tan_medium_required'), 0, $e);
            }

            if (str_contains($message, 'tanMedium not allowed')) {
                return new FintsValidationException(__('filament-accounting::banking/fints/errors.tan_medium_not_allowed'), 0, $e);
            }

            if (str_contains($message, 'Unknown TAN mode')) {
                return new FintsValidationException(__('filament-accounting::banking/fints/errors.tan_mode_unknown'), 0, $e);
            }

            if (str_contains($message, 'Need to login')) {
                return new FintsValidationException(__('filament-accounting::banking/fints/errors.login_required'), 0, $e);
            }
        }

        if ($e instanceof CurlException) {
            $message = $e->getMessage();

            if (str_contains(strtolower($message), 'timeout') || str_contains(strtolower($message), 'timed out')) {
                return new AmbiguousSubmissionException(__('filament-accounting::banking/fints/errors.ambiguous'), 0, $e);
            }

            return new NetworkException(__('filament-accounting::banking/fints/errors.network'), 0, $e);
        }

        if ($e instanceof UnsupportedException) {
            return new UnsupportedCapabilityException($e->getMessage(), 0, $e);
        }

        if ($e instanceof ServerException) {
            $safe = self::sanitize($e->getMessage());
            $lower = strtolower($safe);

            if (str_contains($lower, 'dialog-id')
                || str_contains($lower, 'dialog abgebrochen')
                || str_contains($lower, 'bankreferenz')
                || str_contains($lower, 'nicht gültig')
                || str_contains($lower, 'nicht gueltig')
                || str_contains($lower, 'nicht angemeldet')
                || str_contains($lower, 'erneut anmelden')
                || str_contains($lower, 'wieder anmelden')) {
                return new ScaExpiredException(__('filament-accounting::banking/fints/errors.sca_dialog_expired'), 0, $e);
            }

            if (str_contains($lower, 'pin') || str_contains($lower, 'kennwort')) {
                return new AuthenticationException(__('filament-accounting::banking/fints/errors.authentication'), 0, $e);
            }

            if (str_contains($lower, 'temporarily') || str_contains($lower, 'try later') || str_contains($lower, '9030')) {
                return new RetryableException($safe, 0, $e);
            }

            return new BankRejectedException($safe, $safe, null, 0, $e);
        }

        return new FinTsException(__('filament-accounting::banking/fints/errors.generic'), 0, $e);
    }

    public static function sanitize(string $message): string
    {
        $message = preg_replace('/\b\d{5,8}\b/', '****', $message) ?? $message;
        $message = preg_replace('/PIN[:\s]+\S+/i', 'PIN ****', $message) ?? $message;
        $message = preg_replace('/TAN[:\s]+\S+/i', 'TAN ****', $message) ?? $message;

        return $message;
    }
}
