<?php

namespace FilamentAccounting\Export;

use Closure;
use FilamentAccounting\Exceptions\AuditEvidenceException;
use Illuminate\Database\Connection;

/** One database read view for the complete dataset, independent of host isolation. */
final class DatasetSnapshot
{
    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function run(Connection $connection, Closure $callback): mixed
    {
        if ($connection->transactionLevel() !== 0) {
            throw new AuditEvidenceException('Dataset export requires an independent accounting transaction.');
        }

        $driver = $connection->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            // Applies only to the next transaction; leave the host session default intact.
            // Do not retry internally: a stream callback has non-transactional side effects.
            $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        } elseif ($driver !== 'sqlite') {
            throw new AuditEvidenceException('Dataset snapshots currently support MySQL/MariaDB with InnoDB, or SQLite.');
        }

        return $connection->transaction($callback, attempts: 1);
    }
}
