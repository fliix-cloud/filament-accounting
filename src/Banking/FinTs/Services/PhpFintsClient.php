<?php

namespace FilamentAccounting\Banking\FinTs\Services;

use Fhp\BaseAction;
use Fhp\FinTs;
use Fhp\Model\NoPsd2TanMode;
use Fhp\Model\TanMedium;
use Fhp\Model\TanMode;
use Fhp\Segment\SPA\HISPAS;
use FilamentAccounting\Banking\FinTs\Contracts\FintsClient;
use FilamentAccounting\Banking\FinTs\Data\PersistedFintsState;
use FilamentAccounting\Banking\FinTs\Support\ErrorMapper;

final class PhpFintsClient implements FintsClient
{
    public function __construct(
        private readonly FinTs $fints,
    ) {}

    public function login(): BaseAction
    {
        try {
            $this->fints->forgetDialog();

            return $this->fints->login();
        } catch (\Throwable $e) {
            throw ErrorMapper::map($e);
        }
    }

    public function execute(BaseAction $action): void
    {
        try {
            $this->fints->execute($action);
        } catch (\Throwable $e) {
            throw ErrorMapper::map($e);
        }
    }

    public function submitTan(BaseAction $action, string $tan): void
    {
        try {
            $this->fints->submitTan($action, $tan);
        } catch (\Throwable $e) {
            throw ErrorMapper::map($e);
        }
    }

    public function checkDecoupledSubmission(BaseAction $action): bool
    {
        try {
            return $this->fints->checkDecoupledSubmission($action);
        } catch (\Throwable $e) {
            throw ErrorMapper::map($e);
        }
    }

    public function pollAction(BaseAction $action): void
    {
        try {
            $this->fints->pollAction($action);
        } catch (\Throwable $e) {
            throw ErrorMapper::map($e);
        }
    }

    public function confirmVop(BaseAction $action): void
    {
        try {
            $this->fints->confirmVop($action);
        } catch (\Throwable $e) {
            throw ErrorMapper::map($e);
        }
    }

    public function getTanModes(): array
    {
        try {
            return $this->fints->getTanModes();
        } catch (\Throwable $e) {
            throw ErrorMapper::map($e);
        }
    }

    public function getTanMedia(TanMode|int $tanMode): array
    {
        try {
            return $this->fints->getTanMedia($tanMode);
        } catch (\Throwable $e) {
            throw ErrorMapper::map($e);
        }
    }

    public function selectTanMode(TanMode|int $tanMode, TanMedium|string|null $tanMedium = null): void
    {
        try {
            if ($tanMode instanceof NoPsd2TanMode) {
                $this->fints->selectTanMode($tanMode);

                return;
            }

            $medium = $tanMedium instanceof TanMedium ? $tanMedium->getName() : $tanMedium;
            $this->fints->selectTanMode($tanMode, $medium);
        } catch (\Throwable $e) {
            throw ErrorMapper::map($e);
        }
    }

    public function supportedParameterSegments(): array
    {
        try {
            $bpd = $this->fints->getBpd();
            $supported = [];

            foreach (array_keys($bpd->parameters) as $type) {
                $versions = [];
                foreach ($bpd->parameters[(string) $type] ?? [] as $segment) {
                    $versions[] = (int) $segment->getVersion();
                }

                if ($versions === []) {
                    continue;
                }

                $versions = array_values(array_unique($versions));
                rsort($versions);
                $supported[(string) $type] = $versions;
            }

            ksort($supported);

            return $supported;
        } catch (\Throwable $e) {
            throw ErrorMapper::map($e);
        }
    }

    public function advertisedRequestTypes(): array
    {
        try {
            return $this->fints->getBpd()->tanRequired;
        } catch (\Throwable $e) {
            throw ErrorMapper::map($e);
        }
    }

    public function supportedSepaPainSchemas(): array
    {
        try {
            $hispas = $this->fints->getBpd()->getLatestSupportedParameters('HISPAS');
            if (! $hispas instanceof HISPAS) {
                return [];
            }

            return array_values($hispas->getParameter()->getUnterstuetzteSEPADatenformate());
        } catch (\Throwable $e) {
            throw ErrorMapper::map($e);
        }
    }

    public function persist(bool $minimal = false): string
    {
        return $this->fints->persist($minimal);
    }

    public function close(): void
    {
        try {
            $this->fints->close();
        } catch (\Throwable) {
            // Closing is best-effort.
        }
    }

    public function forgetDialog(): void
    {
        $this->fints->forgetDialog();
    }

    public function hasOpenDialog(): bool
    {
        $data = @unserialize($this->fints->persist(), ['allowed_classes' => true]);

        return is_array($data) && count($data) >= 8 && ($data[7] ?? null) !== null && $data[7] !== '';
    }

    public function snapshot(): PersistedFintsState
    {
        return new PersistedFintsState($this->persist());
    }

    public function unwrap(): FinTs
    {
        return $this->fints;
    }

    public function isDecoupledSelected(): bool
    {
        try {
            $mode = $this->fints->getSelectedTanMode();

            return $mode !== null && $mode->isDecoupled();
        } catch (\Throwable) {
            return false;
        }
    }
}
