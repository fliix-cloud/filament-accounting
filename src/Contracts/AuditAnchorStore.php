<?php

namespace FilamentAccounting\Contracts;

use FilamentAccounting\Audit\AuditAnchor;

interface AuditAnchorStore
{
    /**
     * @return list<AuditAnchor>
     */
    public function all(string $legalEntityUuid): array;

    public function putOnce(AuditAnchor $anchor): void;
}
