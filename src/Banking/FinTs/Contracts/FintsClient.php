<?php

namespace FilamentAccounting\Banking\FinTs\Contracts;

use Fhp\BaseAction;
use Fhp\Model\TanMedium;
use Fhp\Model\TanMode;
use FilamentAccounting\Banking\FinTs\Data\PersistedFintsState;

interface FintsClient
{
    public function login(): BaseAction;

    public function execute(BaseAction $action): void;

    public function submitTan(BaseAction $action, string $tan): void;

    public function checkDecoupledSubmission(BaseAction $action): bool;

    public function pollAction(BaseAction $action): void;

    public function confirmVop(BaseAction $action): void;

    /**
     * @return array<int, TanMode>
     */
    public function getTanModes(): array;

    /**
     * @return array<int, TanMedium>
     */
    public function getTanMedia(TanMode|int $tanMode): array;

    public function selectTanMode(TanMode|int $tanMode, TanMedium|string|null $tanMedium = null): void;

    /**
     * Return the BPD business-transaction parameter segments advertised by the bank.
     * Keys are segment identifiers (for example HICCSS), values are offered versions.
     *
     * @return array<string, list<int>>
     */
    public function supportedParameterSegments(): array;

    /**
     * Request types from HIPINS (for example HKCCS, HKKAZ), mapped to whether a TAN is required.
     *
     * @return array<string, bool>
     */
    public function advertisedRequestTypes(): array;

    /**
     * SEPA PAIN namespaces from HISPAS (for example urn:iso:std:iso:20022:tech:xsd:pain.001.001.03).
     *
     * @return list<string>
     */
    public function supportedSepaPainSchemas(): array;

    public function persist(bool $minimal = false): string;

    public function close(): void;

    public function forgetDialog(): void;

    public function hasOpenDialog(): bool;

    public function snapshot(): PersistedFintsState;

    public function isDecoupledSelected(): bool;
}
