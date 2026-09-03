<?php

namespace FilamentAccounting\Banking\FinTs\Services;

use FilamentAccounting\Banking\FinTs\Contracts\FintsClient;
use FilamentAccounting\Banking\FinTs\Enums\DirectDebitScheme;
use FilamentAccounting\Banking\FinTs\Enums\TransferType;
use FilamentAccounting\Banking\FinTs\Models\BankConnection;

class CapabilityService
{
    public function supportsTransfers(BankConnection $connection): bool
    {
        return $this->flag('transfers') && $this->cachedOrUndiscovered($connection, 'sepa_transfer');
    }

    public function supportsRealtime(BankConnection $connection): bool
    {
        return $this->flag('realtime_transfers') && $this->cachedOrUndiscovered($connection, 'realtime_transfer');
    }

    public function supportsTransferType(BankConnection $connection, ?TransferType $type): bool
    {
        return match ($type) {
            TransferType::Sepa => $this->supportsTransfers($connection),
            TransferType::Realtime => $this->supportsRealtime($connection),
            TransferType::International, null => false,
        };
    }

    public function supportsDirectDebits(BankConnection $connection): bool
    {
        return $this->flag('direct_debits') && $this->cachedOrUndiscovered($connection, 'direct_debit');
    }

    public function supportsDirectDebitScheme(BankConnection $connection, ?DirectDebitScheme $scheme): bool
    {
        if (! $this->flag('direct_debits') || $scheme === null) {
            return false;
        }

        if (! $this->hasDiscovered($connection)) {
            return true;
        }

        return match ($scheme) {
            DirectDebitScheme::Core => $this->cached($connection, 'direct_debit_core'),
            DirectDebitScheme::B2b => $this->cached($connection, 'direct_debit_b2b'),
        };
    }

    public function supportsHoldings(BankConnection $connection): bool
    {
        return $this->flag('holdings') && $this->cached($connection, 'holdings');
    }

    /**
     * Read the bank's BPD instead of assuming that payment operations exist.
     *
     * The capability flags intentionally describe only operations this package
     * can actually send. Batch direct-debit segments are retained as metadata,
     * but do not enable the single-debit UI or service paths.
     *
     * @return array<string, mixed>
     */
    public function discover(BankConnection $connection, FintsClient $client): array
    {
        $segments = $client->supportedParameterSegments();
        $requests = $client->advertisedRequestTypes();

        $directDebitCoreSingle = $this->hasAny($segments, ['HIDSES'])
            || $this->hasRequest($requests, ['HKDSE']);
        $directDebitB2bSingle = $this->hasAny($segments, ['HIBSES'])
            || $this->hasRequest($requests, ['HKBSE']);

        $discovered = [
            'sepa_transfer' => $this->hasAny($segments, ['HICCSS', 'HICSES', 'HICCMS'])
                || $this->hasRequest($requests, ['HKCCS', 'HKCSE', 'HKCCM', 'HKCME']),
            'sepa_transfer_immediate' => $this->hasAny($segments, ['HICCSS', 'HICCMS'])
                || $this->hasRequest($requests, ['HKCCS', 'HKCCM']),
            'sepa_transfer_scheduled' => $this->hasAny($segments, ['HICSES', 'HICMES'])
                || $this->hasRequest($requests, ['HKCSE', 'HKCME']),
            'realtime_transfer' => $this->hasAny($segments, ['HIIPZS'])
                || $this->hasRequest($requests, ['HKIPZ']),

            // DirectDebitService currently sends a single debit, therefore only
            // the single-debit parameter segments enable an executable scheme.
            'direct_debit_core' => $directDebitCoreSingle,
            'direct_debit_b2b' => $directDebitB2bSingle,
            'direct_debit' => $directDebitCoreSingle || $directDebitB2bSingle,
            'direct_debit_core_batch' => $this->hasAny($segments, ['HIDMES']),
            'direct_debit_b2b_batch' => $this->hasAny($segments, ['HIBMES']),

            'holdings' => $this->hasAny($segments, ['HIWPDS']),
            'parameter_segments' => $segments,
            'sepa_pain_schemas' => $client->supportedSepaPainSchemas(),
            'discovered_at' => now()->toIso8601String(),
        ];

        $this->store($connection, $discovered);

        return $discovered;
    }

    /**
     * @param  array<string, mixed>  $capabilities
     */
    public function store(BankConnection $connection, array $capabilities): void
    {
        $connection->capabilities = array_merge($connection->capabilities ?? [], $capabilities);
        $connection->save();
    }

    private function flag(string $feature): bool
    {
        return (bool) config("filament-accounting.banking.fints.features.{$feature}", false);
    }

    private function cached(BankConnection $connection, string $key): bool
    {
        $capabilities = $connection->capabilities ?? [];

        return (bool) ($capabilities[$key] ?? false);
    }

    private function cachedOrUndiscovered(BankConnection $connection, string $key): bool
    {
        if (! $this->hasDiscovered($connection)) {
            return true;
        }

        return $this->cached($connection, $key);
    }

    public function hasDiscovered(BankConnection $connection): bool
    {
        return filled(($connection->capabilities ?? [])['discovered_at'] ?? null);
    }

    /**
     * @return list<string>
     */
    public function sepaPainSchemas(BankConnection $connection, FintsClient $client): array
    {
        try {
            $schemas = $client->supportedSepaPainSchemas();
        } catch (\Throwable) {
            $schemas = [];
        }

        if ($schemas !== []) {
            $this->store($connection, ['sepa_pain_schemas' => $schemas]);

            return $schemas;
        }

        $stored = $connection->capabilities['sepa_pain_schemas'] ?? [];

        return is_array($stored) ? array_values(array_filter($stored, 'is_string')) : [];
    }

    /**
     * @param  array<string, list<int>>  $segments
     * @param  list<string>  $types
     */
    private function hasAny(array $segments, array $types): bool
    {
        foreach ($types as $type) {
            if (($segments[$type] ?? []) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, bool>  $requests
     * @param  list<string>  $types
     */
    private function hasRequest(array $requests, array $types): bool
    {
        foreach ($types as $type) {
            if (array_key_exists($type, $requests)) {
                return true;
            }
        }

        return false;
    }
}
