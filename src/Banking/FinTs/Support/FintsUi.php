<?php

namespace FilamentAccounting\Banking\FinTs\Support;

use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use FilamentAccounting\Banking\FinTs\Exceptions\AuthenticationException;
use FilamentAccounting\Banking\FinTs\Exceptions\BankRejectedException;
use FilamentAccounting\Banking\FinTs\Exceptions\FinTsException;
use FilamentAccounting\Banking\FinTs\Exceptions\UnsupportedCapabilityException;

final class FintsUi
{
    /**
     * Run a live-bank action and convert domain failures into Filament notifications.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function run(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (Halt $e) {
            throw $e;
        } catch (\Throwable $e) {
            self::notifyFailure($e);

            throw new Halt;
        }
    }

    public static function notifyFailure(\Throwable $e): void
    {
        $mapped = $e instanceof FinTsException ? $e : ErrorMapper::map($e);

        $notification = Notification::make()
            ->title($mapped->userMessage())
            ->danger()
            ->persistent();

        if ($mapped instanceof BankRejectedException && filled($mapped->bankMessage) && $mapped->bankMessage !== $mapped->userMessage()) {
            $notification->body($mapped->bankMessage);
        } elseif ($mapped->getPrevious() && (
            $mapped->userMessage() === __('filament-accounting::banking/fints/errors.generic')
            || $mapped instanceof UnsupportedCapabilityException
            || $mapped instanceof AuthenticationException
        )) {
            $previous = ErrorMapper::sanitize($mapped->getPrevious()->getMessage());
            if ($previous !== '' && $previous !== $mapped->userMessage()) {
                $notification->body($previous);
            }
        }

        $notification->send();
    }
}
