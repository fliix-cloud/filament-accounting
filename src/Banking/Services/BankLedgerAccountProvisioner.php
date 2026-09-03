<?php

namespace FilamentAccounting\Banking\Services;

use FilamentAccounting\Enums\AccountType;
use FilamentAccounting\Enums\NormalBalance;
use FilamentAccounting\Models\LedgerAccount;
use FilamentAccounting\Models\LegalEntity;
use FilamentAccounting\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class BankLedgerAccountProvisioner
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function provision(
        LegalEntity $entity,
        string $stableIdentity,
        string $displayName,
        string $currency,
    ): LedgerAccount {
        return DB::transaction(function () use ($entity, $stableIdentity, $displayName, $currency): LedgerAccount {
            $identity = substr(hash('sha256', $stableIdentity), 0, 12);
            $name = 'Bank · '.$displayName.' · '.$identity;

            $existing = LedgerAccount::query()
                ->where('legal_entity_id', $entity->getKey())
                ->where('name', $name)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof LedgerAccount) {
                return $existing;
            }

            $used = LedgerAccount::query()
                ->where('legal_entity_id', $entity->getKey())
                ->whereBetween('code', ['1201', '1299'])
                ->lockForUpdate()
                ->pluck('code')
                ->all();
            $used = array_fill_keys(array_map('strval', $used), true);
            $offset = ((int) sprintf('%u', crc32($stableIdentity))) % 99;

            for ($probe = 0; $probe < 99; $probe++) {
                $code = (string) (1201 + (($offset + $probe) % 99));
                if (isset($used[$code])) {
                    continue;
                }

                $account = LedgerAccount::query()->create([
                    'legal_entity_id' => $entity->getKey(),
                    'code' => $code,
                    'name' => $name,
                    'type' => AccountType::Asset,
                    'normal_balance' => NormalBalance::Debit,
                    'currency' => strtoupper($currency),
                    'is_active' => true,
                ]);

                $this->audit->log($entity, 'bank.ledger-account-provisioned', $account, [
                    'code' => $code,
                    'source_identity_hash' => hash('sha256', $stableIdentity),
                ]);

                return $account;
            }

            throw new RuntimeException('No free bank ledger account code is available in range 1201-1299.');
        });
    }
}
