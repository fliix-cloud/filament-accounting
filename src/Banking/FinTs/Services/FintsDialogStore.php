<?php

namespace FilamentAccounting\Banking\FinTs\Services;

use FilamentAccounting\Banking\FinTs\Contracts\FintsClient;
use FilamentAccounting\Banking\FinTs\Models\BankConnection;

final class FintsDialogStore
{
    public function restore(BankConnection $connection): ?string
    {
        $encoded = $connection->encrypted_fints_state;
        if (! is_string($encoded) || $encoded === '') {
            return null;
        }

        $decoded = base64_decode($encoded, true);

        return $decoded === false ? null : $decoded;
    }

    public function remember(BankConnection $connection, FintsClient $client): void
    {
        try {
            $connection->encrypted_fints_state = base64_encode($client->persist(false));
            $connection->fints_state_saved_at = now();
            $connection->save();
        } catch (\Throwable) {
            // A failed persist must not hide a successful bank operation.
        }
    }

    public function forget(BankConnection $connection): void
    {
        $connection->encrypted_fints_state = null;
        $connection->fints_state_saved_at = null;
        $connection->save();
    }
}
