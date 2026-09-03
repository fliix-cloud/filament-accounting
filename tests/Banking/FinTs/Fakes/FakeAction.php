<?php

namespace FilamentAccounting\Tests\Banking\FinTs\Fakes;

use Fhp\BaseAction;
use Fhp\Protocol\BPD;
use Fhp\Protocol\UPD;

final class FakeAction extends BaseAction
{
    public function markDone(?string $message = 'OK'): void
    {
        $this->isDone = true;
        $this->tanRequest = null;
        $this->pollingInfo = null;
        $this->vopConfirmationRequest = null;
        $this->successMessage = $message;
    }

    public function resetInteractive(): void
    {
        $this->isDone = false;
        $this->tanRequest = null;
        $this->pollingInfo = null;
        $this->vopConfirmationRequest = null;
        $this->successMessage = null;
    }

    protected function createRequest(BPD $bpd, ?UPD $upd)
    {
        return [];
    }
}
