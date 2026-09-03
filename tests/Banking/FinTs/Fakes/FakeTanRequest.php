<?php

namespace FilamentAccounting\Tests\Banking\FinTs\Fakes;

use Fhp\Model\TanRequest;
use Fhp\Syntax\Bin;

final class FakeTanRequest implements TanRequest
{
    public function getProcessId(): string
    {
        return 'process-1';
    }

    public function getChallenge(): ?string
    {
        return 'Please enter the TAN';
    }

    public function getTanMediumName(): ?string
    {
        return 'phone';
    }

    public function getChallengeHhdUc(): ?Bin
    {
        return null;
    }
}
