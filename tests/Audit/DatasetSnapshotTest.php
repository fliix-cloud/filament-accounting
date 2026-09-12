<?php

namespace FilamentAccounting\Tests\Audit;

use FilamentAccounting\Exceptions\AuditEvidenceException;
use FilamentAccounting\Export\DatasetSnapshot;
use Illuminate\Database\Connection;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class DatasetSnapshotTest extends TestCase
{
    #[Test]
    public function unsupported_connections_are_rejected_before_any_export_side_effect(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $connection = new Connection($pdo, config: ['driver' => 'unsupported']);
        $called = false;
        try {
            (new DatasetSnapshot)->run($connection, function () use (&$called): void {
                $called = true;
            });
            $this->fail('An unsupported snapshot connection must be rejected.');
        } catch (AuditEvidenceException $exception) {
            $this->assertStringContainsString('currently support', $exception->getMessage());
        }
        $this->assertFalse($called);
        $this->assertFalse($pdo->inTransaction());
    }
}
